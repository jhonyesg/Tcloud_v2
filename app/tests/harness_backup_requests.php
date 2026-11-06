<?php
/**
 * Harness de regresión del change `solicitud-backup-mis-archivos`.
 *
 * Valida contra PostgreSQL y el NFS REALES el contrato de la solicitud de
 * backup: resolución de la carpeta-día en el árbol de producción, autorización
 * del solicitante, creación del enlace de solo lectura con vigencia de 2
 * días, idempotencia, revocación y supervivencia de la solicitud cuando el
 * material desaparece.
 *
 * Fixtures:
 *   - Un usuario `hmbk_<8hex>` con permiso de LECTURA sobre el storage de
 *     origen.
 *   - El mapeo del storage de origen se declara y se RESTAURA en `finally`
 *     (no se toca el mapeo que el operador haya configurado).
 *   - Todos los shares y solicitudes que crea el harness se borran.
 *
 * El material de producción es real: el harness lee el árbol de backup del
 * NFS, no lo escribe. Lo único que escribe es en la base de datos (shares,
 * backup_requests, users, user_storages), y todo se limpia.
 *
 * Uso:
 *   cd app && php tests/harness_backup_requests.php
 *   exit 0 = OK, exit 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\BackupRequestException;
use App\Models\BackupRequest;
use App\Models\Share;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\MisArchivos\BackupRequestService;
use App\Services\MisArchivos\BackupResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$tag = 'hmbk_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness backup-requests (tag: {$tag})\n";

/** Storage de origen usado por el harness: el canal de TV de Caracol. */
$SOURCE_ID = 6;
$BACKUP_ID = 34;
$BACKUP_FOLDER = '01_Caracol_BK';

$usuario = null;
$mapeoOriginal = null;
$shareIds = [];
$requestIds = [];

