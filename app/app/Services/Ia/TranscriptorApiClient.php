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
     *
     * IMPORTANTE: $stream debe ser un resource ABIERTO por el caller (fopen).
     * El caller es responsable de cerrarlo con fclose() en un bloque finally.
     * Esto evita la fuga de fd que vimos en 2026-08-12 cuando los workers
     * acumulaban ~100 wavs (deleted) en /dev/shm porque Guzzle/Laravel no
     * cierran el stream en todas las rutas (retry, throw: false, 5xx).
     */
    private function submitRequest(string $audioPath, $stream)
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
            // El Content-Type va explicito: si no, Guzzle lo deduce de la
            // extension del nombre y el tipo que ve la API depende de un
            // detalle interno de la dependencia.
            ->attach('file', $stream, basename($audioPath), [
                'Content-Type' => str_ends_with($audioPath, '.wav') ? 'audio/wav' : 'audio/ogg',
            ])
            ->asMultipart();
    }

    /**
     * Abre el archivo de audio y devuelve un resource PHP.
     * Lanza RuntimeException si no se puede abrir (permisos, path invalido).
     * El caller es responsable de cerrarlo con fclose().
     */
    private function openAudioStream(string $audioPath)
    {
        if (!is_file($audioPath) || !is_readable($audioPath)) {
            throw new \RuntimeException("Archivo de audio no legible: {$audioPath}");
        }
        $stream = @fopen($audioPath, 'r');
        if ($stream === false) {
            throw new \RuntimeException("No se pudo abrir el archivo para envio: {$audioPath}");
        }
        return $stream;
    }

    /**
     * Cierra un resource de forma segura. No-op si no es un resource (defensivo:
     * un cierre explicito previo nunca deberia pasar, pero si lo hace, no
     * queremos un TypeError).
     */
    private function closeStream($stream): void
    {
        if (is_resource($stream)) {
            @fclose($stream);
        }
    }

    /**
     * Envia el archivo de audio ya convertido a POST /v1/transcribe SIN callback_url.
     * La recepción del SRT se hace por polling (TranscriptionPollingService).
     * Devuelve ['job_id'=>..., 'priority'=>..., 'state'=>...] o lanza.
     *
     * Solo se envian los campos documentados por la API: file, language, lang_fix.
     * Campos previos como `original_name`, `file_id`, `tcloud_callback` se eliminaron
     * al alinear con la versión actualizada — la API los ignoraba pero no aportaban.
     */
    public function submitNoCallback(File $file, string $audioPath): array
    {
        $stream = $this->openAudioStream($audioPath);

        $endpoint = $this->baseUrl() . '/v1/transcribe';
        try {
            $response = $this->submitRequest($audioPath, $stream)->post($endpoint, [
                'language' => $this->settings->str('language'),
                'lang_fix' => $this->settings->str('lang_fix'),
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException("No se pudo conectar al transcriptor: {$e->getMessage()}", 0, $e);
        } finally {
            $this->closeStream($stream);
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

    /*
     * Aqui vivia submit(File, string, string $callbackUrl), que mandaba un
     * callback_url apuntando a /webhooks/transcription.
     *
     * Eliminado el 2026-08-12: no lo llamaba nadie y esa ruta nunca existio.
     * Su presencia sostenia la ficcion de que los resultados vuelven solos por
     * webhook — el panel de ayuda y el tour lo repetian, y durante una semana
     * nadie miro el polling cuando 33.571 transcripciones dejaron de cerrarse.
     *
     * El unico camino de retorno es TranscriptionPollingService.
     */

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
            throw new TranscriptorUpstreamException(
                "No se pudo descargar el SRT ({$response->status()}): {$response->body()}",
                $response->status(),
                'srt',
            );
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
            throw new TranscriptorUpstreamException(
                "No se pudo descargar el SRT ({$response->status()}): {$response->body()}",
                $response->status(),
                'srt',
            );
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
            throw new TranscriptorUpstreamException(
                "getJob error {$response->status()}: {$response->body()}",
                $response->status(),
                'job',
            );
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
     * Re-encola un job fallido (error|dead) en el transcriptor, reusando el
     * `.bin` aún en disco. Solo funciona si el `.bin` no ha sido purgado
     * (ventana BIN_RETENTION_HOURS, default 24h). Más rápido que re-ffmpeg +
     * re-upload, pero limitado en el tiempo.
     *
     * Endpoint: POST /v1/jobs/{id}/retry
     * Respuesta 200: {job_id, state:"queued"}
     * 404: job no existe | 409: job no está en error|dead
     */
    public function retryUpstream(string $jobId, string $nodeUrl = ''): array
    {
        $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}/retry";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->post($endpoint);

        if ($response->status() === 404) {
            throw new \RuntimeException("retry upstream 404: job no existe en la API externa");
        }
        if ($response->status() === 409) {
            throw new \RuntimeException("retry upstream 409: job no está en estado error/dead");
        }
        if (!$response->successful()) {
            throw new \RuntimeException("retry upstream {$response->status()}: {$response->body()}");
        }

        return $response->json() ?: [];
    }

    /**
     * Saca un job processing colgado de vuelta a queued. Solo permitido si
     * lleva más de WORKER_TIMEOUT en processing. Runbook para motores hung.
     *
     * Endpoint: POST /v1/jobs/{id}/unstick
     * Respuesta 200: {job_id, state:"queued", was_stuck_s}
     * 409: job no está en processing, o no lleva suficiente tiempo.
     */
    public function unstickUpstream(string $jobId, string $nodeUrl = ''): array
    {
        $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}/unstick";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->post($endpoint);

        if (!$response->successful()) {
            throw new \RuntimeException("unstick upstream {$response->status()}: {$response->body()}");
        }

        return $response->json() ?: [];
    }

    /**
     * Cambia la prioridad de un job en queued. El worker_loop ordena por
     * priority DESC, así que el job se reordena automáticamente.
     *
     * Endpoint: PATCH /v1/jobs/{id}/priority
     */
    public function changePriority(string $jobId, int $priority, string $nodeUrl = ''): array
    {
        $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}/priority";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->asMultipart()
            ->patch($endpoint, ['priority' => $priority]);

        if (!$response->successful()) {
            throw new \RuntimeException("priority upstream {$response->status()}: {$response->body()}");
        }

        return $response->json() ?: [];
    }

    /**
     * Elimina un job terminal en la API externa (SRT + .bin). Destructivo e
     * irreversible. Solo permitido en estados done|error|dead|cancelled.
     *
     * Endpoint: DELETE /v1/jobs/{id}
     */
    public function deleteUpstream(string $jobId, string $nodeUrl = ''): bool
    {
        $base = rtrim($nodeUrl ?: $this->baseUrl(), '/');
        $endpoint = "{$base}/v1/jobs/{$jobId}";

        $response = Http::withHeaders($this->authHeaders())
            ->timeout($this->getTimeout())
            ->delete($endpoint);

        if ($response->status() === 404) {
            return true;
        }
        if ($response->status() === 409) {
            throw new \RuntimeException("delete upstream 409: job no está en estado terminal");
        }
        if (!$response->successful()) {
            throw new \RuntimeException("delete upstream {$response->status()}: {$response->body()}");
        }

        return true;
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