<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Correo\Http\Controllers\CorreoConfigController;
use App\Modules\Correo\Http\Controllers\CorreoPlantillaController;
use App\Modules\Correo\Http\Controllers\CorreoLogController;
use App\Modules\Papelera\Http\Controllers\PapeleraController;

Route::get('/', fn() => redirect('/login'));

Route::get('/login', [App\Http\Controllers\AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [App\Http\Controllers\AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth');
Route::get('/auth/me', [App\Http\Controllers\AuthController::class, 'me'])->middleware('auth');
Route::post('/auth/ping', [App\Http\Controllers\AuthController::class, 'ping'])->middleware('auth');
Route::get('/auth/forgot-password', [App\Http\Controllers\AuthController::class, 'showForgotPassword'])->name('forgot-password');
Route::post('/auth/forgot-password', [App\Http\Controllers\AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::get('/auth/reset-password/{token}', [App\Http\Controllers\AuthController::class, 'showResetPassword'])->name('reset-password');
Route::post('/auth/reset-password', [App\Http\Controllers\AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/auth/setup-password/{token}', [App\Http\Controllers\AuthController::class, 'showSetupPassword'])->name('setup-password');
Route::post('/auth/setup-password', [App\Http\Controllers\AuthController::class, 'setupPassword'])->middleware('throttle:5,1');

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/users/search', [App\Http\Controllers\StorageProviderController::class, 'searchUsers']);
    Route::resource('users', App\Http\Controllers\UserController::class);
    Route::resource('storages', App\Http\Controllers\StorageProviderController::class);
    Route::get('/users/{user}/storages', [App\Http\Controllers\UserStorageController::class, 'index']);
    Route::post('/users/{user}/storages', [App\Http\Controllers\UserStorageController::class, 'store']);
    Route::put('/users/{user}/storages/{storage}', [App\Http\Controllers\UserStorageController::class, 'update']);
    Route::delete('/users/{user}/storages/{storage}', [App\Http\Controllers\UserStorageController::class, 'destroy']);
    Route::get('/storages/{storage}/users', [App\Http\Controllers\StorageProviderController::class, 'users']);
    Route::post('/storages/{storage}/users', [App\Http\Controllers\StorageProviderController::class, 'assignUser']);
    Route::put('/storages/{storage}/users/{user}', [App\Http\Controllers\StorageProviderController::class, 'updateUserAssignment']);
    Route::delete('/storages/{storage}/users/{user}', [App\Http\Controllers\StorageProviderController::class, 'removeUserAssignment']);
    Route::post('/storages/{storage}/users/assign-all', [App\Http\Controllers\StorageProviderController::class, 'assignAll']);
    Route::delete('/storages/{storage}/users/all/remove', [App\Http\Controllers\StorageProviderController::class, 'removeAll']);
    Route::get('/storages/{storage}/test', [App\Http\Controllers\StorageProviderController::class, 'test']);
    Route::post('/storages/{storage}/reconcile', [App\Http\Controllers\StorageProviderController::class, 'reconcile']);
    Route::post('/users/{user}/toggle-media-editor', [App\Http\Controllers\UserController::class, 'toggleMediaEditor']);
    Route::get('/media-editor', [App\Http\Controllers\MediaEditorAdminController::class, 'index']);
    Route::get('/media-editor/users', [App\Http\Controllers\MediaEditorAdminController::class, 'users']);
    Route::get('/media-editor/stats', [App\Http\Controllers\MediaEditorAdminController::class, 'stats']);
    Route::post('/media-editor/users/{user}', [App\Http\Controllers\MediaEditorAdminController::class, 'updateUser']);
    Route::get('/postgres', [App\Http\Controllers\PostgresAdminController::class, 'index']);
    Route::post('/postgres/config', [App\Http\Controllers\PostgresAdminController::class, 'saveConfig']);
    Route::post('/postgres/test', [App\Http\Controllers\PostgresAdminController::class, 'testConnection']);
    Route::get('/postgres/schema', [App\Http\Controllers\PostgresAdminController::class, 'getSchema']);
    Route::post('/postgres/query', [App\Http\Controllers\PostgresAdminController::class, 'executeQuery']);
    Route::get('/postgres/backup', [App\Http\Controllers\PostgresAdminController::class, 'backupLocal']);
    Route::post('/postgres/ftp/config', [App\Http\Controllers\PostgresAdminController::class, 'saveFtpConfig']);
    Route::post('/postgres/ftp/backup', [App\Http\Controllers\PostgresAdminController::class, 'backupFtp']);

    Route::get('/correo/config', [CorreoConfigController::class, 'show']);
    Route::post('/correo/config', [CorreoConfigController::class, 'store']);
    Route::post('/correo/config/test', [CorreoConfigController::class, 'testConnection']);
    Route::get('/correo/plantillas', [CorreoPlantillaController::class, 'index']);
    Route::get('/correo/plantillas/{name}', [CorreoPlantillaController::class, 'show']);
    Route::post('/correo/plantillas', [CorreoPlantillaController::class, 'store']);
    Route::put('/correo/plantillas/{plantilla}', [CorreoPlantillaController::class, 'update']);
    Route::delete('/correo/plantillas/{plantilla}', [CorreoPlantillaController::class, 'destroy']);
    Route::get('/correo/logs', [CorreoLogController::class, 'index']);
    Route::post('/correo/plantillas/{plantilla}/preview', [CorreoPlantillaController::class, 'preview']);
    Route::post('/correo/plantillas/{plantilla}/send-test', [CorreoPlantillaController::class, 'sendTest']);

    // Sessions management
    Route::get('/sessions', [App\Http\Controllers\SessionController::class, 'index']);
    Route::delete('/sessions/{session}', [App\Http\Controllers\SessionController::class, 'destroy']);
    Route::delete('/sessions/user/{user}', [App\Http\Controllers\SessionController::class, 'destroyByUser']);
    Route::post('/sessions/settings', [App\Http\Controllers\SessionController::class, 'updateGlobalSettings']);

    // Redis monitor
    Route::get('/redis', [App\Http\Controllers\RedisMonitorController::class, 'index']);
    Route::get('/redis/status', [App\Http\Controllers\RedisMonitorController::class, 'status']);
    Route::get('/redis/config', [App\Http\Controllers\RedisMonitorController::class, 'currentConfig']);
    Route::post('/redis/config/test', [App\Http\Controllers\RedisMonitorController::class, 'testConfig']);
    Route::post('/redis/config/save', [App\Http\Controllers\RedisMonitorController::class, 'saveConfig']);
    Route::post('/redis/toggle-driver', [App\Http\Controllers\RedisMonitorController::class, 'toggleSessionDriver']);
    Route::post('/redis/clean-expired', [App\Http\Controllers\RedisMonitorController::class, 'cleanExpired']);
    Route::post('/redis/clean-orphans', [App\Http\Controllers\RedisMonitorController::class, 'cleanOrphans']);

    // Sites Externos
    Route::get('/external-sites', [App\Http\Controllers\ExternalSiteController::class, 'index']);
    Route::post('/external-sites', [App\Http\Controllers\ExternalSiteController::class, 'store']);
    Route::put('/external-sites/{externalSite}', [App\Http\Controllers\ExternalSiteController::class, 'update']);
    Route::delete('/external-sites/{externalSite}', [App\Http\Controllers\ExternalSiteController::class, 'destroy']);
    Route::get('/external-sites/{externalSite}/users', [App\Http\Controllers\ExternalSiteController::class, 'users']);
    Route::post('/external-sites/{externalSite}/users', [App\Http\Controllers\ExternalSiteController::class, 'assignUser']);
    Route::delete('/external-sites/{externalSite}/users/{user}', [App\Http\Controllers\ExternalSiteController::class, 'removeUser']);

});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/correo', [App\Http\Controllers\CorreoAdminController::class, 'index']);
});

Route::middleware('auth')->group(function () {
    // User self-service sessions
    Route::get('/user/sessions', [App\Http\Controllers\UserSessionController::class, 'index']);
    Route::delete('/user/sessions/others', [App\Http\Controllers\UserSessionController::class, 'destroyOthers']);
    Route::delete('/user/sessions/{session}', [App\Http\Controllers\UserSessionController::class, 'destroy']);

    Route::get('/profile', [App\Http\Controllers\UserController::class, 'profile']);
    Route::put('/profile', [App\Http\Controllers\UserController::class, 'profile']);
    Route::get('/profile/show', [App\Http\Controllers\UserController::class, 'profileShow'])->name('profile.show');
    Route::get('/profile/edit', [App\Http\Controllers\UserController::class, 'profileEdit'])->name('profile.edit');

    Route::post('/files/upload', [App\Http\Controllers\FileController::class, 'upload']);
    Route::post('/files/download-multi', [App\Http\Controllers\FileController::class, 'downloadMulti']);
    Route::resource('files', App\Http\Controllers\FileController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // Papelera de reciclaje
    Route::get('/papelera', [PapeleraController::class, 'index'])->name('papelera.index');
    Route::post('/papelera/{file}/restore', [PapeleraController::class, 'restore'])->name('papelera.restore');
    Route::delete('/papelera/{file}', [PapeleraController::class, 'destroy'])->name('papelera.destroy');
    Route::post('/papelera/empty', [PapeleraController::class, 'empty'])->name('papelera.empty');
    Route::get('/user/storages', [App\Http\Controllers\FileController::class, 'storages']);
    Route::get('/files/{file}/download', [App\Http\Controllers\FileController::class, 'download']);
    Route::get('/files/{file}/download-folder', [App\Http\Controllers\FileController::class, 'downloadFolder']);
    Route::get('/files/{file}/preview', [App\Http\Controllers\FileController::class, 'preview']);
    Route::get('/files/{file}/view', [App\Http\Controllers\FileController::class, 'view']);
    Route::post('/files/{file}/rotate', [App\Http\Controllers\FileController::class, 'rotate']);
    Route::post('/files/{file}/copy', [App\Http\Controllers\FileController::class, 'copy']);
    Route::post('/files/{file}/move', [App\Http\Controllers\FileController::class, 'move']);
    Route::get('/files/{file}/text-content', [App\Http\Controllers\FileController::class, 'textContent']);
    Route::put('/files/{file}/text-content', [App\Http\Controllers\FileController::class, 'saveTextContent']);

    Route::get('/media/{file}/preview', [App\Http\Controllers\MediaPreviewController::class, 'preview']);
    Route::get('/media/{file}/thumbnail', [App\Http\Controllers\MediaPreviewController::class, 'thumbnail']);

    Route::resource('shares', App\Http\Controllers\ShareController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('/shares/bulk-preview', [App\Http\Controllers\ShareController::class, 'bulkPreview'])->name('shares.bulk-preview');
    Route::post('/shares/bulk-delete', [App\Http\Controllers\ShareController::class, 'bulkDelete'])->name('shares.bulk-delete');
    Route::post('/shares/availability/verify', [App\Http\Controllers\ShareController::class, 'verifyAvailability'])->name('shares.availability.verify');
    Route::post('/files/{file}/clip', [App\Http\Controllers\MediaClipController::class, 'clip']);
    Route::get('/media-clip/history', [App\Http\Controllers\MediaClipController::class, 'history']);
    Route::get('/media-clip/{jobId}/reclip', [App\Http\Controllers\MediaClipController::class, 'reclip']);
    Route::get('/media/clip-preview/{token}', [App\Http\Controllers\MediaClipController::class, 'serveTemp']);
    Route::get('/files/{id}/clip-thumbs', [App\Http\Controllers\MediaClipController::class, 'thumbnails']);
    Route::get('/files/{id}/clip-thumb/{n}', [App\Http\Controllers\MediaClipController::class, 'thumb']);

    // Grabaciones Puntuales
    Route::prefix('grabaciones-puntuales')->middleware(['auth'])->group(function () {
        Route::get('/grabadores/users', [App\Http\Controllers\GrabacionesPuntuales\GrabadorController::class, 'getUsers'])->name('grabadores.users');
        Route::get('/grabadores/{grabador}/probar', [App\Http\Controllers\GrabacionesPuntuales\GrabadorController::class, 'probarConexion'])->name('grabadores.probar');
        Route::post('/grabadores/{grabador}/asignar-usuario', [App\Http\Controllers\GrabacionesPuntuales\GrabadorController::class, 'asignarUsuario'])->name('grabadores.asignar-usuario');
        Route::post('/grabadores/{grabador}/actualizar-asignacion/{user}', [App\Http\Controllers\GrabacionesPuntuales\GrabadorController::class, 'actualizarAsignacion'])->name('grabadores.actualizar-asignacion');
        Route::post('/grabadores/{grabador}/remover-usuario/{user}', [App\Http\Controllers\GrabacionesPuntuales\GrabadorController::class, 'removerUsuario'])->name('grabadores.remover-usuario');
        Route::resource('grabadores', App\Http\Controllers\GrabacionesPuntuales\GrabadorController::class);

        Route::post('/canales/sincronizar', [App\Http\Controllers\GrabacionesPuntuales\CanalController::class, 'sincronizar'])->name('canales.sincronizar');
        Route::resource('canales', App\Http\Controllers\GrabacionesPuntuales\CanalController::class)->parameters([
            'canales' => 'canal',
        ]);
        Route::post('/canales/{canal}/ejecutar', [App\Http\Controllers\GrabacionesPuntuales\CanalController::class, 'ejecutar'])->name('canales.ejecutar');
        Route::get('/canales/{canal}/detalle', [App\Http\Controllers\GrabacionesPuntuales\CanalController::class, 'detalle'])->name('canales.detalle');
        Route::get('/estado-grabaciones', [App\Http\Controllers\GrabacionesPuntuales\CanalController::class, 'estado'])->name('grabaciones.estado');
    });
});

Route::middleware('auth')->get('/sites/{externalSite}', [App\Http\Controllers\ExternalSiteViewController::class, 'show']);

Route::get('/s/{token}', [App\Http\Controllers\PublicShareController::class, 'show']);
Route::post('/s/{token}/authenticate', [App\Http\Controllers\PublicShareController::class, 'authenticate'])->name('share.authenticate');
Route::get('/s/{token}/folder/{folder_id}', [App\Http\Controllers\PublicShareController::class, 'folder'])->name('share.folder');
Route::get('/s/{token}/download', [App\Http\Controllers\PublicShareController::class, 'download'])->name('share.download');
Route::get('/s/{token}/download/{file_id}', [App\Http\Controllers\PublicShareController::class, 'download'])->name('share.file-download');
Route::get('/s/{token}/media/{file_id}/preview', [App\Http\Controllers\PublicShareController::class, 'mediaPreview'])->name('share.media-preview');
Route::post('/s/{token}/upload', [App\Http\Controllers\PublicShareController::class, 'upload'])->name('share.upload');
Route::post('/s/{token}/create-folder', [App\Http\Controllers\PublicShareController::class, 'createFolder'])->name('share.create-folder');
Route::post('/s/{token}/rename/{file_id}', [App\Http\Controllers\PublicShareController::class, 'rename'])->name('share.rename');
Route::post('/s/{token}/delete/{file_id}', [App\Http\Controllers\PublicShareController::class, 'delete'])->name('share.delete');
Route::get('/s/{token}/preview/{file_id}', [App\Http\Controllers\PublicShareController::class, 'preview'])->name('share.preview');

// Modulo IA — admin (M1, M2, M4)
Route::middleware(['auth', 'admin'])->prefix('ia')->group(function () {
    // M1: API Transcriptor
    Route::get('/api-transcriptor', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'index']);
    Route::get('/api-transcriptor/jobs/{id}', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'show']);
    Route::post('/api-transcriptor/jobs/{id}/retry', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'retry']);
    Route::post('/api-transcriptor/jobs/{id}/dispatch-now', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'dispatchNow']);
    Route::post('/api-transcriptor/jobs/bulk-dispatch', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'bulkDispatch']);
    Route::post('/api-transcriptor/jobs/{id}/refresh-status', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'refreshStatus']);
    Route::post('/api-transcriptor/jobs/{id}/reprocess', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'reprocess']);
    Route::post('/api-transcriptor/jobs/{id}/cancel', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'cancelJob']);
    Route::delete('/api-transcriptor/jobs/{id}', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'destroy']);
    // El interruptor de transcripción de un storage vive aquí, en su propio
    // módulo. Entre el 18 y el 20 de agosto estuvo en Avisos Inteligentes (como
    // bandera derivada por cliente); fue un acoplamiento equivocado y costó una
    // caída de 44 horas. Ver ApiTranscriptorController::toggleStorage().
    Route::post('/api-transcriptor/storages/{id}/toggle', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'toggleStorage']);
    Route::get('/api-transcriptor/storages/{id}/files', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'storageFiles']);
    Route::post('/api-transcriptor/storages/{id}/scan', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'scanStorage']);
    Route::post('/api-transcriptor/storages/{id}/process-folder', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'processFolder']);
    Route::post('/api-transcriptor/storages/{id}/process-day', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'processDay']);
    Route::post('/api-transcriptor/process-batch', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'processBatch']);
    Route::get('/api-transcriptor/batch-status/{runId}', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'batchStatus'])->where('runId', '[A-Za-z0-9_\-]+');
    // throttle: este endpoint corre ffmpeg + POST SINCRONOS dentro de php-fpm.
    // Defensa en profundidad, no el limitador principal: el tope real es el pool
    // acotado del navegador (ui_max_parallel_sends) y, en su momento, el semaforo
    // inflight_max. Se deja holgado a proposito — un limite estrecho devolveria
    // 429 en envios legitimos de archivos cortos y el usuario los veria como
    // errores. 60/min solo actua ante la rafaga patologica (una pagina cacheada
    // con el Promise.allSettled viejo, o peticiones a mano).
    Route::post('/api-transcriptor/transcribe/{fileId}', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'transcribeFile'])->middleware('throttle:60,1');
    Route::get('/api-transcriptor/jobs/{id}/status', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'jobStatus']);
    Route::get('/api-transcriptor/jobs/{id}/transcript', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'transcript']);
    Route::get('/api-transcriptor/transcribe/progress/{key}', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'transcribeProgress'])->where('key', '[A-Za-z0-9_\-]+');
    Route::post('/api-transcriptor/storages/{id}/sync', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'syncStorage']);
    Route::get('/api-transcriptor/health', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'health']);
    Route::get('/api-transcriptor/stats', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'stats']);
    Route::get('/api-transcriptor/empty-folders', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'emptyFolders']);
    Route::get('/api-transcriptor/shm-status', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'shmStatus']);

    // Configuracion en caliente del pipeline (pestaña "Configuracion").
    Route::get('/api-transcriptor/settings', [App\Http\Controllers\Ia\TranscriptorSettingsController::class, 'index']);
    Route::post('/api-transcriptor/settings', [App\Http\Controllers\Ia\TranscriptorSettingsController::class, 'update'])->middleware('throttle:30,1');
    Route::post('/api-transcriptor/settings/reset', [App\Http\Controllers\Ia\TranscriptorSettingsController::class, 'reset'])->middleware('throttle:30,1');
    Route::post('/api-transcriptor/settings/run-tick', [App\Http\Controllers\Ia\TranscriptorSettingsController::class, 'runTick'])->middleware('throttle:6,1');

    // M2: Avisos Inteligentes
    Route::get('/avisos-inteligentes', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'index']);
    Route::get('/avisos-inteligentes/{userId}', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'show']);
    Route::post('/avisos-inteligentes/{userId}', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'updateUser']);
    Route::post('/avisos-inteligentes/{userId}/emails', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'storeEmail']);
    Route::delete('/avisos-inteligentes/{userId}/emails/{email}', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'destroyEmail'])
        ->where('email', '.+');
    Route::post('/avisos-inteligentes/{userId}/keywords', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'storeKeyword']);
    Route::delete('/avisos-inteligentes/{userId}/keywords/{keywordId}', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'destroyKeyword']);
    Route::post('/avisos-inteligentes/{userId}/emails/{email}/test', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'testEmail'])
        ->where('email', '.+');
    Route::get('/avisos-inteligentes/{userId}/matches', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'matches']);
    Route::post('/avisos-inteligentes/{userId}/storages/{storageId}/transcription-access', [App\Http\Controllers\Ia\AvisosInteligentesController::class, 'toggleStorageAccess'])
        ->whereNumber('userId')->whereNumber('storageId');
    // Aquí NO se decide qué se transcribe. Avisos Inteligentes consume el
    // contenido que produce API Transcriptor (keywords sobre transcripciones ya
    // hechas); encender o apagar un canal es una decisión operativa de ese otro
    // módulo, en /ia/api-transcriptor.

    // M4: Correcciones
    Route::get('/correcciones', [App\Http\Controllers\Ia\CorreccionesController::class, 'index']);
    Route::get('/correcciones/transcription-review', [App\Http\Controllers\Ia\CorreccionesController::class, 'transcriptionReviewList']);
    Route::get('/correcciones/transcription-review/{id}', [App\Http\Controllers\Ia\CorreccionesController::class, 'transcriptionReviewDetail'])->whereNumber('id');
    Route::patch('/correcciones/transcription-review/{id}', [App\Http\Controllers\Ia\CorreccionesController::class, 'transcriptionReviewUpdate'])->whereNumber('id');
    Route::get('/correcciones/pending', [App\Http\Controllers\Ia\CorreccionesController::class, 'pending']);
    Route::get('/correcciones/approved', [App\Http\Controllers\Ia\CorreccionesController::class, 'approved']);
    // Export CSV (original + corrección) para validación fuera del navegador.
    Route::get('/correcciones/export', [App\Http\Controllers\Ia\CorreccionesController::class, 'export']);
    Route::get('/correcciones/ai-suggest-results', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestResults']);
    // Ejemplos de dónde dispara una corrección, para moderarla con contexto.
    Route::get('/correcciones/{id}/contexto', [App\Http\Controllers\Ia\CorreccionesController::class, 'contextExamples'])->whereNumber('id');
    // Detalle del segmento origen (changes/2026-08-12-corrections-pending-segment-context).
    // Abre el modal "Contexto del segmento" desde el snippet en la tabla.
    Route::get('/correcciones/{id}/source-segment', [App\Http\Controllers\Ia\CorreccionesController::class, 'sourceSegment'])->whereNumber('id');
    Route::get('/correcciones/protected-terms', [App\Http\Controllers\Ia\CorreccionesController::class, 'protectedTermsIndex']);
    Route::post('/correcciones/protected-terms', [App\Http\Controllers\Ia\CorreccionesController::class, 'protectedTermsStore']);
    Route::delete('/correcciones/protected-terms/{id}', [App\Http\Controllers\Ia\CorreccionesController::class, 'protectedTermsArchive'])->whereNumber('id');
    Route::post('/correcciones/protected-terms/{id}/restore', [App\Http\Controllers\Ia\CorreccionesController::class, 'protectedTermsRestore'])->whereNumber('id');
    Route::post('/correcciones/{id}/approve', [App\Http\Controllers\Ia\CorreccionesController::class, 'approve']);
    Route::post('/correcciones/{id}/reject', [App\Http\Controllers\Ia\CorreccionesController::class, 'reject']);
    Route::post('/correcciones', [App\Http\Controllers\Ia\CorreccionesController::class, 'store']);
    Route::patch('/correcciones/{id}', [App\Http\Controllers\Ia\CorreccionesController::class, 'update'])->whereNumber('id');
    Route::delete('/correcciones/{id}', [App\Http\Controllers\Ia\CorreccionesController::class, 'destroy']);
    // Atomicity + dictionary audit + context-shift (2026-08-02-corrections-dictionary-atomicity)
    Route::get('/correcciones/{id}/atomicity-suggestions', [App\Http\Controllers\Ia\CorreccionesController::class, 'atomicitySuggestions'])->whereNumber('id');
    Route::post('/correcciones/{id}/atomicity-suggestions/bulk-add', [App\Http\Controllers\Ia\CorreccionesController::class, 'bulkCreateAtomicityFromCorrection'])->whereNumber('id');
    Route::patch('/correcciones/{id}/risk-level', [App\Http\Controllers\Ia\CorreccionesController::class, 'setRiskLevel'])->whereNumber('id');
    Route::post('/correcciones/bulk-destroy-inactive', [App\Http\Controllers\Ia\CorreccionesController::class, 'bulkDestroyInactive']);
    Route::get('/correcciones/dictionary-audit', [App\Http\Controllers\Ia\CorreccionesController::class, 'auditReport']);
    Route::post('/correcciones/apply-retroactive', [App\Http\Controllers\Ia\CorreccionesController::class, 'applyRetroactive']);
    Route::get('/correcciones/apply-retroactive/{runId}', [App\Http\Controllers\Ia\CorreccionesController::class, 'runStatus']);
    Route::get('/correcciones/apply-retroactive-active', [App\Http\Controllers\Ia\CorreccionesController::class, 'activeApplyRun']);
    // Bulk moderation + undo (2026-07-30-corrections-bulk-moderation)
    Route::post('/correcciones/bulk-approve', [App\Http\Controllers\Ia\CorreccionesController::class, 'bulkApprove']);
    Route::post('/correcciones/bulk-reject', [App\Http\Controllers\Ia\CorreccionesController::class, 'bulkReject']);
    Route::post('/correcciones/bulk-destroy', [App\Http\Controllers\Ia\CorreccionesController::class, 'bulkDestroy']);
    Route::post('/correcciones/bulk-destroy-pending', [App\Http\Controllers\Ia\CorreccionesController::class, 'bulkDestroyPending']);
    Route::post('/correcciones/undo/{bulkActionId}', [App\Http\Controllers\Ia\CorreccionesController::class, 'undoBulkAction'])
        ->where('bulkActionId', '[A-Za-z0-9_-]+');
    // Miner EN↔ES status (2026-07-30-corrections-en-es-mix-miner)
    Route::get('/correcciones/mining-status', [App\Http\Controllers\Ia\CorreccionesController::class, 'miningStatus']);
    // AI suggester LLM-powered status (2026-08-01-corrections-ai-suggest-context-aware)
    Route::get('/correcciones/ai-suggest-status', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestStatus']);
    // AI suggester invocación on-demand desde el botón del header (gasto controlado por admin).
    Route::post('/correcciones/ai-suggest-now', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestNow']);
    // AI suggester save-on-preview: reusa los candidatos ya mostrados en el modal sin re-llamar al LLM.
    Route::post('/correcciones/ai-suggest-save', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestSave']);
    // AI suggester configuración editable desde UI (modelo, base_url, defaults) — sin deploy.
    Route::get('/correcciones/ai-suggest-settings', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestSettings']);
    Route::post('/correcciones/ai-suggest-settings', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestSettingsUpdate']);
    Route::delete('/correcciones/ai-suggest-settings', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestSettingsReset']);
    // Forzar refetch de modelos disponibles desde el gateway.
    Route::post('/correcciones/ai-suggest-settings/refresh-models', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestSettingsRefreshModels']);
    // Setear API key cifrada (alternativa al .env para LLM_API_KEY).
    Route::post('/correcciones/ai-suggest-settings/api-key', [App\Http\Controllers\Ia\CorreccionesController::class, 'aiSuggestSettingsApiKey']);

    // Triage en capas de pendientes (cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage).
    Route::post('/correcciones/triage-pending', [App\Http\Controllers\Ia\CorreccionesController::class, 'triagePending']);
    Route::get('/correcciones/triage-pending/{runId}', [App\Http\Controllers\Ia\CorreccionesController::class, 'triageRunStatus'])
        ->where('runId', '[A-Za-z0-9_-]+');

    // Corrección IA inline por ejemplo en el modal de contexto
    // (changes/2026-09-05-corrections-ai-context-correct-inline). Manual-only,
    // mismo master switch que ai-suggest-now.
    Route::post('/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct',
        [App\Http\Controllers\Ia\CorreccionesAiContextCorrectController::class, 'suggest'])
        ->whereNumber('correctionId')->whereNumber('exampleId');
    Route::post('/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve',
        [App\Http\Controllers\Ia\CorreccionesAiContextCorrectController::class, 'approve'])
        ->whereNumber('correctionId')->whereNumber('exampleId');

// Curación de marcas protegidas desde el modal de contexto
     // (changes/2026-09-05-corrections-ai-context-aware-with-mark-curation).
     Route::post('/correcciones/protected-terms',
         [App\Http\Controllers\Ia\ProtectedTermsInlineController::class, 'store']);
     Route::post('/correcciones/protected-terms/unprotect',
         [App\Http\Controllers\Ia\ProtectedTermsInlineController::class, 'destroy']);
     Route::get('/correcciones/protected-terms/list',
         [App\Http\Controllers\Ia\ProtectedTermsInlineController::class, 'index']);
     Route::post('/correcciones/brands/suggest',
         [App\Http\Controllers\Ia\ProtectedTermsInlineController::class, 'suggestBrands']);

    // Corrección IA con contexto ampliado (vecinos ±5) — remplaza al flow
    // básico de ai-context-correct-inline una vez adoptada por la UI.
    // Coexiste con el endpoint anterior (con semantics "sin vecinos") hasta
    // que la UI migre totalmente.
    Route::post('/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct-context',
        [App\Http\Controllers\Ia\CorreccionesAiContextAwareController::class, 'suggest'])
        ->whereNumber('correctionId')->whereNumber('exampleId');
    Route::post('/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct-context/approve',
        [App\Http\Controllers\Ia\CorreccionesAiContextAwareController::class, 'approve'])
        ->whereNumber('correctionId')->whereNumber('exampleId');
});

// Modulo IA — cliente (M3): Mis Avisos + propuestas de corrección
Route::middleware(['auth', 'misavisos'])->group(function () {
    Route::get('/mis-avisos', [App\Http\Controllers\MisAvisosController::class, 'index']);
    Route::post('/mis-avisos/keywords', [App\Http\Controllers\MisAvisosController::class, 'storeKeyword']);
    Route::delete('/mis-avisos/keywords/{keywordId}', [App\Http\Controllers\MisAvisosController::class, 'destroyKeyword']);
    Route::get('/mis-avisos/corrections/mine', [App\Http\Controllers\CorreccionPropuestaController::class, 'mine']);
    Route::post('/mis-avisos/corrections', [App\Http\Controllers\CorreccionPropuestaController::class, 'store']);

    // mis-avisos-menciones: feed en vivo, alcance keyword→store, preferencias
    Route::get('/mis-avisos/feed', [App\Http\Controllers\MisAvisosController::class, 'feed'])->middleware('throttle:30,1');
    Route::get('/mis-avisos/storages', [App\Http\Controllers\MisAvisosController::class, 'storages']);
    Route::put('/mis-avisos/keywords/{keywordId}/scope', [App\Http\Controllers\MisAvisosController::class, 'updateKeywordScope']);
    Route::get('/mis-avisos/preferences', [App\Http\Controllers\MisAvisosController::class, 'preferences']);
    Route::put('/mis-avisos/preferences', [App\Http\Controllers\MisAvisosController::class, 'preferences']);

    // mis-avisos-mentions-viewer: transcripción anclada a la mención
    Route::get('/mis-avisos/transcriptions/{transcriptionId}', [App\Http\Controllers\MisAvisosController::class, 'transcription'])
        ->whereNumber('transcriptionId')->middleware('throttle:20,1');
    Route::get('/mis-avisos/transcriptions/{transcriptionId}/segments', [App\Http\Controllers\MisAvisosController::class, 'transcriptionSegments'])
        ->whereNumber('transcriptionId')->middleware('throttle:20,1');

    // mis-avisos-menciones: histórico 60 días + export CSV/Excel
    Route::get('/mis-avisos/history', [App\Http\Controllers\MisAvisosController::class, 'history'])->middleware('throttle:10,1');
    Route::post('/mis-avisos/exports', [App\Http\Controllers\MisAvisosController::class, 'requestExport'])->middleware('throttle:6,1');
    Route::get('/mis-avisos/exports/{exportId}', [App\Http\Controllers\MisAvisosController::class, 'exportStatus']);
    Route::post('/mis-avisos/exports/{exportId}/email', [App\Http\Controllers\MisAvisosController::class, 'emailExport'])->middleware('throttle:4,1');
    Route::get('/mis-avisos/exports/{export}/download', [App\Http\Controllers\MisAvisosController::class, 'downloadExport'])
        ->name('mis-avisos.exports.download')->middleware('signed');
});
