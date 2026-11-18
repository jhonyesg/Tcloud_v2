<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProvider extends Model
{
    protected $fillable = ['name', 'type', 'kind', 'config', 'base_path', 'enabled', 'is_accessible', 'last_checked_at', 'transcription_enabled', 'folder_layout', 'allow_parent_overlap', 'is_personal', 'duplicate_of_storage_id', 'merged_at', 'merged_reason', 'backup_storage_id', 'backup_folder_name'];

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

    /**
     * Medios de TV y Radio declarados sobre este storage.
     *
     * Un medio es un índice `(storage_id, subpath)`: el storage puede
     * contener varios canales (layout agrupado por subcarpeta, como
     * `02 Emisoras 01 Reg` → `Antioquia/BLU`). `subpath` vacío significa que
     * el medio ES el storage completo.
     */
    public function medios(): HasMany
    {
        return $this->hasMany(Medio::class, 'storage_id');
    }

    /**
     * Storage que contiene la carpeta de backup declarada para este storage.
     *
     * Es el árbol de "Producción": el export NFS de difusor02 montado en
     * `Tcloud/Disco_D/backup`. La relación se declara en el storage de ORIGEN
     * porque los nombres de los dos lados no se corresponden y el vínculo no
     * se puede deducir.
     */
    public function backupStorage(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'backup_storage_id');
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
        // El set y el mapa de hijos en memoria alimentan el BFS del scope: si
        // no se limpian, una mutación de `base_path`/`transcription_enabled` en
        // el mismo request seguiría viendo la jerarquía vieja.
        static::$scopeSetCache = null;
        static::$scopeChildrenCache = null;
        static::$scopeMemo = [];
        // El TTL NO se limpia: no depende de esta mutación. Si se limpiara,
        // cada toggle pagaría una lectura extra a `system_settings`.

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
     * `id => [base_path, transcription_enabled]` de TODOS los storages, cargado
     * UNA vez por proceso.
     *
     * Por qué existe: `resolveInheritedTranscriptionScope()` corría el BFS con
     * un `LIKE` por nodo visitado. `/ia/api-transcriptor` la invoca 2× por cada
     * uno de los 186 storages, y como 13 storages tienen `base_path` vacío el
     * prefijo les queda `/` y matchean el universo entero: 2.394 queries y
     * ~1,9 s por render (medido 2026-11-18).
     *
     * Se guardan arrays planos y NO modelos Eloquent: leer `$model->base_path`
     * 35.000 veces cuesta 533 ms; con arrays son 2 ms (mismo predicado).
     *
     * Invalidación: cualquier mutación de `base_path` o `transcription_enabled`
     * llama a `forgetInheritedTranscriptionScope()`, que limpia esto.
     *
     * @var array<int,array{0:string,1:bool}>|null
     */
    private static ?array $scopeSetCache = null;

    /**
     * Mapa `parent_id => [child_id, ...]` derivado del prefijo de `base_path`,
     * armado UNA vez por proceso.
     *
     * @var array<int,list<int>>|null
     */
    private static ?array $scopeChildrenCache = null;

    /**
     * Scope ya resuelto por root, para que la segunda invocación del mismo
     * storage (el controller la hace dos veces) no repita el BFS.
     *
     * @var array<int,list<int>>
     */
    private static array $scopeMemo = [];

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
        $ttl = static::scopeCacheTtl();

        // TTL 0 = BYPASS (freno de emergencia): no se cachea nada, ni en Redis
        // ni en memoria, para que una mutación directa en BD (harness, tinker,
        // seeder) se vea en la siguiente llamada.
        if ($ttl === 0) {
            return static::computeInheritedTranscriptionScope($rootId);
        }

        if (isset(static::$scopeMemo[$rootId])) {
            return static::$scopeMemo[$rootId];
        }

        $key = 'transcriptor.scope.inherited.' . $rootId;

        $scope = \Illuminate\Support\Facades\Cache::remember(
            $key,
            $ttl,
            fn () => static::computeInheritedTranscriptionScope($rootId)
        );

        // Redis devuelve el array tal cual lo serializamos; se normaliza a
        // `int[]` porque un driver puede devolver strings.
        $scope = array_map('intval', (array) $scope);

        return static::$scopeMemo[$rootId] = $scope;
    }

    /**
     * BFS puro sobre el set en memoria (sin cache). Es la parte que la cache
     * protege: con el mapa de hijos precomputado cuesta ~0,05 ms.
     *
     * @return list<int>
     */
    private static function computeInheritedTranscriptionScope(int $rootId): array
    {
        if (!isset(static::scopeSet()[$rootId])) {
            return [];
        }

        $children = static::scopeChildren();

        $visited = [$rootId => true];
        $order = [$rootId];
        $queue = [$rootId];

        while (!empty($queue)) {
            $current = array_shift($queue);

            foreach ($children[$current] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $order[] = $childId;
                $queue[] = $childId;
            }
        }

        return $order;
    }

    /**
     * TTL de la cache del scope, en segundos. `0` = bypass.
     *
     * Se lee de `SystemSetting('transcriptor_scope_cache_ttl')` (default 300,
     * rango válido 0..3600). El valor se memoiza en Redis 60 s para no pegarle
     * a `system_settings` en cada resolución, y en una propiedad estática para
     * no pagar ni eso dentro del mismo request.
     */
    private static function scopeCacheTtl(): int
    {
        // Sin memo estático: `Cache::forget('transcriptor.scope.ttl')` es el
        // mecanismo documentado para forzar la relectura del setting, y un
        // memo estático lo volvería inoperante hasta el próximo request.
        // El costo es un GET a Redis (sub-ms); la lectura a `system_settings`
        // sí queda memoizada 60 s en esa key.
        $raw = \Illuminate\Support\Facades\Cache::get('transcriptor.scope.ttl');

        if ($raw === null) {
            $raw = \App\Models\SystemSetting::get('transcriptor_scope_cache_ttl');
            \Illuminate\Support\Facades\Cache::put('transcriptor.scope.ttl', $raw ?? '300', 60);
        }

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return 300;
        }

        $ttl = (int) $raw;

        return (($ttl < 0 || $ttl > 3600) ? 300 : $ttl);
    }

    /**
     * Carga (una vez por proceso) las columnas mínimas que necesita el BFS del
     * scope heredado, como arrays planos indexados por id.
     *
     * @return array<int,array{0:string,1:bool}>
     */
    private static function scopeSet(): array
    {
        if (static::$scopeSetCache !== null) {
            return static::$scopeSetCache;
        }

        $set = [];
        foreach (static::query()->get(['id', 'base_path', 'transcription_enabled']) as $row) {
            $set[(int) $row->id] = [(string) $row->base_path, (bool) $row->transcription_enabled];
        }

        return static::$scopeSetCache = $set;
    }

    /**
     * Arma (una vez por proceso) el mapa de hijos por prefijo de `base_path`.
     *
     * Es exactamente el predicado de la consulta anterior
     * (`id != parent AND transcription_enabled AND base_path LIKE '<base>/%'`),
     * comparado en memoria. El BFS después es O(aristas), no O(n²) por root.
     *
     * @return array<int,list<int>>
     */
    private static function scopeChildren(): array
    {
        if (static::$scopeChildrenCache !== null) {
            return static::$scopeChildrenCache;
        }

        $set = static::scopeSet();
        $children = [];

        // Sólo los storages con transcripción encendida pueden ser hijo: el
        // predicado original filtraba por `transcription_enabled = true`.
        $candidates = [];
        foreach ($set as $id => [$base, $enabled]) {
            if ($enabled) {
                $candidates[$id] = $base;
            }
        }

        foreach ($set as $parentId => [$parentBase, $_]) {
            $prefix = rtrim($parentBase, '/') . '/';

            foreach ($candidates as $childId => $childBase) {
                if ($childId === $parentId || !str_starts_with($childBase, $prefix)) {
                    continue;
                }
                $children[$parentId][] = $childId;
            }
        }

        return static::$scopeChildrenCache = $children;
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

    /**
     * Vínculo declarado con la carpeta de backup de este storage, o `null` si
     * no aplica.
     *
     * Devuelve `null` —es decir, "este storage no tiene backup"— cuando:
     *  - no se declaró `backup_storage_id` o `backup_folder_name`;
     *  - el storage de backup declarado ya no existe (fue borrado);
     *  - el storage de backup declarado no es local: leer un backend remoto
     *    por este camino no está soportado y no debe intentarse.
     *
     * @return array{storage: StorageProvider, folder: string}|null
     */
    public function backupMapping(): ?array
    {
        if ($this->backup_storage_id === null) {
            return null;
        }

        $folder = trim((string) $this->backup_folder_name, '/');
        if ($folder === '') {
            return null;
        }

        $backup = $this->relationLoaded('backupStorage')
            ? $this->backupStorage
            : $this->backupStorage()->first();

        if ($backup === null) {
            return null;
        }

        // `kind` es la columna canónica (el `type` es legacy). Un storage que
        // no sea local no se puede leer por ruta.
        if ($backup->kind !== 'local') {
            return null;
        }

        if (trim((string) $backup->base_path, '/') === '') {
            return null;
        }

        return ['storage' => $backup, 'folder' => $folder];
    }

    /**
     * ¿Este storage declara un vínculo utilizable con su carpeta de backup?
     */
    public function hasBackupMapping(): bool
    {
        return $this->backupMapping() !== null;
    }
}