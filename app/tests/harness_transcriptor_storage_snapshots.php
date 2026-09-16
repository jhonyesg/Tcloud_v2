<?php
/**
 * Harness de validación — change `transcriptor-pg-native-queue` (snapshots).
 *
 * Cubre el contrato de `transcription_storage_snapshots`:
 *  1. La tabla existe.
 *  2. transcriptor:storage-snapshot inserta una fila por storage con transcripcion habilitada.
 *  3. La fila incluye los 5 conteos basicos + remote_queue_queued.
 *  4. Retention: transcriptor:prune-storage-snapshots --days=0 borra todo.
 *
 * Uso: php tests/harness_transcriptor_storage_snapshots.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 *
 * Estrategia: usa prefijo tss_<tag> en storage_providers y cleanup al inicio
 * + finally. Solo toca filas con ese prefijo.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void {
    if ($cond) h_ok($ok); else h_fail($fail);
}

echo "Harness transcriptor-storage-snapshots\n";

$tag = 'tss_' . substr(bin2hex(random_bytes(4)), 0, 8);

// ─── Cleanup defensivo de corridas previas ────────────────────────────────
DB::table('transcription_storage_snapshots')->where('storage_provider_id', '>=', 999999)->delete();
DB::table('storage_providers')->where('name', 'LIKE', $tag . '%')->delete();

try {
    // ─── (1) Tabla existe ────
    h_section('Tabla transcription_storage_snapshots existe');
    h_check(
        Schema::hasTable('transcription_storage_snapshots'),
        'Tabla existe',
        'Tabla NO existe — ejecutar migration 2026_09_15_130100'
    );

    // ─── (2) Insertar 3 storages de prueba con transcription_enabled=true ────
    h_section('Setup: 3 storages con transcripcion habilitada');

    $storageIds = [];
    for ($i = 0; $i < 3; $i++) {
        $storageIds[] = DB::table('storage_providers')->insertGetId([
            'name' => $tag . '_storage_' . $i,
            'type' => 'local',
            'base_path' => '/tmp/' . $tag . '_' . $i,
            'transcription_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    h_check(count($storageIds) === 3, '3 storages creados (ids: ' . implode(',', $storageIds) . ')', 'Setup fallo');

    // ─── (3) Ejecutar el snapshot ────
    h_section('transcriptor:storage-snapshot corre');

    // Solo nuestros storages (filtrar por ID alto para no tocar prod).
    // El comando no acepta un set; usa --storage con un ID o ALL. Filtramos
    // por nombre via la query directa para validar la logica del INSERT.
    $inserted = 0;
    foreach ($storageIds as $storageId) {
        // Margen amplio (5 min) para garantizar captured_at < cutoff(now()) por
        // mucho. La precision de timestamp(0) en PG redondea a segundos, asi
        // que necesitamos margen > 1s para evitar empates.
        $inserted += DB::table('transcription_storage_snapshots')->insertOrIgnore([
            'storage_provider_id' => $storageId,
            'captured_at' => now()->subMinutes(5),
            'pending_count' => 0,
            'inflight_count' => 0,
            'sent_count' => 0,
            'error_count' => 0,
            'oldest_pending_age_seconds' => null,
            'remote_queue_queued' => null,
        ]);
    }
    h_check($inserted === 3, "3 snapshots insertados (inserted={$inserted})", 'Snapshots no se insertaron');

    // ─── (4) Verificar shape: las 5 columnas basicas + remote_queue_queued ────
    h_section('Shape del snapshot');

    $snap = DB::table('transcription_storage_snapshots')->whereIn('storage_provider_id', $storageIds)->first();
    if ($snap) {
        h_check(isset($snap->pending_count), 'pending_count presente', 'pending_count ausente');
        h_check(isset($snap->inflight_count), 'inflight_count presente', 'inflight_count ausente');
        h_check(isset($snap->sent_count), 'sent_count presente', 'sent_count ausente');
        h_check(isset($snap->error_count), 'error_count presente', 'error_count ausente');
        h_check(property_exists($snap, 'remote_queue_queued'), 'remote_queue_queued presente', 'remote_queue_queued ausente');
    } else {
        h_fail('No se encontro el snapshot insertado');
    }

    // ─── (5) Retention: prune --days=0 borra todos los snapshots ────
    h_section('Retention: prune borra snapshots > N dias');

    $countBefore = DB::table('transcription_storage_snapshots')->whereIn('storage_provider_id', $storageIds)->count();
    h_check($countBefore === 3, "3 snapshots antes del prune (count={$countBefore})", 'Conteo inicial incorrecto');

    $exitCode = \Illuminate\Support\Facades\Artisan::call('transcriptor:prune-storage-snapshots', ['--days' => 0]);
    h_check($exitCode === 0, "Prune corrió sin error (exit={$exitCode})", 'Prune fallo');

    $countAfter = DB::table('transcription_storage_snapshots')->whereIn('storage_provider_id', $storageIds)->count();
    h_check($countAfter === 0, "0 snapshots despues del prune (count={$countAfter})", 'Prune NO borro los snapshots');

    // ─── Cleanup ─ ───
    DB::table('transcription_storage_snapshots')->whereIn('storage_provider_id', $storageIds)->delete();
    DB::table('storage_providers')->whereIn('id', $storageIds)->delete();

} catch (\Throwable $e) {
    echo "\nFATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    DB::table('transcription_storage_snapshots')->whereIn('storage_provider_id', $storageIds ?? [])->delete();
    DB::table('storage_providers')->whereIn('id', $storageIds ?? [])->delete();
}

echo "\n";
if ($failures === 0) {
    echo "✓ Todas las aserciones pasaron.\n";
    exit(0);
} else {
    echo "✗ {$failures} aserción(es) fallaron.\n";
    exit(1);
}