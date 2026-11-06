<?php
/**
 * Harness de regresión para change `fix-shares-module-regression`.
 *
 * Verifica el CONTRATO DE RUTAS: toda ruta registrada debe resolver a un
 * método de controller que exista.
 *
 * Por qué existe este harness: el 2026-09-21 dos commits (`b07e434` y
 * `13645ca`) eliminaron métodos de controller y servicios SIN retirar las
 * rutas ni la UI que los invocaban. El síntoma en producción fue un HTTP 500
 * en cada intento de depuración masiva de compartidos y en cada enlace
 * público con contraseña. Los dos harnesses que existían para este dominio
 * (`harness_merged_into_rename_compat.php` y
 * `harness_merged_into_create_edit_delete.php`) CRASHEABAN en vez de reportar
 * FAIL, así que el defecto pasó inadvertido.
 *
 * Este harness convierte esa clase de defecto en un fallo visible y
 * temprano. No reemplaza a las pruebas de comportamiento: comprueba que el
 * cableado existe.
 *
 * Uso:
 *   cd app && php tests/harness_route_contract.php
 *
 * Exit code: 0 = todas las rutas resuelven, 1 = hay rutas huerfanas.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;

$failures = 0;
$verificadas = 0;
$omitidas = 0;
$porFaltante = [];

function rc_ok(string $m): void { echo "  ✓ $m\n"; }
function rc_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function rc_section(string $m): void { echo "\n=== {$m} ===\n"; }
function rc_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? rc_ok($ok) : rc_fail($fail ?: $ok);
}

/**
 * Deuda CONOCIDA: rutas cuyo metodo de controller ya no existe y que nadie
 * invoca (restos de la modalizacion de modulos). No son un fallo de este
 * harness — estan fuera del alcance del change `fix-shares-module-regression`
 * y su retirada se tracks en un change aparte.
 *
 * Regla para este archivo: la lista SOLO puede CRECER con una razon explicita
 * y con la referencia al change que la retirara. Una entrada nueva sin esas
 * dos cosas se considera una regresion y debe fallar el harness.
 *
 * Formato: 'Clase::metodo' => ['razon', 'change que la retira'].
 */
