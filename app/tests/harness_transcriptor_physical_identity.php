<?php
/**
 * Harness de regresión — identidad física y frontera entre módulos.
 *
 * Change: `2026-09-16-transcriptor-physical-file-identity`.
 *
 * Contrato verificado:
 *   A. Jerarquía persistida: backfill de parent_storage_id en cadena de 3
 *      niveles (A→B→C); el ancestro es el INMEDIATO, no la raíz.
 *   B. Nodos equivalentes: base_path normalizado idéntico NO se enlaza
 *      padre/hijo entre sí.
 *   C. ownerOf() (dueño efectivo, mira tx): hijo tx=true gana sobre padre
 *      tx=true; hijo tx=false cede al padre tx=true; sin tx en la cadena →
 *      null; empate de ruta idéntica → gana tx=true.
 *   D. resolveGeometricOwner() (dueño de Mis Archivos, NO mira tx): el hijo
 *      tx=false GANA al padre, porque la pertenencia es geométrica.
 *   E. Elegibilidad por CADENA (D9): una fila en un storage tx=false bajo un
 *      ancestro tx=true es elegible.
 *   F. FRONTERA: apagar transcription_enabled no cambia ninguna fila `files`.
 *   G. FRONTERA: el descubrimiento no crea filas `files`.
 *   H. Identidad física: dos filas `files` para un archivo físico producen
 *      UNA sola transcripción (candado source_absolute_path).
 *   I. CASCADE roto: borrar el `files` de una transcripción deja la
 *      transcripción viva con file_id NULL y source_absolute_path intacto.
 *
 * Uso:  cd app && php tests/harness_transcriptor_physical_identity.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\PhysicalFileIdentity;
use App\Services\Ia\StorageHierarchyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$tag = 'htpi_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness transcriptor-physical-file-identity (tag: {$tag})\n";

// El harness muta modelos DIRECTAMENTE (no vía controller), así que la cache
// de jerarquia (60 s) quedaria stale entre secciones. Se desactiva para probar
// la LOGICA pura de resolucion; la invalidacion via controller se verifica
// aparte en la seccion F.
$ttlPrevio = \App\Models\SystemSetting::get('transcriptor_scope_cache_ttl');
\App\Models\SystemSetting::set('transcriptor_scope_cache_ttl', '0');
$svcTtlKey = 'transcriptor.scope.ttl';
Cache::forget($svcTtlKey);
Cache::forget('transcriptor.hierarchy.storage_set');

$base = '/tmp/' . $tag;
@mkdir($base . '/A/B/C', 0777, true);

$createdStorageIds = [];
$createdFileIds = [];
$createdTxIds = [];

$mkStorage = function (string $name, string $path, bool $tx) use (&$createdStorageIds) {
    $s = StorageProvider::create([
        'name' => $name,
        'type' => 'local',
        'config' => [],
        'base_path' => $path,
        'enabled' => true,
        'transcription_enabled' => $tx,
    ]);
    $createdStorageIds[] = (int) $s->id;

    return $s;
};

try {
    $svc = app(StorageHierarchyService::class);
    $identity = app(PhysicalFileIdentity::class);

    // ─────────────────────────────────────────────────────────────────────
    h_section('A. Jerarquía: cadena de 3 niveles');

    $sa = $mkStorage($tag . '_A', $base . '/A', false);
    $sb = $mkStorage($tag . '_B', $base . '/A/B', true);
    $sc = $mkStorage($tag . '_C', $base . '/A/B/C', true);

    $svc->recomputeParent($sb);
    $svc->recomputeParent($sc);

    $sb->refresh(); $sc->refresh();
    h_check((int) $sb->parent_storage_id === (int) $sa->id,
        "B.parent_storage_id = A ({$sa->id})",
        "B.parent_storage_id esperado {$sa->id}, obtenido " . var_export($sb->parent_storage_id, true));
    h_check((int) $sc->parent_storage_id === (int) $sb->id,
        "C.parent_storage_id = B ({$sb->id}) — ancestro INMEDIATO, no la raíz",
        "C.parent_storage_id esperado {$sb->id}, obtenido " . var_export($sc->parent_storage_id, true));

    $anc = $svc->ancestors((int) $sc->id);
    h_check(count($anc) === 2 && (int) $anc[0]->id === (int) $sb->id && (int) $anc[1]->id === (int) $sa->id,
        'ancestors(C) = [B, A] en orden de cercanía',
        'ancestors(C) inesperado: ' . json_encode(array_map(fn ($x) => $x->id, $anc)));

    h_check($svc->rootIdOf((int) $sc->id) === (int) $sa->id,
        "rootIdOf(C) = A ({$sa->id})",
        'rootIdOf(C) = ' . $svc->rootIdOf((int) $sc->id));

    // ─────────────────────────────────────────────────────────────────────
    h_section('B. Nodos equivalentes (ruta normalizada idéntica)');

    $sEq1 = $mkStorage($tag . '_Eq1', $base . '/A/B/C/eq', true);
    $sEq2 = $mkStorage($tag . '_Eq2', $base . '/A/B/C/eq/', false);

    $svc->recomputeParent($sEq1);
    $svc->recomputeParent($sEq2);
    $sEq1->refresh(); $sEq2->refresh();

    h_check($sEq1->parent_storage_id !== null && (int) $sEq1->parent_storage_id === (int) $sc->id,
        'Eq1 cuelga de C (su ancestro por prefijo)');
    h_check($sEq2->parent_storage_id !== null && (int) $sEq2->parent_storage_id === (int) $sc->id,
        'Eq2 cuelga de C también');
    h_check((int) $sEq1->parent_storage_id !== (int) $sEq2->id
        && (int) $sEq2->parent_storage_id !== (int) $sEq1->id,
        'Eq1 y Eq2 NO se enlazan entre sí (mismo nodo, dos gestores)');

    $equiv = $svc->equivalentNodes($sEq1);
    $equivIds = array_map(fn ($x) => (int) $x->id, $equiv);
    h_check(in_array((int) $sEq2->id, $equivIds, true),
        'equivalentNodes(Eq1) incluye Eq2',
        'equivalentNodes(Eq1) = ' . json_encode($equivIds));

    // ─────────────────────────────────────────────────────────────────────
    h_section('C. ownerOf(): dueño efectivo (mira tx)');

    $absUnderC = $base . '/A/B/C/2026/x.mp3';

    // B tx=true, C tx=true → gana C (más profundo)
    $own = $svc->ownerOf($absUnderC);
    h_check($own !== null && (int) $own->id === (int) $sc->id,
        "ownerOf bajo C con B y C habilitados → C ({$sc->id})",
        'ownerOf = ' . ($own ? $own->id : 'null'));

    // Apagar C → gana B
    $sc->update(['transcription_enabled' => false]);
    $own = $svc->ownerOf($absUnderC);
    h_check($own !== null && (int) $own->id === (int) $sb->id,
        "ownerOf con C apagado → B ({$sb->id})",
        'ownerOf = ' . ($own ? $own->id : 'null'));

    // Apagar B también → A está apagado → null
    $sb->update(['transcription_enabled' => false]);
    $own = $svc->ownerOf($absUnderC);
    h_check($own === null,
        'ownerOf con toda la cadena apagada → null',
        'ownerOf = ' . ($own ? $own->id : 'null'));

    // Restaurar B
    $sb->update(['transcription_enabled' => true]);

    // Empate de ruta idéntica: Eq1 y Eq2 tienen el MISMO base_path
    // normalizado. Eq1 tx=true, Eq2 tx=false → ownerOf() elige Eq1.
    $absEq = $base . '/A/B/C/eq/y.mp3';
    $own = $svc->ownerOf($absEq);
    h_check($own !== null && (int) $own->id === (int) $sEq1->id,
        "ownerOf en empate de ruta idéntica → gana el tx=true (Eq1 {$sEq1->id})",
        'ownerOf = ' . ($own ? $own->id : 'null'));

    // ─────────────────────────────────────────────────────────────────────
    h_section('D. resolveGeometricOwner(): dueño de Mis Archivos (NO mira tx)');

    // Empate: ambos tienen la misma longitud de base_path, así que desempata
    // por menor id. Eq1 se creó primero (menor id) y es tx=true.
    $own = $svc->resolveGeometricOwner($absEq);
    $menorId = min((int) $sEq1->id, (int) $sEq2->id);
    h_check($own !== null && (int) $own->id === $menorId,
        "geométrico en empate de ruta idéntica → menor id ({$menorId}), sin mirar tx",
        'geométrico = ' . ($own ? $own->id : 'null') . ", esperado {$menorId}");

    // Lo esencial: con C APAGADO, el geométrico sigue siendo C (el más
    // profundo). Si mirara tx, devolvería B.
    $own = $svc->resolveGeometricOwner($base . '/A/B/C/2026/x.mp3');
    h_check($own !== null && (int) $own->id === (int) $sc->id,
        "geométrico bajo C (tx=false) → C ({$sc->id}), porque la pertenencia NO mira tx",
        'geométrico = ' . ($own ? $own->id : 'null'));

    // ─────────────────────────────────────────────────────────────────────
    h_section('E. Elegibilidad por CADENA (design.md D9)');

    // C está tx=false pero B (su ancestro) tx=true: un archivo bajo C es elegible.
    h_check($svc->isCoveredByEnabledChain($absUnderC) === true,
        'archivo bajo C (tx=false) con B (tx=true) → elegible por cadena');

    // Apagar B → ya nadie cubre → no elegible
    $sb->update(['transcription_enabled' => false]);
    h_check($svc->isCoveredByEnabledChain($absUnderC) === false,
        'con B apagado también → NO elegible');
    $sb->update(['transcription_enabled' => true]);

    // ─────────────────────────────────────────────────────────────────────
    h_section('F. FRONTERA: apagar tx no cambia filas de `files`');

    $filesBefore = File::where('storage_provider_id', $sb->id)->count();
    $sb->update(['transcription_enabled' => false]);
    $sb->refresh();
    $filesAfter = File::where('storage_provider_id', $sb->id)->count();
    h_check($filesBefore === $filesAfter,
        "apagar tx deja el conteo de `files` igual ({$filesBefore})",
        "conteo cambió: {$filesBefore} → {$filesAfter}");
    $sb->update(['transcription_enabled' => true]);

    // ─────────────────────────────────────────────────────────────────────
    h_section('J. Invalidación automática de cache al mutar el modelo');

    // Reactivar la cache.
    \App\Models\SystemSetting::set('transcriptor_scope_cache_ttl', '300');
    Cache::forget($svcTtlKey);
    Cache::forget('transcriptor.hierarchy.storage_set');
    \App\Models\StorageProvider::flushScopeMemo();
    $svc->forgetAll();

    $sb->update(['transcription_enabled' => true]);
    $sb->refresh();

    // Ruta cubierta SOLO por B (A esta apagado, C/Eq/_Hijo son subrutas de
    // /A/B/C y no cubren /A/B/zonab). Asi el dueño esperado es B sin
    // ambiguedad con lo que crearon las secciones previas.
    $pathSoloB = $base . '/A/B/zonab/warm.mp3';

    $own1 = $svc->ownerOf($pathSoloB);
    h_check($own1 !== null && (int) $own1->id === (int) $sb->id,
        "set calentado: dueño de la ruta = B ({$sb->id})",
        'dueño = ' . ($own1 ? $own1->id : 'null'));

    // Apagar B SIN llamar a forgetAll(): el hook `saved` del modelo debe
    // invalidar el memo y el cache Redis por si solo. Un memo stale aqui haria
    // que ownerOf() siguiera devolviendo B pese al apagado — que es lo que
    // detectaba esta seccion antes del hook.
    $sb->update(['transcription_enabled' => false]);
    $ownAuto = $svc->ownerOf($pathSoloB);

    h_check($ownAuto === null,
        'apagar por update() invalida la cache AUTOMATICAMENTE (hook saved) sin forgetAll() manual',
        'el dueño sigue siendo ' . ($ownAuto ? $ownAuto->id : 'null') . ' — la invalidación automática no disparó');

    // Defensa en profundidad: forgetAll() explícito sigue siendo válido.
    $svc->forgetAll();
    $ownFresh = $svc->ownerOf($pathSoloB);
    h_check($ownFresh === null,
        'forgetAll() explícito sigue funcionando como freno manual',
        'tras forgetAll() el dueño sigue siendo ' . ($ownFresh ? $ownFresh->id : 'null'));

    // Restaurar B para el resto del harness.
    $sb->update(['transcription_enabled' => true]);
    $svc->forgetAll();

    // ─────────────────────────────────────────────────────────────────────
    h_section('G/H. Identidad física: dos filas files → una transcripción');

    // Dos storages cubren la MISMA ruta física (padre e hijo).
    $sPadre = $mkStorage($tag . '_Padre', $base . '/A/B', true);
    $sHijo  = $mkStorage($tag . '_Hijo', $base . '/A/B/C', true);
    $svc->recomputeParent($sHijo);

    $abs = $base . '/A/B/C/2026/dup.mp3';

    // Fila en el PADRE (path relativo a su base) y en el HIJO.
    $fPadre = File::create([
        'storage_provider_id' => $sPadre->id,
        'name' => 'dup.mp3',
        'path' => 'C/2026/dup.mp3',
        'size' => 1000000,
        'is_folder' => false,
        'file_modified_at' => now(),
        'owner_id' => 1,
    ]);
    $fHijo = File::create([
        'storage_provider_id' => $sHijo->id,
        'name' => 'dup.mp3',
        'path' => '2026/dup.mp3',
        'size' => 1000000,
        'is_folder' => false,
        'file_modified_at' => now(),
        'owner_id' => 1,
    ]);
    $createdFileIds[] = (int) $fPadre->id;
    $createdFileIds[] = (int) $fHijo->id;

    $resolved = $identity->resolve($abs);
    h_check($resolved !== null && (int) $resolved->id === (int) $fHijo->id,
        "resolve() devuelve la fila del dueño efectivo (el hijo, {$fHijo->id})",
        'resolve = ' . ($resolved ? $resolved->id : 'null'));

    $tx1 = $identity->firstOrCreateTranscription($abs, $resolved, [
        'original_name' => 'dup.mp3',
        'state' => Transcription::STATE_PENDING,
        'generate_alerts' => false,
    ]);
    $createdTxIds[] = (int) $tx1->id;

    // Segunda llamada (simula el otro storage descubriendo el mismo archivo):
    // debe REUTILIZAR la fila, no crear otra.
    $tx2 = $identity->firstOrCreateTranscription($abs, $resolved, [
        'original_name' => 'dup.mp3',
        'state' => Transcription::STATE_PENDING,
        'generate_alerts' => false,
    ]);

    $count = Transcription::where('source_absolute_path', $abs)->count();
    h_check($count === 1,
        "una sola transcripción para el archivo físico (id {$tx2->id})",
        "se crearon {$count} transcripciones");
    h_check((int) $tx1->id === (int) $tx2->id,
        'la segunda llamada reutiliza la misma fila',
        "ids distintos: {$tx1->id} vs {$tx2->id}");

    // ─────────────────────────────────────────────────────────────────────
    h_section('I. CASCADE roto: borrar files no destruye la transcripción');

    $txBefore = Transcription::where('source_absolute_path', $abs)->count();
    $fHijo->delete();

    $txAfter = Transcription::where('source_absolute_path', $abs)->first();
    h_check($txAfter !== null,
        'la transcripción sobrevive al borrado de la fila `files`',
        'la transcripción fue eliminada por el CASCADE');
    h_check($txAfter !== null && $txAfter->file_id === null,
        'file_id quedó NULL (ON DELETE SET NULL)',
        'file_id = ' . var_export($txAfter?->file_id, true));
    h_check($txAfter !== null && $txAfter->source_absolute_path === $abs,
        'source_absolute_path intacto (identificador estable)',
        'source_absolute_path = ' . var_export($txAfter?->source_absolute_path, true));

    // La ruta sigue siendo consultable por identidad (aunque no haya fila).
    h_check($identity->hasTranscription($abs) === true,
        'hasTranscription() sigue reconociendo la ruta tras el borrado');

    // ─────────────────────────────────────────────────────────────────────
    h_section('Frontera: el discovery NO escribe `files`');

    // Verificación estática: el servicio de descubrimiento no menciona INSERT
    // sobre files. Se busca en su código fuente cualquier escritura.
    $src = file_get_contents(__DIR__ . '/../app/Services/Ia/TranscriptionDiscoveryService.php');
    $tieneCreate = preg_match('/File::create|->insert\(|File::updateOrCreate|registry->ensure/', $src);
    h_check($tieneCreate === 0,
        'TranscriptionDiscoveryService no contiene File::create ni registry->ensure',
        'el discovery todavía escribe `files`');

} finally {
    // ─── Cleanup ─────────────────────────────────────────────────────────
    echo "\n=== Cleanup ===\n";

    foreach ($createdTxIds as $id) {
        Transcription::where('id', $id)->delete();
    }
    DB::table('transcriptions')->where('source_absolute_path', 'like', $base . '%')->delete();

    foreach ($createdFileIds as $id) {
        File::where('id', $id)->delete();
    }
    DB::table('files')->where('path', 'like', 'C/2026/dup%')->whereIn('storage_provider_id', $createdStorageIds)->delete();
    DB::table('files')->whereIn('storage_provider_id', $createdStorageIds)->delete();

    // Hijos primero para respetar la FK.
    StorageProvider::whereIn('id', $createdStorageIds)->update(['parent_storage_id' => null]);
    StorageProvider::whereIn('id', $createdStorageIds)->delete();

    foreach ($createdStorageIds as $id) {
        Cache::forget('transcriptor.hierarchy.ancestors.' . $id);
    }
    Cache::forget('transcriptor.hierarchy.storage_set');

    // Restaurar el TTL previo del scope cache.
    if ($ttlPrevio === null) {
        DB::table('system_settings')->where('key', 'transcriptor_scope_cache_ttl')->delete();
    } else {
        \App\Models\SystemSetting::set('transcriptor_scope_cache_ttl', (string) $ttlPrevio);
    }
    Cache::forget($svcTtlKey);

    $rm = function (string $dir) use (&$rm) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $rm($p) : @unlink($p);
        }
        @rmdir($dir);
    };
    $rm($base);

    echo $failures === 0
        ? "\nRESULTADO: OK (0 fallos)\n"
        : "\nRESULTADO: {$failures} fallo(s)\n";
}

exit($failures === 0 ? 0 : 1);
