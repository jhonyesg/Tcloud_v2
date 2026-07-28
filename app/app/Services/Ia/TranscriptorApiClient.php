<?php

namespace App\Services\Ia;

use App\Models\File;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la API externa del transcriptor ASR.
 *
 * La API corre en LAN (TRANSCRIPTOR_BASE_URL). El envio es multipart con
 * callback_url; el SRT se descarga via GET una vez que el job pasa a done.
 */
class TranscriptorApiClient
{
    public function __construct(private TranscriptorSettings $settings) {}

    /*
     * Los valores se leen POR LLAMADA, no en el constructor.
     *
     * Esta clase esta bindeada como singleton y vive dentro de procesos
     * queue:work que duran horas: cachearlos en propiedades los congelaba hasta
     * reiniciar systemd, que es justo lo que la capa de settings en caliente
     * viene a resolver.
     */

    private function baseUrl(): string
    {
        return rtrim((string) config('transcriptor.base_url'), '/');
    }

    private function apiKey(): ?string
    {
        return config('transcriptor.api_key') ?: null;
    }

    private function submitTimeout(): int
    {
        return $this->settings->int('submit_timeout');
    }

    private function getTimeout(): int
    {
        return $this->settings->int('get_timeout');
    }

    /**
     * Cliente para el POST de envio, con reintentos.
     *
     * Antes no habia ninguno: un 502 transitorio mandaba la transcripcion
     * directa a markError() y, tras max_retries, a dead. Solo se reintenta ante
     * fallo de conexion o 5xx — un 4xx/401 es permanente y reintentarlo solo
     * multiplica la carga.
     */
    private function submitRequest(string $opusPath)
    {
        return Http::timeout($this->submitTimeout())
            ->retry(
                $this->settings->int('submit_max_attempts'),
                $this->settings->int('submit_retry_base_ms'),
                function ($exception, $request) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    $status = method_exists($exception, 'response') && $exception->response
                        ? $exception->response->status()
                        : 0;

                    return $status >= 500;
                },
                throw: false
            )
            // Streaming en vez de file_get_contents(): cargar el opus entero en
            // un string PHP se multiplicaba por N workers con memory_limit=512M.
            ->attach('file', fopen($opusPath, 'r'), basename($opusPath))
            ->asMultipart();
    }

    /**
     * Envia el archivo opus a POST /v1/transcribe SIN callback_url.
     * La recepción del SRT se hace por polling (TranscriptionPollingService).
     * Devuelve ['job_id'=>..., 'priority'=>..., 'state'=>..., 'node_id'=>...] o lanza.
     */
    public function submitNoCallback(File $file, string $opusPath): array
    {
        if (!is_file($opusPath) || !is_readable($opusPath)) {
            throw new \RuntimeException("Archivo opus no legible: {$opusPath}");
        }

        $endpoint = $this->baseUrl() . '/v1/transcribe';

        try {
            $response = $this->submitRequest($opusPath)->post($endpoint, [
                'language' => $this->settings->str('language'),
                'lang_fix' => $this->settings->str('lang_fix'),
                'original_name' => $file->name,
                'file_id' => (string) $file->id,
                'storage_id' => (string) $file->storage_provider_id,
                'tcloud_callback' => json_encode([
                    'file_id' => $file->id,
                    'original_name' => $file->name,
                    'storage_id' => $file->storage_provider_id,
                    'path' => $file->path,
                ]),
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException("No se pudo conectar al transcriptor: {$e->getMessage()}", 0, $e);
        }

        if ($response->status() === 401) {
            throw new \RuntimeException('API auth required');
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Transcriptor API error ' . $response->status() . ': ' . $response->body());
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['job_id'])) {
            throw new \RuntimeException('Respuesta inesperada del transcriptor: ' . $response->body());
        }

        return $data;
    }

    /**
     * Envia el archivo opus a POST /v1/transcribe.
     * Devuelve ['job_id'=>..., 'priority'=>..., 'state'=>...] o lanza.
     */
    public function submit(File $file, string $opusPath, string $callbackUrl): array
    {
        if (!is_file($opusPath) || !is_readable($opusPath)) {
            throw new \RuntimeException("Archivo opus no legible: {$opusPath}");
        }

        $endpoint = $this->baseUrl() . '/v1/transcribe';

        try {
            $response = $this->submitRequest($opusPath)->post($endpoint, [
                'language' => $this->settings->str('language'),
                'lang_fix' => $this->settings->str('lang_fix'),
                'callback_url' => $callbackUrl,
                'original_name' => $file->name,
                'file_id' => (string) $file->id,
                'storage_id' => (string) $file->storage_provider_id,
                'tcloud_callback' => json_encode([
                    'file_id' => $file->id,
                    'original_name' => $file->name,
                    'storage_id' => $file->storage_provider_id,
                    'path' => $file->path,
                ]),
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException("No se pudo conectar al transcriptor: {$e->getMessage()}", 0, $e);
        }

        if ($response->status() === 401) {
            throw new \RuntimeException('API auth required');
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Transcriptor API error ' . $response->status() . ': ' . $response->body());
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['job_id'])) {
            throw new \RuntimeException('Respuesta inesperada del transcriptor: ' . $response->body());
        }

        return $data;
    }

    /**
     * Descarga el SRT desde una URL absoluta o relativa devuelta por el nodo.
     * Si $srtUrl es absoluta (http...) la usa tal cual; si es relativa la
     * prefija con $nodeUrl (o baseUrl).
     */
    public function getSrtFromUrl(string $srtUrl, string $nodeUrl = ''): string
    {
        if (preg_match('#^https?://#i', $srtUrl)) {
            $endpoint = $srtUrl;
        } else {
            $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
            $endpoint = $base . '/' . ltrim($srtUrl, '/');
        }

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->get($endpoint);

        if (!$response->successful()) {
            throw new \RuntimeException("No se pudo descargar el SRT ({$response->status()}): {$response->body()}");
        }

        return $response->body();
    }

    /**
     * Descarga el SRT canónico de {nodeUrl}/v1/jobs/{jobId}/srt.
     */
    public function getSrt(string $jobId, string $nodeUrl): string
    {
        $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}/srt";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->get($endpoint);

        if (!$response->successful()) {
            throw new \RuntimeException("No se pudo descargar el SRT ({$response->status()}): {$response->body()}");
        }

        return $response->body();
    }

    /**
     * Obtiene el estado de un job: {state, ...}.
     */
    public function getJob(string $jobId, string $nodeUrl): array
    {
        $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->get($endpoint);

        if (!$response->successful()) {
            throw new \RuntimeException("getJob error {$response->status()}: {$response->body()}");
        }

        $data = $response->json();
        return is_array($data) ? $data : [];
    }

    /**
     * Stats globales de la API (colas por estado, salud del nodo).
     */
    public function getStats(): array
    {
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout($this->getTimeout())
                ->get($this->baseUrl() . '/api/stats');

            if (!$response->successful()) {
                return ['ok' => false, 'error' => 'stats ' . $response->status()];
            }

            $data = $response->json();
            return is_array($data) ? array_merge(['ok' => true], $data) : ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Información del nodo (GET /api/info): node_id, hostname, workers, version.
     */
    public function getInfo(): array
    {
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout($this->getTimeout())
                ->get($this->baseUrl() . '/api/info');

            if (!$response->successful()) {
                return ['ok' => false, 'error' => 'info ' . $response->status()];
            }

            $data = $response->json();
            return is_array($data) ? array_merge(['ok' => true], $data) : ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Health check del transcriptor (GET /health).
     */
    public function getHealth(): array
    {
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout($this->getTimeout())
                ->get($this->baseUrl() . '/health');

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl();
    }

    /**
     * Headers de auth públicos (para uso desde controladores con Http facade).
     */
    public function authHeadersPublic(): array
    {
        return $this->authHeaders();
    }

    private function authHeaders(): array
    {
        $key = $this->apiKey();

        return $key ? ['Authorization' => 'Bearer ' . $key] : [];
    }
}