<?php

namespace App\Services\MisArchivos;

use App\Models\StorageProvider;
use App\Services\MountGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Dueño de las reglas del vínculo "storage de origen → carpeta del árbol de backup".
 *
 * El módulo de solicitud de backup (`solicitud-backup-mis-archivos`) resuelve el material de
 * producción a partir del par declarado en `storage_providers.backup_storage_id` +
 * `backup_folder_name`. Ese par NO se deduce de nombres: los dos lados no se corresponden
 * (`09 Redmas` ↔ `05_Redmas`, `05 Institucional` ↔ `22_Institucional_BK`, `13_Telecafe_BK` y
 * `13_Telepacifico_Bk` comparten prefijo). Por eso el vínculo se DECLARA, y esta clase es el
 * único lugar donde se valida.
 *
 * Todo lo que esta clase lee del disco pasa antes por `MountGuard`: el árbol de producción es un
 * export NFS en modo `hard` y un `stat()` sobre un montaje caído deja PHP-FPM bloqueado
 * indefinidamente. La contención se verifica POR CADENA, sin `realpath()`.
 */
class BackupMappingService
{
    public const ERROR_SOURCE_NOT_FOUND = 'source_not_found';
    public const ERROR_BACKUP_NOT_FOUND = 'backup_not_found';
    public const ERROR_BACKUP_NOT_LOCAL = 'backup_not_local';
    public const ERROR_BACKUP_WITHOUT_BASE = 'backup_without_base_path';
    public const ERROR_SAME_STORAGE = 'same_storage';
    public const ERROR_SOURCE_INSIDE_TREE = 'source_inside_backup_tree';
    public const ERROR_BACKUP_INSIDE_SOURCE = 'backup_inside_source';
    public const ERROR_INVALID_FOLDER_NAME = 'invalid_folder_name';
    public const ERROR_OUTSIDE_ROOT = 'outside_backup_root';
    public const ERROR_MOUNT_DOWN = 'mount_down';
    public const ERROR_FOLDER_NOT_FOUND = 'folder_not_found';
    public const ERROR_FOLDER_ALREADY_MAPPED = 'folder_already_mapped';

    /** TTL del cache del listado de carpetas del árbol (lectura de NFS). */
    private const FOLDERS_CACHE_TTL = 60;

    private const FOLDERS_CACHE_PREFIX = 'backup.mapping.folders.';

    public function __construct(private readonly MountGuard $mountGuard)
    {
    }

    /**
     * Mensajes legibles de cada error. Los usa el controller (JSON) y la vista.
     *
     * @return array<string,string>
     */
    public static function errorMessages(): array
    {
        return [
            self::ERROR_SOURCE_NOT_FOUND => 'El storage de origen no existe.',
            self::ERROR_BACKUP_NOT_FOUND => 'El storage de destino (árbol de backup) no existe.',
            self::ERROR_BACKUP_NOT_LOCAL => 'El árbol de backup debe ser un storage local.',
            self::ERROR_BACKUP_WITHOUT_BASE => 'El árbol de backup no tiene ruta base.',
            self::ERROR_SAME_STORAGE => 'El storage de origen y el de backup son el mismo.',
            self::ERROR_SOURCE_INSIDE_TREE => 'El storage de origen ya está dentro del árbol de backup; no se mapea.',
            self::ERROR_BACKUP_INSIDE_SOURCE => 'El árbol de backup está dentro del storage de origen.',
            self::ERROR_INVALID_FOLDER_NAME => 'El nombre de la carpeta no es válido: debe ser un único segmento, sin "/" ni "..".',
            self::ERROR_OUTSIDE_ROOT => 'La carpeta quedaría fuera del árbol de backup.',
            self::ERROR_MOUNT_DOWN => 'El montaje de red del árbol de backup está caído: no se puede verificar la carpeta.',
            self::ERROR_FOLDER_NOT_FOUND => 'Esa carpeta no existe en el árbol de backup.',
            self::ERROR_FOLDER_ALREADY_MAPPED => 'Esa carpeta ya está asignada a otro storage de origen.',
        ];
    }

