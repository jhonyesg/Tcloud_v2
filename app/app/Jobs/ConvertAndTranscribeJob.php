<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\AudioConverter;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ConvertAndTranscribeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;
    public bool $failOnException = true;

    public function __construct(
        public int $fileId,
        public bool $generateAlerts = true,
        public int $priority = 0
    ) {}

    /**
     * Calcula la prioridad del job para la cola Redis.
     * Formula: storage_priority * 10 + (es_hoy ? 100 : 0) + (es_manual ? 5 : 0)
     */
    public static function calculatePriority(int $storagePriority, bool $isToday, bool $isManual): int
    {
        return ($storagePriority * 10) + ($isToday ? 100 : 0) + ($isManual ? 5 : 0);
    }

    /**
     * Dispatch con prioridad: asigna automáticamente la cola correcta.
     * Uso: ConvertAndTranscribeJob::dispatchWithPriority($fileId, $alerts, $priority)
     */
    public static function dispatchWithPriority(int $fileId, bool $generateAlerts = true, int $priority = 0)
    {
        $queue = $priority >= 100 ? 'transcription-high' : ($priority >= 50 ? 'transcription-medium' : 'transcription-low');
        $instance = new self($fileId, $generateAlerts, $priority);
        $instance->onQueue($queue);
        return dispatch($instance);
    }

    public function handle(AudioConverter $converter, TranscriptorApiClient $client): ?array
    {
        /** @var File|null $file */
        $file = File::with('storageProvider')->find($this->fileId);
        if (!$file) {
            Log::warning("ConvertAndTranscribeJob: file {$this->fileId} no existe.");
            return null;
        }

        $storage = $file->storageProvider;
        if (!$storage) {
            throw new \RuntimeException("File {$file->id} sin storage provider.");
        }

        $srcPath = rtrim((string) $storage->base_path, '/') . '/' . ltrim((string) $file->path, '/');
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            throw new \RuntimeException("Archivo no legible en disco: {$srcPath}");
        }

        // 1. Convertir a Opus 64k mono 16kHz en RAM (tmpfs /dev/shm) para no
        //    desgastar el disco. /dev/shm es memoria compartida con 20G disponibles.
        //    Fallback a sys_get_temp_dir() si /dev/shm no existe o no es escribible.
        $tmpBase = '/dev/shm';
        if (!is_dir($tmpBase) || !is_writable($tmpBase)) {
            $tmpBase = sys_get_temp_dir();
        }
        $tmpDir = $tmpBase . '/tcloud-transcription';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        // Asegurar permisos de escritura si el dir ya existía con owner distinto.
        @chmod($tmpDir, 0777);
        // Nombre legible basado en el archivo original (sin extension), + suffix unico
        // para evitar colisiones. Asi la API y Tcloud muestran el nombre real.
        $baseName = pathinfo($file->name, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName);
        if ($safeBase === '') $safeBase = 'audio';
        $opusPath = $tmpDir . '/' . $safeBase . '_' . substr(md5(uniqid('', true)), 0, 6) . '.opus';
        $progressKey = 'tx_' . $file->id . '_' . substr(md5(uniqid('', true)), 0, 8);
        $progressFile = $converter->progressFilePath($progressKey);

        try {
            $converter->toOpus64k($srcPath, $opusPath, $progressKey);

            // 2. Crear Transcription en state=pending (antes del POST a la API).
            //    Si el job se cae aquí, scan-stale recupera pending sin job_id.
            $transcription = Transcription::firstOrCreate(
                ['file_id' => $file->id],
                [
                    'state' => Transcription::STATE_PENDING,
                    'generate_alerts' => $this->generateAlerts,
                    'language' => config('transcriptor.language', 'es'),
                    'original_name' => $file->name,
                    'started_at' => now(),
                ]
            );

            // 3. Calcular callback URL y enviar a la API.
            $callbackUrl = rtrim((string) config('transcriptor.callback_host'), '/')
                . '/webhooks/transcription';

            // Reportar fase de upload en el cache de progreso (75%->99%)
            if (file_exists($progressFile)) {
                $cur = json_decode(file_get_contents($progressFile), true) ?: [];
                $cur['phase'] = 'uploading';
                $cur['percent'] = 75;
                @file_put_contents($progressFile, json_encode($cur));
            }

            $data = $client->submit($file, $opusPath, $callbackUrl);

            // La API aceptó el job: pasar de pending → queued con job_id.
            $transcription->update([
                'job_id' => $data['job_id'] ?? null,
                'node_url' => $data['node_url'] ?? $client->getBaseUrl(),
                'state' => $data['state'] ?? Transcription::STATE_QUEUED,
                'original_name' => $file->name,
            ]);

            if (file_exists($progressFile)) {
                $cur = json_decode(file_get_contents($progressFile), true) ?: [];
                $cur['phase'] = 'queued';
                $cur['percent'] = 100;
                $cur['job_id'] = $data['job_id'] ?? null;
                @file_put_contents($progressFile, json_encode($cur));
            }
        } finally {
            if (file_exists($opusPath)) {
                @unlink($opusPath);
            }
        }

        return ['progress_key' => $progressKey];
    }
}