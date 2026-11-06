<?php
/**
 * Harness de regresión para change `fix-shares-module-regression`.
 *
 * Valida el CONTRATO del modulo de compartidos contra PostgreSQL real:
 * previsualizacion, depuracion bulk, verificacion por cursor, filtros,
 * ordenamientos, autorizacion y no filtracion de secretos.
 *
 * Bugs originales (2026-09-21, commits `b07e434` y `13645ca`):
 *  - `ShareController::bulkPreview|bulkDelete|verifyAvailability` NO EXISTIAN
 *    aunque las rutas y la UI los invocaban -> HTTP 500 en cada depuracion.
 *  - `PublicShareController::authenticate` NO EXISTIA -> todo enlace con
 *    contrasena era insusable (500 al confirmar la contrasena).
 *  - Los filtros `availability`, rangos de fecha y `status=never` eran
 *    inertes: devolvian el conjunto completo.
 *  - El ordenamiento por `permission`/`status` caia silenciosamente a
 *    `created_at`.
 *  - `password_hash` se serializaba en el alta, el detalle y la edicion.
 *  - `GET /shares/{id}` no autorizaba por propietario.
 *
 * Estrategia:
 *   - Se crean dos usuarios y N shares con tag `hsm_<8hex>`; todo se borra en
 *     `finally` (las shares borran sus access logs por FK).
 *   - Los `files` usados son filas reales (no se crean filas de catalogo):
 *     se guarda su estado de disponibilidad original y se restaura al final.
 *   - El controller se ejercita a traves de sus metodos con Requests
 *     construidos en memoria, que es el mismo camino que usan las rutas.
 *
 * Uso:
 *   cd app && php tests/harness_shares_module.php
 *
 * Exit code: 0 = todas las aserciones pasan, 1 = alguna falla.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareController;
use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

$tag = 'hsm_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

/**
 * Construye un Request con sesion, como lo recibe el controller en HTTP.
 *
 * Por defecto pide JSON: el harness ejercita la API. Para probar la
 * navegacion de navegador hay que pasar `$json = false`.
 */
function h_request(string $uri, string $method = 'GET', array $params = [], bool $json = true): Request
{
    $server = $json ? ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : [];
    $request = Request::create($uri, $method, $params, [], [], $server);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * Ejecuta un metodo del controller capturando la excepcion de validacion
 * (que en HTTP se traduce a 422) en vez de propagarla.
 *
 * @return array{status:int, body:array}
 */
function h_call(callable $fn): array
{
    try {
        $response = $fn();
    } catch (ValidationException $e) {
        return ['status' => 422, 'body' => $e->errors()];
    }

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true) ?? [],
    ];
}

echo "Harness del modulo de compartidos (tag: {$tag})\n";

$owner = null;
$intruder = null;
$admin = null;
$shareIds = [];
$touchedFiles = [];

/**
 * Guarda el estado de disponibilidad original de un file para restaurarlo.
 */
$rememberFile = function (File $file) use (&$touchedFiles) {
    if (! isset($touchedFiles[$file->id])) {
        $touchedFiles[$file->id] = [
            'availability_state' => $file->availability_state,
            'last_verified_at' => $file->last_verified_at,
            'missing_since_at' => $file->missing_since_at,
        ];
    }
};

