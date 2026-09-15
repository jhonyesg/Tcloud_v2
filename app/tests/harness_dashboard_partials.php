<?php
/**
 * Harness de validación — change `dashboard-modular-partials`.
 *
 * Cubre el contrato de los partials auto-gated:
 *  1. Cada partial renderiza HTML cuando el flag es true
 *  2. Cada partial retorna vacío cuando el flag es false (gating)
 *  3. _mis-avisos en contexto cliente NO contiene datos de auditoría/hits
 *  4. _media-editor maneja correctamente los dos contextos
 *  5. _bg-jobs-active / _active-sessions ocultan cuando no hay datos
 *  6. Cada partial se puede renderizar SIN un User::find (testeable aislado)
 *
 * Uso: php tests/harness_dashboard_partials.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 *
 * Estrategia: usa un User real en BD con prefijo hdmp_<tag> y cleanup
 * defensivo al inicio + finally. Read-only contra las tablas de producción
 * excepto por las filas hdmp_* que crea/borrra él mismo.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use App\Models\User;
use App\Models\UserAlertsInteligente;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void {
    if ($cond) h_ok($ok); else h_fail($fail);
}

echo "Harness dashboard-modular-partials\n";

$tag = 'hdmp_' . substr(bin2hex(random_bytes(4)), 0, 8);

// ─── Cleanup defensivo de corridas previas ────────────────────────────────
DB::table('users')->where('email', 'LIKE', $tag . '%')->delete();
DB::table('user_alerts_inteligentes')->where('user_id', 0)->delete();

try {
    function render(string $view, array $data): string {
        $renderer = View::make($view, $data);
        return (string) $renderer->render();
    }

    // ─── Crear usuarios de prueba ─────────────────────────────────────────
    $admin = User::create([
        'email' => $tag . '_admin@test.local',
        'username' => $tag . '_admin',
        'password_hash' => 'harness_dummy',
        'role' => 'admin',
        'status' => 'active',
        'media_editor_enabled' => true,
        'media_editor_clip_limit' => 0,
    ]);

    $clientEnabled = User::create([
        'email' => $tag . '_enabled@test.local',
        'username' => $tag . '_enabled',
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
        'media_editor_enabled' => true,
        'media_editor_clip_limit' => 5,
    ]);

    $clientDisabled = User::create([
        'email' => $tag . '_disabled@test.local',
        'username' => $tag . '_disabled',
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
        'media_editor_enabled' => false,
        'media_editor_clip_limit' => 0,
    ]);

    $clientMisEnabled = User::create([
        'email' => $tag . '_mis@test.local',
        'username' => $tag . '_mis',
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
    ]);
    UserAlertsInteligente::create([
        'user_id' => $clientMisEnabled->id,
        'enabled' => true,
        'keywords_quota' => 10,
        'emails_quota' => 3,
        'emails' => ['x@y.com', 'z@y.com'],
    ]);

    $clientMisDisabled = User::create([
        'email' => $tag . '_misoff@test.local',
        'username' => $tag . '_misoff',
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
    ]);
    UserAlertsInteligente::create([
        'user_id' => $clientMisDisabled->id,
        'enabled' => false,
        'keywords_quota' => 0,
        'emails_quota' => 0,
        'emails' => [],
    ]);

    // ─── 1. _media-editor (admin) renderiza cuando users_with_editor > 0 ──
    h_section('1. _media-editor admin');

    $adminData = [
        'clips_this_month' => 12,
        'users_with_editor' => 2,
        'near_limit' => 1,
        'top_consumers' => [
            ['id' => $admin->id, 'username' => $admin->username, 'email' => $admin->email, 'clips_this_month' => 9],
        ],
    ];
    $htmlOn = render('dashboard.partials._media-editor', ['context' => 'admin', 'data' => $adminData]);
    h_check(str_contains($htmlOn, 'Editor de Medios'), 'renderiza cuando users_with_editor > 0',
        'no renderiza con datos válidos');
    h_check(str_contains($htmlOn, '12'), 'muestra clips_this_month', 'no muestra el contador');
    h_check(str_contains($htmlOn, $admin->username), 'muestra top consumer', 'no muestra top consumer');

    $htmlOff = render('dashboard.partials._media-editor', ['context' => 'admin', 'data' => [
        'clips_this_month' => 0, 'users_with_editor' => 0, 'near_limit' => 0, 'top_consumers' => [],
    ]]);
    h_check(trim($htmlOff) === '', 'no renderiza cuando users_with_editor = 0',
        'renderizó HTML cuando debería estar vacío');

    // ─── 2. _media-editor (client) gating por canUseMediaEditor ───────────
    h_section('2. _media-editor client');

    $htmlEnabled = render('dashboard.partials._media-editor', ['context' => 'client', 'user' => $clientEnabled]);
    h_check(str_contains($htmlEnabled, 'Editor de Medios'), 'renderiza cuando habilitado',
        'no renderiza con editor habilitado');
    h_check(str_contains($htmlEnabled, 'data-dashboard-partial="media-editor"'),
        'selector de tour presente', 'falta data-dashboard-partial');

    $htmlDisabled = render('dashboard.partials._media-editor', ['context' => 'client', 'user' => $clientDisabled]);
    h_check(trim($htmlDisabled) === '', 'no renderiza cuando media_editor_enabled = false',
        'renderizó HTML cuando debería estar vacío');

    // ─── 3. _mis-avisos (admin) con KPIs ───────────────────────────────────
    h_section('3. _mis-avisos admin');

    $misAdminData = ['pairs_total' => 42, 'pairs_pending' => 3, 'pairs_with_hits' => 17, 'drift_negative' => 0];
    $htmlMisAdmin = render('dashboard.partials._mis-avisos', ['context' => 'admin', 'data' => $misAdminData]);
    h_check(str_contains($htmlMisAdmin, '42'), 'muestra pairs_total', 'no muestra pairs_total');
    h_check(str_contains($htmlMisAdmin, 'text-green-600') && str_contains($htmlMisAdmin, '0'),
        'muestra drift_negative en verde cuando es 0',
        'drift_negative no se renderiza en verde');

    // ─── 4. _mis-avisos (client) SOLO estado, sin auditoría ni hits ──────
    h_section('4. _mis-avisos client (privacidad)');

    $clientMisEnabled->refresh();
    $clientMisEnabled->load('alertsInteligente');
    $htmlMisClient = render('dashboard.partials._mis-avisos', ['context' => 'client', 'user' => $clientMisEnabled]);
    h_check(str_contains($htmlMisClient, 'Mis Avisos'), 'renderiza la card', 'no renderiza la card');
    h_check(str_contains($htmlMisClient, '0/10'), 'muestra keywords_used/quota', 'no muestra cuota keywords');

    $forbiddenStrings = ['hits_', 'audit_', 'drift_missing', 'drift_orphan', 'audit_recent'];
    $violations = array_filter($forbiddenStrings, fn ($s) => str_contains($htmlMisClient, $s));
    h_check(empty($violations), 'cliente NO contiene strings de auditoría ni hits',
        'cliente contiene: ' . implode(',', $violations));

    // ─── 5. _mis-avisos (client) gating cuando módulo deshabilitado ───────
    $htmlMisDisabled = render('dashboard.partials._mis-avisos', ['context' => 'client', 'user' => $clientMisDisabled]);
    h_check(trim($htmlMisDisabled) === '', 'no renderiza cuando enabled = false',
        'renderizó HTML cuando debería estar vacío');

    // ─── 6. _bg-jobs-active (admin) oculto cuando vacío ──────────────────
    h_section('6. _bg-jobs-active admin');

    $htmlJobsEmpty = render('dashboard.partials._bg-jobs-active', ['context' => 'admin', 'data' => []]);
    h_check(trim($htmlJobsEmpty) === '', 'no renderiza cuando no hay jobs',
        'renderizó HTML con data vacía');

    $htmlJobsSome = render('dashboard.partials._bg-jobs-active', ['context' => 'admin', 'data' => [
        ['kind' => 'avisos-scan', 'runId' => 'abc', 'module' => 'Avisos', 'label' => 'Scan X',
         'startedAt' => '2026-09-10T12:00:00Z', 'progress' => [], 'url' => '/ia/avisos-inteligentes'],
    ]]);
    h_check(str_contains($htmlJobsSome, 'Avisos'), 'renderiza job de avisos', 'no muestra el job');
    h_check(str_contains($htmlJobsSome, 'data-dashboard-partial="bg-jobs-active"'),
        'selector de tour presente', 'falta data-dashboard-partial');

    // ─── 7. _active-sessions (admin) oculto cuando total = 0 ─────────────
    h_section('7. _active-sessions admin');

    $htmlSessZero = render('dashboard.partials._active-sessions', ['context' => 'admin', 'data' => [
        'total' => 0, 'top_users' => [],
    ]]);
    h_check(trim($htmlSessZero) === '', 'no renderiza cuando total = 0',
        'renderizó HTML con total=0');

    $htmlSessSome = render('dashboard.partials._active-sessions', ['context' => 'admin', 'data' => [
        'total' => 7, 'top_users' => [
            ['id' => 1, 'username' => $admin->username, 'email' => $admin->email, 'count' => 3],
        ],
    ]]);
    h_check(str_contains($htmlSessSome, '7'), 'muestra el total', 'no muestra el total');
    h_check(str_contains($htmlSessSome, $admin->username), 'muestra top user', 'no muestra top user');

    // ─── 8. Separación de contexto ────────────────────────────────────────
    h_section('8. Separación de contexto');

    $htmlAdminCtxAsClient = render('dashboard.partials._media-editor', ['context' => 'admin', 'data' => $adminData]);
    h_check(str_contains($htmlAdminCtxAsClient, 'Top consumidores'),
        'admin: muestra top consumidores',
        'admin: falta sección top consumidores');

    $htmlClientCtxAsAdmin = render('dashboard.partials._bg-jobs-active', ['context' => 'client', 'data' => [
        ['kind' => 'x', 'runId' => 'y', 'module' => 'm', 'label' => 'l', 'startedAt' => 'now', 'progress' => [], 'url' => '/'],
    ]]);
    h_check(trim($htmlClientCtxAsAdmin) === '', 'bg-jobs NO renderiza en contexto cliente',
        'bg-jobs renderizó fuera de admin');

} finally {
    // ─── Cleanup por tag ──────────────────────────────────────────────────
    DB::table('user_alerts_inteligentes')->whereIn('user_id',
        DB::table('users')->where('email', 'LIKE', $tag . '%')->pluck('id')
    )->delete();
    DB::table('users')->where('email', 'LIKE', $tag . '%')->delete();
}

// ─── Resumen ──────────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "✓ Todos los parciales cumplen el contrato.\n";
    echo "  Tag: $tag (cleanup OK)\n";
    exit(0);
} else {
    echo "✗ $failures aserción(es) fallaron.\n";
    exit(1);
}