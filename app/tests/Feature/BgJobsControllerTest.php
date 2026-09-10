<?php

namespace Tests\Feature;

use App\Http\Controllers\BgJobsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\LaravelTestCase;

/**
 * Tests del endpoint /bg-jobs/active.
 *
 * Cambio: openspec/changes/add-bg-job-indicator-widget/.
 *
 * Nota: este proyecto no usa el HTTP test framework de Laravel (actingAs/seeders).
 * Por eso ejercitamos el controller directamente. La integración HTTP completa
 * (middleware auth, admin) se cubre manualmente con curl y Playwright.
 */
class BgJobsControllerTest extends LaravelTestCase
{
    public function test_returns_empty_when_no_jobs(): void
    {
        $controller = app(BgJobsController::class);
        $resp = $controller->active(Request::create('/bg-jobs/active', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());
        $data = $resp->getData(true);
        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertSame([], $data['jobs']);
    }

    public function test_returns_jobs_with_normalized_shape(): void
    {
        Cache::put('avisos_scan_bg:active', ['runId' => 'demo_run_001'], now()->addHours(2));
        Cache::put('avisos_scan_bg:demo_run_001', [
            'attempted_count' => 100,
            'scanned' => 75,
            'hits_new' => 30,
            'failed' => 0,
            'batches' => 2,
            'started_at' => '2026-09-09T10:00:00-05:00',
        ], now()->addHours(2));

        $controller = app(BgJobsController::class);
        $resp = $controller->active(Request::create('/bg-jobs/active', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());
        $jobs = $resp->getData(true)['jobs'];
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('avisos-scan', $job['kind']);
        $this->assertSame('demo_run_001', $job['runId']);
        $this->assertArrayHasKey('module', $job);
        $this->assertArrayHasKey('label', $job);
        $this->assertArrayHasKey('progress', $job);
        $this->assertArrayHasKey('url', $job);
        $this->assertSame(75, $job['progress']['scanned']);
        $this->assertSame(30, $job['progress']['hits_new']);

        Cache::forget('avisos_scan_bg:active');
        Cache::forget('avisos_scan_bg:demo_run_001');
    }

    public function test_swallows_registry_errors_and_returns_empty(): void
    {
        // Inyectamos un cache key corrupto (string en vez de array).
        // El registry debe manejarlo gracefully: si un scanner no puede parsearlo,
        // lo reporta como 0 jobs. El endpoint NUNCA debe lanzar 500 al cliente
        // porque eso rompería el polling del widget.
        Cache::put('avisos_scan_bg:active', 'invalid-not-array', now()->addHours(2));

        $controller = app(BgJobsController::class);
        $resp = $controller->active(Request::create('/bg-jobs/active', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());
        // OJO: el scanner retorna [] porque el active no es array, pero
        // el endpoint igual responde 200. Esto es lo que importa: el
        // polling del widget no se rompe.
        $this->assertSame([], $resp->getData(true)['jobs']);

        Cache::forget('avisos_scan_bg:active');
    }
}
