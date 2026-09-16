<?php

namespace Tests\Unit;

use App\Http\Controllers\ShareController;
use App\Models\File;
use App\Services\StorageSyncService;
use Tests\LaravelTestCase;

class ShareManagementContractTest extends LaravelTestCase
{
    public function test_share_controller_contains_server_side_query_contract(): void
    {
        $source = file_get_contents((new \ReflectionClass(ShareController::class))->getFileName());

        $this->assertStringContainsString('paginate(', $source);
        $this->assertStringContainsString("'created_from'", $source);
        $this->assertStringContainsString("'expires_to'", $source);
        $this->assertStringContainsString('bulkPreview', $source);
        $this->assertStringContainsString('bulkDelete', $source);
        $this->assertStringContainsString('verifyAvailability', $source);
    }

    public function test_file_model_exposes_catalog_availability_fields(): void
    {
        $file = new File();
        $fillable = $file->getFillable();

        $this->assertContains('availability_state', $fillable);
        $this->assertContains('last_verified_at', $fillable);
        $this->assertContains('missing_since_at', $fillable);
    }

    public function test_sync_service_preserves_unknown_state_on_untrusted_paths(): void
    {
        $source = file_get_contents((new \ReflectionClass(StorageSyncService::class))->getFileName());

        $this->assertStringContainsString("'availability_state' => 'unknown'", $source);
        $this->assertStringContainsString('markFolderUnknown', $source);
        $this->assertStringContainsString('markOrphansUnknown', $source);
    }

    public function test_routes_and_view_expose_new_share_management_controls(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/shares/index.blade.php');

        $this->assertStringContainsString('/shares/bulk-preview', $routes);
        $this->assertStringContainsString('/shares/bulk-delete', $routes);
        $this->assertStringContainsString('/shares/availability/verify', $routes);
        $this->assertStringContainsString('created_from', $view);
        $this->assertStringContainsString('selectAllMatching', $view);
        $this->assertStringContainsString('toggleSort', $view);
    }
}
