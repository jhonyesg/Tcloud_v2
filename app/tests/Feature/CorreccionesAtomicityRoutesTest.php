<?php

namespace Tests\Feature;

use App\Http\Controllers\Ia\CorreccionesController;
use Tests\LaravelTestCase;

/**
 * Tests de las rutas nuevas del change 2026-08-02-corrections-dictionary-atomicity.
 *
 * Valida que las rutas estén registradas y apunten a los métodos correctos.
 * Los tests de integración con BD se hacen manualmente dado que esta suite
 * corre sin BD.
 */
class CorreccionesAtomicityRoutesTest extends LaravelTestCase
{
    private const EXPECTED_ROUTES = [
        // [uri, method, controller_action]
        ['ia/correcciones/{id}/atomicity-suggestions', 'GET', 'atomicitySuggestions'],
        ['ia/correcciones/{id}/atomicity-suggestions/bulk-add', 'POST', 'bulkCreateAtomicityFromCorrection'],
        ['ia/correcciones/bulk-destroy-inactive', 'POST', 'bulkDestroyInactive'],
        ['ia/correcciones/dictionary-audit', 'GET', 'auditReport'],
    ];

    public function test_atomicity_routes_registered(): void
    {
        $routes = app('router')->getRoutes();
        // Map: uri => method => action. Laravel incluye HEAD junto con GET.
        $registered = [];
        foreach ($routes as $route) {
            $methods = array_map(
                fn ($m) => $m === 'HEAD' ? 'GET' : $m,
                $route->methods
            );
            foreach ($methods as $m) {
                $registered[$route->uri][strtoupper($m)] = $route->getActionName();
            }
        }

        foreach (self::EXPECTED_ROUTES as [$uri, $method, $action]) {
            $uriKey = $uri;
            $methodKey = strtoupper($method);
            $this->assertArrayHasKey($uriKey, $registered, "Ruta $uri no registrada");
            $this->assertArrayHasKey($methodKey, $registered[$uriKey], "Método $method no registrado para $uri (registered: " . implode(',', array_keys($registered[$uriKey] ?? [])) . ")");
            $this->assertStringContainsString(
                "@{$action}",
                $registered[$uriKey][$methodKey],
                "Ruta $uri $method debe apuntar a @{$action}"
            );
            $this->assertStringContainsString(
                CorreccionesController::class,
                $registered[$uriKey][$methodKey],
                "Ruta $uri $method debe usar CorreccionesController"
            );
        }
    }

    public function test_atomicity_methods_exist(): void
    {
        foreach (self::EXPECTED_ROUTES as [$uri, $method, $action]) {
            $this->assertTrue(
                method_exists(CorreccionesController::class, $action),
                "CorreccionesController debe tener método $action (para $uri $method)"
            );
        }
    }

    public function test_bulk_destroy_inactive_signature(): void
    {
        $ref = new \ReflectionMethod(CorreccionesController::class, 'bulkDestroyInactive');
        $params = $ref->getParameters();
        $this->assertSame('request', $params[0]->getName());
        $this->assertSame('Illuminate\\Http\\Request', (string) $params[0]->getType());
        $this->assertSame('service', $params[1]->getName());
        $this->assertSame('App\\Services\\Ia\\CorrectionService', (string) $params[1]->getType());
    }
}
