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

    /**
     * Conteos del dia de hoy por storage dentro del scope heredado
     * cuya raiz es $rootId. Devuelve un dict {storageId => {pending, done}}.
     *
     * "Hoy" se mide como `transcriptions.created_at >= today_start`. Solo
     * dos categorias: `pending` (no terminado) y `done` (terminado OK).
     * `dead` cuenta como pending (no se va a procesar solo) hasta que un
     * operador lo rescate via backfill.
     *
     * Cache: 60 segundos por scope. Invalidar con invalidate($rootId).
     */
    public function countsForScope(int $rootId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $rootId;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($rootId) {
            return $this->computeScopeCounts($rootId);
        });
    }

    /**
     * Query agregada unica sobre todos los storages del scope (root +
     * descendientes habilitados), agrupada por storage_provider_id y state.
     * Devuelve dict compartido: cada storage_id apunta al MISMO array con
     * las dos categorias del funnel diario.
     */
    private function computeScopeCounts(int $rootId): array
    {
        $scopeIds = StorageProvider::resolveInheritedTranscriptionScope($rootId);
        if (empty($scopeIds)) {
            return [];
        }

        // "Hoy" se mide por la fecha en America/Bogota (app.timezone), NO por UTC.
        // Postgres almacena created_at en UTC, pero la pregunta del operador es
        // "archivos del dia actual en mi horario local". La conversion se hace
        // en SQL con AT TIME ZONE para no perder filas en el borde de medianoche.
        $todayDate = now()->format('Y-m-d');

        $rows = DB::table('files')
            ->join('transcriptions', 'transcriptions.file_id', '=', 'files.id')
            ->whereIn('files.storage_provider_id', $scopeIds)
            ->where('files.is_folder', false)
            ->where('files.is_trashed', false)
            ->whereNull('files.deleted_at')
            ->whereRaw("(transcriptions.created_at AT TIME ZONE 'America/Bogota')::date = ?", [$todayDate])
            ->select(
                'files.storage_provider_id',
                'transcriptions.state',
                DB::raw('COUNT(*) AS cnt')
            )
            ->groupBy('files.storage_provider_id', 'transcriptions.state')
            ->get();

        $byStorage = [];
        foreach ($scopeIds as $sid) {
            $byStorage[$sid] = ['pending' => 0, 'done' => 0];
        }

        foreach ($rows as $r) {
            $sid = (int) $r->storage_provider_id;
            if (!isset($byStorage[$sid])) {
                continue;
            }
            if ($r->state === 'done') {
                $byStorage[$sid]['done'] += (int) $r->cnt;
            } else {
                $byStorage[$sid]['pending'] += (int) $r->cnt;
            }
        }

        return $byStorage;
    }

    public function invalidate(int $rootId): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $rootId);
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

    public function resolveRootIdFor(int $storageId): int
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
