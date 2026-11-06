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
     * Mount de red EFECTIVO que contiene al path dado, o null si el
     * filesystem que lo contiene es local.
     *
     * El kernel resuelve el filesystem de un path por el punto de montaje MÁS
     * ESPECÍFICO (prefijo más largo), no por "el primer NFS que aparezca".
     * Si un ext4 está montado DENTRO de un NFS, el path es local y no hay
     * riesgo de bloqueo. Por eso se elige el match más largo y recién después
     * se pregunta si es de red.
     *
     * Hace falta el prefijo y no la coincidencia exacta porque
     * `isNetworkMount()` solo reconoce el punto de montaje literal: para
     * `Tcloud/Disco_D/backup/01_Caracol_BK/05112026` el punto de montaje es
     * `Tcloud/Disco_D`, y el path profundo no coincide con ninguna clave de
     * `/proc/self/mounts`.
     */
    public function enclosingNetworkMount(string $path): ?string
    {
        $best = null;
        $bestLen = -1;

        foreach (array_keys($this->mounts()) as $point) {
            $point = rtrim((string) $point, '/') ?: '/';
            if ($path !== $point && !str_starts_with($path . '/', $point . '/')) {
                continue;
            }
            if (strlen($point) > $bestLen) {
                $best = $point;
                $bestLen = strlen($point);
            }
        }

        if ($best === null) {
            return null;
        }

        return $this->isNetworkMount($best) ? $best : null;
    }

    /**
     * ¿El path cae bajo un montaje de red que NO está montado?
     *
     * Es el guardia que hay que poner ANTES de un `stat()` sobre un export
     * NFS: con política `hard` una llamada al servidor que no responde deja
     * el proceso en espera indefinida. Cuando el montaje se cae, el punto de
     * montaje sigue existiendo como directorio local vacío, así que sin este
     * guardia el `stat()` "tiene éxito" y devuelve "vacío".
     *
     * `isMounted()` consulta primero `/proc/self/mounts` (lectura local, nunca
     * bloquea); el `stat()` de respaldo compara dispositivos.
     */
    public function isNetworkMountDown(string $path): bool
    {
        $mount = $this->enclosingNetworkMount($path);
        if ($mount === null) {
            return false;
        }

        return !$this->isMounted($mount);
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

        // PRIMERO /proc/self/mounts, que es una lectura LOCAL y nunca bloquea.
        // `stat()` sobre un punto de montaje NFS caido SI bloquea (mount en
        // modo `hard`: la syscall espera indefinidamente y no hay timeout que
        // la corte). Medido 2026-09-16: con Disco_D/E/G/H caidos, un `stat`
        // dejaba procesos artisan colgados >90 s y freezeaba el render de
        // /ia/api-transcriptor (StorageFunnelService::cantidadFor recorre el
        // filesystem de 190 storages).
        //
        // Si /proc/self/mounts conoce el path, esa es la respuesta (sin I/O al
        // dispositivo). El `stat` queda SOLO como fallback para paths que no
        // figuran en la tabla de montajes (bind mounts raros, overlayfs).
        if (array_key_exists($path, $this->mounts())) {
            return true;
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
