<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class File extends Model
{
    protected $fillable = ['name', 'path', 'size', 'mime_type', 'storage_provider_id', 'owner_id', 'parent_id', 'is_folder', 'is_personal', 'file_modified_at'];

    protected $casts = [
        'size' => 'integer',
        'is_folder' => 'boolean',
        'is_personal' => 'boolean',
        'file_modified_at' => 'datetime',
        'availability_state' => 'string',
        'last_verified_at' => 'datetime',
        'missing_since_at' => 'datetime',
    ];

    /**
     * Ruta absoluta del recurso en disco.
     *
     * Formula canonica del proyecto:
     *   rtrim(storage_providers.base_path, '/') . '/' . ltrim(files.path, '/')
     *
     * Se centraliza aqui para no repetir la concatenacion en cada consumidor
     * (transcriptor, sincronizador, modulo de compartidos). Misma formula que
     * `PhysicalFileIdentity::absolutePathOf()`, pero sin acoplar a los servicios
     * del pipeline de transcripcion: este metodo es de solo lectura y no
     * depende de la jerarquia de storages.
     *
     * @return string|null Null si la fila no tiene storage o el storage no
     *                     tiene `base_path` (no hay ruta fisica resoluble).
     */
    public function physicalPath(): ?string
    {
        $storage = $this->relationLoaded('storageProvider')
            ? $this->storageProvider
            : $this->storageProvider()->first();

        if ($storage === null) {
            return null;
        }

        $base = rtrim((string) $storage->base_path, '/');
        if ($base === '') {
            return null;
        }

        return $base . '/' . ltrim((string) $this->path, '/');
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
}