<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth'            => \App\Http\Middleware\Authenticate::class,
            'admin'           => \App\Http\Middleware\AdminOnly::class,
            'role'            => \App\Http\Middleware\CheckRole::class,
            'session.tracker' => \App\Http\Middleware\SessionTracker::class,
            'misavisos'       => \App\Http\Middleware\EnsureMisAvisosEnabled::class,
            'throttle'        => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
        $middleware->appendToGroup('web', \App\Http\Middleware\SessionTracker::class);

        // Excepciones CSRF: el webhook del transcriptor se valida por token propio.
        $middleware->validateCsrfTokens(except: [
            'webhooks/transcription',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Con el indice unico files_storage_provider_id_path_unique restaurado,
        // varios File::create que antes pasaban en silencio pueden lanzar una
        // violacion de unicidad al competir dos peticiones por la misma ruta
        // (copia recursiva de carpetas, subida simultanea, creacion desde un
        // enlace publico). Sin esto serian errores 500; con esto devuelven el
        // mismo 409 que ya devuelven las comprobaciones previas.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, $request) {
            $isUniqueViolation = ($e->errorInfo[0] ?? null) === '23505';

            if (!$isUniqueViolation || !str_contains($e->getMessage(), 'files_storage_provider_id_path_unique')) {
                return null;
            }

            \Illuminate\Support\Facades\Log::info('files.unique_violation', [
                'url' => $request->fullUrl(),
                'user_id' => \Illuminate\Support\Facades\Session::get('user_id'),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Ya existe un archivo o carpeta con esa ruta'], 409);
            }

            return back()->withErrors(['file' => 'Ya existe un archivo o carpeta con esa ruta']);
        });
    })->create();