<?php

namespace Tests\Feature;

use App\Http\Controllers\Ia\AvisosInteligentesController;
use Illuminate\Http\Request;
use Tests\LaravelTestCase;

/**
 * Tests de regresión para el bug "Undefined array key" en
 * AvisosInteligentesController::runScanBackground línea 416.
 *
 * Cambio: openspec/changes/fix-avisos-scanlaunch-from-undefined-key/.
 *
 * Escenario exacto del reporte original:
 *   - Operador elige "Histórico completo" + "Forzar re-escaneo" en el modal.
 *   - El body del POST NO trae `from`/`to` (solo aplican a "Rango personalizado").
 *   - ANTES del fix: la línea 416 hacía `$validated['from'] || $validated['to']`
 *     en una expresión compuesta, sin protección `??`. PHP 8.4 lanzaba
 *     `Undefined array key "from"` y el handler global devolvía 500 opaco.
 *   - DESPUÉS del fix: la línea extrae `$preset`/`$from`/`$to` con `??` ANTES
 *     de usarlos, así que no lanza. Además, un try/catch envolvente devuelve
 *     un JSON con `error` legible si otra excepción interna ocurre.
 */
class AvisosScanLaunchRegressionTest extends LaravelTestCase
{
    /**
     * Reproduce el bug original exactamente: POST con noWindow=true y force=true,
     * sin from/to/preset. Antes del fix, esto devolvía 500 con
     * {"message":"Server Error"}. Después debe ser 202 (o 409 si hay race)
     * con un runId válido.
     */
    public function test_run_bg_with_no_window_and_force_does_not_throw_undefined_key(): void
    {
        $controller = app(AvisosInteligentesController::class);
        $request = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ],
            json_encode([
                'noWindow' => true,
                'force'    => true,
                'limit'    => 50,
            ]),
        );

        $response = $controller->runScanBackground($request, app(\App\Services\Ia\AvisosScanService::class));
        $body = $response->getContent() ?? '';

        // La verificación CRÍTICA del fix: el body NO debe contener
        // "Undefined array key" con la clave faltante. Ese era el bug original
        // (línea 416 con `$validated['from']` sin ??).
        //
        // El status puede ser 202 (worker arrancó OK), 409 (race con scan previo),
        // o 500 con mensaje legítimo ("El proceso de escaneo no arrancó") cuando
        // el liveness ping de 2s falla porque el worker supervisord no está
        // corriendo bajo phpunit. La regresión que nos importa es la AUSENCIA
        // del error "Undefined array key", no el status code en sí.
        $this->assertStringNotContainsString('Undefined array key', $body,
            'El body no debe contener "Undefined array key" — el bug de línea 416 está arreglado. ' .
            'Body: ' . $body);
        $this->assertStringNotContainsString('"from"', $body,
            'El body no debe exponer el nombre de la clave faltante. Body: ' . $body);
        $this->assertStringNotContainsString('"to"', $body,
            'El body no debe exponer el nombre de la clave faltante. Body: ' . $body);
    }

    /**
     * Caso complementario: con `preset=24h` debe seguir funcionando idéntico
     * (regresión — el fix no debe romper el flujo con rango válido).
     *
     * Misma nota que el test anterior: en phpunit el worker supervisord no
     * corre, así que el liveness ping devuelve 500 con mensaje legítimo.
     * Lo que nos importa es que NO mencione "Undefined array key".
     */
    public function test_run_bg_with_preset_does_not_throw_undefined_key(): void
    {
        $controller = app(AvisosInteligentesController::class);
        $request = Request::create(
            '/ia/avisos-inteligentes/scan/run-bg',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'preset'   => '24h',
                'noWindow' => false,
                'limit'    => 50,
            ]),
        );

        $response = $controller->runScanBackground($request, app(\App\Services\Ia\AvisosScanService::class));
        $body = $response->getContent() ?? '';
        $this->assertStringNotContainsString('Undefined array key', $body,
            'El cuerpo de respuesta no debe contener "Undefined array key". Body: ' . $body);
    }

    /**
     * Verifica que el helper respondWithError existe y devuelve el shape esperado.
     * Es la red de seguridad contra futuras excepciones similares.
     */
    public function test_respond_with_error_helper_shape(): void
    {
        $controller = app(AvisosInteligentesController::class);
        $exception = new \ErrorException('Undefined array key "from"', 0, E_WARNING, '/test/file.php', 416);

        $reflection = new \ReflectionMethod($controller, 'respondWithError');
        $this->assertTrue($reflection->isPrivate(), 'respondWithError debe ser private');
        $reflection->setAccessible(true);

        $response = $reflection->invoke($controller, $exception, 'iniciar escaneo');
        $this->assertSame(500, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('Error al iniciar escaneo: Undefined array key "from"', $payload['error']);
        $this->assertSame('Error al iniciar escaneo: Undefined array key "from"', $payload['message']);
    }
}
