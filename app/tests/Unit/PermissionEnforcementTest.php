<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\StorageProvider;
use App\Models\UserStorage;

class PermissionEnforcementTest extends TestCase
{
    public function test_admin_has_all_permissions(): void
    {
        $admin = new User(['role' => 'admin']);
        
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasStoragePermission(1, 'read'));
        $this->assertTrue($admin->hasStoragePermission(1, 'write'));
        $this->assertTrue($admin->hasStoragePermission(1, 'upload'));
        $this->assertTrue($admin->hasStoragePermission(1, 'full'));
    }
    
    public function test_user_without_storage_has_no_permissions(): void
    {
        $user = new User(['role' => 'user']);
        
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->hasStoragePermission(1, 'read'));
        $this->assertFalse($user->hasStoragePermission(1, 'write'));
    }
    
    public function test_read_permission_allows_only_read_operations(): void
    {
        $user = $this->createUserWithStoragePermission('read');
        
        $this->assertTrue($user->hasStoragePermission(1, 'read'));
        $this->assertFalse($user->hasStoragePermission(1, 'write'));
        $this->assertFalse($user->hasStoragePermission(1, 'upload'));
        $this->assertFalse($user->hasStoragePermission(1, 'full'));
    }
    
    public function test_write_permission_includes_read(): void
    {
        $user = $this->createUserWithStoragePermission('write');
        
        $this->assertTrue($user->hasStoragePermission(1, 'read'));
        $this->assertTrue($user->hasStoragePermission(1, 'write'));
        $this->assertFalse($user->hasStoragePermission(1, 'full'));
    }
    
    public function test_upload_permission(): void
    {
        $user = $this->createUserWithStoragePermission('upload');
        
        $this->assertTrue($user->hasStoragePermission(1, 'read'));
        $this->assertTrue($user->hasStoragePermission(1, 'upload'));
        $this->assertFalse($user->hasStoragePermission(1, 'write'));
        $this->assertFalse($user->hasStoragePermission(1, 'full'));
    }
    
    public function test_full_permission_includes_all(): void
    {
        $user = $this->createUserWithStoragePermission('full');
        
        $this->assertTrue($user->hasStoragePermission(1, 'read'));
        $this->assertTrue($user->hasStoragePermission(1, 'write'));
        $this->assertTrue($user->hasStoragePermission(1, 'upload'));
        $this->assertTrue($user->hasStoragePermission(1, 'full'));
    }
    
    public function test_can_create_shares_flag(): void
    {
        $user = $this->createUserWithStoragePermission('read', false);
        $this->assertFalse($user->canCreateSharesInStorage(1));
        
        $userWithShares = $this->createUserWithStoragePermission('read', true);
        $this->assertTrue($userWithShares->canCreateSharesInStorage(1));
    }
    
    private function createUserWithStoragePermission(string $permission, bool $canCreateShares = false): User
    {
        $user = new User(['role' => 'user']);
        
        $userStorage = new UserStorage([
            'permissions' => $permission,
            'can_create_shares' => $canCreateShares,
        ]);
        
        $user->userStorages = [$userStorage];
        
        return $user;
    }
}
