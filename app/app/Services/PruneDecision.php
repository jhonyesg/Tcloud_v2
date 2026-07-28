<?php

namespace App\Services;

/**
 * Veredicto de PruneGuard. Lleva el motivo para que un rechazo quede registrado
 * con contexto en vez de ser un silencio.
 */
final class PruneDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly array $context = [],
    ) {}

    public static function allow(?string $reason = null): self
    {
        return new self(true, $reason);
    }

    public static function refuse(string $reason, array $context = []): self
    {
        return new self(false, $reason, $context);
    }

    public function refused(): bool
    {
        return !$this->allowed;
    }
}
