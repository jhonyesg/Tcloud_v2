<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use App\Services\SessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SessionTracker
{
    public function __construct(private SessionService $sessionService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!Session::has('user_id')) {
            return $next($request);
        }

        $sessionId = Session::getId();
        $record = UserSession::where('session_id', $sessionId)->first();

        if (!$record) {
            if (Session::has('user_id')) {
                Session::flush();
                if ($request->expectsJson()) {
                    return response()->json(['error' => 'Sesión inválida o cerrada'], 401);
                }
                return redirect('/login')->with('error', 'Tu sesión fue cerrada. Inicia sesión nuevamente.');
            }
            return $next($request);
        }

        if ($record->isExpired()) {
            $this->sessionService->killSession($record);
            Session::flush();

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Sesión expirada'], 401);
            }
            return redirect('/login')->with('error', 'Tu sesión ha expirado. Inicia sesión nuevamente.');
        }

        // Throttle: update last_activity_at at most once per 60 seconds
        if ($record->last_activity_at->diffInSeconds(now()) >= 60) {
            $record->update(['last_activity_at' => now()]);
        }

        return $next($request);
    }
}
