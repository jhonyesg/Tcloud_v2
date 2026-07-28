<?php

namespace App\Jobs\Middleware;

use App\Services\Ia\TranscriptorSettings;
use Illuminate\Support\Facades\Redis;

/**
 * Semaforo de concurrencia real para el pipeline de transcripcion.
 *
 * El objetivo de cola (target_redis_queue) regula el RITMO DE ENCOLADO, no la
 * concurrencia de ejecucion: con 11 workers, 11 ffmpeg corren a la vez sin
 * importar lo despacio que se llene la cola. Contra una API con 2 workers GPU y
 * un solo disco, eso es contencion local — la firma del 2026-07-24 11:35 (15
 * "ffmpeg falló" en 4 segundos con stderr truncado a offsets distintos, y cero
 * HTTP 429) es exactamente eso.
 *
 * Este middleware desacopla el numero de workers de la concurrencia real: se
 * pueden dejar los 11 workers y poner inflight_max=4; los sobrantes esperan aqui
 * sin tocar systemd, y el valor es ajustable en caliente desde la UI.
 *
 * Usa Redis::funnel (Illuminate\Redis\Limiters\ConcurrencyLimiter), nativo del
 * framework — sin paquetes nuevos.
 */
class LimitTranscriptionConcurrency
{
    /**
     * releaseAfter DEBE superar ConvertAndTranscribeJob::$timeout (600s) para que
     * un worker muerto libere su cupo en vez de retenerlo para siempre.
     */
    private const RELEASE_AFTER_SECONDS = 650;

    private const LOCK_NAME = 'transcriptor:inflight';

    public function handle(object $job, \Closure $next): void
    {
        $limit = app(TranscriptorSettings::class)->int('inflight_max');

        // 0 = desactivado (default). Passthrough sin coste.
        if ($limit <= 0) {
            $next($job);

            return;
        }

        Redis::funnel(self::LOCK_NAME)
            ->limit($limit)
            ->releaseAfter(self::RELEASE_AFTER_SECONDS)
            ->then(
                fn () => $next($job),
                // Sin cupo: devolver a la cola con espera aleatoria para no
                // sincronizar a todos los workers bloqueados en el mismo reintento.
                fn () => $job->release(random_int(5, 20))
            );
    }
}
