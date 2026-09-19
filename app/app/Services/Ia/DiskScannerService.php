<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use App\Models\Transcription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alcance del descubrimiento + descubrimiento delegado.
 *
 * HISTORIA (change `transcriptor-physical-file-identity`): esta clase escaneaba
 * el filesystem con scandir/stat para descubrir grabaciones. Eso era redundante
 * porque Mis Archivos ya puebla el inventario completo cada 15 minutos
 * (`storage:sync --all` recorre TODOS los storages locales sin filtrar por
 * transcripcion). Medido 2026-09-16: storage 47 tenia 3.452 filas `files` de
 * hoy para 2.144 archivos en disco.
 *
 * Ahora:
 *   - `scanStorage()` DELEGA en TranscriptionDiscoveryService (consulta de solo
 *     lectura sobre `files`). Se conserva la firma por compatibilidad.
 *   - `collectFailedCandidates()` / `collectDoneCandidates()` se conservan
 *     intactos: operan sobre `transcriptions` y solo verifican `is_file()` para
 *     accesibilidad (no descubren).
 *   - Los helpers de escaneo (dayFolders, allFoldersRecursive,
 *     computeExcludedSubpaths, buildKnownOwnersMap, resolveParentId, ~540
 *     lineas) se ELIMINARON. La jerarquia la resuelve StorageHierarchyService.
 *
 * FRONTERA: esta clase ya NO escribe `files` (ni crea carpetas). `files` es la
 * tabla soberana de Mis Archivos.
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
        private TranscriptionDiscoveryService $discovery,
    ) {}

    /**
     * Descubre candidatos de un storage e inserta las transcripciones faltantes.
     *
     * DELEGACION (change `transcriptor-physical-file-identity`): el escaneo de
     * disco se elimino porque Mis Archivos ya puebla el inventario completo
     * cada 15 minutos (`storage:sync --all`). Este metodo conserva la firma
     * para no romper a los callers (ScanAndSubmitCommand, UI, tick), pero el
     * trabajo lo hace TranscriptionDiscoveryService con una consulta de solo
     * lectura sobre `files`.
     *
     * Los parametros `$daysBack`/`$all` se traducen al `$scope` equivalente
     * cuando este no viene, preservando la semantica historica:
     *   - $all=true                -> scope 'all'
     *   - $daysBack>0 && !$all     -> scope 'range' equivalente (hoy - N dias)
     *   - default                  -> scope 'today'
     *
     * `files_created` siempre es 0: el descubrimiento NO escribe `files`.
     *
     * @return array{candidates:int, files_created:int, transcriptions_created:int, scanned:int, skipped_existing:int, inventory_invoked:bool}
     */
    public function scanStorage(StorageProvider $storage, int $daysBack = 0, bool $all = false, ?int $batchOverride = null, bool $generateAlerts = true, ?array $scope = null): array
    {
        $scope ??= $this->legacyScopeFrom($daysBack, $all);

        return $this->discovery->discover($storage, $scope, $batchOverride, $generateAlerts);
    }

    /**
     * Traduce los parametros historicos ($daysBack/$all) al shape de `$scope`.
     */
    private function legacyScopeFrom(int $daysBack, bool $all): array
    {
        if ($all) {
            return self::scopeAll();
        }

        if ($daysBack > 0) {
            $to = BogotaTime::now();
            $from = $to->subDays($daysBack);

            return self::scopeRange($from->format('dmY'), $to->format('dmY'));
        }

        return self::scopeToday();
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
}
