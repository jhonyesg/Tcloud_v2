<?php

namespace App\Observers;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Log;

/**
 * Change `2026-09-17-files-physical-folder-identity` (Task 2.2):
 *
 * Mantiene `files.base_path_snapshot` sincronizado con
 * `storage_providers.base_path`. La copia denormalizada existe para que
 * `File::physicalPathNormalized()` y los queries cross-storage
 * (`resolveListingTargets`) no paguen un JOIN a `storage_providers`
 * en cada lookup.
 *
 * Reglas:
 *  - Si el row no tiene `storage_provider_id` (huérfano), snapshot queda NULL.
 *  - Si `storageProvider->base_path` es NULL/empty, snapshot queda NULL.
 *  - Solo escribe cuando el snapshot difiere del valor actual — evita
 *    disparar `updating` recursivo en saves subsecuentes.
 *  - Cuando el storage es desconocido (lazy load aún no resuelto), no
 *    falla: deja el snapshot existente y deja un warning para investigación.
 */
class FileObserver
{
    public function saving(File $file): void
    {
        if (!$file->storage_provider_id) {
            if ($file->base_path_snapshot !== null) {
                $file->base_path_snapshot = null;
            }
            return;
        }

        $basePath = $this->resolveStorageBasePath($file);

        if ($basePath === null) {
            if ($file->base_path_snapshot !== null) {
                Log::warning('FileObserver: storage_provider sin base_path, snapshot limpiado', [
                    'file_id' => $file->id,
                    'storage_provider_id' => $file->storage_provider_id,
                ]);
                $file->base_path_snapshot = null;
            }
            return;
        }

        if ($file->base_path_snapshot !== $basePath) {
            $file->base_path_snapshot = $basePath;
        }

        // files-mirror-elimination (2026-09-19): la identidad física se resuelve
        // en tiempo de lectura como `lower(rtrim(base_path_snapshot || '/' || path))`.
        // Si cambia cualquiera de sus componentes, la cache de `canonicalFor()`
        // queda stale. Cache key por file_id.
        $file->forgetCanonicalIdentityCache();
    }

    private function resolveStorageBasePath(File $file): ?string
    {
        $relation = $file->storageProvider;

        if ($relation === null) {
            return StorageProvider::where('id', $file->storage_provider_id)->value('base_path');
        }

        return $relation->base_path;
    }
}