<?php

namespace App\Services\MisArchivos;

use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class FilesystemPermissionGuard
{
    private ?array $cachedStorageIds = null;
    private ?int $cachedForUserId = null;

    public function filter(array $entries, int $userId): array
    {
        $user = User::find($userId);
        if (!$user) {
            return [];
        }
        if ($user->isAdmin()) {
            return $entries;
        }

        $allowed = $this->accessibleStorageIds($user);

        return array_values(array_filter(
            $entries,
            fn($entry) => in_array((int) ($entry['storage_provider_id'] ?? 0), $allowed, true)
        ));
    }

    public function canAccess(int $storageId, int $userId, string $permission = 'read'): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        return $user->hasStoragePermission($storageId, $permission);
    }

    public function permissionsFor(int $storageId, int $userId): string
    {
        $user = User::find($userId);
        if (!$user) {
            return 'none';
        }
        if ($user->isAdmin()) {
            return 'admin';
        }
        if (!$user->hasStoragePermission($storageId, 'read')) {
            return 'none';
        }
        if ($user->hasStoragePermission($storageId, 'full')) {
            return 'full';
        }
        if ($user->hasStoragePermission($storageId, 'write')) {
            return 'write';
        }
        return 'read';
    }

    public function actionsFor(string $permission, bool $isFolder): array
    {
        if ($permission === 'admin') {
            return ['download', 'view', 'upload', 'rename', 'delete', 'share'];
        }

        $base = ['view'];
        if ($permission === 'read' || $permission === 'write' || $permission === 'full') {
            $base[] = 'download';
        }
        if ($permission === 'write' || $permission === 'full') {
            $base[] = 'upload';
            $base[] = 'rename';
            if ($isFolder) {
                $base[] = 'create_folder';
            }
        }
        if ($permission === 'full') {
            $base[] = 'delete';
            $base[] = 'share';
        }
        return $base;
    }

    public function deny(int $userId, int $storageId, ?string $subPath = null): void
    {
        Log::warning('mis_archivos.permission_denied', [
            'user_id' => $userId,
            'storage_id' => $storageId,
            'sub_path' => $subPath,
        ]);
    }

    /** @return list<int> */
    public function accessibleStorageIds(User $user): array
    {
        if ($this->cachedForUserId === $user->id && $this->cachedStorageIds !== null) {
            return $this->cachedStorageIds;
        }

        $ids = $user->userStorages()->pluck('storage_provider_id')->map(fn($v) => (int) $v)->all();

        $this->cachedForUserId = $user->id;
        $this->cachedStorageIds = $ids;

        return $ids;
    }
}