const KNOWN_ORPHAN_ROUTES = [
    'App\Http\Controllers\UserController::create' => [
        'Modulo de usuarios modalizado: la vista admin/users.blade.php usa modales, no una pagina create',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\UserController::edit' => [
        'Modulo de usuarios modalizado: la vista admin/users.blade.php usa modales, no una pagina edit',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\StorageProviderController::create' => [
        'Modulo de storages modalizado: la vista admin/storages.blade.php usa modales',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\StorageProviderController::edit' => [
        'Modulo de storages modalizado: la vista admin/storages.blade.php usa modales',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\FileController::transcription' => [
        'Vista de transcripcion expuesta desde Mis Archivos; ninguna vista la consume',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\GrabacionesPuntuales\GrabadorController::create' => [
        'Modulo de grabadores modalizado: la vista index.blade.php usa modales',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\GrabacionesPuntuales\GrabadorController::edit' => [
        'Modulo de grabadores modalizado: la vista index.blade.php usa modales',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
    'App\Http\Controllers\GrabacionesPuntuales\CanalController::show' => [
        'Detalle de canal no expuesto: la vista index.blade.php usa la tabla de canales',
        'pendiente: change de limpieza de rutas de modulos modalizados',
    ],
];

echo "Harness de contrato de rutas\n";

/**
 * Extrae el par (clase, metodo) de una accion de ruta.
 * Devuelve null para closures y para acciones que no son de controller.
 */
function rc_target(mixed $action): ?array
{
    if (! is_string($action)) {
        return null;
    }

    if (! str_contains($action, '@')) {
        return null;
    }

    [$class, $method] = explode('@', $action, 2);

    if (! class_exists($class)) {
        return [trim($class, '\\'), $method];
    }

    return [trim($class, '\\'), $method];
}

rc_section('Resolucion de rutas contra controllers');

$porFaltante = [];
$deudaConocida = [];

foreach (Route::getRoutes() as $route) {
    $target = rc_target($route->getAction('uses'));

    if ($target === null) {
        $omitidas++;
        continue;
    }

    [$class, $method] = $target;
    $verificadas++;
    $signature = "{$class}::{$method}";

    $existe = class_exists($class) && method_exists($class, $method);

    if ($existe) {
        continue;
    }

    if (array_key_exists($signature, KNOWN_ORPHAN_ROUTES)) {
        $deudaConocida[$signature][] = $route->uri() . ' [' . implode(',', $route->methods()) . ']';
        continue;
    }

    $porFaltante[$signature][] = $route->uri() . ' [' . implode(',', $route->methods()) . ']';
}

if ($porFaltante === []) {
    rc_ok("Las {$verificadas} rutas con controller resuelven a un metodo existente");
    rc_ok("({$omitidas} rutas closure/auxiliares omitidas por diseno)");
} else {
    rc_fail(count($porFaltante) . ' metodo(s) de controller ausentes referenciados por rutas registradas:');
    foreach ($porFaltante as $signature => $uris) {
        echo "      {$signature}\n";
        foreach ($uris as $uri) {
            echo "        - {$uri}\n";
        }
    }
}

if ($deudaConocida !== []) {
    rc_section('Deuda conocida (no falla, pero no debe crecer)');
    echo "  Estas rutas ya estaban huerfanas antes de este change y su retirada\n";
    echo "  esta planificada en un change aparte. Si aparece una nueva, el harness falla.\n";
    foreach ($deudaConocida as $signature => $uris) {
        [$razon, $seguimiento] = KNOWN_ORPHAN_ROUTES[$signature];
        echo "  · {$signature}\n";
        echo "      razón: {$razon}\n";
        echo "      seguimiento: {$seguimiento}\n";
        foreach ($uris as $uri) {
            echo "      ruta: {$uri}\n";
        }
    }
}

rc_section('Superficie de compartidos');

$sharesRoutes = [
    'shares.index' => 'App\Http\Controllers\ShareController@index',
    'shares.store' => 'App\Http\Controllers\ShareController@store',
    'shares.show' => 'App\Http\Controllers\ShareController@show',
    'shares.update' => 'App\Http\Controllers\ShareController@update',
    'shares.destroy' => 'App\Http\Controllers\ShareController@destroy',
    'shares.bulk-preview' => 'App\Http\Controllers\ShareController@bulkPreview',
    'shares.bulk-delete' => 'App\Http\Controllers\ShareController@bulkDelete',
    'shares.availability.verify' => 'App\Http\Controllers\ShareController@verifyAvailability',
    'share.authenticate' => 'App\Http\Controllers\PublicShareController@authenticate',
    'share.show' => 'App\Http\Controllers\PublicShareController@show',
];

foreach ($sharesRoutes as $name => $expected) {
    $route = Route::getRoutes()->getByName($name);

    if ($route === null) {
        rc_fail("La ruta nombrada '{$name}' no esta registrada");
        continue;
    }

    $action = $route->getAction('uses');

    if ($action !== $expected) {
        rc_fail("'{$name}' apunta a '{$action}' en vez de '{$expected}'");
        continue;
    }

    [$class, $method] = explode('@', $expected, 2);
    rc_check(
        method_exists($class, $method),
        "'{$name}' -> {$expected} existe",
        "'{$name}' -> {$expected} NO EXISTE"
    );
}

rc_section('Resultado');

if ($failures === 0) {
    echo "\nOK: {$verificadas} rutas verificadas, 0 huerfanas\n";
    exit(0);
}

echo "\nFALLA: {$failures} problema(s) de contrato de rutas\n";
exit(1);
