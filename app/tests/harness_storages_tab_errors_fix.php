<?php
/**
 * Harness de validación del change `fix-storages-tab-cards-and-dead-retry-button`.
 *
 * Ejecuta contra PostgreSQL real. Consultas ligeras y acotadas.
 *   1. El Blade del tab Storages ya NO contiene el form roto
 *      `POST /ia/api-transcriptor/retry-batch` ni las tarjetas
 *      "Pendientes hoy" / "Listos hoy" ni los helpers JS muertos.
 *   2. El Blade SÍ contiene: contador "Errores hoy", línea de errores en la
 *      celda del snapshot, y el registro en `snapshotErrors`.
 *   3. El endpoint `GET /storages/{id}/snapshot` sigue exponiendo
 *      `current.error_count` (contrato del change transcriptor-pg-native-queue,
 *      del cual la UI nueva depende).
 *   4. La tabla `transcription_storage_snapshots` tiene la columna
 *      `error_count` y hay snapshots recientes (el snapshot corre cada 15 min).
 *   5. La ruta muerta `POST /ia/api-transcriptor/retry-batch` NO está
 *      registrada (regresión: nadie la re-agregue sin revivir el controller).
 *   6. El cron semanal de retry-batch sigue registrado (no se tocó).
 *   7. El cron de snapshot por storage sigue activo.
 *
 * Uso: cd app && php tests/harness_storages_tab_errors_fix.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

$tag = 'hstf_' . substr(bin2hex(random_bytes(4)), 0, 8);

echo "Harness fix-storages-tab-cards-and-dead-retry-button (tag: {$tag})\n";

try {
    h_section('(A) Blade limpio: sin elementos muertos');
    $blade = file_get_contents(__DIR__ . '/../resources/views/ia/api-transcriptor/index.blade.php');
    h_check($blade !== false, 'Blade del módulo legible', 'No se pudo leer index.blade.php');
    h_check(strpos($blade, 'action="/ia/api-transcriptor/retry-batch"') === false,
        'Form retry-batch ausente del Blade', 'El form muerto POST retry-batch sigue en el Blade');
    h_check(strpos($blade, 'Reintentar fallidos (upstream batch)') === false
        || preg_match('/(?<!{{-- )\n\s*<button[^>]*Reintentar fallidos/u', $blade) === 0,
        'Botón "Reintentar fallidos" ausente', 'El botón muerto sigue en el Blade');
    h_check(preg_match('/Pendientes hoy\b(?![\s\S]{0,200}retirad)/u', $blade) === 0
        && substr_count($blade, '>Pendientes hoy<') === 0,
        'Tarjeta "Pendientes hoy" ausente', 'La tarjeta "Pendientes hoy" sigue en el Blade');
    h_check(substr_count($blade, '>Listos hoy<') === 0,
        'Tarjeta "Listos hoy" ausente', 'La tarjeta "Listos hoy" sigue en el Blade');
    h_check(substr_count($blade, 'pendientesTotal()') === 0,
        'Helper JS pendientesTotal() eliminado', 'pendientesTotal() sigue en el Blade');
    h_check(substr_count($blade, 'listosTotal()') === 0,
        'Helper JS listosTotal() eliminado', 'listosTotal() sigue en el Blade');

    h_section('(B) Blade enriquecido: errores desde snapshots');
    h_check(strpos($blade, 'Errores hoy:') !== false,
        'Contador "Errores hoy" presente en el header', 'Falta el contador "Errores hoy"');
    h_check(strpos($blade, 'snapshotErrorsTotal()') !== false,
        'snapshotErrorsTotal() definido y usado', 'Falta snapshotErrorsTotal()');
    h_check(strpos($blade, 'snapshotErrors[s.id]') !== false,
        'Registro por fila en snapshotErrors', 'El fetch del snapshot no registra error_count');
    h_check(strpos($blade, 'Purga de errores de storages') !== false,
        'Purga de snapshotErrors al paginar (contador = página visible)',
        'Falta la purga de snapshotErrors en pagedStorages()');
    h_check(strpos($blade, 'error_count') !== false
        && strpos($blade, 'errores hoy') !== false,
        'Línea de errores en la celda del snapshot', 'Falta la línea de errores en la celda');
    h_check(substr_count($blade, 'grid-cols-4') === 0
        || strpos($blade, 'lg:grid-cols-2 gap-3 mb-6') !== false,
        'Grid de tarjetas reducido a 2 columnas', 'El grid de tarjetas sigue con 4 columnas');

    h_section('(C) Contrato del endpoint snapshot intacto');
    $controller = file_get_contents(__DIR__ . '/../app/Http/Controllers/Ia/ApiTranscriptorController.php');
    h_check(strpos($controller, "'current' => \$current ?: null") !== false,
        'storageSnapshot() devuelve current completo (con error_count)',
        'storageSnapshot() cambió: revisar que error_count siga expuesto');
    h_check(strpos($controller, 'public function retryBatch(') === false,
        'Controller sigue sin retryBatch() (coherente con ruta ausente)',
        'retryBatch() reapareció sin su ruta');

    h_section('(D) BD real: snapshots con error_count disponibles');
    $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'transcription_storage_snapshots' AND column_name = 'error_count'");
    h_check(count($cols) === 1, 'Columna error_count existe en transcription_storage_snapshots',
        'Falta columna error_count (migración del change pg-native-queue no aplicada)');
    // OJO: captured_at se persiste en hora local America/Bogota con sufijo
    // '+00' (patron conocido del modulo: la BD interpreta literal). Comparar
    // contra now() del server PHP convertido a Bogota, NO contra now() de PG.
    $recent = DB::selectOne("SELECT COUNT(*) AS n FROM transcription_storage_snapshots WHERE captured_at >= now() AT TIME ZONE 'America/Bogota' - interval '2 hours'");
    h_check((int) $recent->n > 0, 'Snapshots recientes (< 2h): ' . (int) $recent->n . ' filas',
        'No hay snapshots en 2 horas: el cron transcriptor:storage-snapshot no está corriendo');
    $withErrors = DB::selectOne("SELECT COUNT(*) AS n FROM transcription_storage_snapshots WHERE error_count > 0 AND captured_at >= now() - interval '7 days'");
    echo "    (informativo) snapshots con error_count > 0 en 7 días: " . (int) $withErrors->n . "\n";

    h_section('(E) Rutas: muerta ausente, cron vivo');
    $routes = artisan_route_list_flat();
    h_check(!isset($routes['POST ia/api-transcriptor/retry-batch']),
        'Ruta POST ia/api-transcriptor/retry-batch NO registrada',
        'La ruta muerta reapareció');
    $console = file_get_contents(__DIR__ . '/../routes/console.php');
    h_check(strpos($console, 'transcription:retry-batch-upstream') !== false,
        'Cron semanal transcription:retry-batch-upstream registrado',
        'El cron de retry-batch desapareció (regresión)');
    h_check(strpos($console, 'transcriptor:storage-snapshot') !== false
        || strpos($console, 'TranscriptionStorageSnapshotCommand') !== false
        || strpos($console, 'storage-snapshot') !== false,
        'Cron de snapshots por storage registrado',
        'El cron de snapshots desapareció (la columna de errores quedaría sin datos)');
} finally {
    // Este harness es read-only: no INSERTa filas, no requiere cleanup.
    // El tag solo sirve de trazabilidad en logs.
    echo "\n(read-only harness, sin cleanup necesario; tag {$tag})\n";
}

echo "\n" . ($failures === 0 ? "TODOS OK" : "{$failures} FALLOS") . "\n";
exit($failures === 0 ? 0 : 1);

function artisan_route_list_flat(): array
{
    $out = [];
    exec('php artisan route:list 2>/dev/null', $output, $code);
    if ($code !== 0 || empty($output)) {
        return $out;
    }
    // route:list sin --json: filas tipo "POST  ia/api-transcriptor/retry-batch …"
    foreach ($output as $line) {
        if (preg_match('/^(GET\|HEAD|POST|PUT|PATCH|DELETE)\s+(\S+)/', trim($line), $m)) {
            $out[$m[1] . ' ' . $m[2]] = true;
        }
    }
    return $out;
}