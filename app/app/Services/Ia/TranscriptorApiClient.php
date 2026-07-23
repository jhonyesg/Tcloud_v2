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
    private string $baseUrl;
    private ?string $apiKey;
    private int $submitTimeout;
    private int $getTimeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('transcriptor.base_url'), '/');
        $this->apiKey = config('transcriptor.api_key') ?: null;
        $this->submitTimeout = (int) config('transcriptor.submit_timeout', 60);
        $this->getTimeout = (int) config('transcriptor.get_timeout', 30);
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

        $endpoint = $this->baseUrl . '/v1/transcribe';

        try {
            $request = Http::timeout($this->submitTimeout)
                ->attach('file', file_get_contents($opusPath), basename($opusPath));

            $request = $request->asMultipart();

            $response = $request->post($endpoint, [
                'language' => config('transcriptor.language', 'es'),
                'lang_fix' => (string) config('transcriptor.lang_fix', 'async'),
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

        $endpoint = $this->baseUrl . '/v1/transcribe';

        try {
            $request = Http::timeout($this->submitTimeout)
                ->attach('file', file_get_contents($opusPath), basename($opusPath));

            $request = $request->asMultipart();

            $response = $request->post($endpoint, [
                'language' => config('transcriptor.language', 'es'),
                'lang_fix' => 'async',
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
            $base = rtrim($nodeUrl ?: $this->baseUrl, '/');
            $endpoint = $base . '/' . ltrim($srtUrl, '/');
        }

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout)
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
        $base = rtrim($nodeUrl ?: $this->baseUrl, '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}/srt";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout)
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
        $base = rtrim($nodeUrl ?: $this->baseUrl, '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout)
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
                ->timeout($this->getTimeout)
                ->get($this->baseUrl . '/api/stats');

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
                ->timeout($this->getTimeout)
                ->get($this->baseUrl . '/api/info');

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
                ->timeout($this->getTimeout)
                ->get($this->baseUrl . '/health');

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
        return $this->baseUrl;
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
        return $this->apiKey ? ['Authorization' => 'Bearer ' . $this->apiKey] : [];
    }
}