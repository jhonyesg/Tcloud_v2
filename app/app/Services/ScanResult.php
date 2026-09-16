<?php

namespace App\Services;

/**
 * Resultado de un escaneo de directorio, distinguiendo "vacio" de "no fiable".
 *
 * Antes scanDirectory() devolvia un array y usaba [] para CINCO situaciones
 * distintas: directorio realmente vacio, no es un directorio, no legible,
 * scandir() fallido, y excepcion. StorageSyncService no tenia forma de
 * diferenciarlas, asi que interpretaba todas como "el disco esta vacio, borra
 * las filas de la BD".
 *
 * Cuando un montaje NFS cae, el punto de montaje sigue siendo un directorio
 * local vacio y legible: is_dir() y is_readable() pasan, scandir() devuelve
 * [., ..], y el resultado es indistinguible de una carpeta vacia de verdad. Eso
 * vacio el arbol completo de la BD el 2026-07-27 y desencadeno la duplicacion.
 *
 * Se eligio un objeto de resultado en vez de una excepcion porque la señal de
 * "no fiable" tiene que viajar JUNTO a las entradas para alimentar PruneGuard,
 * y porque asi es comprobable con tests sin base de datos.
 */
final class ScanResult
{
    public const NOT_A_DIRECTORY = 'not_a_directory';
    public const NOT_READABLE = 'not_readable';
    public const SCANDIR_FAILED = 'scandir_failed';
    public const EXCEPTION = 'exception';
    public const DEPTH_EXCEEDED = 'depth_exceeded';
    public const MOUNT_DETACHED = 'mount_detached';
    public const PARTIAL_UNREADABLE = 'partial_unreadable';

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  list<string>  $unreadable  nombres de entradas que scandir() listo pero
     *                                    cuyo stat() fallo (EIO en NFS, symlink colgante…)
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $entries,
        public readonly ?string $failureReason = null,
        public readonly ?string $path = null,
        public readonly array $unreadable = [],
    ) {}

    /**
     * Escaneo fiable. Un array vacio aqui significa "el directorio esta vacio de
     * verdad" — que es justo lo que antes no se podia expresar.
     *
     * @param  list<array<string,mixed>>  $entries
     */
    public static function ok(array $entries, ?string $path = null): self
    {
        return new self(true, $entries, null, $path);
    }

    public static function failed(string $reason, ?string $path = null): self
    {
        return new self(false, [], $reason, $path);
    }

    /**
     * Se leyo el directorio, pero alguna entrada concreta no se pudo consultar.
     *
     * Nace del daño real en los NFS de difusor01: 67 archivos devuelven EIO a
     * nivel de kernel, y como el escaner descartaba el directorio entero al
     * primer fallo, 109 archivos perfectamente legibles de esas mismas carpetas
     * quedaban invisibles para la app.
     *
     * `ok` es false a proposito: significa "me fio lo bastante como para BORRAR
     * filas", y de esto no me fio para eso — lo que no se pudo leer no puede
     * contarse como desaparecido del disco. Para "¿sirve para crear/actualizar?"
     * esta usable(), que si acepta este estado.
     *
     * @param  list<array<string,mixed>>  $entries
     * @param  list<string>  $unreadable
     */
    public static function partial(array $entries, array $unreadable, ?string $path = null): self
    {
        return new self(false, $entries, self::PARTIAL_UNREADABLE, $path, $unreadable);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** Vacio Y fiable: el unico caso en que una purga podria estar justificada. */
    public function isTrustworthyEmpty(): bool
    {
        return $this->ok && $this->isEmpty();
    }

    public function isPartial(): bool
    {
        return $this->failureReason === self::PARTIAL_UNREADABLE;
    }

    /**
     * Las entradas sirven para crear y actualizar filas.
     *
     * Distinto de $ok, que gobierna la purga: un escaneo parcial es utilizable
     * pero nunca fiable para borrar.
     */
    public function usable(): bool
    {
        return $this->ok || $this->isPartial();
    }

    /** @return array<string,mixed> contexto para logs */
    public function context(): array
    {
        $context = [
            'ok' => $this->ok,
            'entries' => $this->count(),
            'reason' => $this->failureReason,
            'path' => $this->path,
        ];

        if ($this->unreadable !== []) {
            $context['unreadable'] = count($this->unreadable);
            // Acotado: una carpeta-dia rota puede tener decenas de entradas y el
            // log no es el sitio para volcarlas todas.
            $context['unreadable_sample'] = array_slice($this->unreadable, 0, 10);
        }

        return $context;
    }
}
