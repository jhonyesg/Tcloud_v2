<?php

namespace App\Modules\Papelera\Services;

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Papelera de reciclaje — servicio central.
 *
 * Single source of truth para soft-trash, restore, hard-delete y purga.
 * Antes la logica vivia dispersa en FileController::deleteRecursive (que
 * hard-deleaba con @rmdir silencioso) y en un cron de StorageSyncService.
 *
 * Reglas clave:
 *  - softTrash: marca is_trashed + parent_id=NULL + guarda original_parent_id.
 *    No toca disco. Listados que filtran por parent_id ocultan la fila sin
 *    cambiar la query existente.
 *  - restore: intenta volver al padre original; si no existe o esta trashado,
 *    va al root del storage. Si hay colision de nombre, sufijo -restored-<ts>.
 *  - hardDelete: skip si isFileLinked (protege transcripciones/shares/edits),
 *    luego borra fila + contenido en disco. rmdir puede fallar; logueamos y
 *    seguimos con la fila para no dejar zombie en BD.
 *  - purgeExpired: guardarrail de ratio (mismo patron que SessionService::cleanOrphans).
 *    Aborta si candidatos/total > config('trash.purge_max_ratio').
 *
 * Cache de conteos para sidebar: countFor() cachea por 60s por usuario.
 */
class PapeleraService
{
    public function softTrash(File $file, ?int $actorUserId = null): void
    {
        if ($file->is_trashed) {
            return;
        }

        $now = now();
        $originalParentId = $file->parent_id;

        $file->update([
            'is_trashed' => true,
            'deleted_at' => $now,
            'original_parent_id' => $originalParentId,
            'parent_id' => null,
        ]);

        if ($file->is_folder) {
            $children = File::where('parent_id', $file->id)->get();
            foreach ($children as $child) {
                $this->softTrash($child, $actorUserId);
            }
        }

        Log::info('papelera.soft_trash', [
            'file_id' => $file->id,
            'name' => $file->name,
            'is_folder' => $file->is_folder,
            'original_parent_id' => $originalParentId,
            'actor_user_id' => $actorUserId,
        ]);

        $this->invalidateSidebarCache($file->owner_id);
    }

    public function restore(File $file, ?int $actorUserId = null): File
    {
        if (!$file->is_trashed) {
            return $file;
        }

        $targetParentId = null;
        if ($file->original_parent_id !== null) {
            $originalParent = File::find($file->original_parent_id);
            if ($originalParent && !$originalParent->is_trashed) {
                $targetParentId = $originalParent->id;
            }
        }

        $newName = $this->resolveNameCollision(
            $file->storage_provider_id,
            $targetParentId,
            $file->name,
            $file->id
        );

        $file->update([
            'is_trashed' => false,
            'deleted_at' => null,
            'original_parent_id' => null,
            'parent_id' => $targetParentId,
            'name' => $newName,
        ]);

        Log::info('papelera.restore', [
            'file_id' => $file->id,
            'restored_name' => $newName,
            'target_parent_id' => $targetParentId,
            'actor_user_id' => $actorUserId,
        ]);

        $this->invalidateSidebarCache($file->owner_id);

        // fresh() puede devolver null en algunos edge cases (modelo detached),
        // asi que devolvemos el modelo en memoria directamente: el update()
        // ya se persistio y el estado en memoria es el canonico.
        return $file;
    }

    public function hardDelete(File $file, ?int $actorUserId = null): bool
    {
        if ($this->isFileLinked($file->id)) {
            Log::warning('papelera.hard_delete.skipped_linked', [
                'file_id' => $file->id,
                'actor_user_id' => $actorUserId,
            ]);
            return false;
        }

        $ownerId = $file->owner_id;

        if ($file->is_folder) {
            $this->deleteRecursive($file);
        } else {
            $this->deleteFile($file);
        }

        Log::info('papelera.hard_delete', [
            'file_id' => $file->id,
            'is_folder' => $file->is_folder,
            'actor_user_id' => $actorUserId,
        ]);

        if ($ownerId) {
            $this->invalidateSidebarCache($ownerId);
        }

        return true;
    }

