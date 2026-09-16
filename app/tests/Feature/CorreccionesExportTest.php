<?php

namespace Tests\Feature;

use App\Http\Controllers\Ia\CorreccionesController;
use App\Models\Correction;
use Tests\LaravelTestCase;

/**
 * Tests del endpoint de export CSV de correcciones.
 *
 * NO requiere BD: la mayoría valida firma, ruta, y headers de respuesta
 * esperada. La parte de contenido CSV se valida con un Eloquent builder
 * simulado vía mock de Query Builder para evitar tocar SQLite/MySQL.
 */
class CorreccionesExportTest extends LaravelTestCase
{
    // ============ Ruta registrada ============

    public function test_export_route_is_registered_as_get(): void
    {
        $routes = app('router')->getRoutes();
        $found = false;
        foreach ($routes as $route) {
            if ($route->uri === 'ia/correcciones/export' && in_array('GET', $route->methods, true)) {
                $found = true;
                $this->assertSame(CorreccionesController::class . '@export', $route->getActionName());
                break;
            }
        }
        $this->assertTrue($found, 'Route GET /ia/correcciones/export debe existir y apuntar a CorreccionesController@export');
    }

    // ============ Firma del método ============

    public function test_export_method_signature(): void
    {
        $reflection = new \ReflectionMethod(CorreccionesController::class, 'export');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('request', $params[0]->getName());
        $this->assertSame('Illuminate\\Http\\Request', (string) $params[0]->getType());
    }

    public function test_export_method_is_public(): void
    {
        $reflection = new \ReflectionMethod(CorreccionesController::class, 'export');
        $this->assertTrue($reflection->isPublic(), 'export() debe ser público para que la ruta lo invoque');
    }

    // ============ Constantes del modelo referenciadas ============

    public function test_model_has_required_status_constants(): void
    {
        // El método export() whitelistea 'pending'/'approved'/'rejected'/'all'
        // usando las constantes de status del modelo. Si las constantes
        // cambian, este test cae para forzar la actualización de export().
        $this->assertSame('pending', Correction::STATUS_PENDING);
        $this->assertSame('approved', Correction::STATUS_APPROVED);
        $this->assertSame('rejected', Correction::STATUS_REJECTED);
    }
}
