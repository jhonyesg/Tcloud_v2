<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Correo\Http\Controllers\CorreoConfigController;
use App\Modules\Correo\Http\Controllers\CorreoPlantillaController;
use App\Modules\Correo\Http\Controllers\CorreoLogController;

Route::get('/', fn() => redirect('/login'));

Route::get('/login', [App\Http\Controllers\AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::post('/logout', [App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth');
Route::get('/auth/me', [App\Http\Controllers\AuthController::class, 'me'])->middleware('auth');
Route::post('/auth/forgot-password', [App\Http\Controllers\AuthController::class, 'forgotPassword']);
Route::get('/auth/reset-password/{token}', [App\Http\Controllers\AuthController::class, 'showResetPassword'])->name('reset-password');
Route::post('/auth/reset-password', [App\Http\Controllers\AuthController::class, 'resetPassword']);

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->middleware('auth');

Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
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
    Route::get('/storages/{storage}/test', [App\Http\Controllers\StorageProviderController::class, 'test']);
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
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/correo', [App\Http\Controllers\CorreoAdminController::class, 'index']);
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [App\Http\Controllers\UserController::class, 'profile']);
    Route::put('/profile', [App\Http\Controllers\UserController::class, 'profile']);
    Route::get('/profile/show', [App\Http\Controllers\UserController::class, 'profileShow'])->name('profile.show');
    Route::get('/profile/edit', [App\Http\Controllers\UserController::class, 'profileEdit'])->name('profile.edit');

    Route::resource('files', App\Http\Controllers\FileController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('/user/storages', [App\Http\Controllers\FileController::class, 'storages']);
    Route::post('/files/upload', [App\Http\Controllers\FileController::class, 'upload']);
    Route::get('/files/{file}/download', [App\Http\Controllers\FileController::class, 'download']);
    Route::get('/files/{file}/preview', [App\Http\Controllers\FileController::class, 'preview']);
    Route::get('/files/{file}/view', [App\Http\Controllers\FileController::class, 'view']);

    Route::get('/media/{file}/preview', [App\Http\Controllers\MediaPreviewController::class, 'preview']);
    Route::get('/media/{file}/thumbnail', [App\Http\Controllers\MediaPreviewController::class, 'thumbnail']);

    Route::resource('shares', App\Http\Controllers\ShareController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
});

Route::get('/s/{token}', [App\Http\Controllers\PublicShareController::class, 'show']);
Route::get('/s/{token}/folder/{folder_id}', [App\Http\Controllers\PublicShareController::class, 'folder'])->name('share.folder');
Route::get('/s/{token}/download', [App\Http\Controllers\PublicShareController::class, 'download'])->name('share.download');
Route::get('/s/{token}/download/{file_id}', [App\Http\Controllers\PublicShareController::class, 'download'])->name('share.file-download');
Route::get('/s/{token}/media/{file_id}/preview', [App\Http\Controllers\PublicShareController::class, 'mediaPreview'])->name('share.media-preview');
Route::post('/s/{token}/upload', [App\Http\Controllers\PublicShareController::class, 'upload'])->name('share.upload');
Route::post('/s/{token}/create-folder', [App\Http\Controllers\PublicShareController::class, 'createFolder'])->name('share.create-folder');
Route::post('/s/{token}/rename/{file_id}', [App\Http\Controllers\PublicShareController::class, 'rename'])->name('share.rename');
Route::post('/s/{token}/delete/{file_id}', [App\Http\Controllers\PublicShareController::class, 'delete'])->name('share.delete');
Route::get('/s/{token}/preview/{file_id}', [App\Http\Controllers\PublicShareController::class, 'preview'])->name('share.preview');