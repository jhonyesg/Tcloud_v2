<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\ShareAvailabilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\LaravelTestCase;

class ShareAvailabilityServiceTest extends LaravelTestCase
{
    private string $dir;
    private int $userId;
    private int $storageId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/share_availability_' . uniqid();
        mkdir($this->dir, 0777, true);

        $this->userId = (int) DB::table('users')->insertGetId([
            'email' => 'share-availability-' . uniqid() . '@test.local',
            'password_hash' => 'x',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storageId = (int) DB::table('storage_providers')->insertGetId([
            'name' => 'share-availability-test',
            'type' => 'local',
            'config' => json_encode([]),
            'base_path' => $this->dir,
            'enabled' => true,
            'is_accessible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_storages')->insert([
            'user_id' => $this->userId,
            'storage_provider_id' => $this->storageId,
            'permissions' => 'full',
            'can_create_shares' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('files')->where('storage_provider_id', $this->storageId)->delete();
        DB::table('user_storages')->where('storage_provider_id', $this->storageId)->delete();
        DB::table('storage_providers')->where('id', $this->storageId)->delete();
        DB::table('users')->where('id', $this->userId)->delete();
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_existing_and_missing_paths_receive_distinct_states(): void
    {
        file_put_contents($this->dir . '/exists.txt', 'ok');
        $existing = File::create([
            'name' => 'exists.txt',
            'path' => 'exists.txt',
            'size' => 2,
            'mime_type' => 'text/plain',
            'storage_provider_id' => $this->storageId,
            'owner_id' => $this->userId,
            'is_folder' => false,
            'availability_state' => 'unknown',
        ]);
        $missing = File::create([
            'name' => 'missing.txt',
            'path' => 'missing.txt',
            'size' => 0,
            'mime_type' => 'text/plain',
            'storage_provider_id' => $this->storageId,
            'owner_id' => $this->userId,
            'is_folder' => false,
            'availability_state' => 'unknown',
        ]);

        $summary = app(ShareAvailabilityService::class)->verify(new Collection([
            $existing->load('storageProvider'),
            $missing->load('storageProvider'),
        ]));

        $this->assertSame(2, $summary['checked']);
        $this->assertSame(1, $summary['available']);
        $this->assertSame(1, $summary['missing']);
        $this->assertSame('available', $existing->fresh()->availability_state);
        $this->assertSame('missing', $missing->fresh()->availability_state);
    }

    public function test_non_local_storage_remains_unknown(): void
    {
        DB::table('storage_providers')->where('id', $this->storageId)->update(['type' => 's3']);
        $file = File::create([
            'name' => 'remote.txt',
            'path' => 'remote.txt',
            'size' => 0,
            'mime_type' => 'text/plain',
            'storage_provider_id' => $this->storageId,
            'owner_id' => $this->userId,
            'is_folder' => false,
            'availability_state' => 'unknown',
        ]);

        $summary = app(ShareAvailabilityService::class)->verify(new Collection([$file->load('storageProvider')]));

        $this->assertSame(1, $summary['unknown']);
        $this->assertSame('storage_not_local', $summary['details'][0]['reason']);
    }
}
