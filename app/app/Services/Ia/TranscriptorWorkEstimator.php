<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use App\Models\Transcription;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta el trabajo disponible en un alcance ANTES de lanzarlo.
 *
 * Alimenta el modal de "Procesamiento personalizado" del API Transcriptor: el
 * operador elige alcance (hoy / rango de fechas / histórico) y tipo de trabajo
 * (pendientes, en error, sin fila, reprocesar hechos), y este servicio devuelve
 * cuántos hay de cada tipo para que la decisión sea informada.
 *
 * Contrato: SOLO LEE. No crea filas, no encola, no muta nada. Es seguro llamarlo
 * en cada cambio del formulario del modal.
 *
 * Los conteos son ACOTADOS a propósito (ver $cap): un histórico completo puede
 * tener millones de filas y el operador no necesita el número exacto para
 * decidir, solo el orden de magnitud. Cuando el conteo toca el techo, la
 * respuesta marca `capped = true` para que la UI lo advierta.
 */
class TranscriptorWorkEstimator
{
    /** Techo de conteo por categoría: evita agregaciones masivas sobre 300k+ filas. */
    public const COUNT_CAP = 50000;

    /**
     * Estimación del trabajo disponible para un alcance.
     *
     * @param  array{mode:string, folders?:string[]}  $scope  Salida de DiskScannerService::scope*()
     * @param  list<int>  $storageIds  Storages habilitados a considerar (vacío = todos)
     * @return array{
     *     scope_mode:string,
     *     storages_count:int,
     *     files_missing:int,
     *     pending:int,
     *     error_recoverable:int,
     *     done_rescan:int,
     *     dead_upstream_lost:int,
     *     capped:bool
     * }
     */
    public function estimate(array $scope, array $storageIds = []): array
    {
        $mode = (string) ($scope['mode'] ?? 'today');

        $storageQuery = StorageProvider::query()->where('transcription_enabled', true);
        if (!empty($storageIds)) {
            $storageQuery->whereIn('id', $storageIds);
        }
        $storages = $storageQuery->get(['id', 'base_path', 'folder_layout', 'allow_parent_overlap']);
        $ids = $storages->pluck('id')->all();

        // Rango de fechas efectivo del alcance, en formato dmY -> Carbon.
        [$from, $to] = $this->dateBounds($scope);

        $pending = $this->countPending($ids, $from, $to);
        $errorRecoverable = $this->countError($ids, $from, $to);
        $doneRescan = $this->countDone($ids, $from, $to);
        $deadLost = $this->countDeadUpstreamLost($ids, $from, $to);

        // "Sin fila" no se puede resolver con SQL puro: exige recorrer el disco.
        // En modo 'all' sería un escaneo completo (caro); se aproxima con el
        // conteo de archivos del alcance en la tabla `files` y se marca la
        // limitación en la respuesta.
        $filesMissing = $this->countFilesMissing($ids, $from, $to);

        return [
            'scope_mode' => $mode,
            'storages_count' => count($ids),
            'files_missing' => $filesMissing,
            'pending' => $pending,
            'error_recoverable' => $errorRecoverable,
            'done_rescan' => $doneRescan,
            'dead_upstream_lost' => $deadLost,
            'capped' => max($pending, $errorRecoverable, $doneRescan, $deadLost, $filesMissing) >= self::COUNT_CAP,
        ];
    }

    /**
     * Límites de fecha del alcance. `null` en modo 'all' (sin filtro).
     *
     * @return array{0:?\Carbon\CarbonImmutable,1:?\Carbon\CarbonImmutable}
     */
    private function dateBounds(array $scope): array
    {
        $mode = (string) ($scope['mode'] ?? 'today');

        if ($mode === 'all') {
            return [null, null];
        }

        if ($mode === 'range' && !empty($scope['folders'])) {
            $folders = array_values(array_filter((array) $scope['folders'], 'is_string'));
            sort($folders);
            $first = $this->folderToDate($folders[0]);
            $last = $this->folderToDate(end($folders));

            return [$first, $last];
        }

        // 'today': el propio día en la zona de la app.
        $today = BogotaTime::todayStart();

        return [$today, $today];
    }

