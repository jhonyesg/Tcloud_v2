<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\BogotaTime;
use App\Services\Ia\TranscriptionSubmitService;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FASE 1 del pipeline (transcriptor-two-phase-staging): convierte el audio de
 * origen a WAV/opus en /dev/shm y lo deja listo para el envio.
 *
 * Por que existe: convertir es caro en CPU y enviar es barato pero limitado por
 * el headroom de la cola remota. Cuando ambos pasos van juntos (submit
 * sincrono), el host local sufre picos de CPU cada vez que la cola remota se
 * abre, y se queda sin trabajo util cuando se cierra. Separandolos:
 *
 *   - este comando convierte en LOTES PARALELOS (N ffmpeg a la vez via pcntl),
 *     manteniendo un inventario objetivo en tmpfs;
 *   - el worker PG solo hace el POST de lo ya convertido, que es rapido.
 *
 * Modo de operacion (decidido 2026-09-16 tras auditar el flujo con el
 * operador): el goteo anterior (1 ffmpeg + sleep(fixed)) desperdiciaba el host,
 * porque entre conversiones el CPU caia a ~3% mientras la cola crecia. Ahora
 * el stager lanza `staging_parallel` procesos hijo por lote, espera a que el
 * lote completo termine, y arranca el siguiente lote de inmediato — sin pausa
 * alguna — hasta llenar el inventario objetivo o agotar el presupuesto de
 * RAM disk. El ritmo real queda gobernado por el PRESUPUESTO DE BYTES y no
 * por una pausa artificial.
 *
 * Guardas de seguridad:
 *   - `staging_budget_bytes`: freno duro por bytes. No se empieza un lote si
 *     el total staged mas lo ya convertido en esta corrida supera el
 *     presupuesto. El envio nunca falla por ENOSPC ni se llena la RAM.
 *   - `staging_target_inventory`: freno por conteo de archivos listos. El
 *     tope real es el min(inventario, presupuesto).
 *   - `staging_parallel`: cuantos ffmpeg corren a la vez (default 4, rango
 *     1..8). Regla de bolso: ~1 proceso por core dedicado; 4 es sano en un
 *     host compartido con nginx/PHP-FPM.
 *   - `staging_pace_seconds`: pausa opcional AL FINAL de cada lote (default
 *     0). Solo sirve para cederle CPU a otros procesos en hosts saturados.
 *   - min_shm_free_bytes: piso duro de espacio libre, igual que en submit.
 *
 * Uso: `php artisan transcription:stage [--max=0] [--dry-run] [--storage=ALL]`
 * El cron lo invoca cada minuto; sin argumentos convierte hasta el inventario.
 *
 * Requiere pcntl (siempre habilitado en CLI). Si pcntl no esta disponible cae
 * al modo secuencial de 1 conversion a la vez sin pausa, pero loguea un
 * warning porque es la señal de un entorno mal configurado.
 */
class TranscriptionStageCommand extends Command
{
    protected $signature = 'transcription:stage
                            {--max=0 : Máximo de conversiones en esta corrida (0 = hasta llenar el inventario)}
                            {--storage=ALL : storage_provider_id a procesar (ALL = todos)}
                            {--dry-run : Solo reporta candidatos y presupuesto, no convierte}
                            {--purge-expired : Solo purga staged vencidos por TTL y sale}';

    protected $description = 'Fase 1 del pipeline: convierte pendientes de hoy a /dev/shm en lotes paralelos hasta llenar el inventario, sin enviar.';

    /** Timeout duro por hijo ffmpeg. AudioConverter ya aplica 600s al proceso;
     *  900 es el colchon para que un hijo nunca cuelgue el lote. */
    private const CHILD_TIMEOUT_SECONDS = 900;

    /**
     * Tope de lotes por corrida. La corrida la dispara el cron cada minuto, asi
     * que no necesita agotar la cola de una sentada. Sin este techo, un estado
     * de cola que no converge (candidatos que el claim rechaza) deja el stager
     * girando indefinidamente y el `withoutOverlapping(5)` del scheduler no lo
     * contiene: a los 5 min el lock vence y arranca OTRO stager en paralelo.
     */
    private const MAX_BATCHES_PER_RUN = 20;

