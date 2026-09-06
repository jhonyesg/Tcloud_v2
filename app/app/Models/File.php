<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'size' => 'integer',
        'is_folder' => 'boolean',
        'is_trashed' => 'boolean',
        'file_modified_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'missing_since_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

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
}
