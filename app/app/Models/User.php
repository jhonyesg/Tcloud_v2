<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Model
{
    protected $table = 'users';
    protected $fillable = ['email', 'username', 'password_hash', 'role', 'personal_quota_bytes', 'personal_used_bytes', 'media_editor_enabled', 'media_editor_clip_limit'];
    protected $hidden = ['password_hash'];

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'owner_id');
    }

    public function userStorages(): HasMany
    {
        return $this->hasMany(UserStorage::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'created_by');
    }

    public function storageProviders(): BelongsToMany
    {
        return $this->belongsToMany(StorageProvider::class, 'user_storages')
            ->withPivot('permissions', 'can_create_shares', 'assigned_at');
    }

    public function hasStoragePermission(int $storageId, string $permission): bool
    {
        $userStorage = $this->userStorages()->where('storage_provider_id', $storageId)->first();
        if (!$userStorage) return false;

        $permissions = ['read' => 1, 'write' => 2, 'upload' => 2, 'full' => 3];
        $userLevel = $permissions[$userStorage->permissions] ?? 0;
        $requiredLevel = $permissions[$permission] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    public function canCreateSharesInStorage(int $storageId): bool
    {
        return $this->userStorages()->where('storage_provider_id', $storageId)->where('can_create_shares', true)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canUseMediaEditor(): bool
    {
        return $this->isAdmin() || (bool) $this->media_editor_enabled;
    }

    public function mediaEditorClipsThisMonth(): int
    {
        return \App\Models\MediaEditJob::where('user_id', $this->id)
            ->where('status', 'done')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
    }

    public function hasReachedClipLimit(): bool
    {
        if ($this->isAdmin()) return false;
        $limit = (int) $this->media_editor_clip_limit;
        if ($limit === 0) return false;
        return $this->mediaEditorClipsThisMonth() >= $limit;
    }

    public function grabadores(): BelongsToMany
    {
        return $this->belongsToMany(Grabador::class, 'grabador_usuario')
            ->withPivot('limite_canales')
            ->withTimestamps();
    }

    public function canales(): HasMany
    {
        return $this->hasMany(Canal::class, 'usuario_id');
    }
}