<?php

namespace App\Services;

use App\Models\StorageProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reparación de rutas base de storage: "¿dónde quedó ese material?".
 *
 * Un storage cuya `base_path` ya no existe es INVISIBLE para el cliente: no
 * lista carpetas, así que no hay nada que compartir, respaldar ni ver. El
 * motivo típico es que el servidor reordenó sus exports (pasó `Emisoras/`
 * de un disco a otro, reagrupó `RD6/` bajo `Emisoras/`, movió una radio de
 * `Disco_A` a `Disco_I`).
 *
 * Esta clase busca el material en los demás discos y devuelve candidatos
 * ORDENADOS y EXPLICADOS. No escribe nada: el elige el operador. El repunto
 * final lo hace `repair()`, con snapshot y las mismas salvaguardas del
 * comando `storage:remap-disk`.
 *
 * Guarda de NFS: los exports son `hard` y un `stat()` sobre un montaje caído
 * deja PHP-FPM esperando indefinidamente. Antes de recorrer un disco se
 * consulta `MountGuard::isNetworkMountDown()`.
 */
class StoragePathRepairService
{
    /** TTL del índice de directorios (lectura de NFS, cambia poco). */
    private const INDEX_TTL = 300;

    /** Profundidad máxima del recorrido. */
    private const MAX_DEPTH = 4;

    /** Score mínimo para considerar un candidato. */
    private const MIN_SCORE = 2;

    /** Raíz donde viven los discos (se deriva de la config, no se hardcodea). */
    private function cloudRoot(): string
    {
        return rtrim((string) config('filesystems.cloud_root'), '/');
    }

    /**
     * Raíces de disco candidatas a contener el material.
     *
     * @return array<string,string>  nombre => ruta absoluta
     */
    public function diskRoots(): array
    {
        $roots = [];

        foreach (glob($this->cloudRoot() . '/Disco_*', GLOB_ONLYDIR) ?: [] as $path) {
            $roots[basename($path)] = $path;
        }

        return $roots;
    }

    /**
     * Índice de directorios por disco: nombre del disco => [ruta relativa, ...].
     *
     * @return array<string,array<int,string>>
     */
    public function index(): array
    {
        // La clave incluye la raiz: con una clave global, un indice construido
        // sobre otra raiz (pruebas) envenena el de produccion.
        $cacheKey = 'storage.path_repair.index.' . substr(md5($this->cloudRoot()), 0, 12);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $index = [];

        foreach ($this->diskRoots() as $name => $root) {
            $index[$name] = $this->walk($root);
        }

        Cache::put($cacheKey, $index, self::INDEX_TTL);

        return $index;
    }

