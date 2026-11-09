<?php
/**
 * Harness de regresión del módulo de administración del backup
 * (change `admin-backup-mapping`).
 *
 * Valida el contrato del vínculo "storage de origen → carpeta del árbol de
 * producción":
 *   - las rutas existen, resuelven a métodos reales y están protegidas por
 *     `auth` + `admin`;
 *   - el servicio rechaza los vínculos inválidos (nombre con separadores,
 *     carpeta inexistente, destino no local, origen dentro del árbol,
 *     origen == destino, carpeta ya asignada);
 *   - asignar y quitar el vínculo se reflejan en `backupMapping()`;
 *   - el resolver del módulo cliente ve el vínculo (o dice `no_mapping`).
 *
 * Fixtures: dos storages temporales bajo `/tmp` (origen + árbol de backup) y
 * un directorio con una carpeta-día. Todo se borra en el `finally`.
 *
 * Uso:
 *   cd app && php tests/harness_backup_mapping.php
 *   exit 0 = OK, exit 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\StorageProvider;
use App\Services\MisArchivos\BackupMappingService;
use App\Services\MisArchivos\BackupResolver;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

$tag = 'hbmg_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness backup-mapping (tag: {$tag})\n";

$tmpRoot = '/tmp/' . $tag;
$sourceId = null;
$backupId = null;
$otherSourceId = null;
$nonLocalId = null;
$day = Carbon::now()->format('dmY');
$folder = '01_Test_BK';

try {
    // ------------------------------------------------------------------
    h_section('cleanup defensivo pre-run');
    DB::table('storage_providers')->where('name', 'like', $tag . '%')->delete();
    @exec('rm -rf ' . escapeshellarg($tmpRoot));

    // ------------------------------------------------------------------
    h_section('fixtures');
    mkdir($tmpRoot . '/src', 0755, true);
    mkdir($tmpRoot . '/tree/' . $folder . '/' . $day, 0755, true);
    mkdir($tmpRoot . '/src2', 0755, true);

    $source = StorageProvider::create([
        'name' => $tag . ' origen',
        'type' => 'local',
        'kind' => 'local',
        'base_path' => $tmpRoot . '/src',
        'enabled' => true,
        'is_accessible' => true,
    ]);
    $sourceId = (int) $source->id;

    $backup = StorageProvider::create([
        'name' => $tag . ' arbol',
        'type' => 'local',
        'kind' => 'local',
        'base_path' => $tmpRoot . '/tree',
        'enabled' => true,
        'is_accessible' => true,
    ]);
    $backupId = (int) $backup->id;

    $otherSource = StorageProvider::create([
        'name' => $tag . ' origen 2',
        'type' => 'local',
        'kind' => 'local',
        'base_path' => $tmpRoot . '/src2',
        'enabled' => true,
        'is_accessible' => true,
    ]);
    $otherSourceId = (int) $otherSource->id;

    $nonLocal = StorageProvider::create([
        'name' => $tag . ' remoto',
        'type' => 'local',
        'kind' => 'external',
        'base_path' => $tmpRoot . '/tree',
        'enabled' => true,
        'is_accessible' => true,
    ]);
    $nonLocalId = (int) $nonLocal->id;

    h_check($sourceId > 0 && $backupId > 0, "storages de prueba creados ({$sourceId}, {$backupId})");

    /** @var BackupMappingService $service */
    $service = app(BackupMappingService::class);

    // ------------------------------------------------------------------
    h_section('contrato de rutas');
    $routeNames = ['admin.backups.index', 'admin.backups.folders', 'admin.backups.store', 'admin.backups.destroy'];
    foreach ($routeNames as $name) {
        $route = Route::getRoutes()->getByName($name);
        h_check($route !== null, "ruta {$name} registrada");
    }

    $index = Route::getRoutes()->getByName('admin.backups.index');
    if ($index !== null) {
        $action = $index->getAction('uses');
        h_check(str_contains((string) $action, 'BackupMappingController'), 'la ruta index resuelve al controller del módulo');
        $middleware = $index->gatherMiddleware();
        h_check(in_array('auth', $middleware, true) && in_array('admin', $middleware, true), 'la ruta index exige auth + admin');
    }

    $store = Route::getRoutes()->getByName('admin.backups.store');
    if ($store !== null) {
        $middleware = $store->gatherMiddleware();
        h_check(in_array('auth', $middleware, true) && in_array('admin', $middleware, true), 'la ruta store exige auth + admin');
    }

    // ------------------------------------------------------------------
    h_section('validación: nombres de carpeta inválidos');
    foreach (['con/barra', 'con..puntos', 'con%porcentaje', '', '   '] as $bad) {
        $result = $service->validate($sourceId, $backupId, $bad);
        h_check(
            ! $result['ok'] && in_array(BackupMappingService::ERROR_INVALID_FOLDER_NAME, $result['errors'], true),
            'rechaza el nombre "' . $bad . '"'
        );
    }

    // ------------------------------------------------------------------
    h_section('validación: carpeta inexistente en disco');
    $result = $service->validate($sourceId, $backupId, '99_No_Existe');
    h_check(
        ! $result['ok'] && in_array(BackupMappingService::ERROR_FOLDER_NOT_FOUND, $result['errors'], true),
        'rechaza una carpeta que no existe en el árbol'
    );

    // ------------------------------------------------------------------
    h_section('validación: origen y destino');
    $result = $service->validate($sourceId, $sourceId, $folder);
    h_check(
        ! $result['ok'] && in_array(BackupMappingService::ERROR_SAME_STORAGE, $result['errors'], true),
        'rechaza que el origen sea el propio árbol de backup'
    );

    $result = $service->validate($backupId, $backupId, $folder);
    h_check(
        ! $result['ok'] && in_array(BackupMappingService::ERROR_SOURCE_INSIDE_TREE, $result['errors'], true),
        'rechaza un origen que está dentro del árbol de backup'
    );

    $result = $service->validate($sourceId, $nonLocalId, $folder);
    h_check(
        ! $result['ok'] && in_array(BackupMappingService::ERROR_BACKUP_NOT_LOCAL, $result['errors'], true),
        'rechaza un destino que no es local'
    );

    // ------------------------------------------------------------------
    h_section('validación: asignación válida');
    $result = $service->validate($sourceId, $backupId, $folder);
    h_check($result['ok'], 'acepta un vínculo válido', 'un vínculo válido fue rechazado: ' . implode(',', $result['errors']));

    $assigned = $service->assign($sourceId, $backupId, $folder);
    h_check($assigned['ok'], 'el servicio escribe el vínculo');

    $fresh = StorageProvider::find($sourceId);
    $mapping = $fresh->backupMapping();
    h_check($mapping !== null, 'backupMapping() devuelve el par declarado');
    h_check($mapping !== null && $mapping['folder'] === $folder, 'la carpeta del vínculo es la esperada');
    h_check($mapping !== null && (int) $mapping['storage']->id === $backupId, 'el árbol del vínculo es el esperado');
    h_check($fresh->hasBackupMapping(), 'hasBackupMapping() es true');

    // ------------------------------------------------------------------
    h_section('unicidad de la carpeta');
    $result = $service->validate($otherSourceId, $backupId, $folder);
    h_check(
        ! $result['ok'] && in_array(BackupMappingService::ERROR_FOLDER_ALREADY_MAPPED, $result['errors'], true),
        'una carpeta ya asignada no se puede volver a asignar'
    );

    $result = $service->validate($sourceId, $backupId, $folder);
    h_check($result['ok'], 're-asignar la misma carpeta al MISMO origen sigue siendo válido (idempotente)');

    $duplicates = DB::table('storage_providers')
        ->where('backup_storage_id', $backupId)
        ->where('backup_folder_name', $folder)
        ->count();
    h_check($duplicates === 1, 'no hay duplicados del mismo par (count=' . $duplicates . ')');

    // ------------------------------------------------------------------
    h_section('el resolver del módulo cliente ve el vínculo');
    /** @var BackupResolver $resolver */
    $resolver = app(BackupResolver::class);
    $resolution = $resolver->resolveDayFolder(StorageProvider::find($sourceId), $day, false);
    h_check(
        $resolution['available'] === true && $resolution['reason'] === null,
        'el día del árbol fixture se resuelve como disponible',
        'la resolución falló: ' . json_encode($resolution)
    );
    h_check(
        ($resolution['backup_path'] ?? '') === $tmpRoot . '/tree/' . $folder . '/' . $day,
        'la ruta resuelta es la del árbol fixture',
        'ruta resuelta inesperada: ' . ($resolution['backup_path'] ?? 'null')
    );

    // ------------------------------------------------------------------
    h_section('sin vínculo, el resolver dice no_mapping');
    $service->unassign($sourceId);
    $fresh = StorageProvider::find($sourceId);
    h_check($fresh->backupMapping() === null, 'tras quitar el vínculo, backupMapping() es null');
    h_check(! $fresh->hasBackupMapping(), 'tras quitar el vínculo, hasBackupMapping() es false');

    $resolution = $resolver->resolveDayFolder(StorageProvider::find($sourceId), $day, false);
    h_check(
        $resolution['available'] === false && $resolution['reason'] === BackupResolver::REASON_NO_MAPPING,
        'el resolver responde no_mapping',
        'razón inesperada: ' . ($resolution['reason'] ?? 'null')
    );

    // ------------------------------------------------------------------
    h_section('cobertura');
    $coverage = $service->coverage($backupId);
    h_check($coverage['folders_total'] === 1, 'la cobertura cuenta la carpeta del árbol fixture');
    h_check($coverage['folders_mapped'] === 0, 'la carpeta sin mapear cuenta como huérfana');
    h_check(in_array($folder, $coverage['folders_orphan'], true), 'la carpeta huérfana aparece en la lista');
    h_check($coverage['sources_mapped'] === 0, 'no hay orígenes mapeados a ESTE árbol tras la prueba');
    h_check($coverage['sources_mapped_other'] >= 21, 'los vínculos a otros árboles se cuentan aparte (' . $coverage['sources_mapped_other'] . ')');

    $service->assign($sourceId, $backupId, $folder);
    $coverage = $service->coverage($backupId);
    h_check($coverage['folders_mapped'] === 1, 'la cobertura refleja el vínculo declarado');
    h_check($coverage['sources_mapped'] === 1, 'la cobertura cuenta el origen mapeado a este árbol');
    h_check(! in_array($folder, $coverage['folders_orphan'], true), 'la carpeta mapeada deja de ser huérfana');

    // ------------------------------------------------------------------
    h_section('desasignar');
    $removed = $service->unassign($sourceId);
    h_check($removed, 'unassign() devuelve true');
    h_check(! $service->unassign($sourceId), 'unassign() sobre un storage sin vínculo devuelve false (idempotente)');

    // ------------------------------------------------------------------
    h_section('página de administración (HTTP real con sesión de admin)');
    $sessionId = bin2hex(random_bytes(20));
    $cookieName = (string) config('session.cookie', 'tcloud_session');
    $adminId = (int) DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

    DB::table('user_sessions')->insert([
        'user_id' => $adminId,
        'session_id' => $sessionId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'harness-backup-mapping',
        'created_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $store = Session::driver();
    $store->setId($sessionId);
    $store->start();
    $store->put('user_id', $adminId);
    $store->put('user_role', 'admin');
    $store->save();
    $csrfToken = (string) $store->get('_token');

    $cookieValue = app('encrypter')->encrypt(
        CookieValuePrefix::create($cookieName, app('encrypter')->getKey()) . $sessionId,
        false
    );

    /**
     * Despacha una petición HTTP con la sesión de prueba.
     */
    $dispatch = function (string $method, string $uri, array $payload = []) use ($cookieName, $cookieValue, $csrfToken) {
        $request = Request::create($uri, $method, $payload, [$cookieName => $cookieValue]);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        if ($csrfToken !== '') {
            $request->headers->set('X-CSRF-TOKEN', $csrfToken);
        }

        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    };

    $response = $dispatch('GET', '/admin/backups');
    h_check($response->getStatusCode() === 200, 'GET /admin/backups responde 200 con sesión de admin', 'respondió ' . $response->getStatusCode());

    $html = $response->getContent();
    foreach (['Backup de produccion', 'Asignar carpeta', 'storage de origen', 'Carpeta de backup'] as $needle) {
        h_check(str_contains($html, $needle), "la página contiene «{$needle}»");
    }
    h_check(str_contains($html, '01_Caracol_BK'), 'la página lista las carpetas reales del árbol de producción');

    $response = $dispatch('GET', '/admin/backups/folders?root=34');
    $payload = json_decode($response->getContent(), true);
    h_check($response->getStatusCode() === 200 && ($payload['ok'] ?? false) === true, 'GET /admin/backups/folders responde 200 con las carpetas del árbol');
    h_check(count($payload['folders'] ?? []) > 0, 'la API devuelve al menos una carpeta del árbol real');
    h_check(($payload['root']['name'] ?? '') !== '', 'la API devuelve el árbol raíz');

    // Cliente: el módulo es de administración.
    $store->put('user_role', 'cliente');
    $store->save();
    $response = $dispatch('GET', '/admin/backups');
    h_check(
        $response->getStatusCode() === 403 || $response->getStatusCode() === 302,
        'un cliente NO puede entrar a /admin/backups',
        'respondió ' . $response->getStatusCode()
    );
    $store->put('user_role', 'admin');
    $store->save();

    // POST inválido: no debe escribir.
    $before = DB::table('storage_providers')->where('id', $sourceId)->value('backup_folder_name');
    $response = $dispatch('POST', '/admin/backups', [
        'source_storage_id' => $sourceId,
        'backup_storage_id' => $backupId,
        'backup_folder_name' => '99_No_Existe',
    ]);
    $after = DB::table('storage_providers')->where('id', $sourceId)->value('backup_folder_name');
    h_check($response->getStatusCode() === 422, 'POST con carpeta inexistente responde 422', 'respondió ' . $response->getStatusCode());
    h_check($before === $after, 'el POST inválido NO escribe nada');

    // POST válido: escribe.
    $response = $dispatch('POST', '/admin/backups', [
        'source_storage_id' => $sourceId,
        'backup_storage_id' => $backupId,
        'backup_folder_name' => $folder,
    ]);
    $stored = DB::table('storage_providers')->where('id', $sourceId)->value('backup_folder_name');
    h_check($response->getStatusCode() === 200, 'POST con carpeta válida responde 200', 'respondió ' . $response->getStatusCode());
    h_check($stored === $folder, 'el POST válido escribe el vínculo');

    // DELETE: quita el vínculo.
    $response = $dispatch('DELETE', '/admin/backups/' . $sourceId);
    $stored = DB::table('storage_providers')->where('id', $sourceId)->value('backup_folder_name');
    h_check($response->getStatusCode() === 200, 'DELETE responde 200', 'respondió ' . $response->getStatusCode());
    h_check($stored === null, 'el DELETE quita el vínculo');
} catch (\Throwable $e) {
    h_fail('excepción: ' . get_class($e) . ' — ' . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
} finally {
    // ------------------------------------------------------------------
    h_section('cleanup');
    if (isset($sessionId) && $sessionId !== '') {
        DB::table('user_sessions')->where('session_id', $sessionId)->delete();
        try {
            Session::driver()->getHandler()->destroy($sessionId);
        } catch (\Throwable $e) {
            // la sesión de prueba puede no haberse creado
        }
    }
    DB::table('storage_providers')->whereIn('id', array_filter([$sourceId, $backupId, $otherSourceId, $nonLocalId]))->delete();
    DB::table('storage_providers')->where('name', 'like', $tag . '%')->delete();
    @exec('rm -rf ' . escapeshellarg($tmpRoot));
    h_ok('fixtures eliminados');
}

echo "\n" . ($failures === 0 ? "RESULTADO: OK\n" : "RESULTADO: {$failures} fallo(s)\n");
exit($failures === 0 ? 0 : 1);
