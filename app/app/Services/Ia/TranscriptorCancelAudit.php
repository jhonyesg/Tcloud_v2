<?php

namespace App\Services\Ia;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Bitacora append-only de cancelaciones upstream.
 *
 * Una fila por intento de cancelacion lanzada por
 * App\Console\Commands\TranscriptionCancelStuckOldJobsCommand.
 *
 * Append-only por diseno: NO expone metodos de UPDATE/DELETE.
 * El operador consulta el historial con recent(); nunca se borra
 * desde la app (la tabla no tiene FKs de ciclo de vida que la puedan
 * dejar inconsistente).
 */
class TranscriptorCancelAudit
{
    /**
     * Inserta una fila de auditoria para una cancelacion.
     *
     * @param int|null  $actorUserId      user_id del operador (NULL para CLI manual).
     * @param string    $jobId            id devuelto por el upstream (hex 32).
     * @param int|null  $txId             id local de transcriptions (puede ser null si no se encontro).
     * @param int       $upstreamCode     codigo HTTP / -1 si exception.
     * @param string    $localStateAfter  'dead' | 'unchanged' | 'error'.
     * @param string|null $errorMessage   texto opcional (exception, mensaje upstream).
     */
    public function record(
        ?int $actorUserId,
        string $jobId,
        ?int $txId,
        int $upstreamCode,
        string $localStateAfter,
        ?string $errorMessage = null,
    ): void {
        try {
            DB::table('transcriptor_cancel_audit')->insert([
                'actor_user_id' => $actorUserId,
                'job_id' => $jobId,
                'tx_id' => $txId,
                'upstream_response_code' => $upstreamCode,
                'local_state_after' => $localStateAfter,
                'error_message' => $errorMessage,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('transcriptor.cancel.audit_insert_failed', [
                'job_id' => $jobId,
                'tx_id' => $txId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper para debugging / herramientas internas.
     *
     * @return Collection<int,object>
     */
    public function recent(int $limit = 50): Collection
    {
        return DB::table('transcriptor_cancel_audit')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
