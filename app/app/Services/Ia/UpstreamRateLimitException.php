<?php

namespace App\Services\Ia;

/**
 * 429 Rate Limit del upstream. Indica que el transcriptor rechazo el envio
 * temporalmente y que el sistema DEBE respetar el header `Retry-After`.
 *
 * El servicio que captura este error (TranscriptionSubmitService) debe
 * marcar la Transcription con `state=pending` y `requeue_after_at` igual a
 * `now() + $retryAfter`. El proximo tick respeta ese plazo.
 */
class UpstreamRateLimitException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $retryAfter,
        private string $operation = 'submit',
    ) {
        parent::__construct($message);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * Factory: parsea la respuesta HTTP y construye la excepcion con el
     * `Retry-After` ya resuelto a segundos enteros (con fallback).
     */
    public static function fromResponse(int $status, \Illuminate\Http\Client\Response $response, string $operation): self
    {
        $retryAfter = self::parseRetryAfter($response);

        return new self(
            "upstream {$status} rate-limit en {$operation}; retry_after={$retryAfter}s",
            $retryAfter,
            $operation
        );
    }

    /**
     * Parsea el header `Retry-After` segun RFC 7231.
     * - Segundos enteros: "12" -> 12
     * - HTTP-date: "Wed, 21 Oct 2026 07:28:00 GMT" -> diff con now()
     * - Ausente o malformado: 0 (fallback en caller)
     */
    public static function parseRetryAfter(\Illuminate\Http\Client\Response $response): int
    {
        $value = $response->header('Retry-After');
        if ($value === null || $value === '') {
            return 0;
        }
        if (ctype_digit((string) $value)) {
            return max(0, (int) $value);
        }
        try {
            $ts = strtotime((string) $value);
            if ($ts === false) {
                return 0;
            }
            return max(0, $ts - time());
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
