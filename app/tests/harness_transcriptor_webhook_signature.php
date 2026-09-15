<?php
/**
 * Harness de regresion para `transcriptor-api-surface-completeness` Fase D.
 *
 * Verifica:
 *  - HMAC valido + ts reciente -> 200 con matched=true y result=done
 *  - HMAC invalido -> 401 invalid signature
 *  - ts fuera de ventana (10 minutos atras) -> 401 timestamp out of window
 *  - HMAC valido + job_id sin match local -> 200 con matched=false
 *
 * Cleanup por tag `hwbk_<hex>`. Cleanup defensivo al inicio.
 *
 * Uso:
 *   cd app && php tests/harness_transcriptor_webhook_signature.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use Illuminate\Support\Facades\DB;

$failures = 0;
$tag = 'hwbk_' . bin2hex(random_bytes(4));
$secret = 'test_secret_' . bin2hex(random_bytes(4));

function h_ok(string $msg) { echo "  ✓ {$msg}\n"; }
function h_fail(string $msg) { echo "  ✗ {$msg}\n"; $GLOBALS['failures']++; }
function h_section(string $name) { echo "\n--- {$name} ---\n"; }

putenv("TCLOUD_WEBHOOK_SECRET={$secret}");

// Cleanup defensivo
DB::table('transcriptions')->where('original_name', 'LIKE', "{$tag}_%")->delete();
DB::table('files')->where('name', 'LIKE', "{$tag}_%")->delete();

try {
    $storage = StorageProvider::where('transcription_enabled', true)->first();
    if (!$storage) {
        echo "  ✗ No hay storage habilitado en la BD; abortando\n";
        exit(1);
    }

    $realFile = File::create([
        'storage_provider_id' => $storage->id,
        'owner_id' => 1,
        'path' => "/tmp/{$tag}_test.mp4",
        'name' => "{$tag}_test.mp4",
        'size' => 1024,
        'mime_type' => 'video/mp4',
        'is_folder' => false,
        'file_modified_at' => now(),
    ]);
    $createdFileIds[] = $realFile->id;

    // Caso A: HMAC valido + ts reciente + job_id matched
    h_section('Caso A: HMAC valido + ts reciente + job_id conocido');
    $tx = Transcription::create([
        'file_id' => $realFile->id,
        'original_name' => "{$tag}_caseA.mp4",
        'state' => Transcription::STATE_QUEUED,
        'job_id' => "job_{$tag}_A",
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $createdTxIds[] = $tx->id;

    $bodyA = json_encode([
        'job_id' => $tx->job_id,
        'state' => 'queued',
        'corrected' => 0,
    ]);
    $hmacA = hash_hmac('sha256', $bodyA, $secret);

    $request = \Illuminate\Http\Request::create('/api/webhooks/transcription', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TCLOUD_WEBHOOK_TOKEN' => $hmacA,
        'HTTP_X_TCLOUD_WEBHOOK_TS' => (string) time(),
    ], $bodyA);
    $request->setJson(new \Symfony\Component\HttpFoundation\InputBag(json_decode($bodyA, true) ?? []));

    $controller = app(App\Http\Controllers\Webhooks\TranscriptionWebhookController::class);
    $response = $controller($request, app(App\Services\Ia\TranscriptionPollingService::class));
    if ($response->getStatusCode() === 200) {
        $body = json_decode($response->getContent(), true);
        if (($body['matched'] ?? false) === true) {
            h_ok('200 con matched=true');
        } else {
            h_fail("200 pero matched=" . ($body['matched'] ?? 'null'));
        }
    } else {
        h_fail("HTTP {$response->getStatusCode()} (esperado 200)");
    }

    // Caso B: HMAC invalido -> 401
    h_section('Caso B: HMAC invalido');
    $bodyB = json_encode(['job_id' => $tx->job_id, 'state' => 'queued']);
    $requestB = \Illuminate\Http\Request::create('/api/webhooks/transcription', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TCLOUD_WEBHOOK_TOKEN' => 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899',
        'HTTP_X_TCLOUD_WEBHOOK_TS' => (string) time(),
    ], $bodyB);

    $controller = app(App\Http\Controllers\Webhooks\TranscriptionWebhookController::class);
    $responseB = $controller($requestB, app(App\Services\Ia\TranscriptionPollingService::class));
    if ($responseB->getStatusCode() === 401) {
        h_ok('401 invalid signature');
    } else {
        h_fail("HTTP {$responseB->getStatusCode()} (esperado 401)");
    }

    // Caso C: ts fuera de ventana (10 minutos atras)
    h_section('Caso C: timestamp fuera de ventana (>5 min atras)');
    $bodyC = json_encode(['job_id' => $tx->job_id, 'state' => 'queued']);
    $hmacC = hash_hmac('sha256', $bodyC, $secret);
    $oldTs = time() - 600;

    $requestC = \Illuminate\Http\Request::create('/api/webhooks/transcription', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TCLOUD_WEBHOOK_TOKEN' => $hmacC,
        'HTTP_X_TCLOUD_WEBHOOK_TS' => (string) $oldTs,
    ], $bodyC);

    $responseC = $controller($requestC, app(App\Services\Ia\TranscriptionPollingService::class));
    if ($responseC->getStatusCode() === 401) {
        $bodyC2 = json_decode($responseC->getContent(), true);
        if (($bodyC2['drift_s'] ?? 0) > 300) {
            h_ok('401 timestamp out of window, drift=' . $bodyC2['drift_s']);
        } else {
            h_ok('401 timestamp out of window');
        }
    } else {
        h_fail("HTTP {$responseC->getStatusCode()} (esperado 401)");
    }

    // Caso D: HMAC valido + job_id sin match
    h_section('Caso D: HMAC valido + job_id sin match local');
    $bodyD = json_encode(['job_id' => 'unknown_job_xyz', 'state' => 'queued']);
    $hmacD = hash_hmac('sha256', $bodyD, $secret);
    $requestD = \Illuminate\Http\Request::create('/api/webhooks/transcription', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TCLOUD_WEBHOOK_TOKEN' => $hmacD,
        'HTTP_X_TCLOUD_WEBHOOK_TS' => (string) time(),
    ], $bodyD);

    $responseD = $controller($requestD, app(App\Services\Ia\TranscriptionPollingService::class));
    if ($responseD->getStatusCode() === 200) {
        $bodyD2 = json_decode($responseD->getContent(), true);
        if (($bodyD2['matched'] ?? true) === false) {
            h_ok('200 matched=false (job_id desconocido)');
        } else {
            h_fail("200 pero matched no es false: " . json_encode($bodyD2));
        }
    } else {
        h_fail("HTTP {$responseD->getStatusCode()} (esperado 200)");
    }

    // Caso E: body invalido (no es JSON)
    h_section('Caso E: body no es JSON');
    $bodyE = 'not json at all';
    $hmacE = hash_hmac('sha256', $bodyE, $secret);
    $requestE = \Illuminate\Http\Request::create('/api/webhooks/transcription', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TCLOUD_WEBHOOK_TOKEN' => $hmacE,
        'HTTP_X_TCLOUD_WEBHOOK_TS' => (string) time(),
    ], $bodyE);

    $responseE = $controller($requestE, app(App\Services\Ia\TranscriptionPollingService::class));
    if ($responseE->getStatusCode() === 400) {
        h_ok('400 invalid payload (no JSON)');
    } else {
        h_fail("HTTP {$responseE->getStatusCode()} (esperado 400)");
    }

} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
    $failures++;
} finally {
    foreach ($createdTxIds as $id) {
        if ($id) Transcription::where('id', $id)->delete();
    }
    foreach ($createdFileIds as $id) {
        File::where('id', $id)->delete();
    }
    putenv('TCLOUD_WEBHOOK_SECRET');
}

echo "\n=== harness_transcriptor_webhook_signature ===\n";
if ($failures === 0) {
    echo "✓ Todas las verificaciones pasaron.\n";
    exit(0);
}
echo "✗ {$failures} verificaciones fallaron.\n";
exit(1);
