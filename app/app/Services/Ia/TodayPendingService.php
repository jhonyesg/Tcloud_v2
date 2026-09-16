<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use App\Models\Transcription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Definicion UNICA de "pendiente de hoy" para el modulo API Transcriptor.
 *
 * El problema que resuelve: antes cada consumidor medía "hoy" y "pendiente"
 * con criterios distintos y todos mostraban numeros que no cuadraban con el
 * disco ni entre si:
 *
 *   - `TranscriptionTickCommand::countPendingToday()` -> `transcriptions.created_at`
 *   - `TranscriptionWorkerCommand::claimRow()`        -> `transcriptions.recorded_at`
 *   - `TranscriptorSettingsController::queueDepth()`  -> `created_at` + sin dispatched
 *   - `StorageFunnelService::countsForScope()`        -> `created_at`, sin mirar el disco
 *
 * La definicion operativa acordada (2026-09-15) es la que el operador ve al
 * abrir el navegador de archivos:
 *
 *   "pendiente de hoy" = archivo (files.is_folder=false, no en papelera) cuyo
 *   `file_modified_at` cae en el dia de HOY en America/Bogota, pertenece a un
 *   storage con `transcription_enabled=true`, y NO tiene una transcripcion en
 *   `state='done'`.
 *
 * El "no tiene done" es lo que captura los archivos SIN FILA de transcripcion
 * (huecos de discovery): sin esto, ~1.800 archivos de hoy con storage
 * habilitado quedaban invisibles en TODOS los paneles.
 *
 * `file_modified_at` es la fecha de la grabacion y esta poblada para todo el
 * inventario; `recorded_at` (fecha del programa, ver RecordedAt) puede ser NULL
 * en filas degeneradas, por eso no se usa como eje del filtro.
 *
 * Cache: 45 s por scope (la cache del funnel ya vivia 60 s). La key incluye el
 * root del scope para que el tab Storages reutilice UN calculo por jerarquia.
 */
class TodayPendingService
{
    private const CACHE_PREFIX = 'transcriptor.today_pending.scope.';
    private const CACHE_TTL_SECONDS = 45;

