<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot de metricas por storage cada 15 minutos.
 *
 * Alimenta la tarjeta del tab Storages de /ia/api-transcriptor con el ultimo
 * agregado por storage, incluyendo metricas PG locales (pending/inflight/sent/error)
 * y la lectura de /api/metrics/overview del upstream (remote_queue_queued) cacheada
 * para no pegar HTTP en cada render.
 *
 * Retencion: 7 dias (transcriptor:prune-storage-snapshots --days=7 corre diario).
 *
 * Aprox 96 snapshots/dia x ~70 storages habilitados = ~470k filas/semana.
 */
class TranscriptionStorageSnapshotCommand extends Command
{
    protected $signature = 'transcriptor:storage-snapshot
                            {--storage=ALL : storage_provider_id a procesar (ALL = todos)}';

    protected $description = 'Toma un snapshot de metricas por storage del transcriptor (cola PG + upstream).';

    public function handle(TranscriptorSettings $settings, TranscriptorApiClient $client): int
    {
        $storageId = $this->option('storage');

        $todayBogota = CarbonImmutable::today();
        $cutoffSentError = now()->subMinutes(15);

        // BUG CORREGIDO (2026-09-15): antes se leia
        // `Cache::get('transcriptor:remote_stats')`, que es el shape de
        // `getRemoteStats()` (processing/capacity/usage_pct) — NO tiene la clave
        // `queue_queued`. La que si la tiene es `transcriptor:remote_stats:info`
        // (shape de `getRemoteInfo()`). Resultado: `remote_queue_queued` se
        // guardaba SIEMPRE null y el tab Storages no podia mostrar la cola
        // remota real.
        //
        // Se reusa la misma cache que el regulador y el submit (mismo TTL) para
        // no agregar una llamada HTTP por corrida: si esta fria, se llena aqui.
        $remoteInfo = Cache::remember(
            'transcriptor:remote_stats:info',
            max(1, $settings->int('regulator_remote_cache_seconds')),
            fn () => $client->getRemoteInfo()
        );
        $remoteQueueQueued = is_array($remoteInfo) ? ($remoteInfo['queue_queued'] ?? null) : null;

        $storagesQuery = StorageProvider::query()->transcriptionEnabled();
        if ($storageId !== 'ALL' && ctype_digit((string) $storageId)) {
            $storagesQuery->where('id', (int) $storageId);
        }
        $storages = $storagesQuery->get(['id']);

        if ($storages->isEmpty()) {
            return Command::SUCCESS;
        }

        $inserted = 0;
        foreach ($storages as $storage) {
            $stats = DB::table('files as f')
                ->leftJoin('transcriptions as t', 't.file_id', '=', 'f.id')
                ->where('f.storage_provider_id', $storage->id)
                ->selectRaw("
                    COUNT(*) FILTER (WHERE t.state = 'pending' AND t.recorded_at >= ?) AS pending_count,
                    COUNT(*) FILTER (WHERE t.state = 'processing') AS inflight_count,
                    COUNT(*) FILTER (WHERE t.state = 'done' AND t.finished_at >= ?) AS sent_count,
                    COUNT(*) FILTER (WHERE t.state IN ('error','dead') AND t.finished_at >= ?) AS error_count,
                    EXTRACT(EPOCH FROM (now() - MIN(t.dispatched_at) FILTER (WHERE t.state = 'pending'))) AS oldest_pending_age_seconds
                ", [$todayBogota, $cutoffSentError, $cutoffSentError])
                ->first();

            $inserted += (int) DB::table('transcription_storage_snapshots')->insertOrIgnore([
                'storage_provider_id' => $storage->id,
                'captured_at' => now(),
                'pending_count' => (int) ($stats->pending_count ?? 0),
                'inflight_count' => (int) ($stats->inflight_count ?? 0),
                'sent_count' => (int) ($stats->sent_count ?? 0),
                'error_count' => (int) ($stats->error_count ?? 0),
                'oldest_pending_age_seconds' => $stats->oldest_pending_age_seconds !== null ? (int) $stats->oldest_pending_age_seconds : null,
                'remote_queue_queued' => $remoteQueueQueued !== null ? (int) $remoteQueueQueued : null,
            ]);
        }

        Log::info('transcriptor.storage_snapshot.done', [
            'storages' => $storages->count(),
            'rows_inserted' => $inserted,
        ]);

        return Command::SUCCESS;
    }
}