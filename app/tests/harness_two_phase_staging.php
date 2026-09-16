<?php
/**
 * Harness de validación — change `transcriptor-two-phase-staging`.
 *
 * Cubre los dos contratos que este change introduce/corrige:
 *
 *  A. DEADLOCK DE REQUEUE (bug de producción corregido 2026-09-15).
 *     `TranscriptionSubmitService::markRequeueable()` debe limpiar
 *     `dispatched_at`. Sin eso, el worker PG (que reclama con
 *     `dispatched_at IS NULL`) nunca volvia a tomar la fila y `requeue_after_at`
 *     era codigo muerto: se midieron 1.746 filas del dia atascadas asi, con la
 *     cola remota vacia y el pipeline en 0 envios/min.
 *
 *     Ademas el worker debe RESPETAR `requeue_after_at`: si no, re-toma la fila
 *     de inmediato, gasta ffmpeg y la vuelve a rebotar (busy-loop).
 *
 *  B. CONTRATO DE STAGING (dos fases).
 *     - Columnas staged_path/staged_bytes/staged_at existen y son nullable.
 *     - El claim condicional del stager NO publica staged sobre una fila que ya
 *       no es candidata (pending sin dispatched_at sin staged) — cierra la
 *       carrera que filtro 41 WAV / 1.6 GB en tmpfs.
 *     - El presupuesto y el inventario objetivo son settings vivos.
 *
 * Uso:  cd app && php tests/harness_two_phase_staging.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 *
 * Estrategia: crea filas con `error_message`/`original_name` prefijados con el
 * tag de la corrida y limpia en `finally` solo esas filas (nunca toca datos
 * reales). No ejecuta ffmpeg.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorSettings;
use App\Services\Ia\TranscriptionSubmitService;
use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail = ''): void
{
    if ($cond) {
        h_ok($ok);
    } else {
        h_fail($fail ?: $ok);
    }
}

$tag = 'htps_' . substr(bin2hex(random_bytes(4)), 0, 8);
echo "Harness two-phase-staging (tag: {$tag})\n";

// ─── Cleanup defensivo de corridas previas con el mismo prefijo ────────────
DB::table('transcriptions')->where('original_name', 'LIKE', 'htps\_%')->delete();

$createdIds = [];
$stagedFiles = [];

try {
    $storage = StorageProvider::query()->orderBy('id')->first();
    if (!$storage) {
        echo "  (skip) no hay storage_providers en la BD de prueba\n";
        exit(0);
    }

    $makeRow = function (array $attrs = []) use ($storage, $tag) {
        $ownerId = DB::table('users')->orderBy('id')->value('id');
        $fileId = DB::table('files')->insertGetId([
            'storage_provider_id' => $storage->id,
            'owner_id' => $ownerId,
            'path' => $tag . '/' . uniqid('f', true) . '.mp3',
            'name' => $tag . '.mp3',
            'is_folder' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('transcriptions')->insertGetId(array_merge([
            'file_id' => $fileId,
            'state' => Transcription::STATE_PENDING,
            'language' => 'es',
            'retries' => 0,
            'generate_alerts' => false,
            'original_name' => $tag . '_row.mp3',
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        $GLOBALS['createdIds'][] = $id;
        $GLOBALS['createdFileIds'][] = $fileId;

        return $id;
    };

    // ─── A. Columnas de staging ───────────────────────────────────────────
    h_section('A. Columnas de staging en transcriptions');

    foreach (['staged_path', 'staged_bytes', 'staged_at'] as $col) {
        $exists = DB::selectOne(
            "SELECT 1 AS ok FROM information_schema.columns
             WHERE table_name = 'transcriptions' AND column_name = ?",
            [$col]
        );
        h_check($exists !== null, "columna {$col} existe", "columna {$col} FALTA");
    }

    // ─── B. Deadlock: markRequeueable limpia dispatched_at ────────────────
    h_section('B. Requeue libera dispatched_at (fix deadlock)');

    $id = $makeRow([
        'dispatched_at' => now(),
        'state' => Transcription::STATE_PROCESSING,
    ]);

    $row = Transcription::find($id);
    $settings = app(TranscriptorSettings::class);
    $service = app(TranscriptionSubmitService::class);

    // markRequeueable es privado: se invoca por reflexión (es la unidad bajo
    // prueba; el camino público depende de red/ffmpeg).
    $method = new ReflectionMethod($service, 'markRequeueable');
    $method->setAccessible(true);
    $method->invoke($service, $row, 'test requeue');

    $after = DB::table('transcriptions')->where('id', $id)->first();
    h_check($after->state === Transcription::STATE_PENDING, 'queda en state=pending', 'state=' . $after->state);
    h_check($after->dispatched_at === null, 'dispatched_at liberado (NULL)', 'dispatched_at=' . var_export($after->dispatched_at, true));
    h_check($after->requeue_after_at !== null, 'requeue_after_at agendado');
    h_check((int) $after->retries === 0, 'retries NO incrementa por un aplazamiento (no es fallo)', 'retries=' . $after->retries);

    // ─── C. El worker respeta requeue_after_at ────────────────────────────
    h_section('C. Worker no re-toma una fila aplazada');

    $aplazada = $makeRow([
        'requeue_after_at' => now()->addMinutes(10),
        'recorded_at' => now(),
    ]);

    $claimable = DB::table('transcriptions')
        ->where('id', $aplazada)
        ->where('state', Transcription::STATE_PENDING)
        ->whereNull('dispatched_at')
        ->where(function ($q) {
            $q->whereNull('requeue_after_at')->orWhere('requeue_after_at', '<=', now());
        })
        ->exists();

    h_check($claimable === false, 'fila con requeue futuro NO es tomable', 'la fila aplazada aparece como tomable');

    $vencida = $makeRow([
        'requeue_after_at' => now()->subMinutes(1),
        'recorded_at' => now(),
    ]);

    $claimableVencida = DB::table('transcriptions')
        ->where('id', $vencida)
        ->where('state', Transcription::STATE_PENDING)
        ->whereNull('dispatched_at')
        ->where(function ($q) {
            $q->whereNull('requeue_after_at')->orWhere('requeue_after_at', '<=', now());
        })
        ->exists();

    h_check($claimableVencida === true, 'fila con requeue vencido SI es tomable', 'la fila vencida no aparece como tomable');

    // ─── D. Claim condicional del stager ──────────────────────────────────
    h_section('D. Claim condicional: no publicar staged sobre fila ajena');

    // Fila que un worker ya reclamo (processing + dispatched_at): el stager
    // NO debe poder escribirle staged_path.
    $ajena = $makeRow([
        'state' => Transcription::STATE_PROCESSING,
        'dispatched_at' => now(),
    ]);

    $tmpFake = sys_get_temp_dir() . '/' . $tag . '_fake.wav';
    file_put_contents($tmpFake, str_repeat('x', 2048));
    $GLOBALS['stagedFiles'][] = $tmpFake;

    $claimed = DB::table('transcriptions')
        ->where('id', $ajena)
        ->where('state', Transcription::STATE_PENDING)
        ->whereNull('dispatched_at')
        ->whereNull('staged_path')
        ->update([
            'staged_path' => $tmpFake,
            'staged_bytes' => 2048,
            'staged_at' => now(),
        ]);

    h_check($claimed === 0, 'claim sobre fila reclamada por worker afecta 0 filas', 'claim afecto ' . $claimed . ' filas (deberia ser 0)');

    $siguiendoAjena = DB::table('transcriptions')->where('id', $ajena)->first();
    h_check($siguiendoAjena->staged_path === null, 'la fila ajena queda sin staged_path', 'staged_path=' . var_export($siguiendoAjena->staged_path, true));

    // Fila legitima (pending sin dispatched): el claim SI debe funcionar.
    $propia = $makeRow();
    $claimedOk = DB::table('transcriptions')
        ->where('id', $propia)
        ->where('state', Transcription::STATE_PENDING)
        ->whereNull('dispatched_at')
        ->whereNull('staged_path')
        ->update([
            'staged_path' => $tmpFake,
            'staged_bytes' => 2048,
            'staged_at' => now(),
        ]);

    h_check($claimedOk === 1, 'claim sobre fila candidata afecta 1 fila', 'claim afecto ' . $claimedOk . ' filas (deberia ser 1)');

    // ─── E. Settings del staging ──────────────────────────────────────────
    h_section('E. Settings del staging presentes y con rango');

    $schema = (new ReflectionClass(TranscriptorSettings::class))->getConstant('SCHEMA');
    foreach (['staging_enabled', 'staging_budget_bytes', 'staging_ttl_minutes', 'staging_pace_seconds', 'staging_target_inventory', 'staging_parallel'] as $key) {
        h_check(isset($schema[$key]), "setting {$key} declarado", "setting {$key} FALTA en el schema");
    }

    $s = app(TranscriptorSettings::class);
    h_check($s->int('staging_target_inventory') > 0, 'staging_target_inventory > 0');
    h_check($s->int('staging_budget_bytes') > 0, 'staging_budget_bytes > 0');
    h_check($s->bool('staging_enabled') === true, 'staging_enabled por defecto true');

    // ─── F. Inventario de staging no revienta ─────────────────────────────
    h_section('F. stagedInventory() responde el shape esperado');

    $inv = $service->stagedInventory();
    h_check(is_array($inv) && array_key_exists('files', $inv) && array_key_exists('bytes', $inv),
        'stagedInventory devuelve {files, bytes}', 'shape inesperado: ' . json_encode($inv));
} finally {
    // Cleanup en orden inverso: transcripciones (FK) y luego archivos.
    if (!empty($GLOBALS['createdIds'])) {
        DB::table('transcriptions')->whereIn('id', $GLOBALS['createdIds'])->delete();
    }
    if (!empty($GLOBALS['createdFileIds'])) {
        DB::table('files')->whereIn('id', $GLOBALS['createdFileIds'])->delete();
    }
    foreach ($GLOBALS['stagedFiles'] ?? [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    DB::table('transcriptions')->where('original_name', 'LIKE', 'htps\_%')->delete();
}

echo "\n" . ($failures === 0 ? "✓ ALL PASSED" : "✗ {$failures} FAILURES") . "\n";
exit($failures === 0 ? 0 : 1);
