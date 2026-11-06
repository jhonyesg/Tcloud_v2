<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de dominio del flujo de solicitudes de backup.
 *
 * El servicio de backups lanza esto en vez de responder HTTP: el servicio no
 * debe saber de códigos de estado. El controller lo traduce a JSON con el
 * `status` que trae la excepción.
 *
 * `errorCode` es estable y sirve para que el frontend distinga el motivo
 * (por ejemplo, `no_permission` vs `backup_not_ready`) sin parsear el texto.
 */
class BackupRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
