<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Facades\Log;

/**
 * Envia una Transcription pendiente al transcriptor externo de forma síncrona:
 * ffmpeg → Opus 64k mono 16kHz en /dev/shm → POST /v1/transcribe.
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

        // /dev/shm (tmpfs) con fallback a sys_get_temp_dir.
        $tmpBase = '/dev/shm';
        if (!is_dir($tmpBase) || !is_writable($tmpBase)) {
            $tmpBase = sys_get_temp_dir();
        }
        $tmpDir = $tmpBase . '/tcloud-transcription';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        @chmod($tmpDir, 0777);

        $baseName = pathinfo($file->name, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName);
        if ($safeBase === '') $safeBase = 'audio';
        $opusPath = $tmpDir . '/' . $safeBase . '_' . substr(md5(uniqid('', true)), 0, 6) . '.opus';

        try {
            $this->converter->toOpus64k($srcPath, $opusPath);

            // POST /v1/transcribe sin callback_url (recibimos por polling).
            $data = $this->client->submitNoCallback($file, $opusPath);

            $transcription->update([
                'job_id' => $data['job_id'] ?? null,
                'node_url' => $this->client->getBaseUrl(),
                'node_id' => $data['node_id'] ?? $this->resolveNodeId(),
                'state' => $data['state'] ?? Transcription::STATE_QUEUED,
                'original_name' => $file->name,
                'error_message' => null,
            ]);

            return ['ok' => true, 'job_id' => $transcription->job_id, 'state' => $transcription->state];
        } catch (\Throwable $e) {
            $this->markError($transcription, $e->getMessage());
            Log::error("TranscriptionSubmitService: file {$file->id}: {$e->getMessage()}");
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (file_exists($opusPath)) {
                @unlink($opusPath);
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
        $t->update([
            'state' => Transcription::STATE_ERROR,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}