    public function handle(TranscriptorSettings $settings, TranscriptionSubmitService $submitter): int
    {
        if (!$settings->bool('staging_enabled')) {
            $this->line('Staging deshabilitado (transcriptor.staging_enabled=false). Nada que hacer.');

            return Command::SUCCESS;
        }

        if ($settings->bool('dispatch_paused') || (string) env('TRANSCRIPTOR_DISPATCH_PAUSED') === 'true') {
            $this->line('Envío pausado: no se convierte trabajo nuevo.');

            return Command::SUCCESS;
        }

        $ttlMinutes = $settings->int('staging_ttl_minutes');
        $purged = $this->purgeExpired($submitter, $ttlMinutes, (bool) $this->option('dry-run'));

        if ((bool) $this->option('purge-expired')) {
            $this->info("Staged vencidos purgados: {$purged}");

            return Command::SUCCESS;
        }

        $parallel = max(1, min(8, $settings->int('staging_parallel')));
        $pcntl = function_exists('pcntl_fork');
        if (!$pcntl && $parallel > 1) {
            Log::warning('transcription:stage sin pcntl: cayendo a modo secuencial', [
                'parallel_solicitado' => $parallel,
            ]);
            $this->warn('pcntl no disponible: se convierte en secuencial.');
            $parallel = 1;
        }

        $inventory = $submitter->stagedInventory();
        $targetInventory = max(1, $settings->int('staging_target_inventory'));
        $budgetBytes = max(1, $settings->int('staging_budget_bytes'));
        $usedBytes = $inventory['bytes'];
        $pace = max(0, $settings->int('staging_pace_seconds'));
        $maxPerRun = (int) $this->option('max');

        $this->line(sprintf(
            'Inventario staged: %d archivos, %s MB de %s MB de presupuesto (objetivo %d archivos, %d paralelos).',
            $inventory['files'],
            number_format($usedBytes / 1048576, 1),
            number_format($budgetBytes / 1048576, 0),
            $targetInventory,
            $parallel,
        ));

        if ((bool) $this->option('dry-run')) {
            $slots = max(0, $targetInventory - $inventory['files']);
            if ($maxPerRun > 0) {
                $slots = min($slots, $maxPerRun);
            }
            $candidates = $this->candidates($settings, $slots);
            $this->info(sprintf(
                'DRY-RUN: %d candidatos convertibles (slots=%d, presupuesto libre=%s MB, paralelos=%d).',
                count($candidates),
                $slots,
                number_format(($budgetBytes - $usedBytes) / 1048576, 1),
                $parallel,
            ));

            return Command::SUCCESS;
        }

        $staged = 0;
        $requeued = 0;
        $failed = 0;
        $bytesStaged = 0;
        $lotes = 0;

        // Loop por lotes: cada iteracion convierte hasta `parallel` archivos
        // en simultaneo. No hay pausa entre lotes a menos que se configure.
        while (true) {
            $currentInventory = $submitter->stagedInventory();
            $slotsByCount = $targetInventory - $currentInventory['files'];
            if ($slotsByCount <= 0) {
                $this->line('Inventario en objetivo: no se convierte nada nuevo.');
                break;
            }

            $freeBudget = $budgetBytes - $currentInventory['bytes'];
            if ($freeBudget <= 0) {
                $this->line('Presupuesto de /dev/shm agotado: se corta la corrida.');
                break;
            }

            $slots = min($slotsByCount, $parallel);
            if ($maxPerRun > 0) {
                $remaining = $maxPerRun - ($staged + $requeued + $failed);
                if ($remaining <= 0) {
                    break;
                }
                $slots = min($slots, $remaining);
            }

            // Tope duro de lotes por corrida. El cron pasa cada minuto, asi que
            // un stager que no converja (candidatos que el claim rechaza de
            // forma sistematica) NO debe quedarse girando: es exactamente lo
            // que produjo 25+ procesos concurrentes y CPU al 80%. Con este
            // techo la corrida acota su costo aunque el estado de la cola sea
            // patologico.
            if ($lotes >= self::MAX_BATCHES_PER_RUN) {
                $this->warn(sprintf(
                    'Tope de %d lote(s) por corrida alcanzado: se corta para ceder el turno.',
                    self::MAX_BATCHES_PER_RUN,
                ));
                break;
            }

            $candidates = $this->candidates($settings, $slots);
            if ($candidates->isEmpty()) {
                $this->line('Sin candidatos para convertir.');
                break;
            }

            $lotes++;
            $result = $parallel > 1
                ? $this->runParallelBatch($candidates, $submitter)
                : $this->runSequentialBatch($candidates, $submitter);

            $staged += $result['staged'];
            $requeued += $result['requeued'];
            $failed += $result['failed'];
            $lostClaim = (int) ($result['lost_claim'] ?? 0);
            $bytesStaged += $result['bytes'];

            $this->line(sprintf(
                'Lote %d: %d listos, %d aplazados, %d fallidos%s (+%s MB).',
                $lotes,
                $result['staged'],
                $result['requeued'],
                $result['failed'],
                $lostClaim > 0 ? ", {$lostClaim} sin claim" : '',
                number_format($result['bytes'] / 1048576, 1),
            ));

            // Si el lote entero perdio el claim, no hay progreso posible: los
            // mismos candidatos volverian a elegirse. Cortar evita el bucle.
            if ($lostClaim > 0 && $result['staged'] === 0 && $result['requeued'] === 0) {
                $this->warn('Lote sin progreso (claims perdidos): se corta la corrida.');
                break;
            }

            if ($pace > 0) {
                sleep($pace);
            }
        }

        $this->info(sprintf(
            'Staging: %d convertidos (%s MB), %d aplazados, %d fallidos en %d lote(s).',
            $staged,
            number_format($bytesStaged / 1048576, 1),
            $requeued,
            $failed,
            $lotes,
        ));

        Log::info('transcriptor.stage.run', [
            'staged' => $staged,
            'requeued' => $requeued,
            'failed' => $failed,
            'bytes_staged' => $bytesStaged,
            'lotes' => $lotes,
            'parallel' => $parallel,
            'inventory_before' => $inventory['files'],
            'purged_expired' => $purged,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Modo secuencial (fallback sin pcntl o parallel=1): convierte los
     * candidatos uno por uno, sin pausa. Es el comportamiento del stager
     * original antes del cambio a lotes, y sirve para diagnosticar cuando
     * pcntl_fork se comporta raro en el entorno.
     *
     * @return array{staged:int, requeued:int, failed:int, bytes:int}
     */
    private function runSequentialBatch(\Illuminate\Support\Collection $candidates, TranscriptionSubmitService $submitter): array
    {
        $out = ['staged' => 0, 'requeued' => 0, 'failed' => 0, 'bytes' => 0, 'lost_claim' => 0];

        foreach ($candidates as $tx) {
            $result = $submitter->stage($tx);
            $this->absorbStageResult($result, $out);
        }

        return $out;
    }

    /**
     * Modo paralelo: un fork por candidato del lote. Cada hijo ejecuta
     * `$submitter->stage($tx)` en un proceso aislado y reporta el resultado al
     * padre por un stream par (socketpair). El padre espera con WNOHANG hasta
     * que todos los hijos terminen o timeout, y los mata si se cuelgan.
     *
     * Por que por proceso y no por thread: PHP no tiene threads de usuario
     * seguros, y cada llamada a ffmpeg es un subproceso externo de todos
     * modos — el costo de fork es trivial comparado con el costo de ffmpeg.
     *
     * Seguridad operativa:
     *  - El hijo cierra toda la infraestructura de Laravel que heredo (DB,
     *    cache) antes de convertir: asi el hijo y el padre jamas comparten
     *    sockets de BD (un fork sobre una conexion Postgres compartida es la
     *    receta para "server closed the connection unexpectedly").
     *  - El hijo NUNCA escribe en Laravel; toda la mutacion (BD, tmpfs) la
     *    hace `$submitter->stage()` que es un metodo puro con sus propios
     *    accesos. Terminado el trabajo, el hijo serializa un shape minimo
     *    (staged|requeue|fail + bytes) y muere con exit.
     *  - Si el hijo muere por senal o timeout, se cuenta como failed y su
     *    fila queda igual que antes: el siguiente ciclo del cron la vuelve a
     *    candidatar porque no tiene staged_path ni aplazamiento.
     *
     * @return array{staged:int, requeued:int, failed:int, bytes:int}
     */
    private function runParallelBatch(\Illuminate\Support\Collection $candidates, TranscriptionSubmitService $submitter): array
    {
        $out = ['staged' => 0, 'requeued' => 0, 'failed' => 0, 'bytes' => 0, 'lost_claim' => 0];

        // Lista plana indexada: evitar mutar la coleccion mientras se itera
        // (el fallback necesita cortar y delegar el resto a secuencial).
        $list = $candidates->values()->all();

        $children = [];   // pid => ['tx_id' => int, 'socket' => resource, 'started' => float]

        foreach ($list as $index => $tx) {
            // PHP 8: devuelve [sockA, sockB] o false (no por referencia).
            $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                // Sin socketpair no hay forma segura del canal padre/hijo;
                // degradar a secuencial el resto del lote.
                $tail = $this->runSequentialBatch(collect(array_slice($list, $index)), $submitter);
                $this->mergeBatchOut($out, $tail);
                break;
            }

            [$parentSock, $childSock] = $pair;
            $pid = pcntl_fork();

            if ($pid === -1) {
                // fork fallo: cerrar y degradar al resto del lote.
                fclose($parentSock);
                fclose($childSock);
                $tail = $this->runSequentialBatch(collect(array_slice($list, $index)), $submitter);
                $this->mergeBatchOut($out, $tail);
                break;
            }

            if ($pid === 0) {
                // === HIJO ===
                fclose($parentSock);

                // Aislar conexiones heredadas: BD y cache viven en sockets
                // ahora compartidos con el padre. Si no los cerramos, la
                // primera query del hijo puede romper la conexion del padre.
                try {
                    DB::disconnect();
                } catch (\Throwable $ignored) {
                }

                $result = $submitter->stage($tx);

                $shape = [
                    'ok' => (bool) ($result['ok'] ?? false),
                    'requeueable' => (bool) ($result['requeueable'] ?? false),
                    'lost_claim' => (bool) ($result['lost_claim'] ?? false),
                    'staged_bytes' => (int) ($result['staged_bytes'] ?? 0),
                    'error' => substr((string) ($result['error'] ?? ''), 0, 400),
                ];

                if ($shape['lost_claim']) {
                    // El claim no aplico: la fila ya no era candidata. NO cuenta
                    // como staged (no hay inventario nuevo) ni como failed (no
                    // hubo error). El padre lo usa para detectar lotes que no
                    // progresan y cortar en vez de girar.
                    $shape['bucket'] = 'lost_claim';
                } elseif ($shape['ok']) {
                    $shape['bucket'] = 'staged';
                } elseif ($shape['requeueable']) {
                    $shape['bucket'] = 'requeued';
                } else {
                    $shape['bucket'] = 'failed';
                }

                @fwrite($childSock, json_encode($shape));
                fclose($childSock);

                // posix_kill(getmypid(), SIGKILL) garantiza que el hijo NO
                // ejecuta destructores de Laravel (en particular los que
                // cerrarian sockets compartidos con el padre).
                if (function_exists('posix_kill') && defined('SIGKILL')) {
                    @posix_kill(getmypid(), SIGKILL);
                }
                // Fallback: exit normal si no hay posix.
                exit(0);
            }

            // === PADRE ===
            fclose($childSock);
            stream_set_blocking($parentSock, false);
            $children[$pid] = [
                'tx_id' => (int) $tx->id,
                'socket' => $parentSock,
                'started' => microtime(true),
                'buffer' => '',
            ];
        }

        // Esperar a que todos los hijos terminen o venzan.
        while (!empty($children)) {
            $now = microtime(true);

            foreach ($children as $pid => $child) {
                // Leer lo que el hijo haya escrito hasta ahora (non-blocking).
                $chunk = @stream_get_contents($child['socket']);
                if (is_string($chunk) && $chunk !== '') {
                    $children[$pid]['buffer'] .= $chunk;
                }

                $status = null;
                $res = pcntl_waitpid($pid, $status, WNOHANG);

                if ($res === $pid) {
                    // Hijo termino.
                    $payload = json_decode($children[$pid]['buffer'], true);
                    fclose($child['socket']);
                    unset($children[$pid]);

                    if (is_array($payload) && isset($payload['bucket'])) {
                        $this->absorbParallelPayload($payload, $out);
                    } else {
                        // El hijo murio sin reportar (crash, sigkill, etc.).
                        $out['failed']++;
                        Log::warning('transcription:stage hijo sin payload', [
                            'pid' => $pid,
                            'tx_id' => $child['tx_id'],
                            'exit_status' => $status,
                        ]);
                    }
                    continue;
                }

                if ($now - $child['started'] > self::CHILD_TIMEOUT_SECONDS) {
                    // Hijo colgado: matar y contar como failed.
                    if (function_exists('posix_kill') && defined('SIGKILL')) {
                        @posix_kill($pid, SIGKILL);
                    }
                    pcntl_waitpid($pid, $status);
                    fclose($child['socket']);
                    unset($children[$pid]);
                    $out['failed']++;
                    Log::error('transcription:stage hijo excedio timeout y fue matado', [
                        'pid' => $pid,
                        'tx_id' => $child['tx_id'],
                        'timeout_s' => self::CHILD_TIMEOUT_SECONDS,
                    ]);
                }
            }

            if (!empty($children)) {
                usleep(50_000); // 50 ms entre sondeos: bajo overhead.
            }
        }

        return $out;
    }

    /**
     * Acumula en $out el resultado reportado por un hijo paralelo.
     */
    private function absorbParallelPayload(array $payload, array &$out): void
    {
        match ($payload['bucket'] ?? 'failed') {
            'staged' => $out['staged']++,
            'requeued' => $out['requeued']++,
            'lost_claim' => $out['lost_claim']++,
            default => $out['failed']++,
        };
        $out['bytes'] += (int) ($payload['staged_bytes'] ?? 0);
    }

    /**
     * Acumula un resultado del metodo `$submitter->stage()` directo (modo
     * secuencial). Reusa el shape del resultado del servicio.
     */
    private function absorbStageResult(array $result, array &$out): void
    {
        if ($result['lost_claim'] ?? false) {
            $out['lost_claim']++;
        } elseif ($result['ok'] ?? false) {
            $out['staged']++;
            $out['bytes'] += (int) ($result['staged_bytes'] ?? 0);
        } elseif ($result['requeueable'] ?? false) {
            $out['requeued']++;
        } else {
            $out['failed']++;
        }
    }

    /**
     * Suma un lote parcial (fallback secuencial) al acumulador del lote actual.
     *
     * @param  array{staged:int, requeued:int, failed:int, bytes:int, lost_claim:int}  $out
     * @param  array{staged:int, requeued:int, failed:int, bytes:int, lost_claim:int}  $tail
     */
    private function mergeBatchOut(array &$out, array $tail): void
    {
        $out['staged'] += $tail['staged'];
        $out['requeued'] += $tail['requeued'];
        $out['failed'] += $tail['failed'];
        $out['bytes'] += $tail['bytes'];
        $out['lost_claim'] += $tail['lost_claim'];
    }

    /**
     * Candidatos a convertir: pendientes de HOY sin staged, que no esten
     * aplazados (requeue_after_at vencido o nulo).
     *
     * Se ordena por recorded_at DESC (lo mas reciente primero) para que el
     * inventario sirva el material que el operador quiere ver antes.
     *
     * @return \Illuminate\Support\Collection<int,Transcription>
     */
    private function candidates(TranscriptorSettings $settings, int $limit)
    {
        $today = BogotaTime::todayStart();

        $query = Transcription::query()
            ->where('state', Transcription::STATE_PENDING)
            ->whereNull('staged_path')
            // CRITICO (fix 2026-09-16): misma condicion que exige el claim
            // condicional de stage(). Sin este filtro, una fila `pending` con
            // `dispatched_at` seteado (reencolada por fuera del servicio, p.ej.
            // SQL manual o un reset que no limpio el sello) es candidata pero
            // el claim la rechaza: ffmpeg se paga, el stage devuelve
            // `lost_claim` y el inventario NUNCA crece. El loop de lotes
            // volvia a elegir las mismas filas para siempre: 25+ stagers
            // concurrentes y CPU al 70-80% sin una sola conversion util.
            ->whereNull('dispatched_at')
            ->where('recorded_at', '>=', $today)
            ->where(function ($q) {
                $q->whereNull('requeue_after_at')
                  ->orWhere('requeue_after_at', '<=', now());
            })
            ->orderBy('recorded_at', 'desc')
            ->orderBy('discovered_at', 'desc')
            ->limit(max(1, $limit));

        $storageFilter = (string) $this->option('storage');
        if ($storageFilter !== 'ALL' && ctype_digit($storageFilter)) {
            $query->whereHas('file', function ($q) use ($storageFilter) {
                $q->where('storage_provider_id', (int) $storageFilter);
            });
        }

        return $query->get();
    }

    /**
     * Purga staged que ya no sirven:
     *
     *  1. Filas que SALIERON de `pending` (queued/done/error/dead): su audio ya
     *     viajo, el staged es basura. Este barrido es la red de seguridad del
     *     `discardStaged()` de `send()`: si un envio se completo con una version
     *     anterior del codigo (o el proceso murio justo despues del POST antes
     *     de borrar), el WAV quedaba en tmpfs para siempre. Se midieron 41
     *     archivos / 1.6 GB filtrados asi tras un reinicio de workers.
     *  2. Filas `pending` cuyo `staged_at` supero el TTL: un paro largo de la API
     *     deja WAV obsolescentes ocupando presupuesto; devolverlos a la cola de
     *     conversion mantiene el inventario sano.
     */
    private function purgeExpired(TranscriptionSubmitService $submitter, int $ttlMinutes, bool $dryRun): int
    {
        $purged = 0;

        // (1) Staged de filas que ya no estan en pending: limpieza incondicional.
        try {
            $orphans = Transcription::query()
                ->whereNotNull('staged_path')
                ->where('state', '!=', Transcription::STATE_PENDING)
                ->limit(500)
                ->get();
        } catch (\Throwable $e) {
            $orphans = collect();
            Log::warning('transcription:stage purge non-pending fallo: ' . $e->getMessage());
        }

        if (!$dryRun) {
            foreach ($orphans as $tx) {
                $this->deleteStagedFile($tx);
                $purged++;
            }
        } else {
            $purged += $orphans->count();
        }

        // (2) Staged pending con TTL vencido.
        if ($ttlMinutes <= 0) {
            return $purged;
        }

        $cutoff = now()->subMinutes($ttlMinutes);

        try {
            $expired = Transcription::query()
                ->where('state', Transcription::STATE_PENDING)
                ->whereNotNull('staged_path')
                ->whereNotNull('staged_at')
                ->where('staged_at', '<', $cutoff)
                ->limit(500)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('transcription:stage purgeExpired fallo: ' . $e->getMessage());

            return $purged;
        }

        if ($dryRun) {
            return $purged + $expired->count();
        }

        foreach ($expired as $tx) {
            $this->deleteStagedFile($tx);
            $purged++;
        }

        if ($purged > 0) {
            Log::info('transcriptor.stage.purged', [
                'count' => $purged,
                'orphan_non_pending' => $orphans->count(),
                'expired_pending' => $expired->count(),
                'ttl_minutes' => $ttlMinutes,
            ]);
        }

        return $purged;
    }

    /**
     * Borra el WAV del tmpfs y limpia los punteros sin tocar el estado de la
     * transcripcion (no es un cambio de negocio, es higiene de disco).
     */
    private function deleteStagedFile(Transcription $tx): void
    {
        $path = $tx->staged_path;
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
        $tx->forceFill(['staged_path' => null, 'staged_bytes' => null, 'staged_at' => null])->saveQuietly();
    }
}
