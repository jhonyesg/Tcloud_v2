<?php

namespace Tests\Feature;

use App\Http\Controllers\Ia\AvisosInteligentesController;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\LaravelTestCase;

/**
 * Tests del modo mensual del endpoint POST /scan/run-bg (fix-avisos-scan-by-months-no-saturation).
 *
 * Estos tests ejercitan el controller directamente (sin HTTP testing framework).
 *
 * Limitación operacional: el endpoint llama a `planMonths()` que query'ea
 * `transcriptions`. En phpunit, la BD `tcloudstorage_test` puede no tener
 * esa tabla o no estar seeded. Si no la tiene, los tests se saltan con
 * una nota clara (los casos del mundo real se validan con Playwright contra
 * producción).
 */
class AvisosScanMonthlyModeTest extends LaravelTestCase
{
    /**
     * Helper: si la tabla transcriptions no existe o está vacía, marca el test
     * como skipped con nota. Devuelve true si se puede continuar.
     */
    private function skipIfNoTranscriptions(): bool
    {
        try {
            $count = DB::table('transcriptions')->count();
            if ($count === 0) {
                $this->markTestSkipped(
                    'Tabla transcriptions vacía en DB de tests. ' .
                    'Para validar este test, seedear transcripciones con state=done.'
                );
                return false;
            }
        } catch (QueryException $e) {
            $this->markTestSkipped(
                'Tabla transcriptions no existe en DB de tests: ' . $e->getMessage()
            );
            return false;
        }
        return true;
    }

    public function test_run_bg_detects_monthly_mode_for_noWindow_force_without_range(): void
    {
        if (!$this->skipIfNoTranscriptions()) return;
        $ctrl = app(AvisosInteligentesController::class);
        $req = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'noWindow' => true,
                'force'    => true,
                'limit'    => 50,
            ]),
        );

        $resp = $ctrl->runScanBackground($req, app(\App\Services\Ia\AvisosScanService::class));
        $this->assertSame(202, $resp->getStatusCode(),
            'El endpoint debe responder 202 cuando hay datos. Body: ' . $resp->getContent());

        $body = $resp->getData(true);
        $this->assertSame('monthly', $body['mode'] ?? null,
            'Mode debe ser "monthly" cuando noWindow+force sin rango');
        $this->assertArrayHasKey('month_count', $body, 'month_count debe estar presente en modo monthly');
        $this->assertArrayHasKey('first_month', $body);
        $this->assertArrayHasKey('last_month', $body);
        $this->assertGreaterThan(0, $body['month_count'] ?? 0);
    }

    public function test_run_bg_returns_classic_mode_for_preset(): void
    {
        if (!$this->skipIfNoTranscriptions()) return;
        $ctrl = app(AvisosInteligentesController::class);
        $req = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'preset'   => '24h',
                'noWindow' => false,
                'force'    => true,
                'limit'    => 50,
            ]),
        );

        $resp = $ctrl->runScanBackground($req, app(\App\Services\Ia\AvisosScanService::class));
        $body = $resp->getData(true);
        $this->assertSame('classic', $body['mode'] ?? null,
            'Mode debe ser "classic" cuando hay preset');
        $this->assertArrayNotHasKey('month_count', $body);
    }

    public function test_preview_endpoint_returns_monthly_plan(): void
    {
        if (!$this->skipIfNoTranscriptions()) return;
        $ctrl = app(AvisosInteligentesController::class);
        $req = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg/preview',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'noWindow' => true,
                'force'    => true,
                'limit'    => 50,
            ]),
        );

        $resp = $ctrl->runScanBackgroundPreview($req, app(\App\Services\Ia\AvisosScanService::class));
        $this->assertSame(200, $resp->getStatusCode(),
            'Preview debe responder 200. Body: ' . $resp->getContent());

        $body = $resp->getData(true);
        $this->assertSame('monthly', $body['mode'] ?? null);
        $this->assertGreaterThan(0, $body['month_count'] ?? 0);
        $this->assertArrayHasKey('first_month', $body);
        $this->assertArrayHasKey('last_month', $body);
        $this->assertArrayHasKey('message', $body);
    }

    public function test_preview_endpoint_returns_classic_for_preset(): void
    {
        $ctrl = app(AvisosInteligentesController::class);
        $req = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg/preview',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['preset' => '24h', 'noWindow' => false]),
        );

        $resp = $ctrl->runScanBackgroundPreview($req, app(\App\Services\Ia\AvisosScanService::class));
        $body = $resp->getData(true);
        $this->assertSame('classic', $body['mode'] ?? null);
        $this->assertArrayNotHasKey('month_count', $body);
    }

    public function test_preview_does_not_mutate_cache_or_db(): void
    {
        $cache = \Illuminate\Support\Facades\Cache::store();
        $beforeKeys = $cache->get('avisos_scan_bg:active') === null ? 'absent' : 'present';

        $ctrl = app(AvisosInteligentesController::class);
        $req = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg/preview',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['noWindow' => true, 'force' => true]),
        );

        $ctrl->runScanBackgroundPreview($req, app(\App\Services\Ia\AvisosScanService::class));

        // El preview NO debe crear el pointer activo.
        $afterKeys = $cache->get('avisos_scan_bg:active') === null ? 'absent' : 'present';
        $this->assertSame($beforeKeys, $afterKeys,
            'El preview no debe escribir en cache (no debe crear el pointer activo)');
    }
}
