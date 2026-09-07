<?php

namespace Tests\Feature;

use App\Http\Controllers\Ia\ApiTranscriptorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\LaravelTestCase;

/**
 * Tests del lanzador background del batch "Escanear storages".
 *
 * Cambio: openspec/changes/fix-transcriptor-batch-bg-launcher/.
 *
 * El bug original (antes del cambio): el controller pasaba al trait un
 * `$cmd` que ya terminaba en `&` + `>> log 2>&1`. El trait envolvía eso
 * dentro de otro `&`, produciendo bash inválido
 * (`bash: -c: syntax error near unexpected token ';'`). El artisan nunca
 * arrancaba y el cache se quedaba en `status: starting` durante 2h.
 *
 * Limitación de scope: el test E2E real (cache `starting → running →
 * queued/done` con transiciones de cache entre procesos) no se puede
 * cubrir con `CACHE_DRIVER=array` en phpunit.xml: el child process
 * spawned por `execBackground` tiene su propio array store y nunca
 * comparte estado con el parent. La verificación end-to-end real
 * (task 5.x) la hace el operador contra Redis en producción. Aquí
 * validamos:
 *   1. El controller responde 200 con `run_id` válido.
 *   2. El cache inicial (status=starting) está escrito en el proceso del
 *      test (la escritura del controller ocurre antes del spawn, dentro
 *      del proceso de test, así que SÍ es visible desde el test).
 *   3. El comando bash enviado al trait es sintácticamente válido.
 *      Esto se valida en RunsBackgroundCommandsValidationTest.
 */
class TranscriptorBatchLauncherTest extends LaravelTestCase
{
    public function test_process_batch_returns_200_with_run_id_and_writes_initial_cache(): void
    {
        $controller = app(ApiTranscriptorController::class);
        $request = Request::create('/x', 'POST', [
            'batch' => 5,
            'generate_alerts' => true,
            'include_failed' => false,
        ]);

        $response = $controller->processBatch($request);

        $this->assertSame(200, $response->getStatusCode(), 'processBatch() debe responder 200');
        $payload = $response->getData(true);
        $this->assertArrayHasKey('run_id', $payload);
        $this->assertMatchesRegularExpression('/^batch_\d+_[a-f0-9]+$/', $payload['run_id']);
        $this->assertSame(5, $payload['batch']);
        $this->assertStringContainsString('Lote iniciado en background', $payload['message']);

        $cacheKey = 'transcription_batch:' . $payload['run_id'];

        // Estado inicial escrito por el controller ANTES de lanzar el wrapper.
        // Esta escritura ocurre en el proceso del test (no en el child
        // artisan), por lo que SÍ es visible desde aquí.
        $initial = Cache::get($cacheKey);
        $this->assertIsArray($initial,
            'processBatch() debe escribir cache con status=starting antes de lanzar el wrapper');
        $this->assertSame('starting', $initial['status'] ?? null);
        $this->assertSame(5, $initial['batch'] ?? null);
        $this->assertArrayHasKey('started_at', $initial);
    }
}