    /**
     * Desglose de los archivos de hoy que NO estan listos, por storage.
     *
     * @return array<int, array{
     *     missing: int, pending: int, queued: int, processing: int,
     *     error: int, dead: int, not_done: int, done: int, total: int
     * }>
     */
    public function byStorageForScope(int $rootId): array
    {
        $cacheKey = self::CACHE_PREFIX . $rootId;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($rootId) {
            return $this->computeByStorageForScope($rootId);
        });
    }

    /**
     * Agregado global (todos los storages con transcripcion habilitada) de los
     * archivos de hoy. Es la señal que alimenta la tarjeta "Pendientes de hoy"
     * del tab Configuracion y el `queue_depth` del regulador.
     *
     * @return array{
     *     missing: int, pending: int, queued: int, processing: int,
     *     error: int, dead: int, not_done: int, done: int, total: int,
     *     actionable: int
     * }
     */
    public function global(): array
    {
        return Cache::remember('transcriptor.today_pending.global', self::CACHE_TTL_SECONDS, function () {
            return $this->computeGlobal();
        });
    }

    /**
     * Fuerza el recalculo del dia. Lo llaman los mutadores del modulo (toggle
     * de storage, escaneo manual) para que la UI no espere el TTL.
     */
    public function forget(): void
    {
        Cache::forget('transcriptor.today_pending.global');

        foreach (StorageProvider::query()->pluck('id') as $id) {
            Cache::forget(self::CACHE_PREFIX . $id);
        }
    }

    /**
     * SQL compartido por scope y global. `$scopeIds` null = todos los storages
     * con transcripcion habilitada.
     *
     * Una sola query agregada (group by storage + state) en vez de N+1 por
     * storage: con 70 storages habilitados y ~15k archivos de hoy tarda ~0.9 s
     * en frio y ~0 ms cacheado.
     */
    private function aggregate(?array $scopeIds): \Illuminate\Support\Collection
    {
        $todayStart = $this->todayStart();

        $query = DB::table('files')
            ->join('storage_providers as sp', 'sp.id', '=', 'files.storage_provider_id')
            ->leftJoin('transcriptions as t', 't.file_id', '=', 'files.id')
            ->where('sp.transcription_enabled', true)
            ->where('files.is_folder', false)
            ->where('files.is_trashed', false)
            ->whereNull('files.deleted_at')
            ->where('files.file_modified_at', '>=', $todayStart)
            ->selectRaw('files.storage_provider_id AS sid')
            ->selectRaw("COUNT(*) FILTER (WHERE t.id IS NULL) AS missing")
            ->selectRaw("COUNT(*) FILTER (WHERE t.state = 'pending') AS pending")
            ->selectRaw("COUNT(*) FILTER (WHERE t.state = 'queued') AS queued")
            ->selectRaw("COUNT(*) FILTER (WHERE t.state = 'processing') AS processing")
            ->selectRaw("COUNT(*) FILTER (WHERE t.state = 'error') AS error")
            ->selectRaw("COUNT(*) FILTER (WHERE t.state = 'dead') AS dead")
            ->selectRaw("COUNT(*) FILTER (WHERE t.state = 'done') AS done")
            ->selectRaw("COUNT(*) FILTER (WHERE t.id IS NOT NULL AND t.state <> 'done') AS not_done")
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('files.storage_provider_id');

        if ($scopeIds !== null) {
            if (empty($scopeIds)) {
                return collect();
            }
            $query->whereIn('files.storage_provider_id', $scopeIds);
        }

        return $query->get();
    }

    /**
     * @return array<int, array<string,int>>
     */
    private function computeByStorageForScope(int $rootId): array
    {
        try {
            $scopeIds = StorageProvider::resolveInheritedTranscriptionScope($rootId);
            $rows = $this->aggregate($scopeIds);

            $out = [];
            foreach ($scopeIds as $sid) {
                $out[(int) $sid] = $this->emptyShape();
            }
            foreach ($rows as $r) {
                $out[(int) $r->sid] = $this->shapeFromRow($r);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('TodayPendingService::byStorageForScope fallo', [
                'root_id' => $rootId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string,int>
     */
    private function computeGlobal(): array
    {
        try {
            $rows = $this->aggregate(null);

            $agg = $this->emptyShape();
            foreach ($rows as $r) {
                $row = $this->shapeFromRow($r);
                foreach ($row as $k => $v) {
                    $agg[$k] += $v;
                }
            }
            $agg['actionable'] = $agg['not_done'];

            return $agg;
        } catch (\Throwable $e) {
            Log::warning('TodayPendingService::global fallo', ['error' => $e->getMessage()]);

            return $this->emptyShape();
        }
    }

    /** @return array<string,int> */
    private function shapeFromRow(object $r): array
    {
        $shape = $this->emptyShape();
        foreach (['missing', 'pending', 'queued', 'processing', 'error', 'dead', 'done', 'not_done', 'total'] as $k) {
            $shape[$k] = (int) ($r->$k ?? 0);
        }
        $shape['actionable'] = $shape['not_done'];

        return $shape;
    }

    /** @return array<string,int> */
    private function emptyShape(): array
    {
        return [
            'missing' => 0,
            'pending' => 0,
            'queued' => 0,
            'processing' => 0,
            'error' => 0,
            'dead' => 0,
            'done' => 0,
            'not_done' => 0,
            'total' => 0,
            'actionable' => 0,
        ];
    }

    /**
     * Inicio del dia en America/Bogota expresado en UTC, para comparar contra
     * `files.file_modified_at` (timestamptz). Misma convencion que
     * StorageFunnelService (la pregunta del operador es en su hora local).
     */
    private function todayStart(): CarbonImmutable
    {
        return BogotaTime::todayStart();
    }

    /**
     * IDs de archivos de hoy listos para tomar por el worker PG: sin fila de
     * transcripcion o en `pending` sin `dispatched_at`, del scope indicado.
     *
     * Lo usa el tick para reportar cuantos hay realmente disponibles (el worker
     * decide su propio lote con FOR UPDATE SKIP LOCKED).
     */
    public function actionableFileIds(int $limit = 2000): int
    {
        try {
            $todayStart = $this->todayStart();
            $pending = Transcription::STATE_PENDING;

            return (int) DB::table('files')
                ->join('storage_providers as sp', 'sp.id', '=', 'files.storage_provider_id')
                ->leftJoin('transcriptions as t', 't.file_id', '=', 'files.id')
                ->where('sp.transcription_enabled', true)
                ->where('files.is_folder', false)
                ->where('files.is_trashed', false)
                ->whereNull('files.deleted_at')
                ->where('files.file_modified_at', '>=', $todayStart)
                ->where(function ($q) use ($pending) {
                    $q->whereNull('t.id')
                      ->orWhere(function ($q2) use ($pending) {
                          $q2->where('t.state', $pending)->whereNull('t.dispatched_at');
                      });
                })
                ->limit($limit)
                ->count();
        } catch (\Throwable $e) {
            Log::warning('TodayPendingService::actionableFileIds fallo', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
