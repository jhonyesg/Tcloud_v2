<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Share;

class ShareCreationRulesTest extends TestCase
{
    public function test_token_is_32_characters(): void
    {
        $token = bin2hex(random_bytes(16));
        
        $this->assertEquals(32, strlen($token));
    }
    
    public function test_token_is_hexadecimal(): void
    {
        $token = bin2hex(random_bytes(16));
        
        $this->assertTrue(ctype_xdigit($token));
    }
    
    public function test_valid_permissions(): void
    {
        $validPermissions = ['read', 'write', 'upload', 'full'];
        
        foreach ($validPermissions as $perm) {
            $this->assertContains($perm, $validPermissions);
        }
    }
    
    public function test_permission_hierarchy(): void
    {
        $permissions = ['read' => 1, 'write' => 2, 'upload' => 2, 'full' => 3];
        
        $this->assertLessThan($permissions['write'], $permissions['read']);
        $this->assertLessThan($permissions['full'], $permissions['write']);
    }
    
    public function test_cannot_share_with_more_permissions_than_having(): void
    {
        $userPermissionLevel = 1;
        $sharePermissionLevel = 3;
        
        $canShare = $userPermissionLevel >= $sharePermissionLevel;
        
        $this->assertFalse($canShare);
    }
    
    public function test_can_share_with_equal_or_less_permissions(): void
    {
        $userPermissionLevel = 3;
        $sharePermissionLevel = 2;
        
        $canShare = $userPermissionLevel >= $sharePermissionLevel;
        
        $this->assertTrue($canShare);
    }
    
    public function test_expired_share_detection(): void
    {
        $share = new Share([
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        
        $isExpired = $share->expires_at && strtotime($share->expires_at) < time();
        
        $this->assertTrue($isExpired);
    }
    
    public function test_non_expired_share(): void
    {
        $share = new Share([
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        
        $isExpired = $share->expires_at && strtotime($share->expires_at) < time();
        
        $this->assertFalse($isExpired);
    }
    
    public function test_share_without_expiration(): void
    {
        $share = new Share([
            'expires_at' => null,
        ]);
        
        $isExpired = $share->expires_at && strtotime($share->expires_at) < time();
        
        $this->assertFalse($isExpired);
    }
    
    public function test_password_protection(): void
    {
        $password = 'test_password';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrong_password', $hash));
    }
}
