<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProvider extends Model
{
    protected $fillable = ['name', 'type', 'kind', 'config', 'base_path', 'enabled', 'is_accessible', 'last_checked_at', 'transcription_enabled', 'folder_layout', 'allow_parent_overlap', 'is_personal', 'duplicate_of_storage_id', 'merged_at', 'merged_reason'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'is_accessible' => 'boolean',
        'last_checked_at' => 'datetime',
        'transcription_enabled' => 'boolean',
        'folder_layout' => 'string',
        'allow_parent_overlap' => 'boolean',
        'is_personal' => 'boolean',
    ];

    public function userStorages(): HasMany
    {
        return $this->hasMany(UserStorage::class, 'storage_provider_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function scopeTranscriptionEnabled(Builder $query): Builder
    {
        return $query->where('transcription_enabled', true);
    }

    /**
     * Busca un storage por (kind, physical_path_normalized), EXCLUYENDO los
     * que estan marcados como mergeados (`duplicate_of_storage_id IS NOT NULL`).
     *
     * El indice parcial `storage_providers_physical_path_normalized_idx`
     * (`btree (kind, physical_path_normalized) WHERE physical_path_normalized
     * IS NOT NULL`) cubre el WHERE; el ORDER BY id solo se aplica cuando hay
     * varios candidatos (escenario de duplicado pre-merge).
     *
     * Usado por `StorageProviderController::store/update` para bloquear
     * HTTP 409 cuando se intenta crear/mover un storage a un base_path que
     * ya tiene un storage canonico (no mergeado).
     *
     * @return self|null
     */
    public static function findByNormalizedPath(string $normalizedPath, string $kind = 'local'): ?self
    {
        if ($normalizedPath === '') {
            return null;
        }
        return static::query()
            ->where('kind', $kind)
            ->where('physical_path_normalized', $normalizedPath)
            ->whereNull('duplicate_of_storage_id')
            ->orderBy('id')
            ->first();
    }

    /**
     * Variante canonica de `findByNormalizedPath`: ante varios storages no
     * mergeados con el mismo path, devuelve el de menor `id` (insertion
     * order). Usado por el reconciler de identidad fisica para resolver
     * ambiguedad de forma estable.
     *
     * @return self|null
     */
    public static function canonicalFor(string $normalizedPath, string $kind = 'local'): ?self
    {
        return static::findByNormalizedPath($normalizedPath, $kind);
    }

    /**
     * Invalida la cache de `resolveInheritedTranscriptionScope` para el rootId
     * dado. Llamado por:
     *  - `StorageProviderController::update` cuando se modifica `base_path` y
     *    cambia el root efectivo del storage.
     *  - `StorageProviderController::store/destroy` (via recomputeHierarchy).
     *  - `ApiTranscriptorController::toggleStorage` (cambia `transcription_enabled`).
     *
     * Si el $rootId es null (storage sin ancestro asignado), limpia todas las
     * keys de scope cache.
     */
    public static function forgetInheritedTranscriptionScope(?int $rootId): void
    {
        $cache = \Illuminate\Support\Facades\Cache::store();
        if ($rootId === null) {
            // No hay un patron "por prefijo" en cache; lo mas seguro es no
            // hacer nada. Quien invalide sin root conoce su ambito y debe
            // pasar IDs explicitos.
            return;
        }
        // La key de cache sigue el patron "transcriptor.scope.inherited.{id}".
        // TTL configurable via `transcriptor_scope_cache_ttl` (default 300s).
        $cache->forget("transcriptor.scope.inherited.{$rootId}");
        $cache->forget("transcriptor.scope.inherited.info.{$rootId}");
    }

    /**
     * Retorna el set de storage IDs que forman el scope "virtual" de
     * transcripciones: el storage root más todos sus descendientes con
     * transcription_enabled=true, recursivamente.
     *
     * Un descendiente es cualquier storage cuyo base_path comience con
     * parent.base_path + '/'. Esto preserva la invariante de que la
     * jerarquía se deriva del filesystem real, no de una columna FK.
     *
     * Esta capa es SOLO LECTURA: el scanner y el tick siguen trabajando
     * con storages reales. El helper existe para que las consultas de la
     * UI (ej. storageFiles) puedan mostrar las transcripciones de los
     * hijos como si estuvieran bajo el padre.
     *
     * @param  int $rootId  ID del storage root
     * @return array<int>   IDs ordenados (root primero, descendientes después)
     */
    public static function resolveInheritedTranscriptionScope(int $rootId): array
    {
        $root = static::find($rootId);
        if (!$root) {
            return [];
        }

        $visited = [$rootId => true];
        $order = [$rootId];
        $queue = [$root];

        while (!empty($queue)) {
            $current = array_shift($queue);
            $basePrefix = rtrim($current->base_path, '/') . '/';

            $descendants = static::query()
                ->where('id', '!=', $current->id)
                ->where('transcription_enabled', true)
                ->where('base_path', 'LIKE', $basePrefix . '%')
                ->get(['id', 'base_path']);

            foreach ($descendants as $desc) {
                if (isset($visited[$desc->id])) {
                    continue;
                }
                $visited[$desc->id] = true;
                $order[] = $desc->id;
                $queue[] = $desc;
            }
        }

        return $order;
    }

    /**
     * Retorna información estructurada del scope heredado, útil para la UI:
     * - self: datos del storage root
     * - descendants: lista de storages hijos con TX activa
     * - storage_ids: array plano de IDs (root + descendants)
     *
     * @return array{self: ?StorageProvider, descendants: \Illuminate\Support\Collection, storage_ids: array<int>}
     */
    public static function inheritedTranscriptionScopeInfo(int $rootId): array
    {
        $root = static::find($rootId);
        if (!$root) {
            return ['self' => null, 'descendants' => collect(), 'storage_ids' => []];
        }

        $ids = static::resolveInheritedTranscriptionScope($rootId);
        $descendantIds = array_values(array_diff($ids, [$rootId]));

        $descendants = empty($descendantIds)
            ? collect()
            : static::whereIn('id', $descendantIds)->orderBy('name')->get();

        return [
            'self' => $root,
            'descendants' => $descendants,
            'storage_ids' => $ids,
        ];
    }

    /**
     * Username canonico del storage personal, derivado del ultimo segmento de
     * `base_path` cuando el storage es `is_personal=true`.
     *
     * Formato esperado: `/home/www/Usuarios_tcloud/<username>/...` o
     * `/home/www/Usuarios_tcloud/<username>` (sin slash final). Devuelve el
     * username SIN prefijos, sufijos ni extensiones para que case con
     * `users.username` directamente.
     *
     * Devuelve `null` si el storage no es personal o si `base_path` no tiene
     * un segmento final identificable.
     */
    public function personalCanonicalUsername(): ?string
    {
        $personalFlag = (bool) ($this->is_personal ?? false);
        $pathDerivation = is_string($this->base_path ?? null)
            && str_starts_with(rtrim($this->base_path, '/'), '/home/www/Usuarios_tcloud/');

        if (!$personalFlag && !$pathDerivation) {
            return null;
        }

        $path = rtrim((string) $this->base_path, '/');
        $username = basename($path);

        return $username === '' || $username === '/' ? null : $username;
    }

    /**
     * Determina si el storage personal le pertenece al usuario dado.
     *
     * Si el storage no es personal, devuelve `true` (no aplica la regla de
     * exclusividad). Si es personal, devuelve `true` solo cuando el `username`
     * del usuario coincide con el segmento canonico del base_path.
     */
    public function isOwnedBy(User $user): bool
    {
        $canonical = $this->personalCanonicalUsername();
        if ($canonical === null) {
            return true;
        }
        return $canonical === (string) $user->username;
    }

    /**
     * No-op defensivo: invalida la cache del "owner canonico" de un storage.
     *
     * El helper `canonicalOwnerId` (y su cache) fueron retirados en el change
     * `restore-mis-archivos-august`, pero el observer `UserStorageObserver`
     * conserva esta llamada como conservadora. La cache ya no existe, asi que
     * basta con no fallar. Si en el futuro se restaura el helper de cache,
     * reimplementar aqui.
     */
    public static function forgetCanonicalOwnerCache(int $storageId): void
    {
        // No-op: la cache fue retirada. Ver UserStorageObserver.php docblock.
        unset($storageId);
    }
}