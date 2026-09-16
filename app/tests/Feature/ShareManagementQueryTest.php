<?php

namespace Tests\Feature;

use App\Http\Controllers\ShareController;
use App\Http\Controllers\PublicShareController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\LaravelTestCase;

class ShareManagementQueryTest extends LaravelTestCase
{
    private int $ownerId;
    private int $otherUserId;
    private int $storageId;
    private int $fileId;
    private int $shareId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->ownerId = (int) DB::table('users')->insertGetId([
            'email' => 'share-owner-' . uniqid() . '@test.local',
            'password_hash' => 'x',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->otherUserId = (int) DB::table('users')->insertGetId([
            'email' => 'share-other-' . uniqid() . '@test.local',
            'password_hash' => 'x',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->storageId = (int) DB::table('storage_providers')->insertGetId([
            'name' => 'share-query-test',
            'type' => 'local',
            'config' => json_encode([]),
            'base_path' => sys_get_temp_dir(),
            'enabled' => true,
            'is_accessible' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->fileId = (int) DB::table('files')->insertGetId([
            'name' => 'share-query.txt',
            'path' => 'share-query-' . uniqid() . '.txt',
            'size' => 10,
            'mime_type' => 'text/plain',
            'storage_provider_id' => $this->storageId,
            'owner_id' => $this->ownerId,
            'is_folder' => false,
            'availability_state' => 'unknown',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->shareId = (int) DB::table('shares')->insertGetId([
            'file_id' => $this->fileId,
            'token' => 'share-query-' . uniqid(),
            'permissions' => 'read',
            'created_by' => $this->ownerId,
            'expires_at' => $now->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('shares')->whereIn('created_by', [$this->ownerId, $this->otherUserId])->delete();
        DB::table('files')->where('id', $this->fileId)->delete();
        DB::table('storage_providers')->where('id', $this->storageId)->delete();
        DB::table('users')->whereIn('id', [$this->ownerId, $this->otherUserId])->delete();
        Session::forget('user_id');

        parent::tearDown();
    }

    public function test_owner_listing_is_paginated_and_does_not_expose_hash(): void
    {
        Session::put('user_id', $this->ownerId);
        $request = Request::create('/shares', 'GET', [
            'per_page' => 10,
            'sort' => 'name',
            'direction' => 'asc',
        ]);
        $request->headers->set('Accept', 'application/json');

        $response = app(ShareController::class)->index($request);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayNotHasKey('password_hash', $payload['data'][0]);
    }

    public function test_foreign_detail_is_forbidden_and_bulk_preview_does_not_delete_file(): void
    {
        Session::put('user_id', $this->otherUserId);
        $detail = app(ShareController::class)->show($this->shareId);
        $this->assertSame(403, $detail->getStatusCode());

        Session::put('user_id', $this->ownerId);
        $previewRequest = Request::create('/shares/bulk-preview', 'POST', ['ids' => [$this->shareId]]);
        $previewRequest->headers->set('Accept', 'application/json');
        $preview = app(ShareController::class)->bulkPreview($previewRequest);
        $previewPayload = json_decode($preview->getContent(), true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertSame(1, $previewPayload['count']);
        $this->assertSame(1, DB::table('files')->where('id', $this->fileId)->count());

        $deleteRequest = Request::create('/shares/bulk-delete', 'POST', [
            'ids' => [$this->shareId],
            'confirm_count' => 1,
        ]);
        $deleteRequest->headers->set('Accept', 'application/json');
        $deleted = app(ShareController::class)->bulkDelete($deleteRequest);

        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertSame(0, DB::table('shares')->where('id', $this->shareId)->count());
        $this->assertSame(1, DB::table('files')->where('id', $this->fileId)->count());
    }

    public function test_html_password_authentication_creates_share_session(): void
    {
        $token = (string) DB::table('shares')->where('id', $this->shareId)->value('token');
        DB::table('shares')->where('id', $this->shareId)->update(['password_hash' => Hash::make('secret')]);

        $request = Request::create('/s/' . $token . '/authenticate', 'POST', ['password' => 'secret']);
        $request->setLaravelSession(app('session')->driver());

        $response = app(PublicShareController::class)->authenticate($request, $token);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($request->session()->has('share_auth_' . $token));
    }

    public function test_expired_and_confirmed_missing_public_resources_are_blocked(): void
    {
        $token = (string) DB::table('shares')->where('id', $this->shareId)->value('token');
        DB::table('shares')->where('id', $this->shareId)->update(['expires_at' => now()->subMinute()]);

        $expiredRequest = Request::create('/s/' . $token, 'GET');
        $expiredRequest->headers->set('Accept', 'application/json');
        $expired = app(PublicShareController::class)->show($expiredRequest, $token);
        $this->assertSame(410, $expired->getStatusCode());

        DB::table('shares')->where('id', $this->shareId)->update(['expires_at' => now()->addDay()]);
        DB::table('files')->where('id', $this->fileId)->update(['availability_state' => 'missing']);
        $missingRequest = Request::create('/s/' . $token, 'GET');
        $missingRequest->headers->set('Accept', 'application/json');
        $missing = app(PublicShareController::class)->show($missingRequest, $token);
        $this->assertSame(404, $missing->getStatusCode());
    }
}