    public function purgeExpired(int $batchSize = 500, float $maxRatio = 0.5): int
    {
        $lock = Cache::lock('trash:purge', (int) config('trash.lock_ttl', 600));
        if (!$lock->get()) {
            Log::info('papelera.purge.skipped_locked');
            return 0;
        }

        try {
            $cutoff = now()->subDays((int) config('trash.retention_days', 15));

            $candidates = File::trashed()->where('deleted_at', '<', $cutoff)->count();
            $total = File::notTrashed()->count() + $candidates;

            if ($total === 0) {
                return 0;
            }

            $ratio = $candidates / $total;
            if ($ratio > $maxRatio) {
                Log::warning('papelera.purge.aborted_mass_delete', [
                    'candidates' => $candidates,
                    'total' => $total,
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                ]);
                return 0;
            }

            $deleted = 0;
            File::trashed()
                ->where('deleted_at', '<', $cutoff)
                ->orderBy('id')
                ->chunkById($batchSize, function ($rows) use (&$deleted) {
                    foreach ($rows as $row) {
                        if ($this->hardDelete($row, null)) {
                            $deleted++;
                        }
                    }
                });

            Log::info('papelera.purge.completed', [
                'deleted' => $deleted,
                'candidates' => $candidates,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return $deleted;
        } finally {
            $lock->release();
        }
    }

    public function emptyFor(User $user): int
    {
        $deleted = 0;
        File::trashed()
            ->where('owner_id', $user->id)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted, $user) {
                foreach ($rows as $row) {
                    if ($this->hardDelete($row, $user->id)) {
                        $deleted++;
                    }
                }
            });

        $this->invalidateSidebarCache($user->id);

        Log::info('papelera.empty_for_user', [
            'user_id' => $user->id,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Devuelve ['total' => N, 'urgent' => M] para el sidebar.
     * urgent = items con menos de N dias restantes hasta la purga.
     * Cacheado por usuario para no martillar la BD en cada render del sidebar.
     */
    public function countFor(int $userId): array
    {
        if ($userId <= 0) {
            return ['total' => 0, 'urgent' => 0];
        }

        $ttl = (int) config('trash.sidebar_cache_ttl', 60);
        $cacheKey = "trash_count:{$userId}";

        return Cache::remember($cacheKey, $ttl, function () use ($userId) {
            $urgentThreshold = (int) config('trash.urgent_threshold_days', 3);
            $retentionDays = (int) config('trash.retention_days', 15);
            $urgentCutoff = now()->subDays($retentionDays - $urgentThreshold);

            $base = File::trashed()->where('owner_id', $userId);
            $total = (clone $base)->count();
            $urgent = (clone $base)->where('deleted_at', '>=', $urgentCutoff)->count();

            return ['total' => $total, 'urgent' => $urgent];
        });
    }

    public function daysRemaining(File $file): int
    {
        if (!$file->is_trashed || !$file->deleted_at) {
            return (int) config('trash.retention_days', 15);
        }

        $retentionDays = (int) config('trash.retention_days', 15);
        $elapsed = (int) floor($file->deleted_at->diffInDays(now()));
        return max(0, $retentionDays - $elapsed);
    }

    protected function resolveNameCollision(int $storageId, ?int $parentId, string $name, int $excludeId): string
    {
        $query = File::notTrashed()
            ->where('storage_provider_id', $storageId)
            ->where('name', $name);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        if (!$query->exists()) {
            return $name;
        }

        $suffix = '-' . now()->timestamp;
        $baseName = preg_replace('/-restored-\d+$/', '', $name) ?: $name;

        $candidate = $baseName . '-restored-' . now()->timestamp;
        $counter = 1;
        while ($this->nameExists($storageId, $parentId, $candidate, $excludeId)) {
            $candidate = $baseName . '-restored-' . now()->timestamp . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    protected function nameExists(int $storageId, ?int $parentId, string $name, int $excludeId): bool
    {
        $query = File::notTrashed()
            ->where('storage_provider_id', $storageId)
            ->where('name', $name)
            ->where('id', '!=', $excludeId);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        return $query->exists();
    }

    protected function isFileLinked(int $fileId): bool
    {
        $hasMediaJobsColumn = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT 1 AS ok FROM information_schema.columns WHERE table_name = 'media_edit_jobs' AND column_name = 'source_file_id'"
        ) !== null;

        $hasTx = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT 1 AS ok FROM transcriptions WHERE file_id = ? LIMIT 1',
            [$fileId]
        ) !== null;
        if ($hasTx) {
            return true;
        }

        $hasShare = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT 1 AS ok FROM shares WHERE file_id = ? LIMIT 1',
            [$fileId]
        ) !== null;
        if ($hasShare) {
            return true;
        }

        if ($hasMediaJobsColumn) {
            $hasJob = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT 1 AS ok FROM media_edit_jobs WHERE source_file_id = ? LIMIT 1',
                [$fileId]
            ) !== null;
            if ($hasJob) {
                return true;
            }
        }

        return false;
    }

    /**
     * Borra archivo en disco + fila. Replica FileController::deleteFile pero
     * sin el @rmdir silencioso del legacy — los errores se loguean.
     */
    protected function deleteFile(File $file): void
    {
        $storage = $file->storageProvider;

        if ($storage && $storage->type === 'local' && $file->path) {
            $fullPath = rtrim($storage->base_path, '/') . '/' . $file->path;
            if (is_file($fullPath)) {
                if (!unlink($fullPath)) {
                    Log::error('papelera.unlink_failed', [
                        'file_id' => $file->id,
                        'path' => $fullPath,
                    ]);
                }
            } else {
                Log::warning('papelera.file_not_found_on_disk', [
                    'file_id' => $file->id,
                    'path' => $fullPath,
                ]);
            }
        }

        if ($storage && $storage->is_personal && $file->size > 0) {
            $user = User::find($file->owner_id);
            if ($user) {
                $user->decrement('personal_used_bytes', (int) $file->size);
            }
        }

        $this->deleteClipThumbs($file->id);
        $file->delete();
    }

    /**
     * Borra carpeta recursivamente. Sin @rmdir silencioso: si rmdir falla,
     * logueamos y seguimos con $folder->delete() (la fila en BD no puede
     * quedar viva si todo lo demas se borro).
     */
    protected function deleteRecursive(File $folder): void
    {
        $children = File::where('parent_id', $folder->id)->with('storageProvider')->get();
        foreach ($children as $child) {
            if ($child->is_folder) {
                $this->deleteRecursive($child);
            } else {
                $this->deleteFile($child);
            }
        }

        $storage = $folder->storageProvider;
        if ($storage && $storage->type === 'local' && $folder->path) {
            $dirPath = rtrim($storage->base_path, '/') . '/' . $folder->path;
            if (is_dir($dirPath)) {
                if (!rmdir($dirPath)) {
                    Log::error('papelera.rmdir_failed', [
                        'file_id' => $folder->id,
                        'path' => $dirPath,
                    ]);
                }
            }
        }

        $folder->delete();
    }

    protected function deleteClipThumbs(int $fileId): void
    {
        $dir = storage_path("app/clip-thumbs/{$fileId}");
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    protected function invalidateSidebarCache(int $userId): void
    {
        if ($userId > 0) {
            Cache::forget("trash_count:{$userId}");
        }
    }
}
