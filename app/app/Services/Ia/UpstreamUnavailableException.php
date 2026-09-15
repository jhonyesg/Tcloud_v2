<?php

namespace App\Services\Ia;

/**
 * 503 Service Unavailable del upstream (típicamente ramdisk lleno o motor
 * recargando). El cliente DEBE respetar el header `Retry-After` si viene; si
 * no viene, usa un max_backoff_seconds configurable.
 *
 * Ademas de marcar `requeue_after_at`, contar un strike en el
 * UpstreamCircuitBreaker para eventual freno del tick.
 */
class UpstreamUnavailableException extends \RuntimeException
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

    public static function fromResponse(int $status, \Illuminate\Http\Client\Response $response, string $operation, int $maxBackoffFallback): self
    {
        $retryAfter = self::parseRetryAfter($response);
        if ($retryAfter === 0) {
            $retryAfter = $maxBackoffFallback;
        }

        return new self(
            "upstream {$status} unavailable en {$operation}; retry_after={$retryAfter}s",
            $retryAfter,
            $operation
        );
    }

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
