<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entrega diferida de avisos (mis-avisos-menciones Fase 1).
 *
 * El scan nunca envía correo: deja filas en alert_deliveries con due_at
 * según la cadencia elegida por el cliente. Este servicio (invocado cada
 * minuto por el comando avisos:deliver-alerts) agrupa los vencidos por
 * usuario y encola un digest por usuario, respetando:
 *
 *  - Solo matches del DÍA ACTUAL (los históricos no disparan correo; los
 *    pendientes de reposición salen con el resumen del día siguiente).
 *  - Techo duro diario: emails de aviso de hoy (AlertLog) < emails_quota.
 *    Lo que no cabe se re-encola para la primera ventana de mañana.
 *  - Rate limiter global del relay en el job (SendAlertDigest).
 */
class AlertDeliveryService
{
    /** Ventanas de cadencia válidas (minutos). */
    public const FREQUENCIES = [1, 5, 15, 20, 30, 50, 60, 240, 480, 1440];

    public function __construct(private AlertDispatcher $dispatcher) {}

    /**
     * Procesa las entregas vencidas. Devuelve el número de digests encolados.
     */
    public function run(): int
    {
        $users = DB::table('alert_deliveries as ad')
            ->join('users as u', 'u.id', '=', 'ad.user_id')
            ->join('user_alerts_inteligentes as uai', 'uai.user_id', '=', 'u.id')
            ->whereNull('ad.delivered_at')
            ->where('ad.due_at', '<=', now())
            ->where('uai.enabled', true)
            ->groupBy('ad.user_id', 'u.id', 'uai.emails_quota')
            ->select('ad.user_id', 'uai.emails_quota')
            ->orderBy('ad.user_id')
            ->limit(200) // lote por minuto: predecible y acotado
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $queued = 0;
        foreach ($users as $row) {
            $queued += $this->deliverForUser((int) $row->user_id, (int) $row->emails_quota);
        }

        return $queued;
    }

    /**
     * Entrega (o pospone) los pendientes de un usuario. Devuelve 1 si encoló
     * digest, 0 si todo quedó pospuesto.
     */
    private function deliverForUser(int $userId, int $emailsQuota): int
    {
        $pendientes = DB::table('alert_deliveries as ad')
            ->join('segment_keyword_hits as h', 'h.id', '=', 'ad.hit_id')
            ->where('ad.user_id', $userId)
            ->whereNull('ad.delivered_at')
            ->where('ad.due_at', '<=', now())
            // Solo el día actual. La reposición (reposition_for = mañana) se
            // permite cuando la fila ya fue marcada por el techo: su due_at
            // quedó movido a la ventana de mañana.
            ->where(function ($q) {
                $q->whereDate('h.matched_at', today())
                    ->orWhereNotNull('ad.reposition_for');
            })
            ->select(
                'ad.id',
                'ad.hit_id',
                'ad.reposition_for',
                'h.transcription_id',
                'h.keyword_id',
                'h.snippet',
                'h.matched_at'
            )
            ->orderBy('h.matched_at')
            ->get();

        if ($pendientes->isEmpty()) {
            return 0;
        }

        // Techo diario: correos de aviso ya enviados HOY a este usuario.
        $sentToday = DB::table('alert_logs')
            ->where('user_id', $userId)
            ->whereDate('sent_at', today())
            ->where('status', 'sent')
            ->count();

        if ($sentToday >= $emailsQuota) {
            // Reposición: nada se pierde; sale con el resumen de mañana.
            $tomorrow = today()->addDay()->setHour(8)->setMinute(0);
            DB::table('alert_deliveries')
                ->where('user_id', $userId)
                ->whereNull('delivered_at')
                ->update([
                    'due_at' => $tomorrow,
                    'reposition_for' => $tomorrow,
                ]);

            Log::info('mentions.delivery_quota_deferred', [
                'user_id' => $userId,
                'sent_today' => $sentToday,
                'quota' => $emailsQuota,
            ]);

            return 0;
        }

        $batchId = (string) \Illuminate\Support\Str::uuid();
        $ids = $pendientes->pluck('id')->all();

        // Marcar ANTES de encolar (at-least-once con marca explícita; si el
        // job falla, quedan entregadas en batch sin AlertLog y el admin ve
        // la falla en alert_logs.status=failed).
        DB::table('alert_deliveries')
            ->whereIn('id', $ids)
            ->update(['delivered_at' => now(), 'batch_id' => $batchId]);

        $user = \App\Models\User::find($userId);
        if (!$user || empty($user->alertsInteligente?->emailsList())) {
            return 0; // sin correos configurados: nada que enviar
        }

        \App\Jobs\SendAlertDigest::dispatch($userId, $batchId, $ids);

        return 1;
    }
}