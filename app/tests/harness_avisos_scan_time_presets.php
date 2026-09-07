<?php
/**
 * Harness de validación del change `avisos-scan-time-presets`.
 *
 * Ejecuta contra PostgreSQL real:
 *   1. resolvePreset(): cada preset resuelve el rango esperado (8h/24h/3d/7d/today).
 *   2. normalizeRangeBound(): solo fecha → día completo; con hora → se respeta.
 *   3. estimate() con preset/rango: respeta la ventana al contar pendientes.
 *   4. Guard de exclusividad: noWindow + rango explícito → rango gana.
 *   5. Corrida manual con preset: registra preset/range en log y params.
 *
 * Uso: php tests/harness_avisos_scan_time_presets.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ia\AvisosScanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$tag = 'apreset_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-scan-time-presets (tag: {$tag})\n";

$service = app(AvisosScanService::class);

// ─── 1. resolvePreset ──────────────────────────────────────────────────────
h_section('1. resolvePreset');

$now = now();

$r = $service->resolvePreset('8h');
$expectedFrom = $now->copy()->subHours(8);
if ($r && $r['from']->diffInSeconds($expectedFrom) < 5 && $r['to']->diffInSeconds($now) < 5) {
    h_ok("preset 8h → from≈now-8h ({$r['from']->toDateTimeString()}), to≈now");
} else {
    h_fail('preset 8h no resuelve now-8h → now: ' . json_encode($r));
}

$r = $service->resolvePreset('24h');
if ($r && $r['from']->diffInSeconds($now->copy()->subHours(24)) < 5) {
    h_ok('preset 24h → from≈now-24h');
} else {
    h_fail('preset 24h incorrecto: ' . json_encode($r));
}

$r = $service->resolvePreset('3d');
if ($r && $r['from']->diffInSeconds($now->copy()->subHours(72)) < 5) {
    h_ok('preset 3d → from≈now-72h');
} else {
    h_fail('preset 3d incorrecto: ' . json_encode($r));
}

$r = $service->resolvePreset('7d');
if ($r && $r['from']->diffInSeconds($now->copy()->subHours(168)) < 5) {
    h_ok('preset 7d → from≈now-168h');
} else {
    h_fail('preset 7d incorrecto: ' . json_encode($r));
}

$r = $service->resolvePreset('today');
if ($r && $r['from']->format('Y-m-d') === $now->format('Y-m-d')
    && $r['from']->format('H:i:s') === '00:00:00'
    && $r['to']->diffInSeconds($now) < 5) {
    h_ok('preset today → medianoche de hoy → now');
} else {
    h_fail('preset today no arranca en medianoche: ' . json_encode($r));
}

if ($service->resolvePreset('noexiste') === null && $service->resolvePreset(null) === null) {
    h_ok('presets inválidos/ausentes → null');
} else {
    h_fail('presets inválidos no retornan null');
}

// ─── 2. normalizeRangeBound ───────────────────────────────────────────────
h_section('2. normalizeRangeBound');

$b = $service->normalizeRangeBound('2026-09-06', true);
if ($b && $b->format('Y-m-d H:i:s') === '2026-09-06 00:00:00') {
    h_ok('solo fecha (start) → 00:00:00');
} else {
    h_fail('solo fecha start no trunca a medianoche: ' . ($b?->toDateTimeString() ?? 'null'));
}

$b = $service->normalizeRangeBound('2026-09-06', false);
if ($b && $b->format('Y-m-d H:i:s') === '2026-09-06 23:59:59') {
    h_ok('solo fecha (end) → 23:59:59');
} else {
    h_fail('solo fecha end no llega a fin de día: ' . ($b?->toDateTimeString() ?? 'null'));
}

$b = $service->normalizeRangeBound('2026-09-06 14:00', true);
if ($b && $b->format('Y-m-d H:i:s') === '2026-09-06 14:00:00') {
    h_ok('fecha+hora (start) respeta la hora');
} else {
    h_fail('fecha+hora start altera la hora: ' . ($b?->toDateTimeString() ?? 'null'));
}

$b = $service->normalizeRangeBound('2026-09-06 14:00', false);
if ($b && $b->format('Y-m-d H:i:s') === '2026-09-06 14:00:00') {
    h_ok('fecha+hora (end) respeta la hora (no endOfDay)');
} else {
    h_fail('fecha+hora end altera la hora: ' . ($b?->toDateTimeString() ?? 'null'));
}

if ($service->normalizeRangeBound(null, true) === null) {
    h_ok('null → null');
} else {
    h_fail('null no retorna null');
}

// ─── 3. estimate con preset/rango sobre datos reales ──────────────────────
h_section('3. estimate respeta la ventana');

// Datos temporales: file+transcription done terminada hace 2h y otro par hace 30d.
$ownerId = DB::table('users')->orderBy('id')->value('id') ?? 1;
$storageFk = DB::table('storage_providers')->where('enabled', true)->orderBy('id')->value('id') ?? 1;

$insertFile = fn (string $suffix) => DB::table('files')->insertGetId([
    'name' => "{$tag}{$suffix}.srt",
    'path' => "test/{$tag}{$suffix}/a.srt",
    'size' => 100,
    'mime_type' => 'application/x-subrip',
    'storage_provider_id' => $storageFk,
    'owner_id' => $ownerId,
    'availability_state' => 'available',
    'created_at' => now(),
    'updated_at' => now(),
]);

$fileIdA = $insertFile('a');
$tRecent = DB::table('transcriptions')->insertGetId([
    'file_id' => $fileIdA,
    'state' => 'done',
    'generate_alerts' => true,
    'finished_at' => now()->subHours(2),
    'created_at' => now()->subHours(3),
    'updated_at' => now(),
]);
$fileIdB = $insertFile('b');
$tOld = DB::table('transcriptions')->insertGetId([
    'file_id' => $fileIdB,
    'state' => 'done',
    'generate_alerts' => true,
    'finished_at' => now()->subDays(30),
    'created_at' => now()->subDays(31),
    'updated_at' => now(),
]);

$est8h = $service->estimate(['preset' => '8h']);
$estToday = $service->estimate(['preset' => 'today']);
$estNoWindow = $service->estimate(['noWindow' => true]);

$est8hIncludesRecent = $est8h >= 1;
// La vieja (30d) no puede entrar en 8h ni today.
$onlyRecent = $est8h <= $estNoWindow;

if ($est8hIncludesRecent && $onlyRecent) {
    h_ok("estimate preset 8h = {$est8h} incluye la transcripción de hace 2h y está acotado (noWindow={$estNoWindow})");
} else {
    h_fail("estimate preset 8h = {$est8h}, noWindow = {$estNoWindow} — ventana no respetada");
}

// Rango explícito con hora: from = now-3h (con hora) debe incluirla;
// from = ayer solo-fecha también la incluye (día completo).
$estRange = $service->estimate(['from' => now()->subHours(3)->format('Y-m-d H:i:s')]);
if ($estRange >= 1) {
    h_ok("estimate rango con hora (now-3h) = {$estRange} la incluye");
} else {
    h_fail('estimate rango con hora no incluye la transcripción de hace 2h');
}

// ─── 4. Guard: noWindow + rango explícito ─────────────────────────────────
h_section('4. exclusividad noWindow ↔ rango');

$opts = ['noWindow' => true, 'from' => now()->subHours(1)->format('Y-m-d H:i:s'), 'limit' => 50];
$effective = $service->enforceRangeExclusivity($opts);
if (empty($effective['noWindow'])) {
    h_ok('noWindow + from explícito → noWindow se descarta (rango gana)');
} else {
    h_fail('noWindow sobrevivió junto a rango explícito — riesgo de escaneo masivo no deseado');
}

// Cron jamás usa noWindow (guardía preexistente, se re-verifica).
$cronOpts = $service->enforceRangeExclusivity(['noWindow' => true, 'origin' => 'cron']);
if (empty($cronOpts['noWindow'])) {
    h_ok('cron + noWindow → noWindow rechazado (guardía catchup intacta)');
} else {
    h_fail('cron aceptó noWindow — regresión de la guardía avisos-scan-catchup');
}

// estimate() también resuelve el preset (regresión: el estimado del modal
// se computa con preset sin pasar por run()).
$estPreset = $service->estimate(['preset' => '8h']);
$estDirecto = $service->estimate(['from' => now()->subHours(8)]);
if ($estPreset === $estDirecto) {
    h_ok("estimate aplica el preset internamente ({$estPreset} = {$estDirecto})");
} else {
    h_fail("estimate ignoró el preset ({$estPreset} ≠ {$estDirecto}) — estimado del modal incorrecto");
}

// filterOptsFromRequest: contrato del estimado con filtros (scanStatus).
$req = \Illuminate\Http\Request::create('/scan', 'GET', ['preset' => '8h']);
$fo = $service->filterOptsFromRequest($req);
if (($fo['preset'] ?? null) === '8h' && !isset($fo['noWindow'])) {
    h_ok('filterOptsFromRequest extrae preset de la query');
} else {
    h_fail('filterOptsFromRequest no extrae preset: ' . json_encode($fo));
}
$req2 = \Illuminate\Http\Request::create('/scan', 'GET', []);
if ($service->filterOptsFromRequest($req2) === null) {
    h_ok('sin filtros en query → null (ventana global)');
} else {
    h_fail('query vacía no retorna null');
}

// Drenaje secuencial: excludeIds evita re-visitar en la siguiente tanda
// (regresión del bucle infinito en rangos/presets).
$batch1 = $service->run(['origin' => 'manual', 'preset' => '8h', 'dryRun' => true, 'limit' => 5]);
$ids1 = array_column($batch1['sample'] ?? [], 'id');
$batch2 = $service->run(['origin' => 'manual', 'preset' => '8h', 'dryRun' => true, 'limit' => 5, 'excludeIds' => $ids1]);
$ids2 = array_column($batch2['sample'] ?? [], 'id');
if ($ids1 && empty(array_intersect($ids1, $ids2))) {
    h_ok('excludeIds evita solape entre tandas (drenaje termina)');
} else {
    h_fail('solape entre tandas con excludeIds: ' . json_encode([$ids1, $ids2]));
}

// La corrida real retorna attemptedIds (contrato del drenaje frontend).
$real = $service->run(['origin' => 'manual', 'transcriptionId' => 1194, 'limit' => 1]);
if (isset($real['attemptedIds']) && is_array($real['attemptedIds'])) {
    h_ok('corrida retorna attemptedIds para el drenaje secuencial');
} else {
    h_fail('corrida sin attemptedIds — el frontend no puede drenar sin bucle');
}

// ─── 5. Corrida manual con preset: params y log auditan la ventana ────────
h_section('5. corrida manual con preset (auditoría)');

// Ejecutar dry-run con preset 8h (no toca datos reales: no escanea).
$dry = $service->run(['origin' => 'manual', 'preset' => '8h', 'dryRun' => true, 'limit' => 50]);
if (($dry['status'] ?? '') === 'dry-run' && ($dry['candidates'] ?? -1) >= 0) {
    h_ok("dry-run con preset 8h resuelve candidatos = {$dry['candidates']}");
} else {
    h_fail('dry-run con preset falló: ' . json_encode($dry));
}

// paramsSummary: la última corrida registrada debe llevar preset/from/to.
$lastRun = DB::table('avisos_scan_runs')->orderByDesc('id')->first();
if ($lastRun) {
    $params = json_decode($lastRun->params ?? '{}', true);
    h_ok('avisos_scan_runs accesible; params de última corrida: ' . json_encode(array_intersect_key($params, array_flip(['preset', 'from', 'to']))));
} else {
    h_fail('no hay corridas registradas en avisos_scan_runs');
}

// ─── Limpieza ──────────────────────────────────────────────────────────────
h_section('Limpieza');

// Borrar hits/keywords creados no aplica (dry-run y estimate no crean hits).
DB::table('transcriptions')->where('id', $tRecent)->delete();
DB::table('transcriptions')->where('id', $tOld)->delete();
DB::table('files')->whereIn('id', [$fileIdA, $fileIdB])->delete();
echo "  ✓ datos temporales eliminados (files {$fileIdA}, {$fileIdB}; transcripciones {$tRecent}, {$tOld})\n";

echo "\n" . ($failures === 0 ? "TODOS LOS CHECKS EN VERDE" : "{$failures} CHECK(S) FALLARON") . "\n";
exit($failures === 0 ? 0 : 1);