    /** Convierte un nombre de carpeta 'dmY' a CarbonImmutable (inicio del día). */
    private function folderToDate(string $folder): ?\Carbon\CarbonImmutable
    {
        $m = [];
        if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', trim($folder), $m)) {
            return null;
        }
        $iso = "{$m[3]}-{$m[2]}-{$m[1]}";

        try {
            return \Carbon\CarbonImmutable::parse($iso, config('app.timezone'))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Aplica el filtro de alcance sobre `transcriptions` vía la fecha del
     * programa (`recorded_at`), que es la que mapea a la carpeta diaria.
     */
    private function scopeTranscriptions($query, array $storageIds, ?\Carbon\CarbonImmutable $from, ?\Carbon\CarbonImmutable $to)
    {
        if (!empty($storageIds)) {
            $query->whereIn('transcriptions.file_id', function ($sub) use ($storageIds) {
                $sub->select('id')->from('files')->whereIn('storage_provider_id', $storageIds);
            });
        }

        if ($from !== null) {
            $query->where('transcriptions.recorded_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('transcriptions.recorded_at', '<', $to->addDay());
        }

        return $query;
    }

    private function countPending(array $ids, ?\Carbon\CarbonImmutable $from, ?\Carbon\CarbonImmutable $to): int
    {
        if (empty($ids)) {
            return 0;
        }
        $q = DB::table('transcriptions')
            ->where('state', Transcription::STATE_PENDING)
            ->limit(self::COUNT_CAP);
        $this->scopeTranscriptions($q, $ids, $from, $to);

        return $q->count();
    }

    private function countError(array $ids, ?\Carbon\CarbonImmutable $from, ?\Carbon\CarbonImmutable $to): int
    {
        if (empty($ids)) {
            return 0;
        }
        $q = DB::table('transcriptions')->where('state', Transcription::STATE_ERROR);
        $this->scopeTranscriptions($q, $ids, $from, $to);

        return $q->count();
    }

    private function countDone(array $ids, ?\Carbon\CarbonImmutable $from, ?\Carbon\CarbonImmutable $to): int
    {
        if (empty($ids)) {
            return 0;
        }
        $q = DB::table('transcriptions')->where('state', Transcription::STATE_DONE);
        $this->scopeTranscriptions($q, $ids, $from, $to);

        return min(self::COUNT_CAP, $q->count());
    }

    private function countDeadUpstreamLost(array $ids, ?\Carbon\CarbonImmutable $from, ?\Carbon\CarbonImmutable $to): int
    {
        if (empty($ids)) {
            return 0;
        }
        $q = DB::table('transcriptions')->where('state', Transcription::STATE_DEAD);
        $this->scopeTranscriptions($q, $ids, $from, $to);

        return min(self::COUNT_CAP, $q->count());
    }

    /**
     * Archivos registrados en el alcance que NO tienen ninguna transcripción.
     *
     * Se resuelve contra la tabla `files` (poblada por storage:sync), no contra
     * el filesystem: es una query indexada y no bloquea el disco. El escaneo
     * real (que sí toca disco) lo hace `transcription:scan-and-submit` al
     * lanzarse; este número es la mejor aproximación barata.
     */
    private function countFilesMissing(array $ids, ?\Carbon\CarbonImmutable $from, ?\Carbon\CarbonImmutable $to): int
    {
        if (empty($ids)) {
            return 0;
        }

        $q = DB::table('files')
            ->whereIn('files.storage_provider_id', $ids)
            ->where('files.is_folder', false)
            ->where('files.is_trashed', false)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('transcriptions')
                    ->whereColumn('transcriptions.file_id', 'files.id');
            });

        if ($from !== null) {
            $q->where('files.file_modified_at', '>=', $from);
        }
        if ($to !== null) {
            $q->where('files.file_modified_at', '<', $to->addDay());
        }

        return min(self::COUNT_CAP, $q->count());
    }
}
