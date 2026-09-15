<?php
/**
 * Harness de regresion para change `transcriptor-api-surface-completeness`.
 *
 * Verifica que el manejo de 429 y 503 del upstream respeta `Retry-After` y no
 * aborta los jobs. Strategy:
 *  - Crea una Transcription pending mock en BD con tag `hubk_<8-hex>`.
 *  - Invoca `TranscriptionSubmitService::markRequeueable()` directamente y
 *    verifica los campos.
 *  - Construye `UpstreamRateLimitException` y `UpstreamUnavailableException`
 *    via factory `fromResponse` y los serializa/deserializa.
 *  - Unit: parser `parseRetryAfter` con segundos puros, HTTP-date, malformed.
 *  - Unit: `UpstreamCircuitBreaker` cuenta strikes y abre al threshold.
 *
 * (No ejercitamos el path completo de submit() porque depende de ffmpeg y un
 * MP4 valido en disco. Las pruebas de integracion total de submit viven en
 * los tests de feature del job Convert.)
 *
 * Cleanup final: borra por tag `hubk_<hex>` y limpia system_settings y CB.
 *
 * Uso:
 *   cd app && php tests/harness_transcriptor_upstream_backoff.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\UpstreamCircuitBreaker;
use App\Services\Ia\UpstreamRateLimitException;
use App\Services\Ia\UpstreamUnavailableException;
use Illuminate\Support\Facades\DB;

$failures = 0;
$tag = 'hubk_' . bin2hex(random_bytes(4));

function h_ok(string $msg) { echo "  ✓ {$msg}\n"; }
function h_fail(string $msg) { echo "  ✗ {$msg}\n"; $GLOBALS['failures']++; }
function h_section(string $name) { echo "\n--- {$name} ---\n"; }

// Cleanup defensivo
DB::table('transcriptions')->where('original_name', 'LIKE', "{$tag}_%")->delete();
DB::table('files')->where('name', 'LIKE', "{$tag}_%")->delete();
DB::table('system_settings')
    ->whereIn('key', ['max_backoff_seconds', 'circuit_breaker_threshold', 'circuit_breaker_open_seconds', 'requeue_after_minutes'])
    ->delete();
app(UpstreamCircuitBreaker::class)->clear();
app(App\Services\Ia\TranscriptorSettings::class)->flush();

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
    $createdTxIds = [];

    // ---- Caso 1: UpstreamRateLimitException factory ----
    h_section('Caso 1: UpstreamRateLimitException::fromResponse con Retry-After: 7');
    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '7'])
    );
    $exc = UpstreamRateLimitException::fromResponse(429, $resp, 'submit');
    if ($exc->retryAfter() === 7) {
        h_ok('ex->retryAfter() === 7');
    } else {
        h_fail("ex->retryAfter() === {$exc->retryAfter()} (esperado 7)");
    }
    if ($exc->operation() === 'submit') {
        h_ok('ex->operation() === "submit"');
    } else {
        h_fail("operation: {$exc->operation()}");
    }
    if ($exc->getMessage() && str_contains($exc->getMessage(), 'rate-limit')) {
        h_ok('mensaje incluye "rate-limit"');
    } else {
        h_fail("mensaje mal: {$exc->getMessage()}");
    }

    // ---- Caso 2: UpstreamRateLimitException factory con HTTP-date ----
    h_section('Caso 2: 429 con Retry-After HTTP-date (RFC-7231)');
    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 30) . ' GMT'])
    );
    $exc2 = UpstreamRateLimitException::fromResponse(429, $resp, 'submit');
    if ($exc2->retryAfter() >= 28 && $exc2->retryAfter() <= 32) {
        h_ok("HTTP-date parseado: {$exc2->retryAfter()}s (~30s esperados)");
    } else {
        h_fail("HTTP-date no parseado: {$exc2->retryAfter()}");
    }

    // ---- Caso 3: UpstreamUnavailableException factory ----
    h_section('Caso 3: 503 sin Retry-After -> fallback max_backoff_seconds=45');
    \App\Models\SystemSetting::set('transcriptor.max_backoff_seconds', '45');
    app(\App\Services\Ia\TranscriptorSettings::class)->flush();
    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(503, [])
    );
    $exc3 = UpstreamUnavailableException::fromResponse(503, $resp, 'submit', 45);
    if ($exc3->retryAfter() === 45) {
        h_ok('ex->retryAfter() === 45 (fallback)');
    } else {
        h_fail("503 fallback: {$exc3->retryAfter()}");
    }

    // ---- Caso 4: 503 CON Retry-After respeta el header ----
    h_section('Caso 4: 503 con Retry-After: 90, ignora fallback');
    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(503, ['Retry-After' => '90'])
    );
    $exc4 = UpstreamUnavailableException::fromResponse(503, $resp, 'submit', 999);
    if ($exc4->retryAfter() === 90) {
        h_ok('header Retry-After: 90 tiene prioridad');
    } else {
        h_fail("esperado 90, obtuve {$exc4->retryAfter()}");
    }

    // ---- Caso 5: MarkRequeueable deja la fila en pending con requeue_after_at ----
    h_section('Caso 5: TranscriptionSubmitService::markRequeueable() setea requeue_after_at');
    // Configuramos requeue_after_minutes a un valor que el test entienda:
    // queremos que requeue_after_at sea ~now+7s. Eso es 7/60 minutos. No se
    // puede expresar en minutos, pero al menos validamos el patrón (state=pending,
    // error_message=motivo). La duracion exacta depende del setting.
    \App\Models\SystemSetting::set('requeue_after_minutes', '1');
    app(\App\Services\Ia\TranscriptorSettings::class)->flush();

    $tx = Transcription::create([
        'file_id' => $realFile->id,
        'original_name' => "{$tag}_case5.mp4",
        'state' => Transcription::STATE_PENDING,
        'job_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $createdTxIds[] = $tx->id;

    $submitter = app(\App\Services\Ia\TranscriptionSubmitService::class);
    $reflection = new ReflectionClass($submitter);
    $method = $reflection->getMethod('markRequeueable');
    $method->setAccessible(true);
    $method->invoke($submitter, $tx, 'Rate limit upstream (retry_after=7s)');

    $tx->refresh();
    if ($tx->state === Transcription::STATE_PENDING) {
        h_ok('state sigue en pending');
    } else {
        h_fail("state: {$tx->state} (esperado pending)");
    }
    if ($tx->error_message && str_contains($tx->error_message, 'Rate limit')) {
        h_ok('error_message con motivo "Rate limit" (no fatal)');
    } else {
        h_fail("error_message: {$tx->error_message}");
    }
    if ($tx->requeue_after_at !== null && $tx->requeue_after_at->gt(now())) {
        h_ok("requeue_after_at en el futuro: {$tx->requeue_after_at->toIso8601String()}");
    } else {
        h_fail("requeue_after_at mal: " . ($tx->requeue_after_at?->toIso8601String() ?? 'null'));
    }

    // ---- Caso 6: UpstreamCircuitBreaker cuenta strikes ----
    h_section('Caso 6: UpstreamCircuitBreaker cuenta strikes');
    $cb = app(UpstreamCircuitBreaker::class);
    $cb->clear();

    \App\Models\SystemSetting::set('transcriptor.circuit_breaker_threshold', '2');
    \App\Models\SystemSetting::set('transcriptor.circuit_breaker_open_seconds', '30');
    app(\App\Services\Ia\TranscriptorSettings::class)->flush();

    $cb = new UpstreamCircuitBreaker();

    if (!$cb->isOpen()) {
        h_ok('estado inicial: cerrado');
    } else {
        h_fail('estado inicial: ya abierto');
    }

    $cb->recordStrike();
    if (!$cb->isOpen()) {
        h_ok('tras 1 strike: sigue cerrado');
    } else {
        h_fail('tras 1 strike: deberia seguir cerrado');
    }

    $cb->recordStrike();
    if ($cb->isOpen()) {
        h_ok('tras 2 strikes: abierto (threshold=2)');
    } else {
        h_fail('tras 2 strikes: deberia estar abierto');
    }

    $summary = $cb->summary();
    if ($summary['open'] === true && $summary['strikes_window'] >= 2) {
        h_ok("summary.open=true, strikes_window={$summary['strikes_window']}, threshold={$summary['threshold']}");
    } else {
        h_fail("summary mal: " . json_encode($summary));
    }

    // ---- Unit: parser Retry-After ----
    h_section('Unit: UpstreamRateLimitException::parseRetryAfter');
    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '30'])
    );
    if (UpstreamRateLimitException::parseRetryAfter($resp) === 30) {
        h_ok('parseRetryAfter("30") -> 30s');
    } else {
        h_fail('parseRetryAfter("30") fallo');
    }

    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => 'invalid'])
    );
    if (UpstreamRateLimitException::parseRetryAfter($resp) === 0) {
        h_ok('parseRetryAfter("invalid") -> 0 (fallback)');
    } else {
        h_fail('parseRetryAfter("invalid") no fallback');
    }

    $resp = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(429, [])
    );
    if (UpstreamRateLimitException::parseRetryAfter($resp) === 0) {
        h_ok('parseRetryAfter(null) -> 0');
    } else {
        h_fail('parseRetryAfter(null) no fallback');
    }

} catch (\Throwable $e) {
    echo "  ✗ Excepción no esperada: " . $e->getMessage() . "\n";
    echo "    file: " . $e->getFile() . ":" . $e->getLine() . "\n";
    $failures++;
} finally {
    // Cleanup
    foreach ($createdTxIds as $id) {
        if ($id) Transcription::where('id', $id)->delete();
    }
    foreach ($createdFileIds as $id) {
        File::where('id', $id)->delete();
    }
    DB::table('system_settings')->where('key', 'LIKE', 'transcriptor.%')->delete();
    DB::table('system_settings')->where('key', 'LIKE', 'circuit_breaker%')->delete();
    try { app(UpstreamCircuitBreaker::class)->clear(); } catch (\Throwable $e) {}
    try { app(App\Services\Ia\TranscriptorSettings::class)->flush(); } catch (\Throwable $e) {}
}

echo "\n=== harness_transcriptor_upstream_backoff ===\n";
if ($failures === 0) {
    echo "✓ Todas las verificaciones pasaron.\n";
    exit(0);
}
echo "✗ {$failures} verificaciones fallaron.\n";
exit(1);
