<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de dominio del flujo de solicitud puntual de TV y Radio.
 *
 * Igual que `BackupRequestException`: el servicio lanza esto en vez de
 * responder HTTP, y el controller lo traduce a JSON con el `status` que
 * trae la excepción.
 *
 * `errorCode` es estable y sirve para que el frontend distinga el motivo
 * (`material_no_disponible` vs `motivo_invalido`) sin parsear el texto.
 */
class PuntualRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
