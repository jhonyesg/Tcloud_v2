<?php

use App\Http\Controllers\Webhooks\TranscriptionWebhookController;
use Illuminate\Support\Facades\Route;

// API routes - kept minimal; correo routes moved to web.php for session-based auth

// Webhook entrante del transcriptor (opt-in). Ver
// `openspec/changes/transcriptor-api-surface-completeness/specs/transcription-result-polling/spec.md`
// (Fase D). Throttle 60/min para mitigacion de abuse; HMAC + anti-replay
// en el controller.
Route::post('/webhooks/transcription', TranscriptionWebhookController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
