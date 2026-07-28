<?php

namespace App\Services\Ia;

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escanea directamente el filesystem de los StorageProviders con transcripcion
 * habilitada para descubrir archivos .mp4 nuevos, sin depender de la tabla
 * files poblada por storage:sync.
 *
 * Crea la relacion File <-> Transcription si no existe y deja la Transcription
 * en state=pending sin job_id para que el TranscriptionSubmitService la envie.
 */
class DiskScannerService
{
    public const LAYOUT_FLAT = 'flat';
    public const LAYOUT_GROUPED = 'grouped_by_subfolder';

    public function __construct(
        private TranscriptorSettings $settings,
        private \App\Services\FileRegistry $registry,
    ) {}

    /**
     * Escanea un storage y devuelve estadisticas de lo que encontro/creó.
     *
     * @param  StorageProvider $storage
     * @param  int  $daysBack  Cuantos dias hacia atras escanear (0 = solo hoy)
     * @param  bool $all       Escanear recursivamente todas las carpetas
     * @param  bool $generateAlerts  Marcar las transcripciones creadas para generar avisos
     * @return array{candidates:int, files_created:int, transcriptions_created:int, scanned:int}
     */
    public function scanStorage(StorageProvider $storage, int $daysBack = 0, bool $all = false, ?int $batchOverride = null, bool $generateAlerts = true): array
    {
        $basePath = rtrim((string) $storage->base_path, '/');
        if (!is_dir($basePath) || !is_readable($basePath)) {
            return ['candidates' => 0, 'files_created' => 0, 'transcriptions_created' => 0, 'scanned' => 0];
        }

        $batch = $batchOverride ?? $this->settings->int('scan_batch');
        $minAge = $this->settings->int('scan_min_age_seconds');
        $cutoff = time() - $minAge;
        $tz = config('app.timezone');

        $candidates = [];
        $filesCreated = 0;
        $transcriptionsCreated = 0;
        $scanned = 0;

        $layout = $storage->folder_layout ?? self::LAYOUT_FLAT;

        // Paso 1: calcular subpaths a excluir (storages hijos con transcription_enabled).
        $excludedFirstSegments = $storage->allow_parent_overlap
            ? []
            : $this->computeExcludedSubpaths($storage);

        // Paso 2: descubrir carpetas de día según layout.
        $folderPaths = match ($layout) {
            self::LAYOUT_GROUPED => $this->dayFoldersGrouped($basePath, $daysBack, $all),
            default              => $all
                ? $this->allFoldersRecursive($basePath)
                : $this->dayFolders($basePath, $daysBack),
        };

        // Paso 3: iterar carpetas, excluyendo las que caen bajo un hijo.
        foreach ($folderPaths as $folder) {
            if (!is_dir($folder) || !is_readable($folder)) {
                continue;
            }
            if ($this->isInExcludedPath($folder, $basePath, $excludedFirstSegments)) {
                Log::info("DiskScanner: skip {$folder} (storage hijo toma control)");
                continue;
            }
            $folderRel = ltrim(str_replace($basePath, '', $folder), '/');

            foreach (scandir($folder) as $name) {
                if ($name === '.' || $name === '..') continue;
                if (str_starts_with($name, '.')) continue;

                $full = $folder . '/' . $name;
                if (!is_file($full)) continue;
                if (!preg_match('/\.(mp4|mkv|opus|flac|wav|mp3|aac)$/i', $name)) continue;

                $scanned++;
                $mtime = filemtime($full);
                if ($mtime === false || $mtime > $cutoff) continue; // aún escribiéndose

                $path = $folderRel === '' ? $name : $folderRel . '/' . $name;
                $candidates[] = [
                    'full' => $full,
                    'path' => $path,
                    'name' => $name,
                    'mtime' => $mtime,
                    'size' => (int) filesize($full),
                ];
            }
        }

        // Ordenar por mtime descendente (más recientes primero) y respetar batch.
        usort($candidates, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        if (count($candidates) > $batch) {
            $candidates = array_slice($candidates, 0, $batch);
        }

        // Paso 4 (optimizado): precomputar dedup en UNA sola query por scan.
        // Construye el set de absolute_paths ya registrados bajo OTRO storage
        // y arma un mapa path → StorageProvider dueño.
        $knownOwnerByAbsolute = $this->buildKnownOwnersMap(
            array_map(fn ($c) => $c['full'], $candidates),
            $storage->id
        );

        $ownerId = $storage->userStorages()->first()?->user_id ?? 1;

        foreach ($candidates as $c) {
            $absolutePath = $c['full'];

            if (isset($knownOwnerByAbsolute[$absolutePath])) {
                $owner = $knownOwnerByAbsolute[$absolutePath];
                Log::info("DiskScanner: skip {$absolutePath} (dueño: storage {$owner['id']} {$owner['name']})");
                continue;
            }

            DB::beginTransaction();
            try {
                $file = File::where('storage_provider_id', $storage->id)
                    ->where('path', $c['path'])
                    ->first();

                if (!$file) {
                    $file = $this->registry->ensure($storage, $c['path'], [
                        'name' => $c['name'],
                        'path' => $c['path'],
                        'size' => $c['size'],
                        'mime_type' => 'video/mp4',
                        'storage_provider_id' => $storage->id,
                        'owner_id' => $ownerId,
                        'parent_id' => $this->resolveParentId($storage, $c['path']),
                        'is_folder' => false,
                        'is_personal' => false,
                        'file_modified_at' => Carbon::createFromTimestamp($c['mtime'], $tz),
                    ]);
                    $filesCreated++;
                } else {
                    // Actualizar size/mtime si cambió (archivo reescrito).
                    if ($file->size !== $c['size']) {
                        $file->size = $c['size'];
                    }
                    $newMtime = Carbon::createFromTimestamp($c['mtime'], $tz);
                    if (!$file->file_modified_at || !$file->file_modified_at->eq($newMtime)) {
                        $file->file_modified_at = $newMtime;
                    }
                    if ($file->isDirty()) $file->save();
                }

                $existsTx = Transcription::where('file_id', $file->id)->exists();
                if (!$existsTx) {
                    Transcription::create([
                        'file_id' => $file->id,
                        'original_name' => $c['name'],
                        'state' => Transcription::STATE_PENDING,
                        'generate_alerts' => $generateAlerts,
                        'language' => $this->settings->str('language'),
                        'started_at' => now(),
                    ]);
                    $transcriptionsCreated++;
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error("DiskScanner: error con {$c['path']} (storage {$storage->id}): {$e->getMessage()}");
            }
        }

        return [
            'candidates' => count($candidates),
            'files_created' => $filesCreated,
            'transcriptions_created' => $transcriptionsCreated,
            'scanned' => $scanned,
        ];
    }

    /**
     * Recolecta transcripciones en estado 'error' de un storage y las prepara
     * para reintento: verifica accesibilidad del archivo en disco y resetea
     * la fila a 'pending' (manteniendo id, file_id, retries++). Si el archivo
     * no es accesible, promueve la fila a 'dead' con un mensaje claro.
     *
     * NO toca transcripciones en estado 'dead' (decisión de diseño: requieren
     * acción manual del operador).
     *
     * @param  StorageProvider $storage
     * @param  int $maxRetries Max reintentos automáticos antes de promover a dead
     * @return array{candidates:int, reset_to_pending:int, promoted_to_dead:int, skipped_max_retries:int}
     */
    public function collectFailedCandidates(StorageProvider $storage, int $maxRetries = 3): array
    {
        $stats = [
            'candidates' => 0,
            'reset_to_pending' => 0,
            'promoted_to_dead' => 0,
            'skipped_max_retries' => 0,
        ];

        $candidates = Transcription::where('state', Transcription::STATE_ERROR)
            ->where('retries', '<', $maxRetries)
            ->whereHas('file', function ($q) use ($storage) {
                $q->where('storage_provider_id', $storage->id);
            })
            ->with('file.storageProvider')
            ->get();

        foreach ($candidates as $tx) {
            $stats['candidates']++;
            $file = $tx->file;
            if (!$file || !$file->storageProvider) {
                $tx->update([
                    'state' => Transcription::STATE_DEAD,
                    'error_message' => 'Archivo o storage asociado no existe. No se reintentará automáticamente.',
                    'finished_at' => now(),
                ]);
                $stats['promoted_to_dead']++;
                continue;
            }

            $srcPath = rtrim((string) $file->storageProvider->base_path, '/')
                     . '/' . ltrim((string) $file->path, '/');

            if (!is_file($srcPath) || !is_readable($srcPath)) {
                $tx->update([
                    'state' => Transcription::STATE_DEAD,
                    'error_message' => "Archivo no accesible en disco ({$srcPath}). No se reintentará automáticamente.",
                    'finished_at' => now(),
                ]);
                Log::info("DiskScanner::collectFailedCandidates tx {$tx->id}: archivo no accesible, promovido a dead");
                $stats['promoted_to_dead']++;
                continue;
            }

            $tx->update([
                'state' => Transcription::STATE_PENDING,
                'error_message' => null,
                'job_id' => null,
                'node_url' => null,
                'node_id' => null,
                'retries' => $tx->retries + 1,
            ]);
            $stats['reset_to_pending']++;
        }

        $stats['skipped_max_retries'] = Transcription::where('state', Transcription::STATE_ERROR)
            ->where('retries', '>=', $maxRetries)
            ->whereHas('file', function ($q) use ($storage) {
                $q->where('storage_provider_id', $storage->id);
            })
            ->count();

        return $stats;
    }

    /**
     * Devuelve las rutas absolutas de las carpetas del día para hoy y los N días anteriores.
     */
    private function dayFolders(string $basePath, int $daysBack): array
    {
        $folders = [];
        $daysBack = max(0, $daysBack);
        for ($i = 0; $i <= $daysBack; $i++) {
            $date = now()->subDays($i);
            $folderName = $date->format('dmY');
            $folders[] = $basePath . '/' . $folderName;
        }
        return $folders;
    }

    /**
     * Para layout 'grouped_by_subfolder': devuelve TODAS las rutas absolutas
     * de carpetas <dmY>/ bajo basePath a cualquier profundidad.
     *
     * Soporta:
     *   - 1 nivel: base/<emisora>/dmY/  (ej. storage 47 Emisoras Bogota)
     *   - 2 niveles: base/<region>/<emisora>/dmY/  (ej. storage 49 Emisoras Regiones)
     *   - N niveles: cualquier anidamiento donde la carpeta final sea dmY
     */
    private function dayFoldersGrouped(string $basePath, int $daysBack, bool $all): array
    {
        $folders = [];
        $dayNames = [];
        $daysBack = max(0, $daysBack);
        for ($i = 0; $i <= $daysBack; $i++) {
            $dayNames[] = now()->subDays($i)->format('dmY');
        }
        $daySet = array_flip($dayNames);

        // Escanear recursivamente (depth 4 cubre casos conocidos: region/emisora/dmY).
        $candidates = $all
            ? $this->allFoldersRecursive($basePath, 6)
            : $this->allFoldersRecursive($basePath, 6);

        foreach ($candidates as $folder) {
            $name = basename($folder);
            if (isset($daySet[$name])) {
                $folders[] = $folder;
            }
        }

        return array_values(array_unique($folders));
    }

    /**
     * Devuelve las subcarpetas inmediatas (profundidad 1) de basePath.
     */
    private function immediateSubfolders(string $basePath): array
    {
        $result = [];
        if (!is_dir($basePath) || !is_readable($basePath)) {
            return $result;
        }
        foreach (scandir($basePath) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (str_starts_with($entry, '.')) continue;
            $full = $basePath . '/' . $entry;
            if (is_dir($full) && is_readable($full)) {
                $result[] = $full;
            }
        }
        return $result;
    }

    /**
     * Escaneo recursivo: devuelve todas las carpetas bajo basePath que contengan archivos.
     */
    private function allFoldersRecursive(string $basePath, ?int $maxDepth = null): array
    {
        $folders = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            if ($maxDepth !== null) {
                $it->setMaxDepth($maxDepth);
            }
        } catch (\Throwable $e) {
            return [$basePath];
        }
        $folders[] = $basePath;
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                $folders[] = $entry->getPathname();
            }
        }
        return array_unique($folders);
    }

    /**
     * Devuelve la lista de primeros segmentos de path (relativos a $storage->base_path)
     * que son base_path de otros storages con transcription_enabled=true y allow_parent_overlap=false.
     * El scanner omitirá esos subdirectorios completos para no duplicar.
     */
    private function computeExcludedSubpaths(StorageProvider $storage): array
    {
        $base = rtrim($storage->base_path, '/');
        $prefix = $base . '/';

        return StorageProvider::transcriptionEnabled()
            ->where('id', '!=', $storage->id)
            ->where('allow_parent_overlap', false)
            ->where('base_path', 'LIKE', $prefix . '%')
            ->pluck('base_path')
            ->map(function ($otherBase) use ($base) {
                $relative = ltrim(substr($otherBase, strlen($base)), '/');
                return explode('/', $relative)[0]; // solo el primer segmento
            })
            ->filter(fn ($seg) => $seg !== '' && $seg !== null)
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Determina si una carpeta absoluta cae dentro de algún subpath excluido.
     */
    private function isInExcludedPath(string $absoluteFolder, string $basePath, array $excludedFirstSegments): bool
    {
        if (empty($excludedFirstSegments)) return false;

        $relative = ltrim(substr($absoluteFolder, strlen(rtrim($basePath, '/'))), '/');
        $firstSegment = explode('/', $relative)[0] ?? '';

        return in_array($firstSegment, $excludedFirstSegments, true);
    }

    /**
     * Precomputa un mapa absolute_path => ['id' => ..., 'name' => ...] con el
     * StorageProvider dueño para cada uno de los absolute paths candidatos.
     *
     * Estrategia optimizada: en UNA sola query carga todos los File rows
     * (storage_provider_id, path) cuya base_path sea prefijo de alguno de los
     * absolute_paths candidatos, luego hace match en memoria.
     *
     * absolute_path = storage_provider.base_path + '/' + file.path
     *
     * @param  string[] $absolutePaths
     * @param  int $excludeStorageId
     * @return array<string, array{id:int,name:string}>
     */
    private function buildKnownOwnersMap(array $absolutePaths, int $excludeStorageId): array
    {
        if (empty($absolutePaths)) {
            return [];
        }

        // Encontrar todos los base_paths que sean prefijo de algún candidato.
        // Esto limita el universo de Files a inspeccionar.
        // IMPORTANTE: sólo storages con transcription_enabled=true son dueños válidos.
        // Si el "dueño" registrado está deshabilitado, el archivo está huérfano de
        // transcripción y debe poder ser reclamado por un storage habilitado.
        $matchingProviders = StorageProvider::where('id', '!=', $excludeStorageId)
            ->where('transcription_enabled', true)
            ->where(function ($q) use ($absolutePaths) {
                foreach ($absolutePaths as $abs) {
                    $q->orWhere(function ($qq) use ($abs) {
                        $prefix = rtrim($abs, '/') . '/';
                        $qq->whereRaw('? LIKE (storage_providers.base_path || \'/%\')', [$prefix]);
                    });
                }
            })
            ->get(['id', 'name', 'base_path']);

        if ($matchingProviders->isEmpty()) {
            return [];
        }

        // Cargar todos los Files (is_folder=false) de esos providers en UNA query.
        $providerIds = $matchingProviders->pluck('id')->all();
        $rows = DB::table('files')
            ->select('storage_provider_id', 'path')
            ->whereIn('storage_provider_id', $providerIds)
            ->where('is_folder', false)
            ->get();

        // Construir índice: provider_id => base_path.
        $baseByProvider = [];
        foreach ($matchingProviders as $p) {
            $baseByProvider[$p->id] = rtrim((string) $p->base_path, '/');
        }

        // Match en memoria: para cada File conocido, calcular su absolute_path
        // y registrarlo en el mapa si está en el set de candidatos.
        $candidateSet = array_flip(array_map(fn ($p) => rtrim($p, '/'), $absolutePaths));
        $nameByProvider = [];
        foreach ($matchingProviders as $p) {
            $nameByProvider[$p->id] = $p->name;
        }

        $map = [];
        foreach ($rows as $r) {
            $abs = $baseByProvider[$r->storage_provider_id] . '/' . $r->path;
            if (isset($candidateSet[$abs])) {
                $map[$abs] = [
                    'id' => (int) $r->storage_provider_id,
                    'name' => $nameByProvider[$r->storage_provider_id] ?? '',
                ];
            }
        }

        return $map;
    }

    /**
     * Resuelve el parent_id recorriendo la jerarquía de carpetas del path.
     * Crea las carpetas padre faltantes.
     */
    private function resolveParentId(StorageProvider $storage, string $path): ?int
    {
        $parts = explode('/', $path);
        array_pop($parts); // quitar el nombre del archivo
        if (empty($parts)) return null;

        $parentId = null;
        $accumPath = '';
        foreach ($parts as $part) {
            $accumPath = $accumPath === '' ? $part : $accumPath . '/' . $part;
            $folder = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumPath)
                ->where('is_folder', true)
                ->first();
            if (!$folder) {
                // Via FileRegistry: este bucle corre cada 2 minutos desde
                // transcription:tick, en paralelo con storage:sync, y antes no
                // tenia ninguna proteccion frente a carreras.
                $folder = $this->registry->ensure($storage, $accumPath, [
                    'name' => $part,
                    'path' => $accumPath,
                    'size' => 0,
                    'mime_type' => 'folder',
                    'storage_provider_id' => $storage->id,
                    'owner_id' => $storage->userStorages()->first()?->user_id ?? 1,
                    'parent_id' => $parentId,
                    'is_folder' => true,
                    'is_personal' => false,
                    'file_modified_at' => now(),
                ]);
            }
            $parentId = $folder->id;
        }
        return $parentId;
    }
}
