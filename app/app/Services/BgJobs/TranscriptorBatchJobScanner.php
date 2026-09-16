<?php

namespace App\Services\BgJobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Scanner de batches del API Transcriptor en background.
 *
 * El controlador `ApiTranscriptorController::processBatch` mantiene:
 *   - cache `transcription_batch:{runId}` con el estado del run individual
 *   - cache `transcription_batch:active_runs` con la lista de runIds activos
 *     (entradas pueden ser string runId o array {runId, finishedAt})
 *
 * Este scanner lee la lista y luego cada run individual. Si un runId de la
 * lista ya no existe en cache (expiró), lo limpia automáticamente para que
 * la lista no crezca indefinidamente.
 *
 * fix-A (resultado final visible): los jobs en estado terminal se mantienen
 * en active_runs con `finishedAt` para que el widget los muestre con el
 * resumen final (X pendientes, Y encolados) durante 5 min antes de descartarlos.
 */
class TranscriptorBatchJobScanner
{
    /**
     * Tiempo en segundos que un job terminal permanece visible en el widget
     * antes de descartarse. Suficiente para que el operador lea el resumen
     * tras disparar el batch.
     *
     * NOTA: a partir del fix bg-job-indicator-hide-completed, este scanner
     * ya NO está registrado en BgJobRegistry (la card no aparece más en el
     * widget flotante). Este TTL queda como contrato defensivo por si alguien
     * re-registra el scanner en el futuro.
     */
    private const TERMINAL_TTL_SECONDS = 300;

    public static function scan(): array
    {
        $active = Cache::get('transcription_batch:active_runs', []);
        if (!is_array($active) || empty($active)) {
            return [];
        }

        $jobs = [];
        $cleaned = [];
        $now = time();
        foreach ($active as $entry) {
            // Soportar tanto formato plano (string runId) como formato con metadata
            // (array {runId, finishedAt}). El comando artisan marca finishedAt
            // cuando termina para que el widget muestre el resumen final.
            $runId = is_array($entry) ? ($entry['runId'] ?? null) : $entry;
            $finishedAtIso = is_array($entry) ? ($entry['finishedAt'] ?? null) : null;
            if (!is_string($runId) || $runId === '') {
                continue;
            }

            $state = Cache::get("transcription_batch:{$runId}");
            if (!is_array($state)) {
                // runId huérfano: limpiar
                $cleaned[] = $entry;
                continue;
            }

            // bg-job-indicator-hide-completed: si la entrada es string plano
            // (legacy, registrada por el controller antes del fix), $finishedAtIso
            // es null. Usamos $state['updated_at'] o $state['finished_at'] como
            // fallback para que esas entradas también se drenen en 5 min y no
            // queden atrapadas hasta que expire la cache individual (2h).
            $effectiveFinishedAtIso = $finishedAtIso ?? ($state['updated_at'] ?? $state['finished_at'] ?? null);

            // Descartar entradas terminales que superaron TERMINAL_TTL_SECONDS.
            // El cache del run individual puede seguir vivo (TTL 2h) pero el
            // operador ya tuvo tiempo de leer el resultado.
            if ($effectiveFinishedAtIso !== null) {
                $finishedTs = strtotime($effectiveFinishedAtIso);
                if ($finishedTs !== false && ($now - $finishedTs) > self::TERMINAL_TTL_SECONDS) {
                    $cleaned[] = $entry;
                    continue;
                }
            }

            $processed = (int) ($state['processed'] ?? 0);
            $total = (int) ($state['total_to_process'] ?? 0);
            $errors = (int) ($state['errors'] ?? 0);
            $status = $state['status'] ?? 'unknown';
            $startedAt = $state['started_at'] ?? null;
            $finishedAt = $state['finished_at'] ?? null;
            $pendingCreated = (int) ($state['pending_created'] ?? 0);
            $dispatched = (int) ($state['dispatched'] ?? 0);

            // fix-A: distinguir visualmente si esta corriendo o ya termino.
            $isTerminal = in_array($status, ['done', 'partial', 'error', 'cancelled', 'queued'], true);
            $label = $isTerminal ? '✓ Escaneo de storages' : 'Escaneo de storages';
            if ($isTerminal && $status === 'error') {
                $label = '✗ Escaneo de storages';
            }
            if ($total > 0) {
                $label .= " ({$processed}/{$total})";
            }

            // bg-job-indicator-hide-completed: defensa contra jobs terminales
            // huérfanos de timestamp. Si llegamos acá con status terminal pero
            // sin finishedAt/updated_at/finished_at, es un bug latente — el
            // job terminó pero nadie registró cuándo. Lo descartamos igual y
            // loggeamos para que un operador lo investigue si reaparece.
            if ($isTerminal && $effectiveFinishedAtIso === null) {
                Log::warning('TranscriptorBatchJobScanner: job terminal sin timestamp de finalizacion', [
                    'run_id' => $runId,
                    'status' => $status,
                ]);
                $cleaned[] = $entry;
                continue;
            }

            $jobs[] = [
                'kind' => 'transcriptor-batch',
                'runId' => $runId,
                'module' => 'API Transcriptor',
                'label' => $label,
                'startedAt' => $startedAt,
                'finishedAt' => $finishedAt,
                'progress' => [
                    'processed' => $processed,
                    'total' => $total,
                    'errors' => $errors,
                    'status' => $status,
                    'pending_created' => $pendingCreated,
                    'dispatched' => $dispatched,
                    'is_terminal' => $isTerminal,
                ],
                'url' => '/ia/api-transcriptor?focus=bg-transcriptor-batch-' . $runId,
            ];
        }

        // Persistir limpieza si encontramos huérfanos o terminales vencidos
        if (!empty($cleaned)) {
            $remaining = array_values(array_filter($active, function ($e) use ($cleaned) {
                return !in_array($e, $cleaned, true);
            }));
            if (empty($remaining)) {
                Cache::forget('transcription_batch:active_runs');
            } else {
                Cache::put('transcription_batch:active_runs', $remaining, now()->addHours(2));
            }
        }

        return $jobs;
    }
}
