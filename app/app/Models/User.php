<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Model
{
    protected $table = 'users';
    protected $fillable = ['email', 'username', 'password_hash', 'role', 'personal_quota_bytes', 'personal_used_bytes', 'media_editor_enabled', 'media_editor_clip_limit', 'max_sessions', 'session_lifetime_minutes'];
    protected $hidden = ['password_hash'];

    private ?Collection $cachedStorages = null;

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

    private function loadedStorages(): Collection
    {
        $this->cachedStorages ??= $this->userStorages()->get();
        return $this->cachedStorages;
    }

    public function hasStoragePermission(int $storageId, string $permission): bool
    {
        $userStorage = $this->loadedStorages()->firstWhere('storage_provider_id', $storageId);
        if (!$userStorage) return false;

        $permissions = ['read' => 1, 'write' => 2, 'upload' => 2, 'full' => 3];
        $userLevel = $permissions[$userStorage->permissions] ?? 0;
        $requiredLevel = $permissions[$permission] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    public function canCreateSharesInStorage(int $storageId): bool
    {
        return $this->loadedStorages()
            ->where('storage_provider_id', $storageId)
            ->where('can_create_shares', true)
            ->isNotEmpty();
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

    public function externalSites(): BelongsToMany
    {
        return $this->belongsToMany(ExternalSite::class, 'external_site_user')
            ->withPivot('sort_order')
            ->orderBy('sort_order');
    }
}