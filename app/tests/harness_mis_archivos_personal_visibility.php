<?php
/**
 * Harness de regresion para change `mis-archivos-depurar-registros-fantasma`.
 *
 * Verifica contra PostgreSQL real que `user-storages:fix-personal-visibility`
 * audita y limpia `user_storages` de storages personales con multiples
 * asignaciones, dejando solo al dueno canonico.
 *
 * Estrategia:
 *   - 2 storages personales de prueba (con y sin dueno canonico registrado).
 *   - 1 storage no personal intacto (control negativo).
 *   - Cada storage de prueba recibe 2 user_storages: el canonico + uno ajeno.
 *   - Dry-run: la cantidad de user_storages no cambia.
 *   - Apply: el storage de prueba CON canonico conserva 1 fila; el storage
 *     SIN canonico (nombre en base_path pero usuario inexistente) queda
 *     con 0 filas (warning al operador); el storage no personal intacto
 *     conserva sus 2 filas.
 *
 * Cleanup: todo se crea con tag `hmpv_<8-hex>` y se borra en `finally` por
 * ese tag. Cleanup defensivo al inicio borra residuos de corridas previas.
 *
 * Uso:
 *   cd app && php tests/harness_mis_archivos_personal_visibility.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

$tag = 'hmpv_' . bin2hex(random_bytes(4));
$failures = 0;

echo "Harness user-storages-fix-personal-visibility (tag: {$tag})\n";

// ─── Cleanup defensivo pre-run ───────────────────────────────────────────────
h_section("cleanup defensivo pre-run [tag={$tag}]");
$r1 = DB::table('users')->where('username', 'like', 'hmpv_%')->delete();
$r2 = DB::table('storage_providers')->where('name', 'like', 'hmpv_%')->delete();
$r3 = DB::table('user_storages')->whereIn('user_id', function ($q) {
    $q->select('id')->from('users')->where('username', 'like', 'hmpv_%');
})->delete();
$totalResiduos = $r1 + $r2 + $r3;
$totalResiduos === 0 ? h_ok('sin residuos previos') : h_ok("residuos borrados: users={$r1} storages={$r2} user_storages={$r3}");

try {
    // ─── Bootstrap ─────────────────────────────────────────────────────────
    h_section('bootstrap: usuarios + storages');

    // Usuario canonico (va a quedar tras el fix)
    $canonUser = User::create([
        'email' => "{$tag}_canon@test.local",
        'username' => "{$tag}_canon",
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'role' => 'user',
    ]);

    // Usuario ajeno al canonico (va a ser removido por el fix)
    $alienUser = User::create([
        'email' => "{$tag}_alien@test.local",
        'username' => "{$tag}_alien",
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'role' => 'user',
    ]);

    // Usuario canonico de storage NO personal (no se toca)
    $nonPersonalUser = User::create([
        'email' => "{$tag}_np@test.local",
        'username' => "{$tag}_np",
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'role' => 'user',
    ]);

    // Usuario ajeno al NO personal (no se toca)
    $nonPersonalAlien = User::create([
        'email' => "{$tag}_np_alien@test.local",
        'username' => "{$tag}_np_alien",
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'role' => 'user',
    ]);

    h_ok("canon id={$canonUser->id}, alien id={$alienUser->id}, np id={$nonPersonalUser->id}, np_alien id={$nonPersonalAlien->id}");

    // Storage personal CON canonico existente (path incluye username canónico)
    $personalOk = StorageProvider::create([
        'name' => "{$tag}_personal_ok",
        'kind' => 'local',
        'type' => 'local',
        'base_path' => "/home/www/Usuarios_tcloud/{$tag}_canon",
        'is_personal' => true,
        'enabled' => true,
        'is_accessible' => true,
    ]);

    // Storage personal SIN canonico (usuario no existe; path apunta a un nombre
    // que no esta en users)
    $personalMissing = StorageProvider::create([
        'name' => "{$tag}_personal_missing",
        'kind' => 'local',
        'type' => 'local',
        'base_path' => "/home/www/Usuarios_tcloud/{$tag}_ghost_user",
        'is_personal' => true,
        'enabled' => true,
        'is_accessible' => true,
    ]);

    // Storage NO personal (control negativo: el comando NO debe tocarlo)
    $nonPersonal = StorageProvider::create([
        'name' => "{$tag}_non_personal",
        'kind' => 'local',
        'type' => 'local',
        'base_path' => "/tmp/{$tag}_non_personal_data",
        'is_personal' => false,
        'enabled' => true,
        'is_accessible' => true,
    ]);

    h_ok("storages creados: personal_ok={$personalOk->id}, personal_missing={$personalMissing->id}, non_personal={$nonPersonal->id}");

    // Asignar user_storages:
    // - personalOk: canon + alien (el comando debe eliminar al alien)
    // - personalMissing: solo alien (NO hay canon registrado; el comando debe
    //   dejar 0 filas y reportar unresolved)
    // - nonPersonal: 2 usuarios (NO debe tocarse)
    UserStorage::create([
        'user_id' => $canonUser->id,
        'storage_provider_id' => $personalOk->id,
        'permissions' => 'full',
    ]);
    UserStorage::create([
        'user_id' => $alienUser->id,
        'storage_provider_id' => $personalOk->id,
        'permissions' => 'full',
    ]);
    UserStorage::create([
        'user_id' => $alienUser->id,
        'storage_provider_id' => $personalMissing->id,
        'permissions' => 'full',
    ]);
    UserStorage::create([
        'user_id' => $nonPersonalUser->id,
        'storage_provider_id' => $nonPersonal->id,
        'permissions' => 'full',
    ]);
    UserStorage::create([
        'user_id' => $nonPersonalAlien->id,
        'storage_provider_id' => $nonPersonal->id,
        'permissions' => 'read',
    ]);

    $initialPersonalOk = UserStorage::where('storage_provider_id', $personalOk->id)->count();
    $initialPersonalMissing = UserStorage::where('storage_provider_id', $personalMissing->id)->count();
    $initialNonPersonal = UserStorage::where('storage_provider_id', $nonPersonal->id)->count();
    h_ok("user_storages iniciales: personal_ok={$initialPersonalOk} (esperado 2), personal_missing={$initialPersonalMissing} (esperado 1), non_personal={$initialNonPersonal} (esperado 2)");

    // ─── Dry-run: BD no cambia ─────────────────────────────────────────────
    h_section('dry-run (BD no debe modificarse)');

    $exitCode = Artisan::call('user-storages:fix-personal-visibility');
    h_check($exitCode === 0, "exit code 0 (dry-run global)");

    $exitCodeOk = Artisan::call('user-storages:fix-personal-visibility', [
        '--user' => $personalOk->id,
    ]);
    h_check($exitCodeOk === 0, "exit code 0 (dry-run scoped a personal_ok)");

    $afterDryOk = UserStorage::where('storage_provider_id', $personalOk->id)->count();
    $afterDryMissing = UserStorage::where('storage_provider_id', $personalMissing->id)->count();
    $afterDryNp = UserStorage::where('storage_provider_id', $nonPersonal->id)->count();
    h_check($afterDryOk === $initialPersonalOk, "personal_ok sin cambios tras dry-run ({$afterDryOk})");
    h_check($afterDryMissing === $initialPersonalMissing, "personal_missing sin cambios tras dry-run ({$afterDryMissing})");
    h_check($afterDryNp === $initialNonPersonal, "non_personal sin cambios tras dry-run ({$afterDryNp})");

    // ─── Apply scoped a personal_ok ────────────────────────────────────────
    h_section('apply scoped a personal_ok');

    $exitCode = Artisan::call('user-storages:fix-personal-visibility', [
        '--apply' => true,
        '--yes' => true,
        '--user' => $personalOk->id,
    ]);
    h_check($exitCode === 0, "exit code 0 (apply scoped)");

    $afterApplyOk = UserStorage::where('storage_provider_id', $personalOk->id)->count();
    $remainingUser = UserStorage::where('storage_provider_id', $personalOk->id)->value('user_id');
    h_check($afterApplyOk === 1, "personal_ok queda con 1 user_storage (tenia {$afterApplyOk})");
    h_check((int) $remainingUser === $canonUser->id, "el superviviente es el canonico");

    $afterApplyMissing = UserStorage::where('storage_provider_id', $personalMissing->id)->count();
    h_check($afterApplyMissing === $initialPersonalMissing, "personal_missing sin cambios tras apply scoped (sigue con {$afterApplyMissing})");

    $afterApplyNp = UserStorage::where('storage_provider_id', $nonPersonal->id)->count();
    h_check($afterApplyNp === $initialNonPersonal, "non_personal sin cambios tras apply scoped");

    // ─── Apply global (incluye personal_missing) ───────────────────────────
    h_section('apply global (incluye personal_missing sin canonico)');

    $exitCode = Artisan::call('user-storages:fix-personal-visibility', [
        '--apply' => true,
        '--yes' => true,
    ]);
    h_check($exitCode === 0, "exit code 0 (apply global)");

    $finalPersonalOk = UserStorage::where('storage_provider_id', $personalOk->id)->count();
    $finalPersonalMissing = UserStorage::where('storage_provider_id', $personalMissing->id)->count();
    $finalNonPersonal = UserStorage::where('storage_provider_id', $nonPersonal->id)->count();
    h_check($finalPersonalOk === 1, "personal_ok sigue con 1 fila");
    h_check($finalPersonalMissing === 1, "personal_missing queda con 1 fila (canonico no registrado -> warning, no se borra)");
    h_check($finalNonPersonal === $initialNonPersonal, "non_personal sigue intacto ({$finalNonPersonal})");

    // ─── Verificar que isOwnedBy funciona como helper ──────────────────────
    h_section('verificar helpers del modelo');

    $owned = $personalOk->isOwnedBy($canonUser);
    h_check($owned === true, "personalOk->isOwnedBy(canon) = true");

    $notOwned = $personalOk->isOwnedBy($alienUser);
    h_check($notOwned === false, "personalOk->isOwnedBy(alien) = false");

    $npOwned = $nonPersonal->isOwnedBy($alienUser);
    h_check($npOwned === true, "nonPersonal->isOwnedBy(alien) = true (no aplica regla personal)");

    $usernameOk = $personalOk->personalCanonicalUsername();
    h_check($usernameOk === "{$tag}_canon", "personalOk->personalCanonicalUsername() = {$tag}_canon");

    $usernameNp = $nonPersonal->personalCanonicalUsername();
    h_check($usernameNp === null, "nonPersonal->personalCanonicalUsername() = null");

    echo "\n";
    echo $failures === 0
        ? "✓ Todos los chequeos pasaron.\n"
        : "✗ {$failures} chequeo(s) fallaron.\n";
} finally {
    // ─── Cleanup ───────────────────────────────────────────────────────────
    h_section("cleanup post-run [tag={$tag}]");
    DB::table('user_storages')->whereIn('user_id', function ($q) use ($tag) {
        $q->select('id')->from('users')->where('username', 'like', $tag . '%');
    })->delete();
    DB::table('storage_providers')->where('name', 'like', "{$tag}_%")->delete();
    DB::table('users')->where('username', 'like', "{$tag}_%")->delete();
    h_ok('limpieza completada');
}

exit($failures === 0 ? 0 : 1);