    /**
     * Árbol de backup: storage raíz + carpetas de canal que existen en disco.
     *
     * @param  int|null  $rootId  Storage raíz del árbol. Si es null, se usa el declarado en
     *                             `backups.root_storage_id` o, en su defecto, el destino más
     *                             usado por los mapeos existentes.
     * @param  bool  $fresh  Ignorar el cache del listado en disco.
     * @return array{
     *   root: ?array{id:int,name:string,base_path:string},
     *   mount_down: bool,
     *   folders: list<array{name:string,days:int,last_day:?string,mapped_to:?array{id:int,name:string}}>
     * }
     */
    public function tree(?int $rootId = null, bool $fresh = false): array
    {
        $root = $this->rootStorage($rootId);

        if ($root === null) {
            return ['root' => null, 'mount_down' => false, 'folders' => []];
        }

        $basePath = rtrim((string) $root->base_path, '/');
        $cacheKey = self::FOLDERS_CACHE_PREFIX . $root->id;

        $folders = $fresh
            ? null
            : Cache::get($cacheKey);

        if ($folders === null) {
            if ($this->mountGuard->isNetworkMountDown($basePath)) {
                Log::warning('backup_mapping.mount_down', ['root_id' => $root->id, 'base_path' => $basePath]);

                return [
                    'root' => $this->rootPayload($root),
                    'mount_down' => true,
                    'folders' => [],
                ];
            }

            $folders = $this->scanFolders($basePath);
            Cache::put($cacheKey, $folders, self::FOLDERS_CACHE_TTL);
        }

        $mapped = $this->mappedFoldersByRoot((int) $root->id);

        $out = [];
        foreach ($folders as $folder) {
            $out[] = [
                'name' => $folder['name'],
                'days' => $folder['days'],
                'last_day' => $folder['last_day'],
                'mapped_to' => $mapped[$folder['name']] ?? null,
            ];
        }

        return [
            'root' => $this->rootPayload($root),
            'mount_down' => false,
            'folders' => $out,
        ];
    }

    /**
     * Storage raíz del árbol de backup.
     *
     * Orden de resolución: el que pide el llamador → `backups.root_storage_id` → el destino
     * más usado por los mapeos existentes → null.
     */
    public function rootStorage(?int $rootId = null): ?StorageProvider
    {
        if ($rootId !== null) {
            return StorageProvider::find($rootId);
        }

        $configured = config('backups.root_storage_id');
        if ($configured) {
            $storage = StorageProvider::find((int) $configured);
            if ($storage !== null) {
                return $storage;
            }
        }

        $mostUsedId = StorageProvider::query()
            ->select('backup_storage_id')
            ->whereNotNull('backup_storage_id')
            ->groupBy('backup_storage_id')
            ->orderByRaw('count(*) desc')
            ->value('backup_storage_id');

        return $mostUsedId ? StorageProvider::find((int) $mostUsedId) : null;
    }

