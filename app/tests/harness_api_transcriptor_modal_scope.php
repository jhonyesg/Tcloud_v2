<?php
/**
 * Harness de validación — change `fix-api-transcriptor-modal-scope`.
 *
 * Verifica que el modal "Archivos — <storage>" (`x-show="showFiles"`) se monta
 * DENTRO del scope `apiTranscriptor`, garantizando que Alpine resuelva sus
 * directivas contra el componente correcto.
 *
 * Contrato (specs/transcriptor-storage-files-srt-link/spec.md):
 *  1. El `<h2>` del modal renderiza "Archivos — <nombre>" (binding `currentStorage?.name`).
 *  2. Los botones de modo tienen el binding `filesMode === 'browse' ? ... : ...`.
 *  3. La consola del navegador NO emite "Alpine Expression Error: X is not defined"
 *     originados desde elementos del modal.
 *  4. El HTML servido contiene `<div x-show="showFiles"` ANIDADO en
 *     `<div x-data="apiTranscriptor(...)"` (no como hermano).
 *
 * Uso: php tests/harness_api_transcriptor_modal_scope.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 *
 * Estrategia: renderiza la vista `ia.api-transcriptor.index` con un User de
 * prueba (prefijo hamt_<tag>) y analiza el DOM estáticamente. Sin Playwright.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use App\Models\User;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void {
    if ($cond) h_ok($ok); else h_fail($fail);
}

echo "Harness fix-api-transcriptor-modal-scope\n";

$tag = 'hamt_' . substr(bin2hex(random_bytes(4)), 0, 8);

// ─── Cleanup defensivo de corridas previas ────────────────────────────────
DB::table('users')->where('username', 'LIKE', $tag . '%')->delete();

try {
    $user = User::create([
        'email' => $tag . '_admin@test.local',
        'username' => $tag . '_admin',
        'password_hash' => 'harness_dummy',
        'role' => 'admin',
        'status' => 'active',
        'media_editor_enabled' => true,
        'media_editor_clip_limit' => 0,
    ]);

    // Renderizar la vista directamente con la sesión del usuario.
    // Reutilizamos el mismo helper que ya teníamos para harnesses.
    $session = $app->make('session.store');
    $session->put('user_id', $user->id);

    $html = View::make('ia.api-transcriptor.index', [
        'pending_alert_threshold' => 5,
    ])->render();

    // ─── Aserción 1: el wrapper x-data="apiTranscriptor(...)" está presente ─
    h_section('1. Wrapper apiTranscriptor en HTML servido');
    h_check(
        str_contains($html, 'x-data="apiTranscriptor('),
        'Wrapper <div x-data="apiTranscriptor(...)"> presente en HTML',
        'Falta el wrapper apiTranscriptor — el componente Alpine no se monta'
    );

    // ─── Aserción 2: el modal x-show="showFiles" está presente ─────────────
    h_section('2. Modal showFiles en HTML servido');
    h_check(
        preg_match('/<div[^>]*x-cloak[^>]*x-show="showFiles"/', $html) === 1,
        'Modal <div x-cloak x-show="showFiles"> presente en HTML',
        'Falta el modal del explorador de archivos'
    );

    // ─── Aserción 3: anidación DOM (scope check) ───────────────────────────
    h_section('3. Anidación: modal DENTRO del wrapper apiTranscriptor');
    // Buscar el <div ... x-data="apiTranscriptor(...)"> exacto (no el binding
    // de algún @click interno que diga `Alpine.store(...)`).
    $wrapper_open = preg_match(
        '/<div[^>]*x-data="apiTranscriptor\(/',
        $html,
        $m,
        PREG_OFFSET_CAPTURE
    );
    $wrapper_open_pos = $wrapper_open ? $m[0][1] : false;
    $modal_open = preg_match(
        '/<div[^>]*x-show="showFiles"/',
        $html,
        $m2,
        PREG_OFFSET_CAPTURE
    );
    $modal_open_pos = $modal_open ? $m2[0][1] : false;

    $balance = 0;
    $modal_inside_wrapper = false;
    if ($wrapper_open_pos !== false && $modal_open_pos !== false && $modal_open_pos > $wrapper_open_pos) {
        $segment = substr($html, $wrapper_open_pos, $modal_open_pos - $wrapper_open_pos);
        // Usar regex que acepta <div con cualquier whitespace al inicio O <div>
        // (cuando es exactamente `<div>`, sin atributos).
        preg_match_all('/<div\b[^>]*>/', $segment, $opens);
        preg_match_all('/<\/div>/', $segment, $closes);
        $balance = count($opens[0]) - count($closes[0]);
        $modal_inside_wrapper = $balance >= 1;
    }
    h_check(
        $modal_inside_wrapper,
        sprintf('Modal anidado dentro del wrapper (balance intermedio=%d)', $balance),
        sprintf(
            'Modal NO está dentro del wrapper — balance intermedio=%d (debería ser >= 1). '
            . 'Esto es la causa raíz de los errores "X is not defined" en consola.',
            $balance
        )
    );

    // ─── Aserción 4: anidación DOM (modal dentro del storages tab) ─────────
    h_section('4. Anidación: modal DENTRO del tab storages');
    // Buscar el <div x-show="tab === 'storages'"> concreto (no el binding
    // en la barra de navegación de tabs que aparece antes).
    $storages_open = preg_match(
        '/<div[^>]*x-show="tab === \'storages\'"/',
        $html,
        $m,
        PREG_OFFSET_CAPTURE
    );
    $storages_open_pos = $storages_open ? $m[0][1] : false;
    $balance_storages = 0;
    $modal_inside_storages = false;
    if ($storages_open_pos !== false && $modal_open_pos > $storages_open_pos) {
        $segment = substr($html, $storages_open_pos, $modal_open_pos - $storages_open_pos);
        preg_match_all('/<div\b[^>]*>/', $segment, $opens);
        preg_match_all('/<\/div>/', $segment, $closes);
        $balance_storages = count($opens[0]) - count($closes[0]);
        $modal_inside_storages = $balance_storages >= 1;
    }
    h_check(
        $modal_inside_storages,
        sprintf('Modal anidado dentro del tab storages (balance=%d)', $balance_storages),
        sprintf(
            'Modal NO está dentro del tab storages — balance=%d. '
            . 'El modal debe estar dentro del <div x-show="tab === \'storages\'">.',
            $balance_storages
        )
    );

    // ─── Aserción 5: bindings del modal presentes y bien formados ──────────
    h_section('5. Bindings Alpine del modal');
    h_check(
        str_contains($html, "x-text=\"'Archivos — ' + (currentStorage?.name || '')\""),
        "Binding del título: x-text referencia currentStorage?.name",
        "Falta o está mal el binding del título del modal"
    );
    h_check(
        preg_match('/:class="filesMode === \'browse\' \?[^"]+"/', $html) === 1,
        "Binding del botón Explorar: :class referencia filesMode === 'browse'",
        "Falta o está mal el binding del botón Explorar"
    );
    h_check(
        str_contains($html, 'x-model="filesSearch"'),
        "Binding del input de búsqueda: x-model=\"filesSearch\"",
        "Falta el binding del input de búsqueda"
    );
    h_check(
        str_contains($html, '@click="setMode(\'browse\')"'),
        "Handler del botón Explorar: @click=\"setMode('browse')\"",
        "Falta el handler del botón Explorar"
    );

    // ─── Aserción 6: cierre del wrapper ocurre DESPUÉS del cierre del modal ─
    h_section('6. Cierre: el wrapper cierra DESPUÉS del modal showFiles');
    // Usar un parser stack-based que maneja tags multi-línea. Balancear divs
    // desde el inicio del HTML hasta encontrar el cierre del modal showFiles.
    $modal_close_pos = false;
    $stack = [];
    preg_match_all('/<div\b[^>]*>|<\/div>/', $html, $all_divs, PREG_OFFSET_CAPTURE);
    $found_modal_in_stack = false;
    foreach ($all_divs[0] as $div_match) {
        $tag = $div_match[0];
        $pos = $div_match[1];
        if (str_starts_with($tag, '</div')) {
            if (!empty($stack)) {
                $popped = array_pop($stack);
                if ($found_modal_in_stack && str_contains($popped[0], 'x-show="showFiles"')) {
                    $modal_close_pos = $pos;
                    break;
                }
            }
        } else {
            $stack[] = [$tag, $pos];
            if (str_contains($tag, 'x-show="showFiles"')) {
                $found_modal_in_stack = true;
            }
        }
    }
    // Encontrar el cierre del wrapper: el </div> que cierra el tag con
    // x-data="apiTranscriptor(...)".
    $wrapper_close_pos = false;
    $stack2 = [];
    foreach ($all_divs[0] as $div_match) {
        $tag = $div_match[0];
        $pos = $div_match[1];
        if (str_starts_with($tag, '</div')) {
            if (!empty($stack2)) {
                $popped = array_pop($stack2);
                if (str_contains($popped[0], 'x-data="apiTranscriptor(')) {
                    $wrapper_close_pos = $pos;
                    break;
                }
            }
        } else {
            $stack2[] = [$tag, $pos];
        }
    }
    h_check(
        $modal_close_pos !== false && $wrapper_close_pos !== false && $modal_close_pos < $wrapper_close_pos,
        sprintf('Modal cierra (offset=%d) antes que wrapper (offset=%d)', $modal_close_pos, $wrapper_close_pos),
        sprintf(
            'El modal showFiles cierra fuera del wrapper. modal_close=%s, wrapper_close=%s',
            $modal_close_pos !== false ? $modal_close_pos : 'NOT FOUND',
            $wrapper_close_pos !== false ? $wrapper_close_pos : 'NOT FOUND'
        )
    );

    // ─── Aserción 7: el componente apiTranscriptor existe en el <script> ──
    h_section('7. Componente apiTranscriptor en @push(\'scripts\')');
    h_check(
        preg_match('/function\s+apiTranscriptor\s*\(\s*config\s*=/', $html) === 1,
        'Definición `function apiTranscriptor(config = {})` presente en HTML',
        'Falta la definición del componente apiTranscriptor en el <script> push'
    );

    // ─── Aserción 8: el botón "Escanear storages" está en la página ────────
    h_section('8. Botón "Escanear storages" presente');
    $has_scan_button = preg_match('/Escanear storages/', $html) === 1
        || preg_match('/scanStorages|scanStorage/', $html) === 1;
    h_check(
        $has_scan_button,
        'Texto "Escanear storages" o referencia a scanStorages() en HTML',
        'No se encontró el botón "Escanear storages" — el usuario reportó que no funcionaba'
    );

    // ─── Aserción 9: TODOS los modales están dentro del wrapper ────────────
    // Sin esto, los bindings como `transcript.data?.duration_seconds` fallan
    // con "ReferenceError: transcript is not defined" en consola porque Alpine
    // evalúa `x-show="transcript.open"` aunque el modal esté cerrado, y ese
    // eval busca `transcript` en el scope equivocado.
    h_section('9. TODOS los modales dentro del wrapper apiTranscriptor');
    $all_modals = [
        'showFiles'          => 'showFiles',
        'showProgress'       => 'showProgress',
        'storageToDisable'   => 'storageToDisable',
        'showProcessConfirm' => 'showProcessConfirm',
        'showBatchModal'     => 'showBatchModal',
        'transcript.open'    => 'transcript.open',
    ];
    $all_inside = true;
    foreach ($all_modals as $name => $binding) {
        $modal_open_match = preg_match(
            '/<div[^>]*x-show="' . preg_quote($binding, '/') . '"/',
            $html,
            $m,
            PREG_OFFSET_CAPTURE
        );
        if (!$modal_open_match) {
            h_fail("Modal '$name' no encontrado en HTML");
            $all_inside = false;
            continue;
        }
        $modal_open_pos = $m[0][1];
        $wrapper_open_pos = preg_match(
            '/<div[^>]*x-data="apiTranscriptor\(/',
            $html,
            $m2,
            PREG_OFFSET_CAPTURE
        ) ? $m2[0][1] : false;
        if ($wrapper_open_pos === false || $modal_open_pos <= $wrapper_open_pos) {
            h_fail("Modal '$name' NO encontrado después del wrapper");
            $all_inside = false;
            continue;
        }
        // Verificar que hay al menos 1 div anidado entre wrapper y modal
        $segment = substr($html, $wrapper_open_pos, $modal_open_pos - $wrapper_open_pos);
        preg_match_all('/<div\b[^>]*>/', $segment, $opens);
        preg_match_all('/<\/div>/', $segment, $closes);
        $balance = count($opens[0]) - count($closes[0]);
        if ($balance < 1) {
            h_fail("Modal '$name' está como HERMANO del wrapper (balance=$balance), no como hijo");
            $all_inside = false;
        }
    }
    if ($all_inside) {
        h_ok('Los 6 modales (showFiles/showProgress/storageToDisable/showProcessConfirm/showBatchModal/transcript.open) están dentro del wrapper');
    }

    // ─── Aserción 10: el endpoint live-consumption está registrado ─────────
    h_section('10. Ruta GET /ia/api-transcriptor/live-consumption registrada');
    try {
        $router = $app->make('router');
        $routes = $router->getRoutes();
        $found_live = false;
        foreach ($routes as $route) {
            if (in_array('GET', $route->methods()) && str_contains($route->uri(), 'live-consumption')) {
                $found_live = true;
                break;
            }
        }
        h_check(
            $found_live,
            'Ruta GET ia/api-transcriptor/live-consumption registrada',
            'Falta la ruta GET ia/api-transcriptor/live-consumption — el navegador recibe 404 al pedirla'
        );
    } catch (\Throwable $e) {
        h_fail('No se pudo inspeccionar el router: ' . $e->getMessage());
    }

    // ─── Aserción 11: botón "Iniciar procesamiento" habilitado sin esperar estimate ──
    h_section('11. Botón "Iniciar procesamiento" sin esperar batchEstimateLoading');
    // El botón debe estar habilitado cuando batchScope es válido (default 'today'),
    // independientemente de que la estimación esté cargando. Antes el botón
    // quedaba :disabled durante 5-8s con 21k archivos y 70 storages, lo que
    // hacía creer al operador que estaba roto.
    // Buscamos la cadena exacta del :disabled sin requerir el orden completo
    // de atributos (el `x-show` del botón contiene un `>` literal que rompe
    // cualquier regex que asuma atributos en una sola línea).
    h_check(
        !str_contains($html, ':disabled="!batchScopeValid() || batchEstimateLoading"'),
        'El botón ya no depende de batchEstimateLoading en :disabled',
        'El botón sigue dependiendo de batchEstimateLoading — se va a quedar deshabilitado 5-8s al abrir el modal'
    );
    h_check(
        str_contains($html, ':disabled="!batchScopeValid()"'),
        'El botón tiene :disabled="!batchScopeValid()"',
        'Falta el binding :disabled="!batchScopeValid()" en el botón'
    );
    h_check(
        preg_match('/x-text="batchEstimateLoading \? \'Iniciar \(estimando\.\.\.\)\' : \'Iniciar procesamiento\'/', $html) === 1,
        'El texto del botón cambia a "Iniciar (estimando...)" durante la carga',
        'El texto del botón debería indicar que la estimación está cargando'
    );

    // ─── Aserción 12: badge "carpetas sin archivos" en la tabla ────────────
    h_section('12. Badge "carpetas sin archivos" visible en la tabla de storages');
    // Las filas de storages que aparecen en emptyFolders.items deben mostrar
    // un badge amber con el conteo. Sin esto, esos storages se "esconden"
    // entre 173 filas y el operador no sabe cuáles tocar.
    h_check(
        str_contains($html, "emptyFoldersFor(s.id)") && str_contains($html, 'emptyFoldersBadge(s.id)'),
        'Helpers emptyFoldersFor(s.id) y emptyFoldersBadge(s.id) referenciados en la tabla',
        'Falta el badge "carpetas sin archivos" en la tabla de storages'
    );
    h_check(
        preg_match('/emptyFoldersFor\(s\.id\)/', $html) >= 1,
        'Lookup emptyFoldersFor en la fila de la tabla',
        'Falta la referencia a emptyFoldersFor en la fila'
    );
    h_check(
        str_contains($html, 'bg-amber-50/40') && str_contains($html, "emptyFoldersFor(s.id)"),
        'Highlight amber-50/40 en filas con carpetas sin archivos',
        'Falta el highlight de fondo amber en las filas con carpetas sin archivos'
    );

    // ─── Aserción 13: roots de scope visibles por defecto (no ocultos) ─────
    // Bug reportado por el operador: al buscar "emisoras" en la tabla, los
    // roots (01 Emisoras 01, 02 Emisoras 01 Reg, 05 Emisoras 04) NO aparecian
    // aunque sí estaban en el banner de "carpetas sin archivos". Causa: el
    // filtro visibleStorages() tenia `if (!s.parent_scope_id) return true`,
    // lo que ocultaba los roots (parent_scope_id = self) hasta expandir el
    // scope. Fix: roots siempre visibles, solo los hijos de un scope ajeno
    // se ocultan hasta expandir.
    h_section('13. visibleStorages() muestra roots de scope por defecto');
    h_check(
        str_contains($html, "if (s.parent_scope_id && s.parent_scope_id === s.id) return true;"),
        'visibleStorages() tiene early-return para roots de scope (parent_scope_id === s.id)',
        'El filtro visibleStorages() no distingue roots — siguen ocultos al expandir el scope'
    );

    // ─── Aserción 14: badge del storage origen en cada carpeta del modal ───
    // Cuando el operador navega las carpetas de un storage padre en scope,
    // cada carpeta puede venir de un descendiente distinto (ej. 11x "14092026"
    // para el mismo día en cada uno de los 11 descendientes). Sin un badge
    // que muestre el nombre del storage origen, el operador no puede distinguir
    // entre las carpetas — todas se llaman igual y todas llevan al mismo tipo
    // de contenido. El badge se muestra solo si la carpeta NO pertenece al
    // storage clickeado (si es del mismo storage, no hace falta).
    h_section('14. Badge del storage origen en carpetas del modal de archivos');
    h_check(
        str_contains($html, 'folder.source_storage_id !== currentStorage?.id'),
        'Template de carpetas compara source_storage_id con currentStorage.id',
        'Falta el lookup de storage origen en cada carpeta'
    );
    h_check(
        str_contains($html, 'storageById(folder.source_storage_id)'),
        'Lookup storageById(folder.source_storage_id) en el template',
        'Falta la referencia a storageById en el template de carpetas'
    );
    h_check(
        str_contains($html, 'fa-server') && str_contains($html, 'storageById(folder.source_storage_id)'),
        'Badge con icono fa-server y lookup storageById en el template',
        'Falta el badge visual con icono de storage y lookup del nombre'
    );

} finally {
    // ─── Cleanup por tag ───────────────────────────────────────────────────
    DB::table('users')->where('username', 'LIKE', $tag . '%')->delete();
}

echo "\n";
if ($failures === 0) {
    echo "✅ Harness PASS (0 failures)\n";
    exit(0);
} else {
    echo sprintf("❌ Harness FAIL (%d failure(s))\n", $failures);
    exit(1);
}
