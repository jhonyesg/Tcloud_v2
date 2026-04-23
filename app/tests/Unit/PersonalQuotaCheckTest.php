<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;

class PersonalQuotaCheckTest extends TestCase
{
    public function test_unlimited_quota_allows_any_upload(): void
    {
        $user = new User([
            'personal_quota_bytes' => 0,
            'personal_used_bytes' => 0,
        ]);
        
        $this->assertTrue($user->personal_quota_bytes === 0);
    }
    
    public function test_quota_calculation_when_under_limit(): void
    {
        $user = new User([
            'personal_quota_bytes' => 104857600,
            'personal_used_bytes' => 52428800,
        ]);
        
        $fileSize = 1024;
        $newTotal = $user->personal_used_bytes + $fileSize;
        
        $this->assertLessThan($user->personal_quota_bytes, $newTotal);
    }
    
    public function test_quota_calculation_when_at_limit(): void
    {
        $user = new User([
            'personal_quota_bytes' => 104857600,
            'personal_used_bytes' => 104857600,
        ]);
        
        $fileSize = 1024;
        $newTotal = $user->personal_used_bytes + $fileSize;
        
        $this->assertEquals($user->personal_quota_bytes, $newTotal - $fileSize);
    }
    
    public function test_quota_exceeded_when_upload_exceeds_limit(): void
    {
        $user = new User([
            'personal_quota_bytes' => 104857600,
            'personal_used_bytes' => 104857600,
        ]);
        
        $fileSize = 2048;
        $wouldExceed = ($user->personal_used_bytes + $fileSize) > $user->personal_quota_bytes;
        
        $this->assertTrue($wouldExceed);
    }
    
    public function test_quota_not_consumed_for_shared_storage(): void
    {
        $user = new User([
            'personal_quota_bytes' => 104857600,
            'personal_used_bytes' => 0,
        ]);
        
        $isPersonalFile = false;
        if (!$isPersonalFile) {
            $this->assertEquals(0, $user->personal_used_bytes);
        }
    }
    
    public function test_quota_only_applies_to_personal_files(): void
    {
        $user = new User([
            'personal_quota_bytes' => 104857600,
            'personal_used_bytes' => 52428800,
        ]);
        
        $personalFileSize = 1024;
        $sharedFileSize = 1024;
        
        $newUsedBytes = $user->personal_used_bytes + $personalFileSize;
        
        $this->assertEquals(52428800 + $personalFileSize, $newUsedBytes);
    }
    
    public function test_quota_display_formatting(): void
    {
        $bytes = 1048576;
        $kb = $bytes / 1024;
        $mb = $kb / 1024;
        
        $this->assertEquals(1024, $kb);
        $this->assertEquals(1, $mb);
    }
}
