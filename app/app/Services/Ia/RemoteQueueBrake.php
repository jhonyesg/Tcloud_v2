<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Cache;

/**
 * Freno con histéresis sobre la cola del nodo remoto.
 *
 * El problema que resuelve: comparar `queue_queued >= target_remote_queue` en
 * cada decision produce ping-pong alrededor del techo. Al llegar a 180 el envio
 * frena; el nodo drena UN job y la cola queda en 179, se reanuda; se envia y
 * vuelve a 180. El resultado es un goteo de 1-2 jobs por ciclo en vez de
 * rafagas utiles, y el nodo nunca baja de verdad.
 *
 * Solucion: pestillo (latch) con dos umbrales:
 *   - FRENO   inmediato al alcanzar `target_remote_queue` (default 180).
 *   - REANUDO solo cuando la cola baja a `resume_remote_queue` (default 120).
 * Con el freno activo no se levanta hasta que la cola haya drenado de verdad
 * hasta el umbral de reanudo; entre 120 y 180 el envio permanece detenido, que
 * es el colchon de respiro del nodo.
 *
 * Revalidacion: mientras el freno esta activo, la cola NO se consulta en cada
 * POST; se revalida cada `remote_queue_recheck_seconds` (default 30 s). El
 * freno se aplica siempre de inmediato (no espera la ventana); la ventana solo
 * gobierna cuando se levanta.
 *
 * Compartido por el planner (`TranscriptionTickCommand`) y el sender
 * (`TranscriptionSubmitService`) para que ambos tomen la MISMA decision: si el
 * planner dijo "frenado", el sender tampoco debe hacer POST aunque su lectura
 * puntual de la cola haya bajado un par de jobs.
 *
 * Fail-open: sin telemetria (`remoteInfo === null`) el freno NO aplica. Es
 * preferible enviar de mas y que el nodo devuelva 503 (con circuit breaker)
 * que dejar el pipeline parado por no poder leer metricas.
 */
class RemoteQueueBrake
{
    /** Pestillo con el estado del freno, compartido por tick y sender. */
    public const CACHE_KEY = 'transcriptor:remote_queue_brake';

    /**
     * Evalua el freno y actualiza el pestillo si corresponde.
     *
     * @return array{
     *     braked: bool,
     *     queue: ?int,
     *     target: int,
     *     resume: int,
     *     reason: string,
     *     recheck_in: int
     * }
     */
    public function evaluate(?array $remoteInfo, TranscriptorSettings $settings): array
    {
        $target = max(1, $settings->int('target_remote_queue'));
        // Garantizar histéresis real: el reanudo nunca puede estar en/sobre el
        // techo, o el freno se levantaria con la misma cola que lo activo.
        $resume = max(0, min($settings->int('resume_remote_queue'), $target - 1));
        $recheck = max(5, $settings->int('remote_queue_recheck_seconds'));

        $queue = $this->queueFrom($remoteInfo);

        // Sin telemetria: fail-open y no tocar el pestillo.
        if ($queue === null) {
            return [
                'braked' => false,
                'queue' => null,
                'target' => $target,
                'resume' => $resume,
                'reason' => 'no_telemetry',
                'recheck_in' => $recheck,
            ];
        }

        $state = Cache::get(self::CACHE_KEY);
        $braked = is_array($state) ? (bool) ($state['braked'] ?? false) : false;
        $checkedAt = is_array($state) ? (int) ($state['checked_at'] ?? 0) : 0;
        $now = time();

        // 1. Freno inmediato: alcanzar el techo frena sin esperar la ventana.
        if ($queue >= $target) {
            $this->latch(true, $queue, $now, $recheck);

            return [
                'braked' => true,
                'queue' => $queue,
                'target' => $target,
                'resume' => $resume,
                'reason' => 'remote_queue_full',
                'recheck_in' => $recheck,
            ];
        }

        // 2. Sin freno activo y bajo el techo: envio libre.
        if (!$braked) {
            return [
                'braked' => false,
                'queue' => $queue,
                'target' => $target,
                'resume' => $resume,
                'reason' => 'remote_queue_ok',
                'recheck_in' => 0,
            ];
        }

        // 3. Freno activo: aun dentro de la ventana de revalidacion, mantener.
        $elapsed = $now - $checkedAt;
        if ($elapsed < $recheck) {
            return [
                'braked' => true,
                'queue' => $queue,
                'target' => $target,
                'resume' => $resume,
                'reason' => 'remote_queue_braked',
                'recheck_in' => max(0, $recheck - $elapsed),
            ];
        }

        // 4. Ventana vencida: levantar SOLO si la cola ya bajo al umbral de reanudo.
        if ($queue <= $resume) {
            $this->latch(false, $queue, $now, $recheck);

            return [
                'braked' => false,
                'queue' => $queue,
                'target' => $target,
                'resume' => $resume,
                'reason' => 'remote_queue_resumed',
                'recheck_in' => 0,
            ];
        }

        // 5. Sigue entre resume y target: mantener el freno y revalidar luego.
        $this->latch(true, $queue, $now, $recheck);

        return [
            'braked' => true,
            'queue' => $queue,
            'target' => $target,
            'resume' => $resume,
            'reason' => 'remote_queue_braked',
            'recheck_in' => $recheck,
        ];
    }

    /**
     * Solo el estado del freno, sin consultar ni mutar el pestillo. Lo usa el
     * sender para respetar la decision del planner sin re-evaluar la ventana.
     */
    public function isBraked(): bool
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) && (bool) ($state['braked'] ?? false);
    }

    /**
     * Limpia el pestillo. Lo usan los mutadores del modulo (toggle de storage,
     * cambio de settings) para que un freno viejo no sobreviva a un cambio de
     * configuracion.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function queueFrom(?array $remoteInfo): ?int
    {
        if (!is_array($remoteInfo) || !array_key_exists('queue_queued', $remoteInfo)) {
            return null;
        }
        if ($remoteInfo['queue_queued'] === null) {
            return null;
        }

        return (int) $remoteInfo['queue_queued'];
    }

    /**
     * Persiste el estado del pestillo. El TTL es generoso respecto a la ventana
     * de revalidacion para que el freno no se "olvide" entre evaluaciones.
     */
    private function latch(bool $braked, int $queue, int $now, int $recheck): void
    {
        Cache::put(self::CACHE_KEY, [
            'braked' => $braked,
            'queue' => $queue,
            'checked_at' => $now,
        ], max(120, $recheck * 4));
    }
}
