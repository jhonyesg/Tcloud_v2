<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProvider extends Model
{
    protected $fillable = ['name', 'type', 'config', 'base_path', 'enabled', 'is_accessible', 'last_checked_at', 'transcription_enabled', 'folder_layout', 'allow_parent_overlap', 'is_personal'];

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