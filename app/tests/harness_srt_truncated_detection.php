<?php
/**
 * Harness de regresion para fix SRT truncado en TranscriptionPollingService.
 *
 * Verifica que el detector de SRT truncado funciona correctamente:
 *  - SRT completo (>=90% audio) -> NO marca como truncado
 *  - SRT truncado (<90% audio) -> reencolar como pending con retries++
 *  - Despues de max retries -> promover a dead
 *  - Audio corto (<=60s) -> nunca truncado
 *  - SRT malformado -> no falla (devuelve false = no truncado)
 *
 * Uso: cd app && php tests/harness_srt_truncated_detection.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SystemSetting;
use App\Models\Transcription;
use App\Services\Ia\TranscriptionPollingService;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$tag = 'hsrt_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness srt-truncated-detection (tag: {$tag})\n";

// Activar settings
SystemSetting::set('transcriptor.min_srt_completion_pct', '90');
SystemSetting::set('transcriptor.srt_retry_max', '3');
app(TranscriptorSettings::class)->flush();

$service = app(TranscriptionPollingService::class);
$reflExtract = new ReflectionMethod($service, 'extractLastSrtTimestamp');
$reflExtract->setAccessible(true);
$reflIsTrunc = new ReflectionMethod($service, 'isSrtTruncated');
$reflIsTrunc->setAccessible(true);

// Mock de Transcription para tests
function mockTx(): Transcription {
    $tx = new Transcription();
    $tx->id = 999999;
    $tx->srt_truncated_retries = 0;
    return $tx;
}

// ─── Test 1: extractLastSrtTimestamp ────────────────────────────────────────
h_section("test 1: extractLastSrtTimestamp");

$srtFull = "1\n00:00:01,360 --> 00:00:29,360\ntest\n\n2\n00:00:28,160 --> 00:00:48,720\ntest\n\n100\n00:19:55,000 --> 00:21:00,040\nultimo";
$t = $reflExtract->invoke($service, $srtFull);
h_check($t === 1260.04, 'SRT 21min extrae 1260.04s', "valor=$t");

$srtTrunc = "1\n00:00:01,360 --> 00:00:29,360\ncorto\n\n2\n00:00:28,160 --> 00:00:48,720\ncorto";
$t2 = $reflExtract->invoke($service, $srtTrunc);
h_check($t2 === 48.72, 'SRT truncado a 48s extrae 48.72s', "valor=$t2");

$srtEmpty = "";
$t3 = $reflExtract->invoke($service, $srtEmpty);
h_check($t3 === null, 'SRT vacio devuelve null');

$srtMalformed = "garbage text without timestamps";
$t4 = $reflExtract->invoke($service, $srtMalformed);
h_check($t4 === null, 'SRT malformado devuelve null');

// ─── Test 2: isSrtTruncated — casos ────────────────────────────────────────
h_section("test 2: isSrtTruncated");

// Caso real del problema: 48s de 21min (3.8%)
$tx = mockTx();
$is1 = $reflIsTrunc->invoke($service, $tx, $srtTrunc, ['audio_seconds' => 1260]);
h_check($is1 === true, 'SRT 48s de 1260s audio (3.8%) -> TRUNCADO');

// SRT completo 100%
$is2 = $reflIsTrunc->invoke($service, $tx, $srtFull, ['audio_seconds' => 1260]);
h_check($is2 === false, 'SRT 1260s de 1260s (100%) -> OK');

// SRT al 80% (<90% threshold)
$srt80 = "1\n00:00:00,000 --> 00:16:48,000\ntest";
$is3 = $reflIsTrunc->invoke($service, $tx, $srt80, ['audio_seconds' => 1260]);
h_check($is3 === true, 'SRT 1008s de 1260s (80%) -> TRUNCADO');

// SRT al 95% (>=90% threshold)
$srt95 = "1\n00:00:00,000 --> 00:19:57,000\ntest";
$is4 = $reflIsTrunc->invoke($service, $tx, $srt95, ['audio_seconds' => 1260]);
h_check($is4 === false, 'SRT 1197s de 1260s (95%) -> OK');

// Audio corto (<=60s) nunca truncado
$txShort = mockTx();
$srtShort = "1\n00:00:00,000 --> 00:00:30,000\ntest";
$is5 = $reflIsTrunc->invoke($service, $txShort, $srtShort, ['audio_seconds' => 30]);
h_check($is5 === false, 'Audio 30s con SRT 30s -> OK (audio corto skip)');

// Audio sin audio_seconds -> no falla
$is6 = $reflIsTrunc->invoke($service, $tx, $srtTrunc, ['audio_seconds' => 0]);
h_check($is6 === false, 'Sin audio_seconds -> OK (no falla)');

// ─── Test 3: requeueTruncatedSrt incrementa retries ────────────────────────
h_section("test 3: requeueTruncatedSrt");

$reflRequeue = new ReflectionMethod($service, 'requeueTruncatedSrt');
$reflRequeue->setAccessible(true);

// Crear un file de prueba + transaccion (FK constraint)
$realStorageId = (int) DB::table('storage_providers')->value('id');
$realOwnerId = (int) DB::table('users')->value('id') ?: 1;
$testFile = DB::table('files')->insertGetId([
    'name' => "test_srt_{$tag}.mp3",
    'path' => "/tmp/test_srt_{$tag}.mp3",
    'storage_provider_id' => $realStorageId,
    'owner_id' => $realOwnerId,
    'mime_type' => 'unknown',
    'size' => 1000000,
    'created_at' => now(),
    'updated_at' => now(),
]);

$tx = new Transcription();
$tx->original_name = "test_srt_{$tag}.mp3";
$tx->file_id = $testFile;
$tx->state = Transcription::STATE_QUEUED;
$tx->job_id = 'test-job-' . $tag;
$tx->started_at = now();
$tx->submission_committed_at = now();
$tx->save();

// Caso 1: retry 1 (primer reencolado)
$reflRequeue->invoke($service, $tx, $srtTrunc, ['audio_seconds' => 1260]);
$tx->refresh();
h_check($tx->state === Transcription::STATE_PENDING, 'retry 1 -> state=pending');
h_check($tx->job_id === null, 'retry 1 -> job_id=null');
h_check((int) $tx->srt_truncated_retries === 1, 'retry 1 -> retries=1');
h_check($tx->srt_content === null, 'retry 1 -> srt_content=null');

// Caso 2: retry 2
$tx->update(['state' => Transcription::STATE_QUEUED, 'job_id' => 'test-job-2']);
$reflRequeue->invoke($service, $tx, $srtTrunc, ['audio_seconds' => 1260]);
$tx->refresh();
h_check((int) $tx->srt_truncated_retries === 2, 'retry 2 -> retries=2');

// Caso 3: retry 3 (deberia promover a dead)
$tx->update(['state' => Transcription::STATE_QUEUED, 'job_id' => 'test-job-3']);
$reflRequeue->invoke($service, $tx, $srtTrunc, ['audio_seconds' => 1260]);
$tx->refresh();
h_check((int) $tx->srt_truncated_retries === 3, 'retry 3 -> retries=3');

// Caso 4: retry 4 -> dead
$tx->update(['state' => Transcription::STATE_QUEUED, 'job_id' => 'test-job-4']);
$reflRequeue->invoke($service, $tx, $srtTrunc, ['audio_seconds' => 1260]);
$tx->refresh();
h_check($tx->state === Transcription::STATE_DEAD, 'retry 4 (max=3) -> state=dead');
h_check(str_contains($tx->error_message ?? '', 'SRT truncado agotado'), 'error_message contiene "SRT truncado agotado"');

// Cleanup
DB::table('transcriptions')->where('id', $tx->id)->delete();
DB::table('files')->where('id', $testFile)->delete();
echo "\n  cleanup: deleted test transcriptions and files\n";

echo "\n" . ($failures === 0 ? "✓ ALL PASSED" : "✗ {$failures} FAILURES") . "\n";
exit($failures === 0 ? 0 : 1);
