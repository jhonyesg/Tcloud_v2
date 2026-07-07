<?php

namespace App\Http\Middleware;

use App\Models\UserAlertsInteligente;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class EnsureMisAvisosEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = Session::get('user_id');

        $enabled = UserAlertsInteligente::where('user_id', $userId)
            ->where('enabled', true)
            ->exists();

        if (!$enabled) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No tienes acceso al módulo de avisos'], 403);
            }
            abort(403, 'No tienes acceso al módulo de avisos');
        }

        return $next($request);
    }
}