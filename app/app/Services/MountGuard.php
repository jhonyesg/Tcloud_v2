<?php

namespace App\Services;

/**
 * Detecta montajes de red caidos.
 *
 * El caso que rompio el sistema: cuando un montaje NFS se desmonta o pierde la
 * conexion, el punto de montaje NO desaparece — vuelve a ser el directorio local
 * vacio que habia debajo. Existe, es un directorio, y es legible. Todas las
 * comprobaciones normales pasan y el escaneo devuelve cero entradas, que el
 * sincronizador leia como "borraron todo el contenido".
 *
 * La deteccion clasica: si un punto de montaje comparte numero de dispositivo
 * con su directorio padre, no hay nada montado encima.
 *
 * El parseo de /proc/self/mounts esta separado para poder probarlo con texto de
 * ejemplo, sin depender del sistema real.
 */
class MountGuard
{
    private const NETWORK_FSTYPES = ['nfs', 'nfs4', 'cifs', 'smbfs', 'fuse.sshfs', 'glusterfs'];

    /** @var array<string,string>|null  punto de montaje => fstype */
    private ?array $mounts = null;

    public function __construct(private ?array $config = null) {}

    /**
     * Parsea el contenido de /proc/self/mounts.
     *
     * Formato: dispositivo punto_montaje fstype opciones dump paso
     * Los espacios en el punto de montaje vienen escapados como \040.
     *
     * @return array<string,string> punto de montaje => fstype
     */
    public function parseMounts(string $contents): array
    {
        $out = [];

        foreach (preg_split('/\R/', trim($contents)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $cols = preg_split('/\s+/', $line);
            if (count($cols) < 3) {
                continue;
            }

            $point = str_replace(['\040', '\011', '\012', '\134'], [' ', "\t", "\n", '\\'], $cols[1]);
            $out[$point] = $cols[2];
        }

        return $out;
    }

    /** @return array<string,string> */
    public function mounts(): array
    {
        if ($this->mounts !== null) {
            return $this->mounts;
        }

        $contents = @file_get_contents('/proc/self/mounts');

        return $this->mounts = $contents === false ? [] : $this->parseMounts($contents);
    }

    public function isNetworkMount(string $path): bool
    {
        $fstype = $this->mounts()[rtrim($path, '/')] ?? null;

        return $fstype !== null && in_array($fstype, self::NETWORK_FSTYPES, true);
    }

    /**
     * ¿Es un punto de montaje activo? Compara el dispositivo con el del padre.
     */
    public function isMounted(string $path): bool
    {
        $path = rtrim($path, '/') ?: '/';
        $parent = dirname($path);

        if ($parent === $path) {
            return true; // la raiz siempre esta montada
        }

        clearstatcache(true, $path);
        clearstatcache(true, $parent);

        $a = @stat($path);
        $b = @stat($parent);

        if ($a === false || $b === false) {
            return false;
        }

        return $a['dev'] !== $b['dev'];
    }

    /**
     * ¿Falta un montaje que deberia estar activo?
     *
     * Solo devuelve true cuando la ruta esta declarada como montaje esperado en
     * `storage_sync.mounts.expected`. Sin esa declaracion no se puede distinguir
     * "montaje caido" de "directorio local normal", y suponerlo seria peor que
     * no comprobar nada.
     */
    public function isExpectedMountMissing(string $path): bool
    {
        $path = rtrim($path, '/');

        foreach ($this->expectedMounts() as $expected) {
            $expected = rtrim($expected, '/');
            if ($expected === '' || $expected !== $path) {
                continue;
            }

            if (!$this->isMounted($expected)) {
                return true;
            }

            return !$this->sentinelPresent($expected);
        }

        return false;
    }

    /**
     * Primer montaje esperado caido que afecte a esta ruta (ella misma o
     * cualquiera de sus ancestros).
     */
    public function detachedAncestor(string $path): ?string
    {
        $path = rtrim($path, '/');

        while ($path !== '' && $path !== '/') {
            if ($this->isExpectedMountMissing($path)) {
                return $path;
            }
            $path = dirname($path);
        }

        return null;
    }

    private function sentinelPresent(string $mountPoint): bool
    {
        $sentinel = (string) ($this->cfg()['sentinel'] ?? '');
        if ($sentinel === '') {
            return true; // sin centinela configurado, no se exige
        }

        return is_file(rtrim($mountPoint, '/') . '/' . ltrim($sentinel, '/'));
    }

    /** @return list<string> */
    private function expectedMounts(): array
    {
        return array_values((array) ($this->cfg()['expected'] ?? []));
    }

    private function cfg(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        return function_exists('config')
            ? (array) config('storage_sync.mounts', [])
            : [];
    }
}
