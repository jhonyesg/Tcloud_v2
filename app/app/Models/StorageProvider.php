<?php

namespace App\Models;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StorageProvider extends Model
{
    /**
     * Invalida el memo de jerarquia cuando cambia una fila que participa en el
     * parentesco o en la elegibilidad.
     *
     * Se engancha en el MODELO (no en cada call site) para que ninguna via de
     * escritura olvide invalidar: `update()` directo, `forceFill()->saveQuietly()`
     * de StorageHierarchyService, seeder, tinker, etc. Un memo stale aqui hace
     * que `ownerOf()` devuelva el dueño viejo tras un toggle (lo detecto el
     * harness `harness_transcriptor_physical_identity.php`).
     *
     * Nota: `saveQuietly()` NO dispara eventos del modelo, asi que
     * StorageHierarchyService llama `flushScopeMemo()` explicitamente ademas
     * de este hook.
     */
    protected static function booted(): void
    {
        static::saved(static function (self $storage) {
            if ($storage->wasChanged(['parent_storage_id', 'transcription_enabled', 'base_path'])) {
                static::flushScopeMemo();
            }
        });

        static::deleted(static function () {
            static::flushScopeMemo();
        });
    }

    protected $fillable = ['name', 'type', 'config', 'base_path', 'parent_storage_id', 'enabled', 'is_accessible', 'last_checked_at', 'transcription_enabled', 'folder_layout', 'is_personal', 'kind', 'duplicate_of_storage_id', 'merged_at', 'merged_reason'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'is_accessible' => 'boolean',
        'last_checked_at' => 'datetime',
        'transcription_enabled' => 'boolean',
        'folder_layout' => 'string',
        'is_personal' => 'boolean',
        'kind' => 'string',
        'parent_storage_id' => 'integer',
        'duplicate_of_storage_id' => 'integer',
        'merged_at' => 'datetime',
    ];

    /**
     * @property int|null $duplicate_of_storage_id FK self-ref (ON DELETE SET NULL) que apunta al storage canónico cuando este row es un duplicado físico.
     * @property \Carbon\Carbon|null $merged_at
     * @property string|null $merged_reason
     *
     * Schema clarity: ver `app/AGENTS.md § Schema clarity policy`. Esta columna se llama
     * `duplicate_of_storage_id` (no `merged_into_id`) para reflejar sin ambigüedad que
     * marca storages que son duplicados físicos de otro, no folders espejo.
     */

    /**
     * Cache del scope heredado de StorageProvider::resolveInheritedTranscriptionScope().
     *
     * - PREFIX: prefijo de clave en el cache store default (Redis en produccion).
     * - TTL_DEFAULT: 5 min. Configurable via SystemSetting('transcriptor_scope_cache_ttl').
     *                Valor 0 = bypass (freno de emergencia operativo).
     * - El rango valido es [0, 3600] segundos (ver resolveInheritedTranscriptionScope).
     *
     * Change: 2026-09-12-api-transcriptor-index-perf-cache. Antes de mutar
     * storage_providers.base_path o transcription_enabled, llamar
     * StorageProvider::forgetInheritedTranscriptionScope($rootId).
     */
    private const SCOPE_CACHE_PREFIX = 'transcriptor.scope.inherited.';
    private const SCOPE_CACHE_TTL_DEFAULT = 300;
    private const SCOPE_CACHE_TTL_MIN = 0;
    private const SCOPE_CACHE_TTL_MAX = 3600;

    public function userStorages(): HasMany
    {
        return $this->hasMany(UserStorage::class, 'storage_provider_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * Storage padre inmediato (jerarquia persistida, migracion
     * 2026_09_16_200000). Reemplaza la deduccion por LIKE de base_path.
     */
    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_storage_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_storage_id');
    }

