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
     * True si este row es un folder mirror (apunta a un canónico via canonical_folder_id).
     * Falso si es canónico o huérfano.
     *
     * Ver convención de naming en AGENTS.md § Schema clarity policy.
     */
    public function isFolderMirror(): bool
    {
        return $this->canonical_folder_id !== null;
    }

    /**
     * Alias deprecado de isFolderMirror() conservado por un ciclo de release.
     * Migración: cambiar todos los call sites a isFolderMirror() y eliminar.
     */
    public function isMirror(): bool
    {
        return $this->isFolderMirror();
    }

    /**
     * Devuelve el folder canónico: self si no es mirror, el row apuntado si lo es.
     * Retorna null si el canónico fue borrado (FK dangling — `canonical_folder_id` quedó
     * apuntando a NULL por el ON DELETE SET NULL).
     */
    public function canonicalFolder(): ?self
    {
        if (!$this->isFolderMirror()) {
            return $this;
        }
        return static::find($this->canonical_folder_id);
    }

    /**
     * Alias deprecado de canonicalFolder() conservado por un ciclo de release.
     * Migración: cambiar todos los call sites a canonicalFolder() y eliminar.
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
}
