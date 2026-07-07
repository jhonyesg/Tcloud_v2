<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProvider extends Model
{
    protected $fillable = ['name', 'type', 'config', 'base_path', 'enabled', 'is_accessible', 'last_checked_at', 'transcription_enabled', 'transcription_priority'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'is_accessible' => 'boolean',
        'last_checked_at' => 'datetime',
        'transcription_enabled' => 'boolean',
        'transcription_priority' => 'integer',
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
}