try {
    // ------------------------------------------------------------------
    h_section('cleanup defensivo pre-run');
    DB::table('backup_requests')->whereIn('user_id', function ($q) {
        $q->select('id')->from('users')->where('username', 'like', 'hmbk_%');
    })->delete();
    DB::table('user_storages')->whereIn('user_id', function ($q) {
        $q->select('id')->from('users')->where('username', 'like', 'hmbk_%');
    })->delete();
    User::where('username', 'like', 'hmbk_%')->delete();
    h_ok('residuos hmbk_* eliminados');

    $source = StorageProvider::findOrFail($SOURCE_ID);
    $backup = StorageProvider::findOrFail($BACKUP_ID);
    h_check(
        (int) $backup->kind === 0 || $backup->kind === 'local',
        "el storage de backup {$BACKUP_ID} es local",
        "el storage de backup {$BACKUP_ID} no es local (kind={$backup->kind})"
    );
    h_check(
        $backup->base_path !== null && is_dir($backup->base_path),
        "el árbol de backup está montado en {$backup->base_path}",
        "el árbol de backup NO está montado en {$backup->base_path}"
    );

    // El harness declara el mapeo y lo restaura al final.
    $mapeoOriginal = [
        'backup_storage_id' => $source->backup_storage_id,
        'backup_folder_name' => $source->backup_folder_name,
    ];
    $source->forceFill([
        'backup_storage_id' => $BACKUP_ID,
        'backup_folder_name' => $BACKUP_FOLDER,
    ])->save();
    $source->refresh();

    // ------------------------------------------------------------------
    h_section('fixture: usuario con lectura sobre el storage de origen');
    $usuario = User::create([
        'email' => $tag . '@harness.local',
        'username' => $tag,
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
    ]);
    DB::table('user_storages')->insert([
        'user_id' => $usuario->id,
        'storage_provider_id' => $SOURCE_ID,
        'permissions' => 'read',
        'can_create_shares' => false,
        'transcription_access' => false,
    ]);
    $usuario->refresh();
    h_check(
        $usuario->hasStoragePermission($SOURCE_ID, 'read'),
        'el usuario de prueba tiene read sobre el storage de origen',
        'el usuario de prueba NO tiene read sobre el storage de origen'
    );

    // ------------------------------------------------------------------
    h_section('el día disponible se lee DEL DISCO, no del catálogo');
    $resolver = app(BackupResolver::class);
    $service = app(BackupRequestService::class);

    $base = rtrim((string) $backup->base_path, '/') . '/' . $BACKUP_FOLDER;
    $diasEnDisco = array_values(array_filter(scandir($base) ?: [], fn ($d) => strlen($d) === 8 && ctype_digit($d)));
    sort($diasEnDisco);
    h_check(count($diasEnDisco) > 0, 'el árbol de backup tiene carpetas-día en disco', 'el árbol de backup no tiene carpetas-día');

    $diaDisponible = end($diasEnDisco) ?: null;
    h_check($diaDisponible !== null, "día disponible en disco: {$diaDisponible}", 'no hay día disponible');

    $res = $resolver->resolveDayFolder($source, (string) $diaDisponible);
    h_check($res['available'] === true, "el resolver marca {$diaDisponible} como disponible", 'el resolver no marcó el día como disponible');
    h_check(
        $res['backup_storage_id'] === $BACKUP_ID,
        'la resolución queda anclada en el storage de backup declarado',
        "la resolución se ancla en otro storage ({$res['backup_storage_id']})"
    );

    $fila = $res['file_id'] ? DB::table('files')->where('id', $res['file_id'])->first() : null;
    h_check(
        $fila !== null && (int) $fila->storage_provider_id === $BACKUP_ID,
        'la fila del día pertenece al storage de backup, no al catálogo paralelo',
        'la fila del día pertenece al catálogo paralelo (storage 5)'
    );
    h_check(
        $fila !== null && str_ends_with((string) $fila->path, '/' . $diaDisponible),
        "la fila del día termina en el segmento {$diaDisponible}",
        "la fila del día no termina en {$diaDisponible}"
    );

    // Un día fuera de la ventana de retención.
    $diaLejano = Carbon::now()->subYears(2)->format('dmY');
    $resLejano = $resolver->resolveDayFolder($source, $diaLejano);
    h_check(
        $resLejano['available'] === false && $resLejano['reason'] === BackupResolver::REASON_EXPIRED_WINDOW,
        "un día fuera de la ventana se reporta no disponible ({$resLejano['reason']})",
        "un día fuera de la ventana se reportó como disponible ({$resLejano['reason']})"
    );

    // Storage sin mapeo.
    $sinMapeo = StorageProvider::whereNull('backup_storage_id')->where('kind', 'local')->first();
    if ($sinMapeo) {
        $resSinMapeo = $resolver->resolveDayFolder($sinMapeo, (string) $diaDisponible);
        h_check(
            $resSinMapeo['available'] === false && $resSinMapeo['reason'] === BackupResolver::REASON_NO_MAPPING,
            'un storage sin mapeo no ofrece backup',
            'un storage sin mapeo ofreció backup'
        );
    }

    // ------------------------------------------------------------------
    h_section('el motivo es obligatorio');
    $carpetaOrigen = DB::table('files')
        ->where('storage_provider_id', $SOURCE_ID)
        ->where('is_folder', true)
        ->where('name', $diaDisponible)
        ->orderBy('id')
        ->first();

    if ($carpetaOrigen === null) {
        h_fail("no hay carpeta-día {$diaDisponible} en el storage de origen (id={$SOURCE_ID})");
    } else {
        $carpeta = App\Models\File::find($carpetaOrigen->id);

        foreach ([
            ['', 'motivo vacío'],
            ['corto', 'motivo de 5 caracteres'],
        ] as [$motivo, $caso]) {
            try {
                $service->create($usuario, $carpeta, $motivo);
                h_fail("{$caso}: se creó la solicitud (no debería)");
            } catch (BackupRequestException $e) {
                h_check(
                    $e->errorCode === 'motivo_invalido' && $e->status === 422,
                    "{$caso}: rechazado con 422 ({$e->errorCode})",
                    "{$caso}: código inesperado {$e->errorCode}/{$e->status}"
                );
            }
        }
        h_check(
            BackupRequest::where('user_id', $usuario->id)->count() === 0,
            'no se registró ninguna solicitud con motivo inválido',
            'se registró una solicitud con motivo inválido'
        );

        // ------------------------------------------------------------------
        h_section('autorización: sin lectura sobre el origen no se puede pedir');
        $otro = User::create([
            'email' => $tag . '_x@harness.local',
            'username' => $tag . '_x',
            'password_hash' => 'harness_dummy',
            'role' => 'user',
            'status' => 'active',
        ]);
        try {
            $service->create($otro->refresh(), $carpeta, 'Necesito el master para una edicion');
            h_fail('un usuario sin permiso creó una solicitud');
        } catch (BackupRequestException $e) {
            h_check(
                $e->errorCode === 'no_permission' && $e->status === 403,
                'un usuario sin permiso recibe 403',
                "un usuario sin permiso recibió {$e->status}/{$e->errorCode}"
            );
        } finally {
            User::where('id', $otro->id)->delete();
        }

        // ------------------------------------------------------------------
        h_section('la solicitud crea el enlace de solo lectura');
        $solicitud = $service->create($usuario, $carpeta, 'Necesito el master del dia para la edicion de hoy');
        $requestIds[] = $solicitud->id;

        h_check($solicitud->status === BackupRequest::STATUS_READY, 'la solicitud queda en estado ready', "estado inesperado: {$solicitud->status}");
        h_check($solicitud->share_id !== null, 'la solicitud tiene enlace', 'la solicitud no tiene enlace');

        $share = Share::find($solicitud->share_id);
        h_check($share !== null, 'el share existe', 'el share no existe');
        if ($share) {
            $shareIds[] = $share->id;
            h_check($share->permissions === 'read', 'el enlace es de solo lectura', "permisos del enlace: {$share->permissions}");
            h_check((int) $share->created_by === (int) $usuario->id, 'el enlace quedó a nombre del solicitante', 'el enlace no quedó a nombre del solicitante');
            h_check($share->password_hash === null, 'el enlace no tiene contraseña', 'el enlace tiene contraseña');

            $dias = Carbon::now()->diffInDays($share->expires_at, false);
            h_check(
                $dias >= 1 && $dias <= 2.5,
                "el enlace vence en {$dias} días (vigencia configurada)",
                "la vigencia del enlace no es la configurada: {$dias} días"
            );

            h_check(
                (int) $share->file_id === (int) $res['file_id'],
                'el enlace apunta a la fila del día en el árbol de backup',
                'el enlace no apunta a la fila del día en el árbol de backup'
            );
        }

        // ------------------------------------------------------------------
        h_section('idempotencia: un enlace vivo por día');
        $solicitud2 = $service->create($usuario, $carpeta, 'otra justificacion para el mismo dia');
        h_check(
            (int) $solicitud2->id === (int) $solicitud->id,
            're-solicitar el mismo día devuelve la solicitud viva',
            're-solicitar el mismo día creó una solicitud nueva'
        );
        h_check(
            Share::where('created_by', $usuario->id)->count() === 1,
            'no se multiplicaron los enlaces públicos',
            'se crearon varios enlaces públicos para el mismo día'
        );

        // ------------------------------------------------------------------
        h_section('el historial es del solicitante');
        $historial = $service->listFor($usuario);
        h_check(count($historial) === 1, 'el historial tiene una solicitud', 'el historial tiene ' . count($historial) . ' solicitudes');

        $payload = $historial[0];
        h_check(
            isset($payload['link']['public_url']) && str_contains((string) $payload['link']['public_url'], '/s/'),
            'la solicitud viva expone su enlace público',
            'la solicitud viva no expone enlace'
        );

        $otroHistorial = User::create([
            'email' => $tag . '_z@harness.local',
            'username' => $tag . '_z',
            'password_hash' => 'harness_dummy',
            'role' => 'user',
            'status' => 'active',
        ]);
        h_check(
            count($service->listFor($otroHistorial)) === 0,
            'el historial de otro usuario no incluye la solicitud',
            'el historial de otro usuario incluye la solicitud'
        );

        // ------------------------------------------------------------------
        h_section('el material puede desaparecer sin romper el historial');
        // Se borra el share como lo haría el ON DELETE CASCADE de `files`.
        Share::where('id', $solicitud->share_id)->delete();
        $solicitud->refresh();
        $payloadMuerto = $service->payload($solicitud->refresh());
        h_check(
            $payloadMuerto['link'] === null,
            'la solicitud sin enlace no muestra un enlace roto',
            'la solicitud muestra un enlace roto'
        );
        h_check(
            $payloadMuerto['status'] !== BackupRequest::STATUS_READY,
            'la solicitud sin material se reporta como no viva',
            'la solicitud sin material se sigue reportando como ready'
        );
        h_check(
            BackupRequest::find($solicitud->id) !== null,
            'la solicitud sobrevive a la desaparición del material',
            'la solicitud desapareció con el material'
        );

        // ------------------------------------------------------------------
        h_section('la revocación invalida el enlace');
        $solicitud3 = $service->create($usuario, $carpeta, 'solicitud para revocar y probar');
        $share3 = Share::find($solicitud3->share_id);
        if ($share3) {
            $shareIds[] = $share3->id;
        }
        $token = $share3->token ?? null;

        $service->revoke($usuario, $solicitud3);
        h_check($solicitud3->refresh()->status === BackupRequest::STATUS_REVOKED, 'la solicitud queda en revoked', 'la solicitud no quedó en revoked');
        h_check(Share::find($solicitud3->share_id) === null, 'el share de la solicitud revocada se borró', 'el share de la solicitud revocada sigue existiendo');
        if ($token !== null) {
            $r = app(App\Http\Controllers\PublicShareController::class);
            $res404 = $r->show($token);
            $status = method_exists($res404, 'getStatusCode') ? $res404->getStatusCode() : 500;
            h_check($status === 404, 'el token revocado ya no resuelve (404)', "el token revocado respondió {$status}");
        }

        // ------------------------------------------------------------------
        h_section('no se puede revocar la solicitud de otro');
        $solicitudAjena = $service->create($usuario, $carpeta, 'solicitud propia para probar el aislamiento');
        $otro2 = User::create([
            'email' => $tag . '_y@harness.local',
            'username' => $tag . '_y',
            'password_hash' => 'harness_dummy',
            'role' => 'user',
            'status' => 'active',
        ]);
        try {
            $service->revoke($otro2->refresh(), $solicitudAjena);
            h_fail('un usuario revocó la solicitud de otro');
        } catch (BackupRequestException $e) {
            h_check(
                $e->status === 403,
                'revozar la solicitud de otro responde 403',
                "revozar la solicitud de otro respondió {$e->status}"
            );
        } finally {
            User::where('id', $otro2->id)->delete();
        }
        h_check(
            $solicitudAjena->refresh()->status === BackupRequest::STATUS_READY,
            'la solicitud ajena quedó intacta',
            'la solicitud ajena cambió de estado'
        );

        User::where('id', $otroHistorial->id)->delete();
    }
} catch (Throwable $e) {
    h_fail('excepción no controlada: ' . get_class($e) . ' — ' . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
} finally {
    // ----------------------------------------------------------------------
    h_section('cleanup');
    foreach ($shareIds as $id) {
        Share::where('id', $id)->delete();
    }
    if ($usuario) {
        BackupRequest::where('user_id', $usuario->id)->delete();
        DB::table('user_storages')->where('user_id', $usuario->id)->delete();
        User::where('id', $usuario->id)->delete();
    }
    User::where('username', 'like', $tag . '%')->delete();
    if ($mapeoOriginal !== null) {
        StorageProvider::where('id', $SOURCE_ID)->update($mapeoOriginal);
    }
    h_ok('shares, solicitudes, usuarios y pivotes eliminados');
    h_ok('mapeo del storage de origen restaurado');
}

echo "\n=== Resultado ===\n";
if ($failures === 0) {
    echo "OK: todas las aserciones pasan\n";
    exit(0);
}

echo "FALLOS: {$failures}\n";
exit(1);