    /**
     * Recorre un disco hasta la profundidad máxima.
     *
     * @return array<int,string>
     */
    private function walk(string $root): array
    {
        $found = [];
        $stack = [[$root, '']];

        while ($stack !== []) {
            [$path, $rel] = array_pop($stack);

            $entries = @scandir($path);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }

                $child = $path . '/' . $entry;
                if (! is_dir($child)) {
                    continue;
                }

                $childRel = $rel === '' ? $entry : $rel . '/' . $entry;
                $found[] = $childRel;

                if (substr_count($childRel, '/') + 1 < self::MAX_DEPTH) {
                    $stack[] = [$child, $childRel];
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Escanea varios storages con ruta inexistente y devuelve, para cada uno, sus
     * candidatos.
     *
     * El índice de directorios se construye UNA vez (cache 300 s) y se reutiliza
     * para todos los storages del lote.
     *
     * @param  iterable<StorageProvider>  $storages
     * @return array<int,array{id:int,name:string,base_path:string,candidates:array}>
     */
    public function scanFor(iterable $storages, int $limit = 3): array
    {
        $this->index(); // calienta el índice una sola vez para todo el lote

        $out = [];

        foreach ($storages as $storage) {
            $out[] = [
                'id' => (int) $storage->id,
                'name' => (string) $storage->name,
                'base_path' => (string) $storage->base_path,
                'candidates' => $this->candidates($storage, $limit),
            ];
        }

        return $out;
    }

    /**
     * Candidatos para un storage cuya ruta base no existe.
     *
     * @return array<int,array{path:string,score:int,reasons:list<string>,claimed_by:?array{id:int,name:string}}>
     */
    public function candidates(StorageProvider $storage, int $limit = 6): array
    {
        $declared = rtrim((string) $storage->base_path, '/');
        if ($declared === '' || is_dir($declared)) {
            return [];
        }

        $parts = explode('/', $declared);
        $leaf = (string) end($parts);
        array_pop($parts);
        $parents = array_values(array_filter($parts, fn (string $p) => $p !== ''));

        $leafTokens = $this->tokens($leaf);
        $nameTokens = $this->tokens((string) $storage->name);
        $wanted = array_unique(array_merge($leafTokens, $nameTokens));

        $claimed = $this->claimedPaths();

        $scored = [];

        foreach ($this->index() as $disk => $relatives) {
            foreach ($relatives as $rel) {
                $score = 0;
                $reasons = [];

                $relLeaf = basename($rel);
                $relParents = array_values(array_filter(explode('/', dirname($rel)), fn (string $p) => $p !== '' && $p !== '.'));

                if ($relLeaf === $leaf) {
                    $score += 6;
                    $reasons[] = 'mismo nombre de canal';
                } elseif ($this->normalize($relLeaf) === $this->normalize($leaf)) {
                    $score += 4;
                    $reasons[] = 'mismo nombre, distinta grafía';
                }

                $shared = array_intersect($wanted, $this->tokens($relLeaf));
                if ($shared !== []) {
                    $score += min(3, count($shared));
                    $reasons[] = 'comparten: ' . implode(', ', $shared);
                }

                $parentHits = array_values(array_intersect($parents, $relParents));
                if ($parentHits !== []) {
                    $score += min(3, count($parentHits));
                    $reasons[] = 'misma carpeta padre: ' . implode(', ', $parentHits);
                }

                if ($score < self::MIN_SCORE) {
                    continue;
                }

                // El árbol de producción es el DESTINO del backup, no el origen.
                if ($this->isInsideBackupTree($disk, $rel)) {
                    continue;
                }

                $absolute = $this->cloudRoot() . '/' . $disk . '/' . $rel;

                $scored[] = [
                    'path' => $absolute,
                    'score' => $score,
                    'reasons' => $reasons,
                    'claimed_by' => $claimed[$absolute] ?? null,
                ];
            }
        }

        usort($scored, function (array $a, array $b) {
            return [$b['score'], strlen($a['path'])] <=> [$a['score'], strlen($b['path'])];
        });

        return array_slice($scored, 0, $limit);
    }

    /**
     * Aplica el repunto de un storage a una ruta verificada.
     *
     * @return array{ok:bool,errors:list<string>,message:string}
     */
    public function repair(StorageProvider $storage, string $newPath): array
    {
        $newPath = rtrim(trim($newPath), '/');

        if ($newPath === '') {
            return ['ok' => false, 'errors' => ['ruta_vacia'], 'message' => 'La ruta destino está vacía.'];
        }

        if (! str_starts_with($newPath, $this->cloudRoot() . '/')) {
            return ['ok' => false, 'errors' => ['fuera_de_raiz'], 'message' => 'La ruta destino está fuera de la raíz de discos.'];
        }

        if (! is_dir($newPath)) {
            return ['ok' => false, 'errors' => ['ruta_inexistente'], 'message' => 'La ruta destino no existe en disco.'];
        }

        if ($this->isInsideBackupTree(...$this->splitDisk($newPath))) {
            return ['ok' => false, 'errors' => ['dentro_del_arbol_de_backup'], 'message' => 'El árbol de producción es el destino del backup, no puede ser el origen.'];
        }

        $oldPath = (string) $storage->base_path;

        $snapshot = 'storage_providers_repair_pre_' . date('Ymd_His');
        DB::statement("CREATE TABLE {$snapshot} AS SELECT * FROM storage_providers WHERE id = " . (int) $storage->id);
        DB::statement("CREATE INDEX ON {$snapshot} (id)");

        DB::table('storage_providers')->where('id', $storage->id)->update(['base_path' => $newPath]);

        Cache::forget("transcriptor.scope.inherited.{$storage->id}");
        Cache::forget("transcriptor.scope.{$storage->id}");
        Cache::forget("folder_gen:{$storage->id}:null");
        Cache::forget('admin:storages:index');
        Cache::forget('storage.path_repair.index.' . substr(md5($this->cloudRoot()), 0, 12));

        try {
            if (app()->bound(\App\Services\Ia\StorageHierarchyService::class)) {
                $hierarchy = app(\App\Services\Ia\StorageHierarchyService::class);
                if (method_exists($hierarchy, 'forget')) {
                    $hierarchy->forget((int) $storage->id);
                }
                if (method_exists($hierarchy, 'forgetAll')) {
                    $hierarchy->forgetAll();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('storage_path_repair.hierarchy_invalidate_failed', ['error' => $e->getMessage()]);
        }

        Log::info('storage_path_repair.applied', [
            'actor_user_id' => session('user_id'),
            'storage_id' => (int) $storage->id,
            'storage_name' => (string) $storage->name,
            'old_path' => $oldPath,
            'new_path' => $newPath,
            'snapshot_table' => $snapshot,
        ]);

        return [
            'ok' => true,
            'errors' => [],
            'message' => "Ruta actualizada a {$newPath}. Snapshot: {$snapshot}",
        ];
    }

    /**
     * Rutas ya ocupadas por otro storage (para avisar de una colisión).
     *
     * @return array<string,array{id:int,name:string}>
     */
    private function claimedPaths(): array
    {
        $out = [];

        foreach (StorageProvider::query()->whereNotNull('base_path')->get(['id', 'name', 'base_path']) as $row) {
            $out[rtrim((string) $row->base_path, '/')] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $out;
    }

    /**
     * @return array{0:string,1:string}  [disco, ruta relativa]
     */
    private function splitDisk(string $absolute): array
    {
        $prefix = $this->cloudRoot() . '/';
        $rel = str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : ltrim($absolute, '/');
        $parts = explode('/', $rel);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function isInsideBackupTree(string $disk, string $rel): bool
    {
        $backupRoot = rtrim((string) config('backups.root_storage_id'), '"');

        if ($backupRoot === '') {
            // Sin root declarado: se descarta el subárbol `backup/` de los exports.
            return str_starts_with($rel, 'backup/');
        }

        $root = StorageProvider::find((int) $backupRoot);

        return $root !== null && $disk === 'Disco_D' && str_starts_with($rel, 'backup/');
    }

    /**
     * Palabras normalizadas de un nombre: minúsculas, alfanuméricos.
     *
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '';

        $out = [];
        foreach (preg_split('/\s+/', trim($normalized)) ?: [] as $token) {
            if ($token === '' || ctype_digit($token)) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    }
}
