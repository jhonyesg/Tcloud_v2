<?php
/**
 * Harness de validación del change `fix-mis-archivos-breadcrumb-duplicates`.
 *
 * 12+ aserciones que cubren:
 *  - Caso legítimo lib/lib (tailwindcss) → validator permite cuando no existe duplicado.
 *  - Validator aborta cuando ya existe duplicado.
 *  - Cadena X > X detectada, reparada, idempotente.
 *  - Caso ambiguo X > X > X preservado (no repara).
 *  - findAllCycles lista solo pares parent-child same-name.
 *  - Cache epoch se bumpea tras repair.
 *  - End-to-end con breadcrumb del operador.
 *  - Cadenas limpias y casos negativos.
 *
 * Uso: cd app && php tests/harness_mis_archivos_breadcrumb_integrity.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$tag = 'hbb_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness mis-archivos-breadcrumb-integrity (tag: {$tag})\n";

    // Cleanup defensivo al inicio: borrar residuos de corridas previas con prefijo 'hbb_'
    $prevStorages = DB::table('storage_providers')
        ->where('name', 'LIKE', 'hbb_%')
        ->pluck('id')
        ->all();
    if (!empty($prevStorages)) {
        DB::table('files')->whereIn('storage_provider_id', $prevStorages)->delete();
        DB::table('storage_providers')->whereIn('id', $prevStorages)->delete();
        echo "Limpieza previa: " . count($prevStorages) . " storages huérfanos borrados.\n";
    }
    $prevDirs = glob('/tmp/hbb_*') ?: [];
    foreach ($prevDirs as $d) { @rmdir($d); }

$createdFileIds = [];
$createdStorageIds = [];
$createdUserIds = [];

try {
    $adminId = (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1);
    if ($adminId < 1) {
        $adminId = (int) DB::table('users')->insertGetId([
            'username' => "{$tag}_admin",
            'email' => "{$tag}_admin@test.local",
            'password' => bcrypt('x'),
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $createdUserIds[] = $adminId;
    }

    $storageId = (int) DB::table('storage_providers')->insertGetId([
        'name' => "{$tag}_storage",
        'kind' => 'local',
        'type' => 'local',
        'base_path' => "/tmp/{$tag}",
        'is_personal' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $createdStorageIds[] = $storageId;

    @mkdir("/tmp/{$tag}", 0777, true);

    $mkFolder = function (string $name, ?int $parentId, string $pathSuffix) use ($storageId, &$createdFileIds): int {
        $path = $pathSuffix === '' ? $name : "{$pathSuffix}/{$name}";
        $id = (int) DB::table('files')->insertGetId([
            'name' => $name,
            'path' => $path,
            'storage_provider_id' => $storageId,
            'owner_id' => 1,
            'parent_id' => $parentId,
            'is_folder' => true,
            'is_personal' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $createdFileIds[] = $id;
        return $id;
    };

    // ═════════════════════════════════════════════════════════════════════
    h_section('1) Caso legítimo lib/lib (SIN duplicado previo, debe permitir)');
    // ═════════════════════════════════════════════════════════════════════
    $ta = $mkFolder('node_modules', null, '');
    $tb = $mkFolder('tailwindcss', $ta, 'node_modules');
    $tc = $mkFolder('lib', $tb, 'node_modules/tailwindcss');

    $gate1 = \App\Services\FileBreadcrumbIntegrityService::assertNoSelfNestedName($storageId, $tc, 'lib');
    h_check($gate1['ok'] === true, 'lib/lib sin duplicado previo → ok=true');
    h_check($gate1['reason'] === 'legitimate_lib_lib_case', 'reason=legitimate_lib_lib_case (no hay otro "lib" bajo tc todavía)');

    // ═════════════════════════════════════════════════════════════════════
    h_section('2) Validator aborta duplicado preexistente');
    // ═════════════════════════════════════════════════════════════════════
    $td = $mkFolder('lib', $tc, 'node_modules/tailwindcss/lib'); // ahora sí hay 2 "lib" bajo tc

    $gate2 = \App\Services\FileBreadcrumbIntegrityService::assertNoSelfNestedName($storageId, $tc, 'lib');
    h_check($gate2['ok'] === false, 'segundo intento lib/lib → ok=false');
    h_check($gate2['reason'] === 'duplicate_path_already_exists', 'reason=duplicate_path_already_exists');
    h_check(isset($gate2['existing_id']) && (int) $gate2['existing_id'] === $td, 'existing_id apunta a lib ya creado (id=' . $td . ')');

    // ═════════════════════════════════════════════════════════════════════
    h_section('3) Cadena X > X detectada por findConsecutiveDuplicates');
    // ═════════════════════════════════════════════════════════════════════
    // Montar cadena con padre y abuelo claros:
    //   Z (root) -> Y -> X (parent) -> X (current)
    //   chainFor(current) = [X (depth 0), X (depth 1), Y (depth 2), Z (depth 3)]
    //   El duplicado es chain[0] vs chain[1] (ambos X), el abuelo es chain[2]=Y.
    $xa = $mkFolder('Z', null, '');
    $xb = $mkFolder('Y', $xa, 'Z');
    $xc = $mkFolder('X', $xb, 'Z/Y');
    $xd = $mkFolder('X', $xc, 'Z/Y/X'); // current, mismo nombre que su padre

    $chain = \App\Services\FileBreadcrumbIntegrityService::chainFor($xd);
    $names = array_column($chain, 'name');
    h_check($names === ['X', 'X', 'Y', 'Z'], 'chain nombres: ' . json_encode($names));

    $dups = \App\Services\FileBreadcrumbIntegrityService::findConsecutiveDuplicates($chain);
    h_check(count($dups) === 1, 'exactamente 1 duplicado detectado (count=' . count($dups) . ')');
    h_check($dups[0] === 1, 'duplicado en depth 1 (donde chain[1]=X coincide con chain[0]=X)');

    // ═════════════════════════════════════════════════════════════════════
    h_section('4) repairInPlace re-parenta al abuelo y es idempotente');
    // ═════════════════════════════════════════════════════════════════════
    $result1 = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($xd);
    h_check($result1['repaired'] === true, 'primer repair → repaired=true');
    h_check(($result1['new_parent_id'] ?? null) === $xb, 're-parado al abuelo Y (xb=' . $xb . '), no al padre X intermedio');

    // Tras repair, parent_id de $xd es ahora $xb (no $xc). Re-aplicar debe ser no-op.
    $result2 = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($xd);
    h_check(in_array($result2['reason'], ['no_duplicate', 'already_correct'], true),
        'segundo repair sobre cadena ya limpia → reason=' . ($result2['reason'] ?? 'null') . ' (no-op)');

    // Forzar re-patológico y reintentar
    DB::table('files')->where('id', $xd)->update(['parent_id' => $xc]);
    $result3 = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($xd);
    h_check($result3['repaired'] === true, 'tercer repair (re-forzado) → repaired=true');

    // ═════════════════════════════════════════════════════════════════════
    h_section('5) Caso ambiguo X > X > X preservado (no repara)');
    // ═════════════════════════════════════════════════════════════════════
    $wa = $mkFolder('Q', null, '');
    $wb = $mkFolder('Q', $wa, 'Q');
    $wc = $mkFolder('Q', $wb, 'Q/Q');
    $wd = $mkFolder('R', $wc, 'Q/Q/Q'); // depth-0 actual; chain= [R, Q, Q, Q]

    $chainAmbig = \App\Services\FileBreadcrumbIntegrityService::chainFor($wd);
    $dupsAmbig = \App\Services\FileBreadcrumbIntegrityService::findConsecutiveDuplicates($chainAmbig);
    h_check(count($dupsAmbig) >= 2, 'cadena ambigua tiene 2+ duplicados (count=' . count($dupsAmbig) . ')');

    $result5 = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($wd);
    h_check($result5['repaired'] === false, 'repair en cadena ambigua → repaired=false');
    h_check($result5['reason'] === 'ambiguous_multiple', 'reason=ambiguous_multiple');

    // Verificar que NO se modificó la fila
    $wdParentAfter = (int) DB::table('files')->where('id', $wd)->value('parent_id');
    h_check($wdParentAfter === $wc, 'parent_id de wd intacto (=' . $wc . ')');

    // ═════════════════════════════════════════════════════════════════════
    h_section('6) findAllCycles lista pares parent-child same-name');
    // ═════════════════════════════════════════════════════════════════════
    $cycles = \App\Services\FileBreadcrumbIntegrityService::findAllCycles();
    $patIds = $cycles->pluck('id')->all();

    // El par lib/lib (td.parent=tc, ambos "lib") aparece en findAllCycles
    // (par parent-child same-name — el operador decide en el comando si es patológico o legítimo).
    h_check(in_array($td, $patIds, true), 'lib/lib aparece en findAllCycles (par parent-child same-name)');

    // $xd fue reparado en aserción 4 (parent_id ahora apunta a $xb que se llama 'Y'), ya NO es par same-name.
    h_check(in_array($xd, $patIds, true) === false, 'caso reparado en aserción 4 ya NO aparece en findAllCycles');

    // Lo patológico de aserción 5 ($wc con parent=$wb ambos Q) sí aparece
    h_check(in_array($wc, $patIds, true), 'caso Q/Q intermedio sí aparece');

    // ═════════════════════════════════════════════════════════════════════
    h_section('7) Cache epoch se bumpea tras repair');
    // ═════════════════════════════════════════════════════════════════════
    // Montar cadena con abuelo claro y nombres únicos (distintos de aserción 3)
    //   CC (root) -> Y -> Y -> W (current)
    //   Tras repair: $yc (Y intermedio) re-parenta a $ya (CC), bumpeando folder_gen[$storageId:$ya]
    $ya = $mkFolder('CC', null, '');
    $yb = $mkFolder('Y', $ya, 'CC');
    $yc = $mkFolder('Y', $yb, 'CC/Y');
    $yd = $mkFolder('W', $yc, 'CC/Y/Y');

    $genKey = "folder_gen:{$storageId}:{$ya}";
    Cache::forget($genKey);
    $genBefore = (int) (Cache::get($genKey, 0) ?? 0);

    $repair7 = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($yd);
    h_check($repair7['repaired'] === true, 'aserción 7 setup: repair reparado (reason=' . ($repair7['reason'] ?? 'null') . ')');

    $genAfter = (int) (Cache::get($genKey, 0) ?? 0);
    h_check($genAfter > $genBefore, "folder_gen[{$genKey}]: {$genBefore} → {$genAfter} (epoch bumpeado)");

    // ═════════════════════════════════════════════════════════════════════
    h_section('8) End-to-end con breadcrumb del operador');
    // ═════════════════════════════════════════════════════════════════════
    // Replicar la estructura exacta del reporte:
    //   Bolivar > Alerta_Cartagena > 28092026 > 28092026 (patológico)
    $oBol = $mkFolder('Bolivar', null, '');
    $oAle = $mkFolder('Alerta_Cartagena', $oBol, 'Bolivar');
    $oDay1 = $mkFolder('28092026', $oAle, 'Bolivar/Alerta_Cartagena'); // padre que también se llama 28092026
    $oDay2 = $mkFolder('28092026', $oDay1, 'Bolivar/Alerta_Cartagena/28092026'); // patológico (current)

    $chainOp = \App\Services\FileBreadcrumbIntegrityService::chainFor($oDay2);
    $opNames = array_column($chainOp, 'name');
    h_check($opNames === ['28092026', '28092026', 'Alerta_Cartagena', 'Bolivar'],
        'cadena del operador: ' . json_encode($opNames));

    $opDups = \App\Services\FileBreadcrumbIntegrityService::findConsecutiveDuplicates($chainOp);
    h_check(count($opDups) === 1, '1 duplicado detectado (los dos 28092026 consecutivos)');
    h_check($opDups[0] === 1, 'duplicado en depth 1');

    $opResult = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($oDay2);
    h_check($opResult['repaired'] === true, 'repair del operador → repaired=true');
    h_check($opResult['new_parent_id'] === $oAle,
        're-parado a Alerta_Cartagena (=' . $oAle . '), NO al 28092026 intermedio');

    $chainAfter = \App\Services\FileBreadcrumbIntegrityService::chainFor($oDay2);
    $chainAfterNames = array_column($chainAfter, 'name');
    h_check($chainAfterNames === ['28092026', 'Alerta_Cartagena', 'Bolivar'],
        'cadena post-repair: ' . json_encode($chainAfterNames));

    // ═════════════════════════════════════════════════════════════════════
    h_section('9) Validator permite cuando padre tiene nombre distinto');
    // ═════════════════════════════════════════════════════════════════════
    $pa = $mkFolder('ParentZ', null, '');
    $gate9 = \App\Services\FileBreadcrumbIntegrityService::assertNoSelfNestedName($storageId, $pa, 'ChildDifferent');
    h_check($gate9['ok'] === true, 'padre "ParentZ" + child "ChildDifferent" → ok=true');
    h_check($gate9['reason'] === 'parent_name_differs', 'reason=parent_name_differs');

    // ═════════════════════════════════════════════════════════════════════
    h_section('10) Validator: parent_id=null omite validación');
    // ═════════════════════════════════════════════════════════════════════
    $gate10 = \App\Services\FileBreadcrumbIntegrityService::assertNoSelfNestedName($storageId, null, 'Anything');
    h_check($gate10['ok'] === true, 'parent_id=null → ok=true');
    h_check($gate10['reason'] === 'no_parent', 'reason=no_parent');

    // ═════════════════════════════════════════════════════════════════════
    h_section('11) Validator: storage_id=null omite validación');
    // ═════════════════════════════════════════════════════════════════════
    $gate11 = \App\Services\FileBreadcrumbIntegrityService::assertNoSelfNestedName(null, $pa, 'Anything');
    h_check($gate11['ok'] === true, 'storage_id=null → ok=true');
    h_check($gate11['reason'] === 'no_parent', 'reason=no_parent');

    // ═════════════════════════════════════════════════════════════════════
    h_section('12) Cadena limpia no dispara repair');
    // ═════════════════════════════════════════════════════════════════════
    $la = $mkFolder('L1', null, '');
    $lb = $mkFolder('L2', $la, 'L1');
    $lc = $mkFolder('L3', $lb, 'L1/L2');

    $cleanResult = \App\Services\FileBreadcrumbIntegrityService::repairInPlace($lc);
    h_check($cleanResult['repaired'] === false, 'cadena sin ciclos → repaired=false');
    h_check($cleanResult['reason'] === 'no_duplicate', 'reason=no_duplicate');

    echo "\n=== RESUMEN ===\n";
    echo "12 aserciones ejecutadas. Cobertura: validator (write-time) + repair (read-time) + bulk detection.\n";

} finally {
    // ─── Limpieza por tag + defensiva por prefijo ─────────────────────────
    if (!empty($createdStorageIds)) {
        DB::table('files')->whereIn('storage_provider_id', $createdStorageIds)->delete();
        DB::table('files')->whereIn('id', $createdFileIds)->delete();
    }
    // Cleanup defensivo: cualquier storage con nombre 'hbb_%' de corridas anteriores
    $leftover = DB::table('storage_providers')
        ->where('name', 'LIKE', 'hbb_%')
        ->pluck('id')
        ->all();
    if (!empty($leftover)) {
        DB::table('files')->whereIn('storage_provider_id', $leftover)->delete();
        DB::table('storage_providers')->whereIn('id', $leftover)->delete();
    }
    foreach ($createdStorageIds as $sid) {
        @rmdir("/tmp/{$tag}");
    }
    foreach ($createdUserIds as $uid) {
        DB::table('users')->where('id', $uid)->delete();
    }
    echo "\nLimpieza completada (tag {$tag}).\n";
}

echo $failures === 0
    ? "\nTODOS LOS CHECKS OK\n"
    : "\n{$failures} CHECK(S) FALLARON\n";
exit($failures === 0 ? 0 : 1);