    /**
     * Storage canónico al que este storage fue mergeado (Stage 2 del change
     * `storage-physical-path-normalization`). NULL = storage vivo.
     */
    public function mergedInto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_storage_id');
    }

    /**
     * Storages mergeados HACIA este storage (los duplicados que apuntan al
     * canonical). Usado por `MergeDuplicatesCommand` para listar descendientes.
     */
    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_storage_id');
    }

    /**
     * True si este storage es un duplicado físico de otro canónico
     * (`duplicate_of_storage_id IS NOT NULL`).
     * Los storages duplicados quedan invisibles al sync (ver
     * `StorageSyncService::findMoreSpecificStorage`).
     *
     * Ver convención de naming en AGENTS.md § Schema clarity policy.
     */
    public function isDuplicate(): bool
    {
        return $this->duplicate_of_storage_id !== null;
    }

    /**
     * Alias deprecado de isDuplicate() conservado por un ciclo de release.
     * Migración: cambiar todos los call sites a isDuplicate() y eliminar.
     */
    public function isMerged(): bool
    {
        return $this->isDuplicate();
    }

    /**
     * Busca el storage canónico para un path normalizado y un kind dados.
     * Filtra storages duplicados: si 142 fue mergeado en 132, solo 132 retorna.
     *
     * Stage 1 de `storage-physical-path-normalization`. Antes del merge, si
     * hay múltiples storages con el mismo path, retorna el de menor id (el
     * "primer" creado). Tras el merge, retorna el canonical.
     */
    public static function findByNormalizedPath(string $normalized, string $kind): ?self
    {
        return static::query()
            ->whereNull('duplicate_of_storage_id')
            ->where('kind', $kind)
            ->where('physical_path_normalized', $normalized)
            ->orderBy('id')
            ->first();
    }

    /**
     * Elige el canonical entre múltiples storages que comparten el mismo
     * `physical_path_normalized`. Algoritmo (design D4):
     *
     *   1. Filtrar storages mergeados.
     *   2. Contar transcripciones linkeadas vía `files.storage_provider_id`.
     *      El storage con más TX es el activo operacional — es el que el
     *      usuario ha estado usando y donde vive su historial.
     *   3. Tie-break: id menor (determinista).
     *
     * Devuelve null si no hay candidatos. Usado por
     * `MergeDuplicatesCommand` para sugerir el canonical cuando el operador
     * no pasa `--canonical` explícitamente.
     */
    public static function canonicalFor(string $normalized, string $kind): ?self
    {
        $candidates = static::query()
            ->whereNull('duplicate_of_storage_id')
            ->where('kind', $kind)
            ->where('physical_path_normalized', $normalized)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Score por # de transcripciones linkeadas
        $scores = [];
        foreach ($candidates as $c) {
            $scores[$c->id] = (int) DB::table('files as f')
                ->join('transcriptions as t', 't.file_id', '=', 'f.id')
                ->where('f.storage_provider_id', $c->id)
                ->count();
        }

        $maxScore = max($scores);
        $winners = $candidates->filter(fn ($c) => $scores[$c->id] === $maxScore);

        // Tie-break: id menor
        return $winners->sortBy('id')->first();
    }

    public function scopeTranscriptionEnabled(Builder $query): Builder
    {
        return $query->where('transcription_enabled', true);
    }

    /**
     * Retorna el set de storage IDs que forman el scope "virtual" de
     * transcripciones: el storage root más todos sus descendientes con
     * transcription_enabled=true, recursivamente.
     *
     * Wrapper cacheado de computeInheritedTranscriptionScope(). Cache:
     * 5 min default, configurable via SystemSetting('transcriptor_scope_cache_ttl').
     * Bypass con valor 0 (freno de emergencia).
     *
     * Antes de mutar storage_providers.base_path o transcription_enabled,
     * llamar StorageProvider::forgetInheritedTranscriptionScope($rootId).
     *
     * Change: 2026-09-12-api-transcriptor-index-perf-cache.
     */
    /**
     * Memo por proceso del mapa de jerarquia. Ver hierarchyChildrenMap().
     *
     * @var array<int,list<StorageProvider>>|null
     */
    private static ?array $hierarchyChildrenMapMemo = null;

    public static function resolveInheritedTranscriptionScope(int $rootId): array
    {
        $ttl = self::resolveScopeCacheTtl();
        if ($ttl === 0) {
            return static::computeInheritedTranscriptionScope($rootId);
        }

        return Cache::remember(
            self::SCOPE_CACHE_PREFIX . $rootId,
            $ttl,
            fn () => static::computeInheritedTranscriptionScope($rootId)
        );
    }

    /**
     * BFS recursivo del scope heredado. Logica original movida aqui sin
     * cambios para mantener la compatibilidad exacta. Cambio solo en la
     * capa de cache (resolveInheritedTranscriptionScope).
     *
     * @param  int $rootId  ID del storage root
     * @return array<int>   IDs ordenados (root primero, descendientes después)
     */
    private static function computeInheritedTranscriptionScope(int $rootId): array
    {
        // Recorre la jerarquia PERSISTIDA (parent_storage_id, migracion
        // 2026_09_16_200000) en vez de comparar base_path con LIKE. Medido
        // 2026-09-16: ambos criterios producen el MISMO conjunto en los 70
        // storages habilitados (0 diferencias), asi que el cambio es de
        // fuente de verdad, no de comportamiento.
        //
        // El scope incluye al root + todos sus descendientes recursivos con
        // transcription_enabled. Un descendiente apagado NO se incluye (no
        // aporta transcripciones) pero TAMPOCO corta la recursion: sus hijos
        // habilitados si aportan.
        //
        // CARGA MASIVA + MEMOIZACION:
        //  - antes: 1 query por nodo visitado (`where('parent_storage_id',
        //    $currentId)->get()` dentro del while) -> ~600 queries por corrida;
        //  - despues: 1 query de la tabla completa por LLAMADA. Con 190
        //    storages × 2 loops en `indexData()` seguian siendo 380 queries y
        //    ~6.6 s en frio (medido 2026-09-17);
        //  - ahora: la tabla se lee UNA vez por proceso y el mapa de hijos se
        //    reutiliza en todas las llamadas. El memo se invalida con
        //    `forgetInheritedTranscriptionScope()`/`flushScopeMemo()`, que es
        //    lo que llaman los mutadores de `transcription_enabled` y
        //    `base_path`.
        $childrenByParent = static::hierarchyChildrenMap();

        $visited = [$rootId => true];
        $order = [$rootId];
        $queue = [$rootId];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            foreach ($childrenByParent[$currentId] ?? [] as $child) {
                $childId = (int) $child->id;

                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $queue[] = $childId;

                if ($child->transcription_enabled) {
                    $order[] = $childId;
                }
            }
        }

        return $order;
    }

    /**
     * Invalida la cache del scope de un root especifico. Idempotente.
     * Llamar este metodo en cualquier mutacion de base_path o
     * transcription_enabled del root o sus descendientes.
     */
    public static function forgetInheritedTranscriptionScope(int $rootId): void
    {
        Cache::forget(self::SCOPE_CACHE_PREFIX . $rootId);
        self::flushScopeMemo();
    }

    /**
     * Mapa `parent_id => [StorageProvider, ...]` de TODA la jerarquia,
     * memoizado por proceso.
     *
     * Por que: `computeInheritedTranscriptionScope()` arma este mapa para cada
     * root, y en `indexData()` se invoca 190 veces. Sin memo, cada llamada
     * hacia `SELECT id, parent_storage_id, transcription_enabled FROM
     * storage_providers` completa: 380 queries y ~6.6 s en frio (2026-09-17).
     *
     * El memo vive en un `static` y se limpia con `flushScopeMemo()`. La
     * jerarquia cambia solo en mutaciones explicitas (toggle de
     * transcription_enabled, alta/edicion de base_path), y todas pasan por
     * `forgetInheritedTranscriptionScope()` o `flushScopeMemo()`.
     *
     * @return array<int,list<StorageProvider>>
     */
    private static function hierarchyChildrenMap(): array
    {
        if (self::$hierarchyChildrenMapMemo === null) {
            $map = [];
            foreach (static::query()->get(['id', 'parent_storage_id', 'transcription_enabled']) as $r) {
                if ($r->parent_storage_id !== null) {
                    $map[(int) $r->parent_storage_id][] = $r;
                }
            }
            self::$hierarchyChildrenMapMemo = $map;
        }

        return self::$hierarchyChildrenMapMemo;
    }

    /**
     * Limpia el memo del mapa de jerarquia. Debe llamarse desde cualquier
     * mutacion de `parent_storage_id` o `transcription_enabled`.
     */
    public static function flushScopeMemo(): void
    {
        self::$hierarchyChildrenMapMemo = null;
    }

    /**
     * Lee el TTL configurado via SystemSetting('transcriptor_scope_cache_ttl').
     * Si no esta seteado, usa SCOPE_CACHE_TTL_DEFAULT (5 min).
     * Acota al rango [SCOPE_CACHE_TTL_MIN, SCOPE_CACHE_TTL_MAX].
     * Valor fuera de rango o invalido cae al default.
     *
     * El TTL se cachea en Redis 60s para evitar una query a system_settings
     * por cada llamada a resolveInheritedTranscriptionScope (con 70 storages
     * eso eran 70 queries redundantes por page load). 60s es aceptable:
     * el operador rara vez cambia este setting; si lo cambia, la nueva
     * configuracion tarda 60s en aplicarse.
     */
    private static function resolveScopeCacheTtl(): int
    {
        $cached = Cache::get('transcriptor.scope.ttl');
        if ($cached !== null) {
            return (int) $cached;
        }
        $raw = SystemSetting::get('transcriptor_scope_cache_ttl');
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            $ttl = self::SCOPE_CACHE_TTL_DEFAULT;
        } else {
            $ttl = (int) $raw;
            if ($ttl < self::SCOPE_CACHE_TTL_MIN || $ttl > self::SCOPE_CACHE_TTL_MAX) {
                $ttl = self::SCOPE_CACHE_TTL_DEFAULT;
            }
        }
        Cache::put('transcriptor.scope.ttl', $ttl, 60);
        return $ttl;
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
}