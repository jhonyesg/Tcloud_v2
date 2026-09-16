<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\DB;

/**
 * AuditLogArchiver: mueve filas antiguas de watermark_audit_log a
 * watermark_audit_log_archive sin perder auditoría.
 *
 * - Mantiene append-only: nunca borra, solo mueve.
 * - Procesa en chunks de 1000 filas (cada uno en su transacción) para no
 *   bloquear escrituras concurrentes sobre el log activo.
 * - Idempotente: ejecutar dos veces seguidas mueve 0 filas la segunda vez.
 */
class AuditLogArchiver
{
    private const CHUNK_SIZE = 1000;

    /** Cuenta cuántas filas tienen created_at anterior al umbral. */
    public function countOlderThan(int $days): int
    {
        return (int) DB::table('watermark_audit_log')
            ->where('created_at', '<', now()->subDays($days))
            ->count();
    }

    /**
     * Mueve filas en chunks transaccionales.
     * Retorna { archived: N, chunks: N, min_created_at: ..., max_created_at: ... }.
     */
    public function archive(int $days): array
    {
        $archived = 0;
        $chunks = 0;
        $minCreated = null;
        $maxCreated = null;

        while (true) {
            $chunk = DB::table('watermark_audit_log')
                ->where('created_at', '<', now()->subDays($days))
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            $ids = $chunk->pluck('id')->all();
            $minChunk = $chunk->min('created_at');
            $maxChunk = $chunk->max('created_at');
            if ($minCreated === null || $minChunk < $minCreated) {
                $minCreated = $minChunk;
            }
            if ($maxCreated === null || $maxChunk > $maxCreated) {
                $maxCreated = $maxChunk;
            }

            DB::transaction(function () use ($ids, $days) {
                DB::table('watermark_audit_log_archive')->insert(
                    DB::table('watermark_audit_log')
                        ->whereIn('id', $ids)
                        ->get()
                        ->map(fn ($r) => [
                            'actor_user_id' => $r->actor_user_id,
                            'action' => $r->action,
                            'keyword_id' => $r->keyword_id,
                            'storage_id' => $r->storage_id,
                            'before_value' => $r->before_value,
                            'after_value' => $r->after_value,
                            'metadata' => $r->metadata,
                            'created_at' => $r->created_at,
                            'archived_at' => now()->toDateTimeString(),
                        ])
                        ->all(),
                );
                DB::table('watermark_audit_log')->whereIn('id', $ids)->delete();
            });

            $archived += count($ids);
            $chunks++;
        }

        return [
            'archived' => $archived,
            'chunks' => $chunks,
            'min_created_at' => $minCreated,
            'max_created_at' => $maxCreated,
            'days_threshold' => $days,
        ];
    }
}
