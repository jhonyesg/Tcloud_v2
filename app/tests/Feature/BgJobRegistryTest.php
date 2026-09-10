<?php

namespace Tests\Feature;

use App\Services\BgJobRegistry;
use Illuminate\Support\Facades\Cache;
use Tests\LaravelTestCase;

/**
 * Tests del registry que agrega jobs en background de todos los módulos.
 *
 * Cambio: openspec/changes/add-bg-job-indicator-widget/.
 */
class BgJobRegistryTest extends LaravelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Limpiar cache entre tests
        Cache::forget('avisos_scan_bg:active');
        Cache::forget('avisos_scan_bg:test_reg_001');
        Cache::forget('transcription_batch:active_runs');
        Cache::forget('transcription_batch:test_reg_002');
    }

    public function test_discover_returns_empty_array_when_no_jobs(): void
    {
        $jobs = app(BgJobRegistry::class)->discover();
        $this->assertSame([], $jobs);
    }

    public function test_discover_picks_up_avisos_scan_when_active(): void
    {
        $runId = 'test_reg_001';
        Cache::put('avisos_scan_bg:active', ['runId' => $runId], now()->addHours(2));
        Cache::put("avisos_scan_bg:{$runId}", [
            'attempted_count' => 5000,
            'scanned' => 3400,
            'hits_new' => 1200,
            'failed' => 0,
            'batches' => 8,
            'started_at' => now()->subMinutes(2)->toIso8601String(),
        ], now()->addHours(2));

        $jobs = app(BgJobRegistry::class)->discover();
        $this->assertCount(1, $jobs);
        $this->assertSame('avisos-scan', $jobs[0]['kind']);
        $this->assertSame($runId, $jobs[0]['runId']);
        $this->assertSame(3400, $jobs[0]['progress']['scanned']);
        $this->assertSame(1200, $jobs[0]['progress']['hits_new']);
        $this->assertStringContainsString($runId, $jobs[0]['url']);
    }

    public function test_discover_picks_up_transcriptor_batch_when_active(): void
    {
        $runId = 'test_reg_002';
        Cache::put('transcription_batch:active_runs', [$runId], now()->addHours(2));
        Cache::put("transcription_batch:{$runId}", [
            'status' => 'running',
            'processed' => 5,
            'total_to_process' => 44,
            'errors' => 0,
            'started_at' => now()->subMinutes(1)->toIso8601String(),
        ], now()->addHours(2));

        $jobs = app(BgJobRegistry::class)->discover();
        $this->assertCount(1, $jobs);
        $this->assertSame('transcriptor-batch', $jobs[0]['kind']);
        $this->assertSame($runId, $jobs[0]['runId']);
        $this->assertSame(5, $jobs[0]['progress']['processed']);
        $this->assertSame(44, $jobs[0]['progress']['total']);
        $this->assertSame('running', $jobs[0]['progress']['status']);
    }

    public function test_discover_skips_terminal_batch_jobs(): void
    {
        $runId = 'test_reg_done';
        Cache::put('transcription_batch:active_runs', [$runId], now()->addHours(2));
        Cache::put("transcription_batch:{$runId}", [
            'status' => 'done', 'processed' => 10, 'total_to_process' => 10,
            'started_at' => now()->subMinutes(5)->toIso8601String(),
        ], now()->addHours(2));

        $jobs = app(BgJobRegistry::class)->discover();
        // El scanner lo limpió automáticamente de la lista
        $this->assertCount(0, $jobs);
        $this->assertNull(Cache::get('transcription_batch:active_runs'));
    }

    public function test_known_kinds_returns_registered_scanners(): void
    {
        $kinds = app(BgJobRegistry::class)->knownKinds();
        $this->assertContains('avisos-scan', $kinds);
        $this->assertContains('transcriptor-batch', $kinds);
    }
}
