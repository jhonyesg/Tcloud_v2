<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Facades\Log;

/**
 * Envia una Transcription pendiente al transcriptor externo de forma síncrona:
 * ffmpeg → mono 16kHz en /dev/shm (wav pcm_s16le u opus 64k, segun el ajuste
 * audio_output_format) → POST /v1/transcribe.
 *
 * No usa colas Redis. No envía callback_url: la recepción del SRT se hace por
 * polling (TranscriptionPollingService).
 */
class TranscriptionSubmitService
{
    private ?string $cachedNodeId = null;

    public function __construct(
        private AudioConverter $converter,
        private TranscriptorApiClient $client,
        private TranscriptorSettings $settings,
    ) {}

    /**
     * Convierte y envía una Transcription pendiente sin job_id.
     * Persiste job_id, node_url, node_id y pasa a state=queued.
     * En caso de error marca state=error con mensaje.
     */
    public function submit(Transcription $transcription): array
    {
        $file = $transcription->file;
        if (!$file) {
            $this->markError($transcription, 'Archivo asociado no existe');
            return ['ok' => false, 'error' => 'Archivo no existe'];
        }

        $storage = $file->storageProvider;
        if (!$storage) {
            $this->markError($transcription, 'Storage provider no existe');
            return ['ok' => false, 'error' => 'Storage no existe'];
        }

        $srcPath = rtrim((string) $storage->base_path, '/') . '/' . ltrim((string) $file->path, '/');
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            $this->markError($transcription, "Archivo no legible en disco: {$srcPath}");
            return ['ok' => false, 'error' => 'Archivo no legible'];
        }

        // Filtro de tamano: archivos < N bytes no pasan por ffmpeg.
        // Caso tipico: el grabador de radio crea el MP3 al iniciar y el tick lo
        // dispatcha mientras aun se esta escribiendo (0 bytes). Si el stream de
        // red se congela, el archivo queda en 0 bytes durante minutos; marcarlo
        // dead irreversiblemente perdia la transcripcion cuando el stream se
        // descongelaba y el archivo crecia. Ahora se reencola (requeue_after_at)
        // para que el tick lo reintente cuando el archivo haya crecido, con tope
        // de max_retries para no encolar basura corrupta para siempre.
        $minSize = $this->settings->int('min_file_size_bytes');
        if ($minSize > 0) {
            $size = @filesize($srcPath);
            if ($size !== false && $size < $minSize) {
                $maxRetries = $this->settings->int('max_retries');
                if ((int) $transcription->retries < $maxRetries) {
                    $transcription->update(['retries' => (int) $transcription->retries + 1]);
                    $this->markRequeueable($transcription, "Archivo incompleto ({$size} bytes < {$minSize} minimo). Reintento en el proximo ciclo.");
                    return ['ok' => false, 'error' => "Archivo < {$minSize} bytes", 'requeueable' => true];
                }
                $this->markDead($transcription, "Archivo descartado por tamano ({$size} bytes < {$minSize} minimo). Probable archivo truncado por grabador en vivo.");
                return ['ok' => false, 'error' => "Archivo < {$minSize} bytes"];
            }
        }

        // /dev/shm (tmpfs) con fallback a sys_get_temp_dir.
        $tmpBase = '/dev/shm';
        if (!is_dir($tmpBase) || !is_writable($tmpBase)) {
            $tmpBase = sys_get_temp_dir();
            Log::warning("TranscriptionSubmitService: /dev/shm no escribible, fallback a {$tmpBase}", [
                'file_id' => $file->id,
            ]);
        }
        $tmpDir = $tmpBase . '/tcloud-transcription';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        @chmod($tmpDir, 0777);

        // Pre-flight: si /dev/shm no tiene espacio suficiente, NO se invoca ffmpeg.
        // El job se reencola para el siguiente ciclo con requeue_after_at futuro;
        // ver markRequeueable() y el filtro del TranscriptionTickCommand.
        // Sin esta guarda, ffmpeg falla en ~250ms con "Could not seek: Invalid
        // argument" y el job pasa a error/dead tras max_retries, saturando la
        // cola con basura recuperable.
        $minShmFree = $this->settings->int('min_shm_free_bytes');
        $freeBytes  = @disk_free_space($tmpDir);
        if ($freeBytes !== false && $freeBytes < $minShmFree) {
            $msg = "tmpfs sin espacio: {$freeBytes} bytes libres, mínimo {$minShmFree}. "
                 . "Job reencolado para próximo intento (revisar /dev/shm).";
            Log::warning("TranscriptionSubmitService: {$msg}", [
                'tmp_dir' => $tmpDir,
                'free_bytes' => $freeBytes,
                'min_shm_free_bytes' => $minShmFree,
                'file_id' => $file->id,
            ]);
            $this->markRequeueable($transcription, $msg);
            return ['ok' => false, 'error' => $msg, 'requeueable' => true];
        }

