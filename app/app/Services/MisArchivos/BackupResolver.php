<?php

namespace App\Services\MisArchivos;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\FileRegistry;
use App\Services\MountGuard;
use App\Services\StorageSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve la carpeta-día del árbol de backup ("Producción") que corresponde
 * a una carpeta-día del storage de origen.
 *
 * Reglas que este servicio sostiene (spec `storage-backup-mapping`):
 *
 *  1. El vínculo NO se deduce de nombres. Los dos lados no se corresponden
 *     (`09 Redmas` ↔ `05_Redmas`, `05 Institucional` ↔ `22_Institucional_BK`).
 *     Se usa el par declarado en `storage_providers.backup_storage_id` +
 *     `backup_folder_name`.
 *
 *  2. La fila del día se busca ANCLADA en el storage de backup declarado.
 *     Nunca por nombre cruzando storages: `files` tiene dos catálogos
 *     paralelos del mismo árbol (el storage 34 con los días recientes y el
 *     storage 5 con días viejos), y una búsqueda por nombre devolvería la
 *     carpeta vieja.
 *
 *  3. La contención se verifica POR CADENA, sin `realpath()`: los exports
 *     NFS son `hard` y un `realpath()` sobre un mount caído bloquea
 *     PHP-FPM indefinidamente.
 *
 *  4. Toda lectura del filesystem pasa antes por `MountGuard`, con el punto de
 *     montaje MÁS ESPECÍFICO que contiene el path: `isNetworkMount()` solo
 *     reconoce el punto exacto y devolvería false para un path profundo.
 *
 *  5. La disponibilidad se verifica EN DISCO, nunca leyendo el catálogo:
 *     el catálogo del árbol de backup está obsoleto (536 carpetas-día
 *     catalogadas frente a 368 en disco).
 *
 *  6. El catálogo no se escribe con SQL: si la fila del día falta, se
 *     materializa por la vía canónica del módulo (`StorageSyncService`).
 */
class BackupResolver
{
    /** El storage de origen no declara carpeta de backup. */
    public const REASON_NO_MAPPING = 'no_mapping';

    /** El nombre de la carpeta no es una fecha `DDMMYYYY`. */
    public const REASON_INVALID_DAY = 'invalid_day';

    /** La ruta resuelta se sale del directorio base del storage de backup. */
    public const REASON_OUTSIDE_ROOT = 'outside_backup_root';

    /** El export NFS está caído: no se puede leer nada. */
    public const REASON_MOUNT_DETACHED = 'mount_detached';

    /** El día aún no existe en el árbol de backup (la producción va con retraso). */
    public const REASON_NOT_READY = 'backup_not_ready';

    /** El día ya salió de la ventana de retención del árbol de backup. */
    public const REASON_EXPIRED_WINDOW = 'backup_expired_window';

    /** Existe en disco pero no se pudo catalogar en `files`. */
    public const REASON_NOT_INDEXED = 'backup_not_indexed';

    public function __construct(
        private readonly MountGuard $mountGuard,
        private readonly FileRegistry $registry,
        private readonly StorageSyncService $sync,
    ) {
    }

    /**
     * ¿El nombre de la carpeta tiene forma de día `DDMMYYYY`?
     *
     * Ocho dígitos, día 01-31, mes 01-12 y año de cuatro dígitos. Además el
     * calendario tiene que admitir la fecha: `31022026` tiene la forma pero
     * no existe, y una carpeta así no puede resolverse a ningún día.
     */
    public function isDayFolderName(string $name): bool
    {
        return $this->parseDayFolder($name) !== null;
    }

    /**
     * Convierte `DDMMYYYY` en la fecha correspondiente (medianoche), o `null`
     * si el nombre no es un día válido.
     */
    public function parseDayFolder(string $name): ?Carbon
    {
        if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', $name, $m)) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day))
            ->startOfDay();
    }

