<?php

namespace Tests\Unit;

use App\Models\Share;
use Tests\LaravelTestCase;

class ShareLifecycleRulesTest extends LaravelTestCase
{
    public function test_expired_share_is_detected_as_boolean(): void
    {
        $share = new Share(['expires_at' => now()->subDay()]);

        $this->assertTrue($share->isExpired());
        $this->assertIsBool($share->isExpired());
    }

    public function test_future_and_permanent_shares_are_not_expired(): void
    {
        $future = new Share(['expires_at' => now()->addDay()]);
        $permanent = new Share(['expires_at' => null]);

        $this->assertFalse($future->isExpired());
        $this->assertFalse($permanent->isExpired());
    }

    public function test_new_share_expiry_configuration_defaults_to_thirty_days(): void
    {
        $this->assertSame(30, (int) config('shares.default_expiry_days'));
    }

    public function test_password_hash_is_hidden_from_model_serialization(): void
    {
        $share = new Share(['password_hash' => 'secret-hash']);

        $this->assertContains('password_hash', $share->getHidden());
        $this->assertArrayNotHasKey('password_hash', $share->toArray());
    }
}
