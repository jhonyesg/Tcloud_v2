<?php

namespace App\Services\Ia;

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\FileScannerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /**
     * transcriptor-scan-scope-selector: alcance del descubrimiento.
     * shape: {mode: 'today'|'range'|'all', folders?: string[]}
     *  - today: solo la carpeta dmY de hoy (comportamiento vigente)
     *  - range: carpetas dmY explícitas del rango (folders ya calculadas como
     *           nombres 'dmY'; el service las resuelve por layout)
     *  - all: recursivo completo (ya existía vía --all)
     */
    public static function scopeToday(): array
    {
        return ['mode' => 'today', 'folders' => []];
    }

    public static function scopeRange(string $fromDmY, string $toDmY): array
    {
        return ['mode' => 'range', 'folders' => self::foldersInRange($fromDmY, $toDmY)];
    }

    public static function scopeAll(): array
    {
        return ['mode' => 'all', 'folders' => []];
    }

    /**
     * Nombres de carpeta 'dmY' para CADA día del rango [from, to] inclusive.
     * from/to con formato DDMMYYYY. Lanza InvalidArgumentException si el rango
     * es inválido (from > to, formato incorrecto o fecha inexistente tipo 3102).
     *
     * @return string[] nombres 'dmY' (NO rutas absolutas)
     */
    public static function foldersInRange(string $fromDmY, string $toDmY): array
    {
        $parse = function (string $v): ?\DateTimeImmutable {
            $m = [];
            if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', trim($v), $m)) {
                return null;
            }
            $iso = $m[3] . '-' . $m[2] . '-' . $m[1];
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $iso);
            // Compara con el ISO reconstruido: descarta fechas tipo 31022026
            // que DateTime malnormaliza silenciosamente.
            if (!$d || $d->format('Y-m-d') !== $iso) {
                return null;
            }
            return $d;
        };

        $dateFrom = $parse($fromDmY);
        $dateTo = $parse($toDmY);
        if ($dateFrom === null || $dateTo === null) {
            throw new \InvalidArgumentException("Formato de fecha inválido: se espera DDMMYYYY (recibido from={$fromDmY}, to={$toDmY})");
        }
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException("Rango inválido: desde ({$fromDmY}) es posterior a hasta ({$toDmY})");
        }

        $names = [];
        $cur = $dateFrom;
        // Cota de seguridad: 366 días cubre un año completo de backlog.
        $guard = 0;
        while ($cur <= $dateTo && $guard < 366) {
            $names[] = $cur->format('dmY');
            $cur = $cur->modify('+1 day');
            $guard++;
        }

        return $names;
    }

    public function __construct(
        private TranscriptorSettings $settings,
        private \App\Services\FileRegistry $registry,
        private FileScannerService $fileScanner,
    ) {}

    /**
     * Escanea un storage y devuelve estadisticas de lo que encontro/creó.
     *
     * transcriptor-scan-scope-selector: $daysBack/$all se mantienen para
     * compatibilidad con callers existentes, pero $scope (nuevo, opcional)
     * manda cuando viene. Scope shape:
     *   {mode: 'today'|'range'|'all', folders?: string[]}
     *
     * @param  StorageProvider $storage
     * @param  int  $daysBack  Cuantos dias hacia atras escanear (0 = solo hoy)
     * @param  bool $all       Escanear recursivamente todas las carpetas
     * @param  bool $generateAlerts  Marcar las transcripciones creadas para generar avisos
     * @param  array|null $scope  Alcance del descubrimiento (manda sobre daysBack/all)
     * @return array{candidates:int, files_created:int, transcriptions_created:int, scanned:int}
     */
    public function scanStorage(StorageProvider $storage, int $daysBack = 0, bool $all = false, ?int $batchOverride = null, bool $generateAlerts = true, ?array $scope = null): array
    {
        $basePath = rtrim((string) $storage->base_path, '/');
        if (!is_dir($basePath) || !is_readable($basePath)) {
            return ['candidates' => 0, 'files_created' => 0, 'transcriptions_created' => 0, 'scanned' => 0];
        }

        $batch = $batchOverride ?? $this->settings->int('scan_batch');
        $minAge = $this->settings->int('scan_min_age_seconds');
        $cutoff = time() - $minAge;
        $skipLatest = $this->settings->bool('scan_skip_latest_per_storage');
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

        // Paso 2: descubrir carpetas de día según layout y alcance.
        // transcriptor-scan-scope-selector: el scope 'range' inyecta la lista
        // explícita de nombres dmY; 'all' y 'today' conservan el camino previo.
        $scopeMode = $scope['mode'] ?? null;
        $rangeFolders = null;
        if ($scopeMode === 'range') {
            $rangeFolders = array_values(array_filter((array) ($scope['folders'] ?? [])));
        }

        $folderPaths = match ($layout) {
            self::LAYOUT_GROUPED => $rangeFolders !== null
                ? $this->dayFoldersGrouped($basePath, $daysBack, false, $rangeFolders)
                : $this->dayFoldersGrouped($basePath, $daysBack, $all),
            default => $rangeFolders !== null
                ? $this->dayFoldersExplicit($basePath, $rangeFolders)
                : ($all
                    ? $this->allFoldersRecursive($basePath)
                    : $this->dayFolders($basePath, $daysBack)),
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
                if (!preg_match('/\.(mp4|mkv|m4a|opus|flac|wav|mp3|aac)$/i', $name)) continue;

                $scanned++;

                // stat suprimido y comprobado: sin la @, el E_WARNING que emite un
                // archivo ilegible (EIO en los NFS, o borrado entre el is_file y
                // esta linea) se convierte en ErrorException y aborta el escaneo
                // COMPLETO del storage. La comprobacion de false ni siquiera
                // llegaba a ejecutarse.
                $mtime = @filemtime($full);
                if ($mtime === false || $mtime > $cutoff) continue; // ilegible o aún escribiéndose

                $size = @filesize($full);
                if ($size === false) continue;

                // Filtro de tamaño en el ORIGEN (fix 2026-09-16): una grabación
                // con el stream caído existe en disco pero pesa 0 bytes, y el
                // tick la encolaba igual. El stage la rechazaba por
                // `min_file_size_bytes`, la reencolaba cada 5 min y consumia 8
                // reintentos (~40 min) antes de morir: la fila se veia como
                // "en cola" esperando algo que nunca iba a poder convertirse.
                //
                // Filtrando aqui, la fila NI SE CREA. Y si el grabador estaba
                // escribiendo y el archivo crece despues, el proximo pase del
                // tick lo levanta normalmente: el filtro se reevalua en cada
                // escaneo.
                $minSize = $this->settings->int('min_file_size_bytes');
                if ($minSize > 0 && $size < $minSize) {
                    continue;
                }

                $path = $folderRel === '' ? $name : $folderRel . '/' . $name;
                $candidates[] = [
                    'full' => $full,
                    'path' => $path,
                    'name' => $name,
                    'mtime' => $mtime,
                    'size' => (int) $size,
                    'storage_id' => (int) $storage->id,
                ];
            }
        }

        // Ordenar por mtime descendente (más recientes primero) y respetar batch.
        usort($candidates, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        // Validacion 3: excluir el archivo mas reciente por storage (transcriptor-burst-validation).
        // El operador confirma que "siempre el archivo mas reciente del dia se esta
        // generando" (ffmpeg con -t 15-20min). Por tanto, descartamos el primer
        // elemento de cada storage del sort por mtime DESC.
        if ($skipLatest && !empty($candidates)) {
            $latestMtimePerStorage = [];
            foreach ($candidates as $idx => $c) {
                $sid = (int) ($c['storage_id'] ?? 0);
                if (!isset($latestMtimePerStorage[$sid])) {
                    $latestMtimePerStorage[$sid] = $c['mtime'];
                    unset($candidates[$idx]); // descartar el mas reciente
                }
            }
            $candidates = array_values($candidates);
        }

        // transcriptor-two-phase-staging — el cap del batch se aplica DESPUES de
        // descartar lo ya registrado, no antes.
        //
        // Antes: se cortaba a `$batch` y recien entonces se saltaban los archivos
        // que ya tenian fila de transcripcion. Como el orden es mtime DESC, cada
        // ciclo gastaba cupo en los archivos recien registrados (que siguen siendo
        // los mas nuevos) y solo descubria los pocos huecos que quedaran dentro de
        // esa ventana. Medido en "02 Emisoras 01 Reg": de 100 slots, ~20 se
        // desperdiciaban en archivos conocidos, y quedaban 1.467 archivos del dia
        // sin fila — invisibles para todos los paneles.
        //
        // Ahora: se filtra contra el registro existente y se corta despues, asi
        // los slots del batch se gastan integramente en material nuevo.
        $candidates = $this->dropAlreadyRegistered(
            $candidates,
            (int) $storage->id,
            $maxProbe = max(1000, $batch * 20),
        );

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
                        'mime_type' => $this->fileScanner->getMimeType($c['name']),
                        'storage_provider_id' => $storage->id,
                        'owner_id' => $ownerId,
                        'parent_id' => $this->resolveParentId($storage, $c['path']),
                        'is_folder' => false,
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
                        'discovered_at' => now(),
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
     * transcriptor-scan-scope-selector: con $fromIso/$toIso (YYYY-MM-DD) el
     * reintento se acota a transcripciones creadas dentro del rango; sin
     * rango, comportamiento previo (todos).
     *
     * @param  StorageProvider $storage
     * @param  int $maxRetries Max reintentos automáticos antes de promover a dead
     * @param  string|null $fromIso YYYY-MM-DD (inclusive)
     * @param  string|null $toIso YYYY-MM-DD (inclusive)
     * @return array{candidates:int, reset_to_pending:int, promoted_to_dead:int, skipped_max_retries:int}
     */
    public function collectFailedCandidates(StorageProvider $storage, int $maxRetries = 3, ?string $fromIso = null, ?string $toIso = null): array
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
            ->when($fromIso !== null, fn ($q) => $q->where('created_at', '>=', $fromIso))
            ->when($toIso !== null, fn ($q) => $q->where('created_at', '<=', $toIso . ' 23:59:59'))
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
     * Recolecta transcripciones en estado 'done' de un storage y las prepara
     * para reprocesamiento (transcriptor-rescan-completed): verifica accesibilidad
     * del archivo en disco y resetea la fila a 'pending' (manteniendo id, file_id,
     * srt_content y retries++). Si el archivo no es accesible, promueve la fila
     * a 'dead' con un mensaje claro.
     *
     * A diferencia de collectFailedCandidates, este método:
     *  - No tiene tope de retries (el admin decide cuándo reprocesar).
     *  - Conserva srt_content como fallback si el job nuevo falla upstream.
     *  - Limpia el lock ShouldBeUnique previo si lo hubiera (compatibilidad
     *    con locks legados del dispatch Bus pre-cutover, eliminados en
     *    transcriptor-pg-native-queue).
     *
     * El filtro de fecha es por finished_at (no created_at): el operador piensa
     * en "lo que terminó hoy", no en "lo que se creó hoy".
     *
     * @param  StorageProvider $storage
     * @param  string|null $fromIso YYYY-MM-DD (inclusive, filtra finished_at >=)
     * @param  string|null $toIso YYYY-MM-DD (inclusive, filtra finished_at <=)
     * @return array{candidates:int, reset_to_pending:int, promoted_to_dead:int, skipped_no_file:int}
     */
    public function collectDoneCandidates(StorageProvider $storage, ?string $fromIso = null, ?string $toIso = null): array
    {
        $stats = [
            'candidates' => 0,
            'reset_to_pending' => 0,
            'promoted_to_dead' => 0,
            'skipped_no_file' => 0,
        ];

        $candidates = Transcription::where('state', Transcription::STATE_DONE)
            ->whereHas('file', function ($q) use ($storage) {
                $q->where('storage_provider_id', $storage->id);
            })
            ->when($fromIso !== null, fn ($q) => $q->where('finished_at', '>=', $fromIso . ' 00:00:00'))
            ->when($toIso !== null, fn ($q) => $q->where('finished_at', '<=', $toIso . ' 23:59:59'))
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
                $stats['skipped_no_file']++;
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
                Log::info("DiskScanner::collectDoneCandidates tx {$tx->id}: archivo no accesible, promovido a dead");
                $stats['promoted_to_dead']++;
                continue;
            }

            $tx->update([
                'state' => Transcription::STATE_PENDING,
                'error_message' => null,
                'job_id' => null,
                'node_url' => null,
                'node_id' => null,
                'finished_at' => null,
                'retries' => $tx->retries + 1,
            ]);

            // transcriptor-rescan-completed (R1): limpiar cualquier lock ShouldBeUnique
            // que pudiera quedar de la era pre-migracion (clase de job eliminada
            // en transcriptor-pg-native-queue). El patron de cache key viene de
            // UniqueLock::getKey() en Illuminate\Bus: 'laravel_unique_job:{class}:{uniqueId}'.
            // Si existian locks legados en cache con esa key, forceRelease los limpia
            // para que el dispatch en Fase 2 NO sea deduplicado por Laravel. La cadena
            // literal preserva la clave exacta que venia usando el Bus historico.
            Cache::lock('laravel_unique_job:App\\Jobs\\ConvertAndTranscribeJob:' . $tx->file_id)->forceRelease();

            $stats['reset_to_pending']++;
        }

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
     * transcriptor-scan-scope-selector: rutas absolutas para la lista EXPLÍCITA
     * de nombres dmY del rango elegido (layout flat).
     *
     * @param string[] $folderNames nombres 'dmY'
     * @return string[] rutas absolutas
     */
    private function dayFoldersExplicit(string $basePath, array $folderNames): array
    {
        $folders = [];
        foreach ($folderNames as $name) {
            if (!is_string($name) || !preg_match('/^\d{8}$/', $name)) {
                continue; // defensa: solo nombres dmY
            }
            $folders[] = $basePath . '/' . $name;
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
    private function dayFoldersGrouped(string $basePath, int $daysBack, bool $all, ?array $explicitDayNames = null): array
    {
        $folders = [];
        $dayNames = [];
        if ($explicitDayNames !== null) {
            // transcriptor-scan-scope-selector: lista inyectada del rango elegido.
            $dayNames = array_values(array_filter($explicitDayNames, fn ($n) => is_string($n) && preg_match('/^\d{8}$/', $n)));
        } else {
            $daysBack = max(0, $daysBack);
            for ($i = 0; $i <= $daysBack; $i++) {
                $dayNames[] = now()->subDays($i)->format('dmY');
            }
        }
        if (empty($dayNames)) {
            return [];
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
     * Devuelve la lista de subpaths completos (relativos a $storage->base_path)
     * que son base_path de otros storages con transcription_enabled=true y
     * allow_parent_overlap=false. El scanner omitira SOLO esos subdirectorios
     * especificos, no el primer segmento completo. Antes (bug 2026-09-15) se
     * skipeaba el primer segmento completo (ej: "Antioquia/"), lo que dejaba
     * emisoras hermanas sin reclamar dentro del mismo padre (ej: archivos bajo
     * "Antioquia/Otra_Emisora_Sin_Storage_Hijo/14092026/" nunca se escaneaban).
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
                return rtrim($relative, '/');
            })
            ->filter(fn ($seg) => $seg !== '' && $seg !== null)
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Determina si una carpeta absoluta cae dentro de algun subpath excluido.
     * Ahora compara contra paths completos (no solo primer segmento), asi que
     * solo se skipea lo que un storage hijo reclama explicitamente.
     */
    private function isInExcludedPath(string $absoluteFolder, string $basePath, array $excludedSubpaths): bool
    {
        if (empty($excludedSubpaths)) return false;

        $relative = ltrim(substr($absoluteFolder, strlen(rtrim($basePath, '/'))), '/');

        foreach ($excludedSubpaths as $excluded) {
            if ($excluded === '' || $excluded === null) continue;
            if ($relative === $excluded || str_starts_with($relative . '/', $excluded . '/')) {
                return true;
            }
        }

        return false;
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
     * Descarta de la lista de candidatos los archivos que YA tienen fila de
     * transcripcion en este storage.
     *
     * Por que existe: el cap `scan_batch` de cada ciclo se aplicaba antes de
     * este chequeo, asi que los slots se gastaban en archivos recien
     * registrados (que por el orden mtime DESC siempre estan al frente) en vez
     * de en material nuevo. Resultado medido: 1.838 archivos del dia sin fila,
     * invisibles en todos los paneles.
     *
     * La query es acotada: usa el indice unico `transcriptions(file_id)` y
     * resuelve los IDs de `files` por (storage_provider_id, path) — el mismo
     * par que usa el resto del scanner. Se limita con `$maxProbe` para no
     * construir un IN gigante si un storage tuviera decenas de miles de
     * candidatos en un dia.
     *
     * @param  list<array{full:string,path:string,name:string,mtime:int,size:int,storage_id:int}>  $candidates
     * @return list<array{full:string,path:string,name:string,mtime:int,size:int,storage_id:int}>
     */
    private function dropAlreadyRegistered(array $candidates, int $storageId, int $maxProbe): array
    {
        if (empty($candidates)) {
            return [];
        }

        $probe = array_slice($candidates, 0, max(1, $maxProbe));

        $paths = array_map(static fn ($c) => (string) $c['path'], $probe);

        try {
            // Un solo query: paths de este storage que ya tienen transcripcion.
            $registered = DB::table('files')
                ->join('transcriptions as t', 't.file_id', '=', 'files.id')
                ->where('files.storage_provider_id', $storageId)
                ->where('files.is_folder', false)
                ->whereIn('files.path', $paths)
                ->pluck('files.path')
                ->all();
        } catch (\Throwable $e) {
            // Fail-open: si el chequeo falla, es preferible procesar candidatos
            // de mas (el loop de abajo los salta igual) que no descubrir nada.
            Log::warning("DiskScanner: dropAlreadyRegistered fallo storage {$storageId}: {$e->getMessage()}");

            return $candidates;
        }

        if (empty($registered)) {
            return $candidates;
        }

        $known = array_flip($registered);

        return array_values(array_filter(
            $candidates,
            static fn ($c) => !isset($known[(string) $c['path']])
        ));
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
                    'file_modified_at' => now(),
                ]);
            }
            $parentId = $folder->id;
        }
        return $parentId;
    }
}
