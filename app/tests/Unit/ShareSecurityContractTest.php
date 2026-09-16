<?php

namespace Tests\Unit;

use App\Http\Controllers\ShareController;
use App\Http\Controllers\PublicShareController;
use App\Models\Share;
use App\Models\User;
use Tests\LaravelTestCase;

class ShareSecurityContractTest extends LaravelTestCase
{
    public function test_share_detail_uses_the_same_management_authorization_rule(): void
    {
        $source = file_get_contents((new \ReflectionClass(ShareController::class))->getFileName());

        $this->assertStringContainsString("if (!\$this->canManage(\$share, \$user))", $source);
        $this->assertStringContainsString("'has_password' => !is_null(\$share->password_hash)", $source);
        $this->assertStringNotContainsString("'password_hash' => \$share->password_hash", $source);
    }

    public function test_public_controller_has_html_password_authentication(): void
    {
        $source = file_get_contents((new \ReflectionClass(PublicShareController::class))->getFileName());

        $this->assertStringContainsString('public function authenticate(', $source);
        $this->assertStringContainsString('share_auth_', $source);
        $this->assertStringContainsString('Always load current metadata', $source);
    }

    public function test_owner_or_admin_is_required_to_manage_a_share(): void
    {
        $controller = app(ShareController::class);
        $method = new \ReflectionMethod(ShareController::class, 'canManage');
        $method->setAccessible(true);

        $share = new Share(['created_by' => 7]);
        $owner = (new User(['role' => 'user']))->setAttribute('id', 7);
        $otherUser = (new User(['role' => 'user']))->setAttribute('id', 8);
        $admin = (new User(['role' => 'admin']))->setAttribute('id', 8);

        $this->assertTrue($method->invoke($controller, $share, $owner));
        $this->assertFalse($method->invoke($controller, $share, $otherUser));
        $this->assertTrue($method->invoke($controller, $share, $admin));
    }
}
