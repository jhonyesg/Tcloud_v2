<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StorageFunnelService
{
    private const CACHE_TTL_SECONDS = 60;
    private const CACHE_KEY_PREFIX = 'transcriptor.funnel.scope.';
    private const CANTIDAD_CACHE_PREFIX = 'transcriptor.cantidad.';
    private const CANTIDAD_CACHE_TTL = 300;
    private const ROOT_ID_CACHE_PREFIX = 'transcriptor.root_id_for.';
    private const ROOT_ID_CACHE_TTL = 600;

    /**
     * Conteos del dia de hoy por storage dentro del scope heredado
     * cuya raiz es $rootId. Devuelve un dict {storageId => {...}}.
     *
     * Delegacion a TodayPendingService (2026-09-15): antes esta query hacia un
     * INNER JOIN contra `transcriptions`, asi que un archivo de hoy SIN fila de
     * transcripcion no aparecia en la columna "Pendientes" del tab Storages. El
     * operador veia "pendientes" solo donde ya habia fila, que es justo lo
     * contrario de lo que necesita: los huecos de discovery (~1.800 archivos)
     * eran invisibles.
     *
     * Hoy el eje es el archivo (`files.file_modified_at` del dia) con LEFT JOIN,
     * y "pendiente" = no tiene `done`. Se conservan las claves historicas
     * `pending`/`done` (la UI las usa) y se agregan los cortes por estado.
     *
     * `pending` mantiene el significado previo para no romper consumidores:
     * todo lo que no esta terminado (incluye huecos, error y dead), que es
     * como el operador piensa la columna.
     *
     * Cache: la maneja TodayPendingService (45 s por scope, invalidable).
     */
    public function countsForScope(int $rootId): array
    {
        $byStorage = app(TodayPendingService::class)->byStorageForScope($rootId);

        $out = [];
        foreach ($byStorage as $sid => $counts) {
            $out[$sid] = [
                // Claves historicas: la tabla de storages y sus agregados.
                'pending' => (int) ($counts['not_done'] ?? 0),
                'done' => (int) ($counts['done'] ?? 0),
                // Desglose nuevo: lo que permite explicar la columna.
                'missing' => (int) ($counts['missing'] ?? 0),
                'queued' => (int) ($counts['queued'] ?? 0),
                'processing' => (int) ($counts['processing'] ?? 0),
                'error' => (int) ($counts['error'] ?? 0),
                'dead' => (int) ($counts['dead'] ?? 0),
                'total' => (int) ($counts['total'] ?? 0),
            ];
        }

        return $out;
    }

    public function invalidate(int $rootId): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $rootId);
        app(TodayPendingService::class)->forget();
        Log::info('transcriptor.funnel.invalidated', ['root_id' => $rootId]);
    }

    /**
     * Sube por la jerarquia de prefijo hasta encontrar el ancestro raiz del
     * scope. La raiz es el storage que NO es descendiente (por base_path) de
     * ningun otro storage habilitado. Si el storage mismo es raiz, devuelve
     * su propio id.
     */
    /**
     * Cantidad de medios del storage: 1 (el storage mismo) + todas las carpetas
     * que existen recursivamente bajo base_path en el filesystem. Esto refleja
     * lo que el operador ve en el navegador de archivos, NO la cantidad de
     * storage_providers hijos registrados en BD.
     *
     * Cache: 5 minutos (la geometria del FS cambia lento).
     */
    public function cantidadFor(int $storageId): int
    {
        return Cache::remember(
            self::CANTIDAD_CACHE_PREFIX . $storageId,
            self::CANTIDAD_CACHE_TTL,
            function () use ($storageId) {
                $storage = StorageProvider::find($storageId);
                if (!$storage || empty($storage->base_path)) {
                    return 1;
                }
                $medioCount = $this->countFoldersRecursive($storage->base_path);
                // Si el storage no tiene sub-medios, el operador lo cuenta como
                // 1 (el storage mismo). Si tiene sub-medios (e.g. "Emisoras 01 Reg"
                // con 34 radios), muestra el conteo de medios, NO el conteo + 1.
                return $medioCount > 0 ? $medioCount : 1;
            }
        );
    }

    private function countFoldersRecursive(string $basePath): int
    {
        // Cantidad de medios = carpetas de "segundo nivel" bajo base_path
        // (las que el operador ve como medios al hacer drill-down desde el
        // navegador de archivos). Las carpetas de "primer nivel" son
        // agrupaciones regionales (Antioquia, Valle_Cauca...) y NO cuentan.
        // Las carpetas dmY (01092026) y sus hijos son buckets temporales de
        // grabacion y tambien se excluyen.
        //
        // Se usa RecursiveCallbackFilterIterator para que las carpetas sin
        // permisos de lectura (papelera .Recycle_bin, etc.) sean SALTADAS
        // en vez de lanzar Permission denied y tumbar todo el conteo.
        $isDayFolder = static function (string $name): bool {
            return (bool) preg_match('/^\d{8}$/', $name);
        };
        if (!is_dir($basePath)) {
            return 0;
        }
        try {
            $baseIter = new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator($baseIter, static function ($current) use ($isDayFolder) {
                try {
                    $name = $current->getFilename();
                    if ($isDayFolder($name)) {
                        return false;
                    }
                    if ($current->isDir() && !$current->isReadable()) {
                        return false;
                    }
                    return true;
                } catch (\Throwable $e) {
                    return false;
                }
            });
            $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth(2);
        } catch (\Throwable $e) {
            return 0;
        }
        $count = 0;
        $baseReal = realpath($basePath) ?: $basePath;
        foreach ($it as $entry) {
            try {
                if (!$entry->isDir()) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            $real = $entry->getPathname();
            $relative = ltrim(substr($real, strlen($baseReal)), '/');
            $slashes = substr_count($relative, '/');
            if ($slashes === 1) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Resuelve el rootId del scope al que pertenece un storage. Cacheado en
     * el store de caché 10 min (ROOT_ID_CACHE_TTL): la geometria del
     * filesystem cambia lento y este helper se llamaba 70+ veces por
     * indexData() sin cache, cada una disparando 2 queries (find + LIKE
     * seq scan). Change 2026-09-12-api-transcriptor-index-perf-cache.
     */
    public function resolveRootIdFor(int $storageId): int
    {
        $cacheKey = self::ROOT_ID_CACHE_PREFIX . $storageId;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }
        $result = $this->computeRootIdFor($storageId);
        Cache::put($cacheKey, $result, self::ROOT_ID_CACHE_TTL);
        return $result;
    }

    private function computeRootIdFor(int $storageId): int
    {
        $current = StorageProvider::find($storageId);
        if (!$current || empty($current->base_path)) {
            return $storageId;
        }

        $currentPath = rtrim($current->base_path, '/');
        $parent = StorageProvider::query()
            ->where('id', '!=', $current->id)
            ->whereNotNull('base_path')
            ->where('base_path', '!=', '')
            ->whereRaw("? LIKE (base_path || '/%')", [$currentPath])
            ->orderByRaw('LENGTH(base_path) DESC')
            ->first();

        return $parent ? (int) $parent->id : (int) $current->id;
    }
}
