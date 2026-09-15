<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cuenta strikes de respuestas 4xx/5xx del upstream en una ventana móvil
 * y abre el break cuando se supera el threshold. Sirve para cortar el tick
 * antes de castigar al upstream con reenvios que sabe no podra absorber.
 *
 * Implementación en Redis (mismo store que el resto del módulo):
 * - "transcriptor:upstream:strikes:{minute}" con TTL 5 min, valor = count
 * - "transcriptor:upstream:open_until" sin TTL, valor = epoch_seconds
 *
 * Mientras `isOpen()` retorne true, el regulador del tick frena con
 * `reason=upstream_circuit_open` y `batch_computed=0`.
 */
class UpstreamCircuitBreaker
{
    public function __construct(
        private ?TranscriptorSettings $settings = null,
    ) {
        if ($this->settings === null) {
            $this->settings = app(TranscriptorSettings::class);
        }
    }

    /**
     * ¿El break está abierto ahora mismo?
     */
    public function isOpen(): bool
    {
        $openUntil = (int) Cache::get('transcriptor:upstream:open_until', 0);
        return $openUntil > time();
    }

    /**
     * Sumar un strike. Si se supera el threshold en los últimos 5 min, abre.
     */
    public function recordStrike(): void
    {
        $threshold = (int) $this->settings->int('circuit_breaker_threshold') ?: 3;
        $windowSeconds = 300;
        $openedFor = (int) $this->settings->int('circuit_breaker_open_seconds') ?: 60;

        $minute = (int) (floor(time() / $windowSeconds));
        $key = "transcriptor:upstream:strikes:{$minute}";
        $current = (int) Cache::increment($key);
        // TTL asegurado (Cache::increment no setea TTL en algunos drivers)
        Cache::put($key, $current, now()->addSeconds($windowSeconds + 30));

        if ($current >= $threshold) {
            Cache::put('transcriptor:upstream:open_until', time() + $openedFor, now()->addSeconds($openedFor));
            Log::warning("UpstreamCircuitBreaker: abierto tras {$current} strikes (threshold={$threshold}); abriendo por {$openedFor}s");
        }
    }

    /**
     * Limpiar strikes (en éxito del tick / submit). Usado por tests.
     */
    public function clear(): void
    {
        for ($i = -1; $i <= 1; $i++) {
            $minute = (int) (floor(time() / 300) + $i);
            Cache::forget("transcriptor:upstream:strikes:{$minute}");
        }
        Cache::forget('transcriptor:upstream:open_until');
    }

    /**
     * Para diagnóstico.
     */
    public function summary(): array
    {
        $minute = (int) (floor(time() / 300));
        $openUntil = (int) Cache::get('transcriptor:upstream:open_until', 0);

        return [
            'open' => $openUntil > time(),
            'open_until' => $openUntil,
            'open_until_iso' => $openUntil > time() ? date('c', $openUntil) : null,
            'strikes_window' => (int) Cache::get("transcriptor:upstream:strikes:{$minute}", 0),
            'threshold' => (int) $this->settings->int('circuit_breaker_threshold') ?: 3,
            'opened_for_seconds' => (int) $this->settings->int('circuit_breaker_open_seconds') ?: 60,
        ];
    }
}