    /**
     * Valida una asignación SIN escribir nada.
     *
     * @return array{ok:bool,errors:list<string>,source:?StorageProvider,backup:?StorageProvider,folder:string}
     */
    public function validate(int $sourceId, int $backupStorageId, string $folder): array
    {
        $errors = [];

        $source = StorageProvider::find($sourceId);
        if ($source === null) {
            $errors[] = self::ERROR_SOURCE_NOT_FOUND;
        }

        $backup = StorageProvider::find($backupStorageId);
        if ($backup === null) {
            $errors[] = self::ERROR_BACKUP_NOT_FOUND;
        }

        $folder = trim($folder);

        if ($source !== null && $backup !== null) {
            if ($source->id === $backup->id) {
                $errors[] = self::ERROR_SAME_STORAGE;
            }

            if ($backup->kind !== 'local') {
                $errors[] = self::ERROR_BACKUP_NOT_LOCAL;
            }

            $backupBase = rtrim((string) $backup->base_path, '/');
            $sourceBase = rtrim((string) $source->base_path, '/');

            if ($backupBase === '') {
                $errors[] = self::ERROR_BACKUP_WITHOUT_BASE;
            }

            if ($sourceBase !== '' && $backupBase !== '') {
                if ($this->isPathWithinBase($backupBase, $sourceBase)) {
                    $errors[] = self::ERROR_SOURCE_INSIDE_TREE;
                } elseif ($this->isPathWithinBase($sourceBase, $backupBase)) {
                    $errors[] = self::ERROR_BACKUP_INSIDE_SOURCE;
                }
            }
        }

        if (! $this->isValidFolderName($folder)) {
            $errors[] = self::ERROR_INVALID_FOLDER_NAME;
        }

        if ($backup !== null && $backupBase !== '' && $this->isValidFolderName($folder)) {
            $absolute = $backupBase . '/' . $folder;

            if (! $this->isPathWithinBase($backupBase, $absolute)) {
                $errors[] = self::ERROR_OUTSIDE_ROOT;
            } elseif ($this->mountGuard->isNetworkMountDown($absolute)) {
                $errors[] = self::ERROR_MOUNT_DOWN;
            } elseif (! is_dir($absolute)) {
                $errors[] = self::ERROR_FOLDER_NOT_FOUND;
            } else {
                $other = StorageProvider::query()
                    ->where('backup_storage_id', $backup->id)
                    ->where('backup_folder_name', $folder)
                    ->where('id', '!=', $sourceId)
                    ->first();

                if ($other !== null) {
                    $errors[] = self::ERROR_FOLDER_ALREADY_MAPPED;
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'source' => $source,
            'backup' => $backup,
            'folder' => $folder,
        ];
    }

    /**
     * Declara el vínculo. Valida antes de escribir.
     *
     * @return array{ok:bool,errors:list<string>,storage:?StorageProvider}
     */
    public function assign(int $sourceId, int $backupStorageId, string $folder): array
    {
        $validation = $this->validate($sourceId, $backupStorageId, $folder);

        if (! $validation['ok']) {
            return ['ok' => false, 'errors' => $validation['errors'], 'storage' => null];
        }

        /** @var StorageProvider $source */
        $source = $validation['source'];

        $source->backup_storage_id = $backupStorageId;
        $source->backup_folder_name = $validation['folder'];
        $source->save();

        $this->forgetFoldersCache((int) $backupStorageId);

        Log::info('backup_mapping.assigned', [
            'actor_user_id' => session('user_id'),
            'source_storage_id' => $sourceId,
            'backup_storage_id' => $backupStorageId,
            'backup_folder_name' => $validation['folder'],
        ]);

        return ['ok' => true, 'errors' => [], 'storage' => $source->fresh()];
    }

    /**
     * Quita el vínculo. Es idempotente: quitar a un storage sin vínculo no es un error.
     */
    public function unassign(int $sourceId): bool
    {
        $source = StorageProvider::find($sourceId);
        if ($source === null || $source->backup_storage_id === null) {
            return false;
        }

        $backupStorageId = (int) $source->backup_storage_id;

        $source->backup_storage_id = null;
        $source->backup_folder_name = null;
        $source->save();

        $this->forgetFoldersCache($backupStorageId);

        Log::info('backup_mapping.unassigned', [
            'actor_user_id' => session('user_id'),
            'source_storage_id' => $sourceId,
            'backup_storage_id' => $backupStorageId,
        ]);

        return true;
    }

    /**
     * Cobertura del módulo: qué está mapeado y qué falta.
     *
     * @return array{
     *   folders_total: int,
     *   folders_mapped: int,
     *   folders_orphan: list<string>,
     *   sources_total: int,
     *   sources_mapped: int,
     *   sources_unmapped: int,
     *   broken: list<array{id:int,name:string,reason:string}>,
     *   suggestions: array<string,array{id:int,name:string}>,
     *   mount_down: bool
     * }
     */
    public function coverage(?int $rootId = null): array
    {
        $tree = $this->tree($rootId);

        $folders = $tree['folders'];
        $mapped = [];
        $orphans = [];
        foreach ($folders as $folder) {
            if ($folder['mapped_to'] !== null) {
                $mapped[] = $folder;
            } else {
                $orphans[] = $folder['name'];
            }
        }

        $sources = $this->candidateSources($tree['root']['id'] ?? null);
        $rootId = $tree['root']['id'] ?? null;

        $broken = [];
        $sourcesMapped = 0;
        $sourcesMappedOther = 0;
        foreach ($sources as $source) {
            if ($source->backup_storage_id === null) {
                continue;
            }

            if ($rootId !== null && (int) $source->backup_storage_id !== (int) $rootId) {
                $sourcesMappedOther++;
                continue;
            }

            $sourcesMapped++;

            $link = $source->backupMapping();
            if ($link === null) {
                $broken[] = [
                    'id' => $source->id,
                    'name' => $source->name,
                    'reason' => ! StorageProvider::whereKey($source->backup_storage_id)->exists()
                        ? 'El árbol de backup ya no existe.'
                        : 'El árbol declarado no es local.',
                ];
            }
        }

        return [
            'folders_total' => count($folders),
            'folders_mapped' => count($mapped),
            'folders_orphan' => $orphans,
            'sources_total' => count($sources),
            'sources_mapped' => $sourcesMapped,
            'sources_mapped_other' => $sourcesMappedOther,
            'sources_unmapped' => count($sources) - $sourcesMapped - $sourcesMappedOther,
            'broken' => $broken,
            'suggestions' => $this->suggestions($rootId, $sources),
            'mount_down' => $tree['mount_down'],
        ];
    }

    /**
     * Candidatos a storage de ORIGEN: locales, con ruta base y fuera del árbol de backup.
     *
     * @return Collection<int,StorageProvider>
     */
    public function candidateSources(?int $rootId = null): Collection
    {
        $root = $this->rootStorage($rootId);
        $rootBase = $root !== null ? rtrim((string) $root->base_path, '/') : '';

        return StorageProvider::query()
            ->where('kind', 'local')
            ->whereNotNull('base_path')
            ->whereRaw("rtrim(base_path, '/') <> ''")
            ->orderBy('name')
            ->get()
            ->filter(function (StorageProvider $storage) use ($rootBase) {
                $base = rtrim((string) $storage->base_path, '/');

                return $rootBase === '' || ! $this->isPathWithinBase($rootBase, $base);
            })
            ->values();
    }

    /**
     * Sugerencias de correspondencia storage → carpeta, por token compartido único.
     *
     * Es una AYUDA visual: nunca escribe. Un par dudoso se declara a mano.
     *
     * @param  Collection<int,StorageProvider>|null  $sources
     * @return array<string,array{id:int,name:string}>  carpeta => storage sugerido
     */
    public function suggestions(?int $rootId = null, ?Collection $sources = null): array
    {
        $tree = $this->tree($rootId);
        if ($tree['root'] === null || $tree['mount_down']) {
            return [];
        }

        $sources = $sources ?? $this->candidateSources((int) $tree['root']['id']);
        $sources = $sources->filter(fn (StorageProvider $s) => $s->backup_storage_id === null)->values();

        $folders = array_values(array_filter(
            $tree['folders'],
            fn (array $f) => $f['mapped_to'] === null
        ));
        $folderNames = array_column($folders, 'name');

        if ($sources->isEmpty() || $folderNames === []) {
            return [];
        }

        $sourceTokens = [];
        foreach ($sources as $source) {
            $sourceTokens[$source->id] = $this->tokens($source->name);
        }

        $folderTokens = [];
        foreach ($folderNames as $folder) {
            $folderTokens[$folder] = $this->tokens($folder);
        }

        $out = [];
        $usedFolders = [];
        $usedSources = [];

        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($sources as $source) {
                if (isset($usedSources[$source->id])) {
                    continue;
                }

                $candidates = [];
                foreach ($folderNames as $folder) {
                    if (isset($usedFolders[$folder])) {
                        continue;
                    }

                    $shared = array_intersect($sourceTokens[$source->id], $folderTokens[$folder]);
                    if ($shared === []) {
                        continue;
                    }

                    foreach ($shared as $token) {
                        $folderCount = $this->countToken($token, $folderTokens, $usedFolders);
                        $sourceCount = $this->countToken($token, $sourceTokens, $usedSources);
                        if ($folderCount === 1 && $sourceCount === 1) {
                            $candidates[$folder] = true;
                            break;
                        }
                    }
                }

                if (count($candidates) === 1) {
                    $folder = array_key_first($candidates);
                    $out[$folder] = ['id' => $source->id, 'name' => $source->name];
                    $usedFolders[$folder] = true;
                    $usedSources[$source->id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Carpetas de canal del árbol, leídas DEL DISCO.
     *
     * @return list<array{name:string,days:int,last_day:?string}>
     */
    private function scanFolders(string $basePath): array
    {
        $entries = @scandir($basePath);
        if ($entries === false) {
            return [];
        }

        $folders = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $basePath . '/' . $entry;
            if (! is_dir($path)) {
                continue;
            }

            $days = 0;
            $lastDay = null;

            $children = @scandir($path);
            if ($children !== false) {
                foreach ($children as $child) {
                    if (! preg_match('/^\d{8}$/', $child)) {
                        continue;
                    }
                    $days++;
                    if ($lastDay === null || $this->dayIsNewer($child, $lastDay)) {
                        $lastDay = $child;
                    }
                }
            }

            $folders[] = ['name' => $entry, 'days' => $days, 'last_day' => $lastDay];
        }

        usort($folders, fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return $folders;
    }

    /**
     * Carpeta de backup asignada a un storage de origen, indexada por nombre de carpeta.
     *
     * @return array<string,array{id:int,name:string}>
     */
    private function mappedFoldersByRoot(int $rootId): array
    {
        $rows = StorageProvider::query()
            ->where('backup_storage_id', $rootId)
            ->whereNotNull('backup_folder_name')
            ->get(['id', 'name', 'backup_folder_name']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->backup_folder_name] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $out;
    }

    /**
     * ¿Es un nombre de carpeta válido? Un único segmento, sin separadores ni recorrido de padre.
     */
    private function isValidFolderName(string $folder): bool
    {
        if ($folder === '' || strlen($folder) > 120) {
            return false;
        }
        if (str_contains($folder, '/') || str_contains($folder, '\\')) {
            return false;
        }
        if (str_contains($folder, '..') || str_contains($folder, '%')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $folder)) {
            return false;
        }

        return true;
    }

    /**
     * Contención por cadena. NO usar `realpath()` sobre un path de NFS `hard`.
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

    /**
     * Compara dos días `DDMMYYYY` por fecha real (no lexicográficamente).
     */
    private function dayIsNewer(string $candidate, string $current): bool
    {
        $a = $this->dayToSortKey($candidate);
        $b = $this->dayToSortKey($current);

        return $a !== null && $b !== null && $a > $b;
    }

    private function dayToSortKey(string $day): ?int
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{4})$/', $day, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return ((int) $m[3]) * 10000 + ((int) $m[2]) * 100 + (int) $m[1];
    }

    /**
     * Palabras normalizadas de un nombre: minúsculas, alfanuméricos, sin prefijo numérico
     * ni sufijo `bk`.
     *
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '';

        $out = [];
        foreach (preg_split('/\s+/', trim($normalized)) ?: [] as $token) {
            if ($token === '' || $token === 'bk') {
                continue;
            }
            if (ctype_digit($token)) {
                continue; // prefijo numérico: no corresponde entre los dos lados
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string,list<string>>  $tokens
     * @param  array<string,bool>  $used
     */
    private function countToken(string $token, array $tokens, array $used): int
    {
        $count = 0;
        foreach ($tokens as $key => $set) {
            if (isset($used[$key])) {
                continue;
            }
            if (in_array($token, $set, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{id:int,name:string,base_path:string}|null
     */
    private function rootPayload(StorageProvider $root): array
    {
        return [
            'id' => (int) $root->id,
            'name' => (string) $root->name,
            'base_path' => (string) $root->base_path,
        ];
    }

    private function forgetFoldersCache(int $rootId): void
    {
        Cache::forget(self::FOLDERS_CACHE_PREFIX . $rootId);
    }
}