        // Se lee por llamada: los workers viven horas y el ajuste es en caliente.
        $format = $this->settings->str('audio_output_format');

        $baseName = pathinfo($file->name, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName);
        if ($safeBase === '') $safeBase = 'audio';
        $tmpPath = $tmpDir . '/' . $safeBase . '_' . substr(md5(uniqid('', true)), 0, 6)
            . '.' . AudioConverter::extensionFor($format);

        try {
            $this->converter->convert($srcPath, $tmpPath, $format);

            // POST /v1/transcribe sin callback_url (recibimos por polling).
            $data = $this->client->submitNoCallback($file, $tmpPath);

            $transcription->update([
                'job_id' => $data['job_id'] ?? null,
                'node_url' => $this->client->getBaseUrl(),
                'node_id' => $data['node_id'] ?? $this->resolveNodeId(),
                'state' => $data['state'] ?? Transcription::STATE_QUEUED,
                'original_name' => $file->name,
                'error_message' => null,
                // Marca del envio EFECTIVO. Antes solo se ponia al crear la
                // fila, asi que en un reenvio (backfill, retry, reproceso)
                // quedaba congelada en la fecha original. El corte por
                // antiguedad del poller la usa para decidir si un job lleva
                // demasiado tiempo sin resolverse: con la fecha vieja, una
                // fila recien reenviada se cerraria como caducada al instante.
                'started_at' => now(),
                'last_polled_at' => null,
            ]);

            return ['ok' => true, 'job_id' => $transcription->job_id, 'state' => $transcription->state];
        } catch (\Throwable $e) {
            $this->markError($transcription, $e->getMessage());
            Log::error("TranscriptionSubmitService: file {$file->id}: {$e->getMessage()}");
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Cataloga el node_id consultando /api/info una sola vez y cacheándolo
     * en memoria para evitar llamadas repetidas durante un lote de envíos.
     */
    private function resolveNodeId(): ?string
    {
        if ($this->cachedNodeId !== null) {
            return $this->cachedNodeId;
        }
        try {
            $info = $this->client->getInfo();
            $this->cachedNodeId = $info['node_id'] ?? null;
        } catch (\Throwable $e) {
            $this->cachedNodeId = null;
        }
        return $this->cachedNodeId;
    }

    private function markError(Transcription $t, string $message): void
    {
        $maxRetries = $this->settings->int('max_retries');
        $newRetries = (int) $t->retries + 1;

        $state = Transcription::STATE_ERROR;
        if ($newRetries >= $maxRetries) {
            $state = Transcription::STATE_DEAD;
            $message = "[Auto] Max retries ({$maxRetries}) alcanzado. {$message}";
        }

        $t->update([
            'state' => $state,
            'error_message' => $message,
            'finished_at' => now(),
            'retries' => $newRetries,
        ]);
    }

    /**
     * Marca la transcripcion como 'dead' inmediatamente, sin retry.
     * Para casos donde reintentar no aporta nada (archivo vacio, formato
     * invalido, etc.). Conserva retries anteriores para no romper el contador.
     */
    private function markDead(Transcription $t, string $message): void
    {
        $t->update([
            'state' => Transcription::STATE_DEAD,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * Devuelve la transcripcion a 'pending' con requeue_after_at futuro, para
     * que el TranscriptionTickCommand la ignore durante N minutos y la
     * reencole automáticamente cuando pase el plazo.
     *
     * Esto es independiente de la lógica de retries: un job rebotado por
     * tmpfs lleno debe reintentar cada pocos minutos hasta que haya espacio,
     * NO ir a dead tras max_retries. El campo requeue_after_at es la señal
     * que usa el tick para NO dispatcharlo; el cleanup-orphan-wav + el
     * check-shm-health no tocan esta lógica.
     *
     * El estado DEBE ser pending: el tick filtra `state = pending` antes de
     * mirar requeue_after_at (TranscriptionTickCommand::handle), asi que
     * dejarlo en 'error' — como hacia antes — convertia el campo en codigo
     * muerto y el job no se reencolaba nunca. Peor: cada rebote sumaba
     * retries, de modo que tras max_retries la fila quedaba excluida tambien
     * de la recuperacion manual (--include-failed exige retries < max).
     *
     * Por eso tampoco se incrementa retries aqui: un rebote de pre-flight no
     * es un intento fallido de transcripcion, es un aplazamiento.
     */
    private function markRequeueable(Transcription $t, string $message): void
    {
        $requeueMinutes = max(1, $this->settings->int('requeue_after_minutes'));
        $t->update([
            'state' => Transcription::STATE_PENDING,
            'job_id' => null,
            'error_message' => $message,
            'finished_at' => null,
            'requeue_after_at' => now()->addMinutes($requeueMinutes),
        ]);
    }
}