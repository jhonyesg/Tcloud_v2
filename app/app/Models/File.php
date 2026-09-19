<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class File extends Model
{
    protected $fillable = [
        'name',
        'path',
        'size',
        'mime_type',
        'storage_provider_id',
        'owner_id',
        'parent_id',
        'original_parent_id',
        'is_folder',
        'file_modified_at',
        'availability_state',
        'last_verified_at',
        'missing_since_at',
        'is_trashed',
        'deleted_at',
        'canonical_folder_id',
        'merged_at',
        'merged_reason',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_folder' => 'boolean',
        'is_trashed' => 'boolean',
        'file_modified_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'missing_since_at' => 'datetime',
        'deleted_at' => 'datetime',
        'canonical_folder_id' => 'integer',
        'merged_at' => 'datetime',
    ];

    /**
     * @property int|null $canonical_folder_id FK self-ref (ON DELETE SET NULL) que apunta al folder canónico cuando este row es un mirror.
     * @property \Carbon\Carbon|null $merged_at
     * @property string|null $merged_reason
     *
     * Schema clarity: ver `app/AGENTS.md § Schema clarity policy`. Esta columna se llama
     * `canonical_folder_id` (no `merged_into_id`) para reflejar sin ambigüedad que apunta
     * al folder canónico de un row folder, no al storage canónico de un storage row.
     */

    public function scopeTrashed($query)
    {
        return $query->where('is_trashed', true);
    }

    public function scopeNotTrashed($query)
    {
        return $query->where('is_trashed', false);
    }

    /**
     * @deprecated (change `files-mirror-elimination`, 2026-09-19)
     *
     * Todos los rows vivos son canónicos tras el retiro de los mirrors. El
     * scope se conserva por un ciclo de release y retorna la query sin filtrar
     * por `canonical_folder_id`.
     */
    public function scopeCanonical($query)
    {
        return $query->whereNull('canonical_folder_id');
    }

    /**
     * @deprecated (change `files-mirror-elimination`, 2026-09-19)
     *
     * Ya no existen rows mirror vivos. Se conserva por un ciclo de release.
     */
    public function scopeMirror($query)
    {
        return $query->whereNotNull('canonical_folder_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(File::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(File::class, 'parent_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    public function transcription(): HasOne
    {
        return $this->hasOne(Transcription::class, 'file_id');
    }

    /**
     * Identidad física del folder/file: el path absoluto en disco normalizado.
     * Devuelve NULL si el archivo es huérfano (sin storage_provider_id).
     *
     * Mismo algoritmo que `storage_providers.physical_path_normalized` para
     * mantener consistencia cross-table: `lower(rtrim(base_path, '/') || '/' || ltrim(path, '/'))`.
     *
     * Usa `base_path_snapshot` (denormalizado por FileObserver) en vez de
     * JOIN a `storage_providers` para evitar el costo en queries cross-storage.
     */
    public function physicalPathNormalized(): ?string
    {
        if (empty($this->base_path_snapshot) || $this->path === null) {
            return null;
        }
        $base = rtrim((string) $this->base_path_snapshot, '/');
        $rel  = ltrim((string) $this->path, '/');
        return strtolower($base . '/' . $rel);
    }

    /**
     * @deprecated (change `files-mirror-elimination`, 2026-09-19)
     *
     * La materialización de mirrors se retiró: la identidad física se resuelve
     * en tiempo de lectura vía `FilePhysicalIdentity::canonicalFor()`. Este
     * método queda por un ciclo de release para no romper call sites y siempre
     * retorna `false`. Se elimina junto con la columna `canonical_folder_id`.
     */
    public function isFolderMirror(): bool
    {
        return false;
    }

    /**
     * @deprecated Alias de isFolderMirror(). Siempre `false`. Ver nota arriba.
     */
    public function isMirror(): bool
    {
        return $this->isFolderMirror();
    }

    /**
     * @deprecated (change `files-mirror-elimination`, 2026-09-19)
     *
     * Retorna `$this` siempre: ya no hay un enlace materializado que resolver.
     * Para resolver la identidad física usa
     * `app(FilePhysicalIdentity::class)->canonicalFor($file)`.
     */
    public function canonicalFolder(): ?self
    {
        return $this;
    }

    /**
     * @deprecated Alias de canonicalFolder(). Retorna `$this`. Ver nota arriba.
     */
    public function canonical(): ?self
    {
        return $this->canonicalFolder();
    }

    /**
     * Identidad estable del archivo: "<id>@<physical_path_normalized>".
     * Útil para logging y como cache key (estable frente a re-parentings).
     */
    public function physicalIdentity(): string
    {
        $norm = $this->physicalPathNormalized() ?? '(orphan)';
        return "{$this->id}@{$norm}";
    }

    /**
     * Invalida la cache de identidad física (`FilePhysicalIdentity`) para la
     * identidad actual y la original, cuando cambia alguno de sus componentes.
     *
     * Change `files-mirror-elimination` (2026-09-19).
     */
    public function forgetCanonicalIdentityCache(): void
    {
        if (!$this->isDirty('path')
            && !$this->isDirty('storage_provider_id')
            && !$this->isDirty('base_path_snapshot')) {
            return;
        }

        $keys = [];

        $current = $this->physicalPathNormalized();
        if ($current !== null) {
            $keys[] = \App\Services\FilePhysicalIdentity::cacheKeyFor($current);
        }

        $origBase = $this->getOriginal('base_path_snapshot');
        $origPath = $this->getOriginal('path');
        if (!empty($origBase) && $origPath !== null && $origPath !== '') {
            $keys[] = \App\Services\FilePhysicalIdentity::cacheKeyFor(
                strtolower(rtrim((string) $origBase, '/') . '/' . ltrim((string) $origPath, '/'))
            );
        }

        foreach (array_unique($keys) as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
    }
}
