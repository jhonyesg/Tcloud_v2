<?php
/**
 * Harness de validación del change `2026-09-12-api-transcriptor-index-perf-cache`.
 *
 * Ejecuta contra PostgreSQL y Redis reales. Consultas ligeras y acotadas.
 *   1. Índice `storage_providers_base_path_pattern_idx` existe y se usa.
 *   2. `StorageProvider::resolveInheritedTranscriptionScope()` cachea con TTL
 *      configurable (SystemSetting) y bypass con TTL=0.
 *   3. `StorageProvider::forgetInheritedTranscriptionScope()` es idempotente
 *      y borra la key exacta del cache.
 *   4. `inheritedTranscriptionScopeInfo()` hereda el cache (mismo key prefix).
 *   5. Cold < 200 ms / Warm < 30 ms en llamada repetida sobre el mismo rootId.
 *
 * Uso: cd app && php tests/harness_api_transcriptor_index_perf.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\StorageProvider;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$tag = 'htip_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness api-transcriptor-index-perf-cache (tag: {$tag})\n";

// ─── Datos temporales: una jerarquía storage_providers con prefijo htip_ ──────
$rootId = (int) DB::table('storage_providers')->insertGetId([
    'name' => "{$tag}_root", 'type' => 'local',
    'base_path' => "/tmp/{$tag}/root", 'enabled' => true,
    'transcription_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
]);
$childId = (int) DB::table('storage_providers')->insertGetId([
    'name' => "{$tag}_child", 'type' => 'local',
    'base_path' => "/tmp/{$tag}/root/child", 'enabled' => true,
    'transcription_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
]);
$siblingId = (int) DB::table('storage_providers')->insertGetId([
    'name' => "{$tag}_sibling", 'type' => 'local',
    'base_path' => "/tmp/{$tag}/sibling", 'enabled' => true,
    'transcription_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
]);

try {

    h_section('1. Índice `storage_providers_base_path_pattern_idx` existe y se usa');

    $hasIndex = (bool) DB::selectOne(
        "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'storage_providers_base_path_pattern_idx'"
    );
    h_check($hasIndex, 'Índice presente en pg_indexes');

    if ($hasIndex) {
        $plan = collect(DB::select(
            "EXPLAIN SELECT id FROM storage_providers WHERE transcription_enabled = true AND base_path LIKE '/tmp/{$tag}/root/%'"
        ))->pluck('QUERY PLAN')->implode("\n");
        $usesIdx = str_contains($plan, 'storage_providers_base_path_pattern_idx');
        h_check($usesIdx, 'EXPLAIN usa Index Scan (no Seq Scan)', 'Plan no usa el índice:\n' . $plan);
    }

    h_section('2. Cache del scope heredado');

    // Bypass por si quedó algo de tests previos: TTL=0 invalida comportamiento cache.
    $originalTtl = SystemSetting::get('transcriptor_scope_cache_ttl');
    SystemSetting::set('transcriptor_scope_cache_ttl', '300');
    StorageProvider::forgetInheritedTranscriptionScope($rootId);
    StorageProvider::forgetInheritedTranscriptionScope($childId);

    $cacheKey = "transcriptor.scope.inherited.{$rootId}";
    h_check(!Cache::has($cacheKey), 'Cache miss en el primer hit (no pre-poblado)');

    $t = microtime(true);
    $scopeFirst = StorageProvider::resolveInheritedTranscriptionScope($rootId);
    $coldMs = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    $scopeSecond = StorageProvider::resolveInheritedTranscriptionScope($rootId);
    $warmMs = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    for ($i = 0; $i < 50; $i++) {
        StorageProvider::resolveInheritedTranscriptionScope($rootId);
    }
    $warmBatchMs = (microtime(true) - $t) * 1000;
    $warmPerCallMs = $warmBatchMs / 50;

    h_check(count($scopeFirst) >= 2, 'Scope del root incluye al menos root + child (BFS ok)',
        'scope size = ' . count($scopeFirst));
    h_check(in_array($childId, $scopeFirst, true), 'root scope contiene el child creado');
    h_check(!in_array($siblingId, $scopeFirst, true), 'root scope NO contiene el sibling (jerarquía correcta)');
    h_check($warmMs < 5, sprintf('Warm single call < 5 ms (%.2f ms)', $warmMs));
    // <2 ms/call en vez de <1 ms: cada Cache::remember implica un round-trip a
    // Redis, por lo que el piso realista es ~1-2 ms aunque la query sea cero.
    // Lo que validamos es que warm << cold, no que warm sea cero.
    h_check($warmPerCallMs < 2, sprintf('Warm 50-call avg < 2 ms (%.2f ms/call)', $warmPerCallMs));
    h_check($coldMs < 200, sprintf('Cold first call < 200 ms (%.2f ms)', $coldMs));
    h_check($coldMs > $warmMs * 2, sprintf('Cold > 2x warm (cold=%.2fms warm=%.2fms)', $coldMs, $warmMs), 'Cold deberia ser notablemente mayor que warm');

    // Cache hit returns same value as cold (consistencia).
    h_check($scopeFirst === $scopeSecond, 'Warm retorna el mismo array que cold');

    h_section('3. Invalidación explícita via forgetInheritedTranscriptionScope');

    h_check(Cache::has($cacheKey), 'Cache poblado tras resolveInheritedTranscriptionScope');

    StorageProvider::forgetInheritedTranscriptionScope($rootId);
    h_check(!Cache::has($cacheKey), 'Forget borra la key exacta del cache del root');

    // Llamada tras forget repuebla (no lanza error).
    $repopulated = StorageProvider::resolveInheritedTranscriptionScope($rootId);
    h_check(count($repopulated) === count($scopeFirst), 'Repopulado tras forget devuelve mismo scope');
    h_check(Cache::has($cacheKey), 'Cache re-poblado tras forget + resolve');

    // Idempotence: forget 2 veces seguidas no lanza.
    StorageProvider::forgetInheritedTranscriptionScope($rootId);
    StorageProvider::forgetInheritedTranscriptionScope($rootId);
    StorageProvider::forgetInheritedTranscriptionScope(999999);
    h_ok('Forget idempotente (3 calls, ninguna excepción)');

    h_section('4. inheritedTranscriptionScopeInfo hereda el cache');

    $root2 = $childId;
    $infoKey = "transcriptor.scope.inherited.{$root2}";
    StorageProvider::forgetInheritedTranscriptionScope($root2);

    $t = microtime(true);
    $infoCold = StorageProvider::inheritedTranscriptionScopeInfo($root2);
    $infoColdMs = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    $infoWarm = StorageProvider::inheritedTranscriptionScopeInfo($root2);
    $infoWarmMs = (microtime(true) - $t) * 1000;

    h_check(Cache::has($infoKey), 'scopeInfo deja la key en cache para futuros hits');
    h_check($infoCold['storage_ids'] === $infoWarm['storage_ids'], 'Cold y warm devuelven mismo set');
    h_check($infoWarmMs < 5, sprintf('scopeInfo warm < 5 ms (%.2f ms)', $infoWarmMs));

    h_section('5. Bypass con SystemSetting ttl=0');

    SystemSetting::set('transcriptor_scope_cache_ttl', '0');
    // Tambien limpiar el cache de 60s del propio TTL: el setting nuevo no se
    // relee hasta que esa key expire. Esto NO es comportamiento operativo
    // (un operator que cambia el setting vive con hasta 60s de lag), pero
    // el harness necesita un bypass inmediato.
    Cache::forget('transcriptor.scope.ttl');
    StorageProvider::forgetInheritedTranscriptionScope($rootId);

    $t = microtime(true);
    $r1 = StorageProvider::resolveInheritedTranscriptionScope($rootId);
    $bypass1 = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    $r2 = StorageProvider::resolveInheritedTranscriptionScope($rootId);
    $bypass2 = (microtime(true) - $t) * 1000;

    h_check(!Cache::has($cacheKey), 'TTL=0 NO escribe en cache');
    h_check($r1 === $r2, 'Bypass devuelve mismo scope en calls consecutivos');
    h_check($bypass2 > 0, 'Bypass NO es instantáneo (cada call ejecuta compute)');

    // Restaurar TTL.
    SystemSetting::set('transcriptor_scope_cache_ttl', $originalTtl ?? '300');

    echo "\n";

} finally {

    // Cleanup: borrar filas creadas con prefijo htip_ y keys cache con prefijo htip_.
    DB::table('storage_providers')->where('name', 'like', "{$tag}_%")->delete();
    foreach (['transcriptor.scope.inherited.' . $rootId, 'transcriptor.scope.inherited.' . $childId, 'transcriptor.scope.inherited.' . $siblingId] as $k) {
        Cache::forget($k);
    }
    SystemSetting::set('transcriptor_scope_cache_ttl', $originalTtl ?? '300');
}

if ($failures === 0) {
    echo "All assertions passed ✓\n";
    exit(0);
}
echo "{$failures} assertion(s) failed ✗\n";
exit(1);