    /**
     * Resuelve la carpeta-día del backup para un storage de origen.
     *
     * @return array{
     *   available: bool,
     *   reason: ?string,
     *   day: string,
     *   date: ?string,
     *   backup_storage_id: ?int,
     *   backup_path: ?string,
     *   relative_path: ?string,
     *   file_id: ?int
     * }
     */
    public function resolveDayFolder(StorageProvider $source, string $dayName): array
    {
        $result = [
            'available' => false,
            'reason' => null,
            'day' => $dayName,
            'date' => null,
            'backup_storage_id' => null,
            'backup_path' => null,
            'relative_path' => null,
            'file_id' => null,
        ];

        $date = $this->parseDayFolder($dayName);
        if ($date === null) {
            $result['reason'] = self::REASON_INVALID_DAY;
            return $result;
        }
        $result['date'] = $date->toDateString();

        $mapping = $source->backupMapping();
        if ($mapping === null) {
            $result['reason'] = self::REASON_NO_MAPPING;
            return $result;
        }

        /** @var StorageProvider $backup */
        $backup = $mapping['storage'];
        $folder = $mapping['folder'];

        $relative = $folder . '/' . $dayName;
        $absolute = rtrim((string) $backup->base_path, '/') . '/' . $relative;

        // Guarda de contención por cadena (NUNCA realpath: ver docblock).
        if (!$this->isPathWithinBase((string) $backup->base_path, $absolute)) {
            Log::warning('backup_request.path_outside_base', [
                'storage_id' => $source->id,
                'backup_storage_id' => $backup->id,
                'absolute' => $absolute,
                'base' => $backup->base_path,
            ]);
            $result['reason'] = self::REASON_OUTSIDE_ROOT;
            return $result;
        }

        $result['backup_storage_id'] = $backup->id;
        $result['backup_path'] = $absolute;
        $result['relative_path'] = $relative;

        // Guardia de montaje ANTES de tocar el filesystem: los exports NFS
        // son `hard` y un stat() colgado deja PHP-FPM en espera indefinida.
        //
        // `isNetworkMountDown()` resuelve el punto de montaje MÁS ESPECÍFICO
        // que contiene el path (`Tcloud/Disco_D`), no el path literal:
        // `MountGuard::isNetworkMount()` solo reconoce el punto exacto y
        // habría devuelto false para un path profundo.
        if ($this->mountGuard->isNetworkMountDown($absolute)) {
            Log::warning('backup_request.mount_detached', [
                'storage_id' => $source->id,
                'backup_storage_id' => $backup->id,
                'absolute' => $absolute,
                'mount_point' => $this->mountGuard->enclosingNetworkMount($absolute),
            ]);
            $result['reason'] = self::REASON_MOUNT_DETACHED;
            return $result;
        }

        // LA DISPONIBILIDAD SE VERIFICA EN DISCO, NUNCA EN EL CATÁLOGO.
        //
        // El catálogo del árbol de backup está obsoleto: 536 carpetas-día
        // catalogadas frente a 368 que existen en disco (168 fantasma, 31 %).
        // Resolver desde `files` entregaría enlaces a carpetas que ya no
        // están, cuyo listado público mostraría archivos que al descargar
        // darían 404.
        if (!@is_dir($absolute)) {
            $result['reason'] = $this->classifyMissingDay($date);
            return $result;
        }

        $file = $this->registry->find($backup->id, $relative);

        if ($file === null) {
            $file = $this->materializeDayFolder($backup, $folder, $dayName);
        }

        if ($file === null) {
            // Existe en disco pero no se pudo catalogar (por ejemplo, la
            // carpeta padre del canal tampoco está en `files`).
            $result['reason'] = self::REASON_NOT_INDEXED;
            return $result;
        }

        $result['file_id'] = $file->id;
        $result['available'] = true;

        return $result;
    }

    /**
     * Materializa la fila de la carpeta-día por la vía canónica del módulo.
     *
     * `files` es tabla soberana de Mis Archivos: solo `StorageSyncService`
     * (vía `FileRegistry::ensure()`) y las acciones de usuario escriben en
     * ella. Este servicio vive dentro de Mis Archivos, así que delegar en el
     * sync es lícito; un INSERT propio no lo sería.
     *
     * Se sincroniza la CARPETA de backup (la que contiene los días), no el
     * storage completo: `Disco_D/backup` tiene 28 subcarpetas y un sync de
     * raíz sobre el NFS es innecesario para materializar un día.
     */
    private function materializeDayFolder(StorageProvider $backup, string $folder, string $dayName): ?File
    {
        $parent = $this->registry->find($backup->id, $folder);
        if ($parent === null) {
            // Sin la fila de la carpeta de backup no hay a quien delegar el
            // sync: `syncFolder()` exige un parent_id del mismo storage.
            return null;
        }

        $this->sync->syncFolder($backup, $parent->id);

        return $this->registry->find($backup->id, $folder . '/' . $dayName);
    }

    /**
     * Distingue "el backup de ese día todavía no llega" de "ese día ya salió
     * de la ventana de retención".
     *
     * El árbol de backup es rodante (~13 carpetas-día): los días pasados que
     * no están ya se fueron; los días de hoy y futuros es que aún no han
     * llegado.
     */
    private function classifyMissingDay(Carbon $date): string
    {
        if ($date->isToday() || $date->isFuture()) {
            return self::REASON_NOT_READY;
        }

        return self::REASON_EXPIRED_WINDOW;
    }

    /**
     * Contención por cadena. Copia deliberadamente de
     * `FilesystemListingService::isPathWithinBase()`: NO llamar `realpath()`
     * sobre un path de NFS `hard`.
     */
    private function isPathWithinBase(string $base, string $absolute): bool
    {
        $baseClean = rtrim($base, '/');
        $targetClean = rtrim($absolute, '/');

        if ($baseClean === '') {
            return false;
        }

        if ($targetClean === $baseClean) {
            return true;
        }

        return str_starts_with($targetClean . '/', $baseClean . '/');
    }
}
