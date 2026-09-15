<?php

namespace App\Models;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class StorageProvider extends Model
{
    protected $fillable = ['name', 'type', 'config', 'base_path', 'enabled', 'is_accessible', 'last_checked_at', 'transcription_enabled', 'folder_layout', 'allow_parent_overlap', 'is_personal', 'kind'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'is_accessible' => 'boolean',
        'last_checked_at' => 'datetime',
        'transcription_enabled' => 'boolean',
        'folder_layout' => 'string',
        'allow_parent_overlap' => 'boolean',
        'is_personal' => 'boolean',
        'kind' => 'string',
    ];

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
     * Invalida la cache del scope de un root especifico. Idempotente.
     * Llamar este metodo en cualquier mutacion de base_path o
     * transcription_enabled del root o sus descendientes.
     */
    public static function forgetInheritedTranscriptionScope(int $rootId): void
    {
        Cache::forget(self::SCOPE_CACHE_PREFIX . $rootId);
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