try {
    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------
    h_section('Fixtures');

    $owner = User::create([
        'email' => $tag . '_owner@harness.local',
        'username' => $tag . '_owner',
        'password_hash' => Hash::make('harness_dummy'),
        'role' => 'user',
        'status' => 'active',
    ]);
    $intruder = User::create([
        'email' => $tag . '_intruder@harness.local',
        'username' => $tag . '_intruder',
        'password_hash' => Hash::make('harness_dummy'),
        'role' => 'user',
        'status' => 'active',
    ]);
    $admin = User::create([
        'email' => $tag . '_admin@harness.local',
        'username' => $tag . '_admin',
        'password_hash' => Hash::make('harness_dummy'),
        'role' => 'admin',
        'status' => 'active',
    ]);
    h_ok('usuarios de prueba creados');

    // Files reales: no se crean filas de catalogo (Mis Archivos es su dueno).
    $files = File::whereNotNull('id')->limit(6)->get();
    h_check($files->count() >= 3, 'se localizaron files reales para las fixtures', 'hacen falta al menos 3 files');
    foreach ($files as $file) {
        $rememberFile($file);
    }

    // Matriz de shares: permisos y vencimientos variados.
    $matrix = [
        ['permission' => 'read', 'expires_at' => null],
        ['permission' => 'write', 'expires_at' => null],
        ['permission' => 'upload', 'expires_at' => null],
        ['permission' => 'full', 'expires_at' => null],
        ['permission' => 'read', 'expires_at' => now()->subDay()],
        ['permission' => 'full', 'expires_at' => now()->subDay()],
        ['permission' => 'read', 'expires_at' => now()->addDay()],
    ];

    foreach ($matrix as $i => $row) {
        $share = Share::create([
            'file_id' => $files[$i % $files->count()]->id,
            'path_snapshot' => $files[$i % $files->count()]->path,
            'token' => $tag . '_tok_' . $i . '_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'password_hash' => $i === 0 ? Hash::make('clave-harness') : null,
            'expires_at' => $row['expires_at'],
            'permissions' => $row['permission'],
            'created_by' => $owner->id,
        ]);
        $shareIds[] = $share->id;
    }

    $total = count($shareIds);
    $expiredCount = count(array_filter($matrix, fn ($r) => $r['expires_at'] !== null && $r['expires_at']->isPast()));
    $permanentCount = count(array_filter($matrix, fn ($r) => $r['expires_at'] === null));
    h_ok("{$total} shares creados ({$expiredCount} expirados, {$permanentCount} sin vencimiento)");

    Session::put('user_id', $owner->id);
    $controller = app(ShareController::class);
    $publicController = app(PublicShareController::class);

    // ------------------------------------------------------------------
    // Listado, filtros y ordenamiento
    // ------------------------------------------------------------------
    h_section('Listado: filtros y ordenamiento');

    $index = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['per_page' => 50])));
    h_check($index['status'] === 200, 'el listado responde 200', "el listado respondio {$index['status']}");

    // Una navegacion normal del navegador NO es una peticion JSON: debe
    // devolver la pagina del panel, no el volcado crudo del JSON. (Regresion
    // 2026-10-27: al reescribir el controller se perdio la rama HTML y la
    // pagina mostro el JSON crudo en el navegador.)
    $navigation = $controller->index(h_request('/shares', 'GET', [], false));
    h_check(
        $navigation instanceof \Illuminate\View\View,
        'una navegacion de navegador devuelve la VISTA, no el JSON',
        'una navegacion de navegador no devolvio la vista: ' . get_class($navigation)
    );
    if ($navigation instanceof \Illuminate\View\View) {
        $html = $navigation->render();
        h_check(
            str_contains($html, 'sharesApp()'),
            'la vista montada contiene el panel Alpine',
            'la vista montada no contiene el panel Alpine'
        );
        h_check(
            ! str_contains($html, '"data":[{"id"'),
            'la vista montada NO es el volcado JSON crudo',
            'la vista montada es el volcado JSON crudo'
        );
    }
    h_check(
        ($index['body']['meta']['total'] ?? -1) === $total,
        "el listado acotado al usuario devuelve los {$total} shares propios",
        "el listado devolvio " . ($index['body']['meta']['total'] ?? 'null') . " shares"
    );
    h_check(
        ! str_contains(json_encode($index['body']), 'password_hash'),
        'el listado NO expone password_hash',
        'el listado EXPONE password_hash'
    );

    $expired = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['status' => 'expired', 'per_page' => 50])));
    h_check(
        ($expired['body']['meta']['total'] ?? -1) === $expiredCount,
        "el filtro status=expired devuelve los {$expiredCount} expirados",
        'el filtro status=expired devolvio ' . ($expired['body']['meta']['total'] ?? 'null')
    );

    $never = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['status' => 'never', 'per_page' => 50])));
    h_check(
        ($never['body']['meta']['total'] ?? -1) === $permanentCount,
        "el filtro status=never devuelve los {$permanentCount} sin vencimiento",
        'el filtro status=never devolvio ' . ($never['body']['meta']['total'] ?? 'null')
    );

    $active = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['status' => 'active', 'per_page' => 50])));
    h_check(
        ($active['body']['meta']['total'] ?? -1) === ($total - $expiredCount - $permanentCount),
        'el filtro status=active devuelve exactamente los vigentes',
        'el filtro status=active devolvio ' . ($active['body']['meta']['total'] ?? 'null')
    );

    $partition = ($expired['body']['meta']['total'] ?? 0) + ($never['body']['meta']['total'] ?? 0) + ($active['body']['meta']['total'] ?? 0);
    h_check($partition === $total, 'los tres estados de expiracion particionan el conjunto', "la particion dio {$partition} de {$total}");

    $byRange = h_call(fn () => $controller->index(h_request('/shares', 'GET', [
        'created_from' => now()->subDay()->format('Y-m-d'),
        'created_to' => now()->addDay()->format('Y-m-d'),
        'per_page' => 50,
    ])));
    h_check(
        ($byRange['body']['meta']['total'] ?? -1) === $total,
        'el rango de fechas de creación cubre el día completo',
        'el rango de fechas devolvio ' . ($byRange['body']['meta']['total'] ?? 'null')
    );

    $badSort = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['sort' => 'DROP TABLE'])));
    h_check($badSort['status'] === 422, 'un sort no permitido se rechaza con 422', "respondio {$badSort['status']}");

    $badStatus = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['status' => 'permanent'])));
    h_check($badStatus['status'] === 422, 'el alias legacy `permanent` se rechaza con 422', "respondio {$badStatus['status']}");

    $permAsc = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['sort' => 'permission', 'direction' => 'asc', 'per_page' => 50])));
    $permSeq = array_column($permAsc['body']['data'] ?? [], 'permissions');
    $levels = array_map(fn ($p) => match ($p) {
        'read' => 1,
        'write', 'upload' => 2,
        'full' => 3,
        default => 4,
    }, $permSeq);
    $sorted = $levels;
    sort($sorted);
    h_check(
        $levels === $sorted,
        'el orden por permiso agrupa por NIVEL (Lectura, Escritura/Subida, Completo)',
        'el orden por permiso no agrupo por nivel: ' . implode(',', $permSeq)
    );

    $statusAsc = h_call(fn () => $controller->index(h_request('/shares', 'GET', ['sort' => 'status', 'direction' => 'asc', 'per_page' => 50])));
    $statusSeq = array_column($statusAsc['body']['data'] ?? [], 'expiry_status');
    $group = array_map(fn ($s) => match ($s) {
        'never' => 1,
        'active' => 2,
        'expired' => 3,
        default => 4,
    }, $statusSeq);
    $sortedGroup = $group;
    sort($sortedGroup);
    h_check(
        $group === $sortedGroup,
        'el orden por estado agrupa por GRUPO (Sin vencimiento, Activo, Expirado)',
        'el orden por estado no agrupo por grupo: ' . implode(',', $statusSeq)
    );

    $payload = json_encode($index['body']);
    h_check(str_contains($payload, '"expiry_status"'), 'el payload expone expiry_status', 'el payload NO expone expiry_status');
    h_check(str_contains($payload, '"availability_state"'), 'el payload expone file.availability_state', 'el payload NO expone availability_state');

    // ------------------------------------------------------------------
    // Previsualizacion: no muta
    // ------------------------------------------------------------------
    h_section('Previsualizacion de depuracion');

    $before = Share::whereIn('id', $shareIds)->count();

    $preview = h_call(fn () => $controller->bulkPreview(h_request('/shares/bulk-preview', 'POST', [
        'all_matching' => true,
        'status' => 'expired',
    ])));

    h_check($preview['status'] === 200, 'la previsualizacion responde 200 (antes: 500)', "respondio {$preview['status']}");
    h_check(
        ($preview['body']['count'] ?? -1) === $expiredCount,
        "la previsualizacion cuenta los {$expiredCount} expirados",
        'la previsualizacion conto ' . ($preview['body']['count'] ?? 'null')
    );
    h_check(
        array_key_exists('summary', $preview['body'])
        && array_key_exists('expired', $preview['body']['summary'])
        && array_key_exists('missing', $preview['body']['summary'])
        && array_key_exists('unknown', $preview['body']['summary'])
        && array_key_exists('permissions', $preview['body']['summary']),
        'la previsualizacion expone el resumen (expired/missing/unknown/permissions)',
        'la previsualizacion no expone el resumen completo'
    );

    $after = Share::whereIn('id', $shareIds)->count();
    h_check($before === $after, 'la previsualizacion NO muta datos', "la previsualizacion cambio el conteo ({$before} -> {$after})");

    $emptyScope = h_call(fn () => $controller->bulkPreview(h_request('/shares/bulk-preview', 'POST', [])));
    h_check($emptyScope['status'] === 422, 'un alcance vacío se rechaza con 422', "respondio {$emptyScope['status']}");

    // ------------------------------------------------------------------
    // Verificacion por cursor
    // ------------------------------------------------------------------
    h_section('Verificacion de disponibilidad por cursor');

    $vistas = [];
    $checked = 0;
    $available = 0;
    $missing = 0;
    $unknown = 0;
    $cursor = null;
    $batches = 0;

    do {
        $body = ['all_matching' => true, 'limit' => 2, 'sort' => 'permission', 'direction' => 'asc'];
        if ($cursor !== null) {
            $body['cursor'] = $cursor;
        }
        $batch = h_call(fn () => $controller->verifyAvailability(h_request('/shares/availability/verify', 'POST', $body)));
        h_check($batch['status'] === 200, 'la verificacion responde 200 (antes: 500)', "respondio {$batch['status']}");

        if ($batch['status'] !== 200) {
            break;
        }

        foreach ($batch['body']['details'] ?? [] as $detail) {
            $vistas[] = $detail['share_id'];
        }
        $checked += $batch['body']['checked'] ?? 0;
        $available += $batch['body']['available'] ?? 0;
        $missing += $batch['body']['missing'] ?? 0;
        $unknown += $batch['body']['unknown'] ?? 0;
        $cursor = ($batch['body']['has_more'] ?? false) ? ($batch['body']['next_cursor'] ?? null) : null;
        $batches++;
    } while ($cursor !== null && $batches < 30);

    h_check($checked === $total, "la verificacion cubrio los {$total} enlaces (checked={$checked})", "la verificacion cubrio {$checked} de {$total}");
    h_check(
        count($vistas) === count(array_unique($vistas)),
        'la verificacion no repite ningun enlace',
        'la verificacion repitio enlaces'
    );
    h_check(
        ($available + $missing + $unknown) === $checked,
        'available + missing + unknown = checked',
        'las cuentas de la verificacion no cuadran'
    );
    h_check($batches > 1, 'el recorrido se hizo en varias tandas (cursor real)', "solo hubo {$batches} tanda(s)");

    // ------------------------------------------------------------------
    // Autorizacion y no filtracion de secretos
    // ------------------------------------------------------------------
    h_section('Autorizacion y secretos');

    Session::put('user_id', $intruder->id);
    $foreign = h_call(fn () => $controller->show($shareIds[0]));
    h_check($foreign['status'] === 403, 'GET /shares/{id} de otro usuario responde 403', "respondio {$foreign['status']}");
    h_check(
        ! str_contains(json_encode($foreign['body']), $owner->username),
        'la respuesta de detalle ajeno no revela datos del propietario',
        'la respuesta de detalle ajeno revela datos'
    );

    Session::put('user_id', $owner->id);
    $own = h_call(fn () => $controller->show($shareIds[0]));
    h_check($own['status'] === 200, 'GET /shares/{id} del propietario responde 200', "respondio {$own['status']}");
    h_check(
        ! str_contains(json_encode($own['body']), 'password_hash'),
        'el detalle NO expone password_hash',
        'el detalle EXPONE password_hash'
    );

    // ------------------------------------------------------------------
    // Alta: politica de expiracion por defecto
    // ------------------------------------------------------------------
    h_section('Alta y ciclo de vida');

    // El alta se hace como admin: un usuario normal NO puede compartir un
    // archivo que no posee y sobre el que no tiene permiso (403 correcto), y
    // esa regla no es lo que se esta probando aqui.
    Session::put('user_id', $admin->id);

    $created = h_call(fn () => $controller->store(h_request('/shares', 'POST', [
        'file_id' => $files[0]->id,
        'permissions' => 'read',
    ])));
    h_check($created['status'] === 201, 'el alta responde 201', "respondio {$created['status']}");

    if ($created['status'] === 201) {
        $newId = $created['body']['id'];
        $shareIds[] = $newId;

        h_check(
            ($created['body']['expiry_status'] ?? '') === 'active',
            'un alta sin fecha vence por la politica vigente (no queda permanente)',
            'un alta sin fecha quedo ' . ($created['body']['expiry_status'] ?? 'null')
        );
        h_check(
            ! empty($created['body']['expires_at']),
            'un alta sin fecha trae fecha de vencimiento futura',
            'un alta sin fecha no trae fecha de vencimiento'
        );
        h_check(
            ! str_contains(json_encode($created['body']), 'password_hash'),
            'el alta NO expone password_hash',
            'el alta EXPONE password_hash'
        );
    }

    $permanent = h_call(fn () => $controller->store(h_request('/shares', 'POST', [
        'file_id' => $files[0]->id,
        'permissions' => 'read',
        'never_expires' => true,
    ])));
    h_check($permanent['status'] === 201, 'el alta con never_expires responde 201', "respondio {$permanent['status']}");
    if ($permanent['status'] === 201) {
        $shareIds[] = $permanent['body']['id'];
        h_check(
            ($permanent['body']['expiry_status'] ?? '') === 'never',
            'never_expires deja el enlace sin vencimiento',
            'never_expires dejo ' . ($permanent['body']['expiry_status'] ?? 'null')
        );
    }

    // Un usuario normal sin permiso sobre el recurso NO puede compartirlo.
    Session::put('user_id', $owner->id);
    $denied = h_call(fn () => $controller->store(h_request('/shares', 'POST', [
        'file_id' => $files[0]->id,
        'permissions' => 'read',
    ])));
    h_check($denied['status'] === 403, 'un usuario sin permiso sobre el recurso NO puede compartirlo', "respondio {$denied['status']}");

    Session::put('user_id', $owner->id);

    // ------------------------------------------------------------------
    // Depuracion bulk: respeta alcance, no borra files
    // ------------------------------------------------------------------
    h_section('Depuracion bulk');

    $filesBefore = DB::table('files')->count();
    $logRowsBefore = DB::table('share_access_log')->count();

    $target = $shareIds[0];
    $foreignId = Share::create([
        'file_id' => $files[0]->id,
        'path_snapshot' => $files[0]->path,
        'token' => $tag . '_foreign_' . substr(bin2hex(random_bytes(4)), 0, 8),
        'expires_at' => now()->subDay(),
        'permissions' => 'read',
        'created_by' => $intruder->id,
    ])->id;

    $mismatch = h_call(fn () => $controller->bulkDelete(h_request('/shares/bulk-delete', 'POST', [
        'ids' => [$target],
        'confirm_count' => 999,
    ])));
    h_check($mismatch['status'] === 409, 'un confirm_count desactualizado responde 409 y no borra', "respondio {$mismatch['status']}");
    h_check(Share::where('id', $target)->exists(), 'el share sobrevive al 409', 'el share se borro pese al 409');

    $bulk = h_call(fn () => $controller->bulkDelete(h_request('/shares/bulk-delete', 'POST', [
        'ids' => [$target, $foreignId],
        'confirm_count' => 1,
    ])));
    h_check($bulk['status'] === 200, 'la depuracion bulk responde 200 (antes: 500)', "respondio {$bulk['status']}");
    h_check(
        ($bulk['body']['deleted_count'] ?? -1) === 1,
        'la depuracion elimina SOLO el share propio',
        'deleted_count = ' . ($bulk['body']['deleted_count'] ?? 'null')
    );
    h_check(
        ($bulk['body']['omitted_count'] ?? -1) === 1,
        'la depuracion reporta el share ajeno como omitido',
        'omitted_count = ' . ($bulk['body']['omitted_count'] ?? 'null')
    );
    h_check(
        array_key_exists('failed_count', $bulk['body'] ?? []),
        'la depuracion expone failed_count',
        'la depuracion no expone failed_count'
    );
    h_check(! Share::where('id', $target)->exists(), 'el share propio quedo revocado', 'el share propio no se revoco');
    h_check(Share::where('id', $foreignId)->exists(), 'el share ajeno NO fue revocado', 'el share ajeno se revoco (FUGA DE ALCANCE)');

    $filesAfter = DB::table('files')->count();
    h_check($filesBefore === $filesAfter, 'la depuracion NO borro filas de files', "files cambio de {$filesBefore} a {$filesAfter}");
    h_check(
        Share::where('id', $foreignId)->exists(),
        'la fila ajena sigue intacta tras la depuracion',
        'la fila ajena desaparecio'
    );
    $logRowsAfter = DB::table('share_access_log')->count();
    h_check($logRowsAfter <= $logRowsBefore, 'los access logs solo pueden decrecer (cascada por FK)', 'los access logs crecieron');

    Share::where('id', $foreignId)->delete();

    // ------------------------------------------------------------------
    // Enlace publico con contrasena
    // ------------------------------------------------------------------
    h_section('Enlace publico con contrasena');

    $protected = Share::whereIn('id', $shareIds)->whereNotNull('password_hash')->first();
    if ($protected === null) {
        $protected = Share::create([
            'file_id' => $files[0]->id,
            'path_snapshot' => $files[0]->path,
            'token' => $tag . '_prot_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'password_hash' => Hash::make('clave-harness'),
            'expires_at' => now()->addDay(),
            'permissions' => 'read',
            'created_by' => $owner->id,
        ]);
        $shareIds[] = $protected->id;
    }

    $token = $protected->token;
    cache()->forget("share:meta:{$token}");

    $wrong = h_call(fn () => $publicController->authenticate(
        h_request("/s/{$token}/authenticate", 'POST', ['password' => 'incorrecta']),
        $token
    ));
    h_check($wrong['status'] === 401, 'una contrasena incorrecta responde 401 (antes: 500)', "respondio {$wrong['status']}");
    h_check(
        ! session()->has("share_auth_{$token}"),
        'la contrasena incorrecta NO crea la sesion de acceso',
        'la contrasena incorrecta creo la sesion de acceso'
    );

    $right = h_call(fn () => $publicController->authenticate(
        h_request("/s/{$token}/authenticate", 'POST', ['password' => 'clave-harness']),
        $token
    ));
    h_check($right['status'] === 302, 'una contrasena correcta redirige (302)', "respondio {$right['status']}");
    h_check(
        session()->has("share_auth_{$token}"),
        'la contrasena correcta crea la sesion de acceso',
        'la contrasena correcta NO creo la sesion de acceso'
    );

    cache()->forget("share:meta:{$token}");
} catch (Throwable $e) {
    h_fail('EXCEPCION NO CONTROLADA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // ------------------------------------------------------------------
    // Limpieza
    // ------------------------------------------------------------------
    h_section('Limpieza');

    foreach ($shareIds as $id) {
        Share::where('id', $id)->delete();
    }
    Share::where('token', 'like', $tag . '%')->delete();

    // Restaurar el estado de disponibilidad original de los files tocados.
    foreach ($touchedFiles as $fileId => $original) {
        DB::table('files')->where('id', $fileId)->update([
            'availability_state' => $original['availability_state'],
            'last_verified_at' => $original['last_verified_at'],
            'missing_since_at' => $original['missing_since_at'],
        ]);
    }

    foreach ([$owner, $intruder, $admin] as $user) {
        if ($user !== null) {
            $user->delete();
        }
    }

    h_ok('fixtures eliminadas (shares, users y estado de disponibilidad restaurado)');
}

echo "\n";

if ($failures === 0) {
    echo "OK: todas las aserciones pasan\n";
    exit(0);
}

echo "FALLA: {$failures} asercion(es)\n";
exit(1);
