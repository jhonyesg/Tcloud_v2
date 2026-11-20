<?php
/**
 * Harness de regresión del CONTROL DE SOLICITUDES DE BACKUP POR CLIENTE
 * (change `solicitudes-backup-por-cliente`).
 *
 * Valida el contrato de la vista de administración:
 *   - el servicio agrupa las solicitudes por cliente, con el detalle y la
 *     demanda por canal;
 *   - el estado que se reporta es el EFECTIVO (un `ready` con el enlace
 *     vencido se reporta `expired`, uno sin share se reporta `unavailable`);
 *   - el enlace solo se expone cuando la solicitud está viva;
 *   - el reporte es de SOLO LECTURA: no muta `backup_requests` ni `shares`;
 *   - las rutas existen, resuelven a métodos reales y están protegidas por
 *     `auth` + `admin` (un cliente recibe 403);
 *   - la pantalla de Backup muestra la sección y su ancla de guía.
 *
 * Fixtures: usuarios, un storage, una carpeta, shares y solicitudes con un
 * tag único. Todo se borra en el `finally`.
 *
 * Uso:
 *   cd app && php tests/harness_backup_control_por_cliente.php
 *   exit 0 = OK, exit 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BackupRequest;
use App\Services\MisArchivos\BackupRequestReportService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

$tag = 'hbc_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness control de solicitudes de backup por cliente (tag: {$tag})\n";

$clienteA = null;
$clienteB = null;
$storage = null;
$carpeta = null;
$adminId = null;
$sessions = [];

try {
    // ------------------------------------------------------------------
    h_section('cleanup defensivo pre-run');
    DB::table('backup_requests')->where('motivo', 'like', $tag . '%')->delete();
    DB::table('users')->where('username', 'like', $tag . '%')->delete();

    // ------------------------------------------------------------------
    h_section('fixtures');

    $adminId = (int) DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
    h_check($adminId > 0, "hay un administrador para la sesión ({$adminId})");

    $mkUsuario = function (string $sufijo) use ($tag) {
        return DB::table('users')->insertGetId([
            'email' => "{$tag}_{$sufijo}@example.com",
            'password_hash' => bcrypt('harness'),
            'role' => 'user',
            'username' => "{$tag}_{$sufijo}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $clienteA = (int) $mkUsuario('a');
    $clienteB = (int) $mkUsuario('b');

    $storageId = DB::table('storage_providers')->insertGetId([
        'name' => "{$tag} origen",
        'type' => 'local',
        'kind' => 'local',
        'base_path' => '/tmp/' . $tag,
        'enabled' => true,
        'is_accessible' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $storage = App\Models\StorageProvider::find($storageId);

    $carpetaId = DB::table('files')->insertGetId([
        'name' => 'dia',
        'path' => $tag . '/dia',
        'size' => 0,
        'storage_provider_id' => $storageId,
        'owner_id' => $clienteA,
        'is_folder' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $carpeta = App\Models\File::find($carpetaId);

    h_check($clienteA > 0 && $clienteB > 0 && $storage !== null && $carpeta !== null, 'fixtures creados');

    // Dos shares: uno vivo (vigencia futura) y uno vencido.
    $mkShare = function (int $createdBy, Carbon $expires) use ($carpeta, $tag) {
        return DB::table('shares')->insertGetId([
            'file_id' => $carpeta->id,
            'token' => $tag . '_' . bin2hex(random_bytes(6)),
            'permissions' => 'read',
            'created_by' => $createdBy,
            'expires_at' => $expires,
            'path_snapshot' => $tag . '/dia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $shareVivo = (int) $mkShare($clienteA, Carbon::now()->addDays(2));
    $shareVencido = (int) $mkShare($clienteA, Carbon::now()->subDay());

    $mkSolicitud = function (int $userId, string $day, string $status, ?int $shareId, ?Carbon $expira) use ($storage, $carpeta, $tag) {
        return DB::table('backup_requests')->insertGetId([
            'user_id' => $userId,
            'source_storage_id' => $storage->id,
            'source_folder_id' => $carpeta->id,
            'backup_storage_id' => null,
            'share_id' => $shareId,
            'day' => $day,
            'motivo' => $tag . ' motivo de prueba',
            'status' => $status,
            'backup_path' => '/tmp/' . $tag . '/' . $day,
            'link_expires_at' => $expira,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // Cliente A: vigente, expirada y revocada.
    $rVigente = (int) $mkSolicitud($clienteA, '11112026', BackupRequest::STATUS_READY, $shareVivo, Carbon::now()->addDays(2));
    $rExpirada = (int) $mkSolicitud($clienteA, '10112026', BackupRequest::STATUS_READY, $shareVencido, Carbon::now()->subDay());
    $rRevocada = (int) $mkSolicitud($clienteA, '09112026', BackupRequest::STATUS_REVOKED, null, null);

    // Cliente B: sin material (declarada) y sin material (share borrado).
    $rSinMaterial = (int) $mkSolicitud($clienteB, '08112026', BackupRequest::STATUS_UNAVAILABLE, null, null);
    $rSinShare = (int) $mkSolicitud($clienteB, '07112026', BackupRequest::STATUS_READY, null, Carbon::now()->addDays(2));

    h_check($rVigente > 0 && $rExpirada > 0 && $rRevocada > 0 && $rSinMaterial > 0 && $rSinShare > 0, 'solicitudes de prueba creadas');

    // ------------------------------------------------------------------
    h_section('el servicio agrupa por cliente');
    /** @var BackupRequestReportService $service */
    $service = app(BackupRequestReportService::class);

    $reporteA = $service->porCliente(['user_id' => $clienteA]);
    h_check(count($reporteA['clientes']) === 1, 'el filtro por cliente deja UN cliente', 'quedaron ' . count($reporteA['clientes']));
    h_check($reporteA['totales']['solicitudes'] === 3, 'el cliente A tiene 3 solicitudes', 'tiene ' . $reporteA['totales']['solicitudes']);
    h_check($reporteA['totales']['vigentes'] === 1, 'una solicitud vigente', 'vigentes=' . $reporteA['totales']['vigentes']);
    h_check($reporteA['totales']['expiradas'] === 1, 'una solicitud expirada', 'expiradas=' . $reporteA['totales']['expiradas']);
    h_check($reporteA['totales']['revocadas'] === 1, 'una solicitud revocada', 'revocadas=' . $reporteA['totales']['revocadas']);

    $reporteB = $service->porCliente(['user_id' => $clienteB]);
    h_check($reporteB['totales']['solicitudes'] === 2, 'el cliente B tiene 2 solicitudes', 'tiene ' . $reporteB['totales']['solicitudes']);
    h_check($reporteB['totales']['sin_material'] === 2, 'las dos del cliente B están sin material', 'sin_material=' . $reporteB['totales']['sin_material']);

    // ------------------------------------------------------------------
    h_section('el estado reportado es el efectivo');
    $detalle = [];
    foreach ($reporteA['clientes'][0]['detalle'] as $fila) {
        $detalle[$fila['id']] = $fila;
    }

    h_check(($detalle[$rVigente]['status'] ?? null) === BackupRequest::STATUS_READY, 'la vigente se reporta ready');
    h_check(($detalle[$rExpirada]['status'] ?? null) === BackupRequest::STATUS_EXPIRED, 'la `ready` con el enlace vencido se reporta expired', 'se reportó ' . ($detalle[$rExpirada]['status'] ?? '?'));
    h_check(($detalle[$rRevocada]['status'] ?? null) === BackupRequest::STATUS_REVOKED, 'la revocada se reporta revoked');

    $detalleB = [];
    foreach ($reporteB['clientes'][0]['detalle'] as $fila) {
        $detalleB[$fila['id']] = $fila;
    }
    h_check(
        ($detalleB[$rSinShare]['status'] ?? null) === BackupRequest::STATUS_UNAVAILABLE,
        'la `ready` sin share se reporta unavailable',
        'se reportó ' . ($detalleB[$rSinShare]['status'] ?? '?')
    );

    // ------------------------------------------------------------------
    h_section('el enlace solo se expone cuando la solicitud está viva');
    h_check(! empty($detalle[$rVigente]['link']['public_url']), 'la solicitud vigente expone su enlace');
    h_check(($detalle[$rExpirada]['link'] ?? null) === null, 'la expirada NO expone enlace');
    h_check(($detalle[$rRevocada]['link'] ?? null) === null, 'la revocada NO expone enlace');
    h_check(($detalleB[$rSinShare]['link'] ?? null) === null, 'la que no tiene share NO expone enlace');

    // ------------------------------------------------------------------
    h_section('la demanda por canal cuadra con el total');
    $reporte = $service->porCliente();
    $totalFixture = (int) DB::table('backup_requests')->where('motivo', 'like', $tag . '%')->count();
    h_check($reporte['totales']['solicitudes'] >= $totalFixture, 'el total del reporte incluye todas las solicitudes', 'total ' . $reporte['totales']['solicitudes']);

    $canalFixture = null;
    foreach ($reporte['canales'] as $canal) {
        if ((int) $canal['storage_id'] === (int) $storage->id) {
            $canalFixture = $canal;
        }
    }
    h_check($canalFixture !== null, 'el storage de origen aparece en la demanda por canal');
    h_check($canalFixture !== null && $canalFixture['solicitudes'] === $totalFixture, 'el canal cuenta todas las solicitudes del fixture', 'cuenta ' . ($canalFixture['solicitudes'] ?? '?'));
    h_check($canalFixture !== null && $canalFixture['clientes'] === 2, 'el canal lo pidieron 2 clientes', 'clientes=' . ($canalFixture['clientes'] ?? '?'));

    // ------------------------------------------------------------------
    h_section('el filtro por estado');
    $soloVigentes = $service->porCliente(['user_id' => $clienteA, 'estado' => BackupRequestReportService::FILTRO_VIGENTES]);
    h_check($soloVigentes['totales']['solicitudes'] === 1, 'el filtro "vigentes" deja 1', 'dejó ' . $soloVigentes['totales']['solicitudes']);

    $soloRevocadas = $service->porCliente(['user_id' => $clienteA, 'estado' => BackupRequestReportService::FILTRO_REVOCADAS]);
    h_check($soloRevocadas['totales']['solicitudes'] === 1, 'el filtro "revocadas" deja 1', 'dejó ' . $soloRevocadas['totales']['solicitudes']);

    $estadoInventado = $service->porCliente(['estado' => 'inventado']);
    h_check($estadoInventado['estado'] === BackupRequestReportService::FILTRO_TODAS, 'un estado desconocido cae en "todas"');

    // ------------------------------------------------------------------
    h_section('el reporte es de SOLO LECTURA');
    $antesSolicitudes = DB::table('backup_requests')->count();
    $antesShares = DB::table('shares')->count();
    $antesUpdated = DB::table('backup_requests')
        ->where('motivo', 'like', $tag . '%')
        ->max('updated_at');

    $service->porCliente();
    $service->toTxt($service->porCliente());
    $service->clientesConSolicitudes();

    h_check(DB::table('backup_requests')->count() === $antesSolicitudes, 'no se crean solicitudes');
    h_check(DB::table('shares')->count() === $antesShares, 'no se crean shares');
    h_check(
        DB::table('backup_requests')->where('motivo', 'like', $tag . '%')->max('updated_at') === $antesUpdated,
        'no se modifican las solicitudes'
    );

    // ------------------------------------------------------------------
    h_section('contrato de rutas');
    foreach (['admin.backups.control', 'admin.backups.control-clientes', 'admin.backups.control-exportar'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        h_check($route !== null, "ruta {$name} registrada");
    }

    foreach (['admin.backups.control', 'admin.backups.control-clientes', 'admin.backups.control-exportar'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        if ($route === null) {
            continue;
        }
        h_check(
            str_contains((string) $route->getAction('uses'), 'BackupRequestController'),
            "la ruta {$name} resuelve al controller del control"
        );
        $middleware = $route->gatherMiddleware();
        h_check(in_array('auth', $middleware, true), "la ruta {$name} exige auth");
        h_check(in_array('admin', $middleware, true), "la ruta {$name} exige admin");
    }

    // ------------------------------------------------------------------
    h_section('la pantalla de Backup muestra la sección');
    $mkSesion = function (int $userId, string $role) use (&$sessions) {
        $sessionId = bin2hex(random_bytes(20));
        $cookieName = (string) config('session.cookie', 'tcloud_session');

        DB::table('user_sessions')->insert([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'harness-backup-control',
            'created_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $store = Session::driver();
        $store->setId($sessionId);
        $store->start();
        $store->put('user_id', $userId);
        $store->put('user_role', $role);
        $store->save();

        $cookieValue = app('encrypter')->encrypt(
            CookieValuePrefix::create($cookieName, app('encrypter')->getKey()) . $sessionId,
            false
        );

        $sessions[] = $sessionId;

        return [$sessionId, $cookieName, $cookieValue, (string) $store->get('_token')];
    };

    $dispatch = function (string $uri, array $cookie, string $accept = 'application/json') {
        [$sessionId, $cookieName, $cookieValue, $csrf] = $cookie;
        $request = Request::create($uri, 'GET', [], [$cookieName => $cookieValue]);
        $request->headers->set('Accept', $accept);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('X-CSRF-TOKEN', $csrf);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    };

    $adminCookie = $mkSesion($adminId, 'admin');
    $clienteCookie = $mkSesion((int) $clienteA, 'user');

    $response = $dispatch('/admin/backups/control?user_id=' . $clienteA, $adminCookie);
    h_check($response->getStatusCode() === 200, 'el admin recibe el reporte', 'respondió ' . $response->getStatusCode());
    $json = json_decode($response->getContent(), true);
    h_check(is_array($json) && isset($json['totales'], $json['clientes'], $json['canales']), 'el reporte trae totales, clientes y canales');
    h_check(($json['totales']['solicitudes'] ?? 0) === 3, 'el reporte del cliente A trae 3 solicitudes', 'trajo ' . ($json['totales']['solicitudes'] ?? '?'));

    $response = $dispatch('/admin/backups/control/clientes', $adminCookie);
    h_check($response->getStatusCode() === 200, 'el admin recibe la lista de clientes');
    $clientes = json_decode($response->getContent(), true)['clientes'] ?? [];
    h_check(is_array($clientes) && count($clientes) > 0, 'la lista de clientes no está vacía');

    $response = $dispatch('/admin/backups/control/exportar?user_id=' . $clienteA, $adminCookie);
    h_check($response->getStatusCode() === 200, 'el admin descarga el TXT');
    h_check(
        str_contains((string) $response->headers->get('Content-Type'), 'text/plain'),
        'el TXT se sirve como text/plain',
        'Content-Type: ' . $response->headers->get('Content-Type')
    );
    h_check(str_contains($response->getContent(), $tag . '_a'), 'el TXT nombra al cliente del fixture');

    // Un cliente NO puede ver el reporte de los demás.
    foreach ([
        '/admin/backups/control',
        '/admin/backups/control/clientes',
        '/admin/backups/control/exportar',
    ] as $ruta) {
        $response = $dispatch($ruta, $clienteCookie);
        h_check($response->getStatusCode() === 403, "un cliente recibe 403 en {$ruta}", 'respondió ' . $response->getStatusCode());
    }

    // La pantalla de administración expone la sección como PESTAÑA (no como
    // tarjeta desplegable): es el mismo patrón del módulo de Solicitud
    // Puntual ("Medios | Mis solicitudes | Control por cliente").
    $response = $dispatch('/admin/backups', $adminCookie, 'text/html');
    h_check($response->getStatusCode() === 200, 'la pantalla de Backup responde 200');
    $html = (string) $response->getContent();
    h_check(str_contains($html, 'Solicitudes de backup por cliente'), 'la pantalla muestra las solicitudes por cliente');
    h_check(str_contains($html, 'data-backup-tour="requests"'), 'la pestaña tiene el ancla de la guía');
    h_check(str_contains($html, 'Demanda por canal'), 'la pantalla muestra la demanda por canal');
    h_check(str_contains($html, "selectTab('requests')"), 'la pestaña de solicitudes cambia con selectTab');
    h_check(str_contains($html, "selectTab('links')"), 'la pestaña de vínculos cambia con selectTab');
    h_check(str_contains($html, "x-show=\"tab === 'requests'\""), 'el reporte vive en una pestaña');
    h_check(str_contains($html, 'Vínculos de backup'), 'la barra de pestañas declara los vínculos');
    h_check(str_contains($html, 'solicitudesTotal'), 'la pestaña muestra el total de solicitudes');
    h_check(! str_contains($html, 'toggleRequests()'), 'la tarjeta desplegable ya no existe');
    h_check(! str_contains($html, 'requestsOpen'), 'el estado del desplegable ya no existe');

    // ------------------------------------------------------------------
    h_section('cleanup');
    DB::table('backup_requests')->where('motivo', 'like', $tag . '%')->delete();
    DB::table('shares')->where('token', 'like', $tag . '%')->delete();
    DB::table('files')->where('id', $carpeta->id)->delete();
    DB::table('storage_providers')->where('id', $storage->id)->delete();
    DB::table('users')->whereIn('id', [$clienteA, $clienteB])->delete();
    h_check(
        DB::table('backup_requests')->where('motivo', 'like', $tag . '%')->count() === 0,
        'las solicitudes de prueba se borraron'
    );
} catch (\Throwable $e) {
    h_fail('excepción: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
} finally {
    DB::table('backup_requests')->where('motivo', 'like', $tag . '%')->delete();
    DB::table('shares')->where('token', 'like', $tag . '%')->delete();
    DB::table('files')->where('path', 'like', $tag . '%')->delete();
    DB::table('storage_providers')->where('name', 'like', $tag . '%')->delete();
    DB::table('users')->where('username', 'like', $tag . '%')->delete();

    foreach ($sessions as $sessionId) {
        DB::table('user_sessions')->where('session_id', $sessionId)->delete();
        Session::driver()->getHandler()->destroy($sessionId);
    }
}

// ----------------------------------------------------------------------
echo "\n=== Resultado ===\n";

if ($failures === 0) {
    echo "OK: todas las aserciones pasan\n";
    exit(0);
}

echo "FALLOS: {$failures}\n";
exit(1);
