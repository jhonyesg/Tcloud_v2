<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class UserSessionController extends Controller
{
    public function __construct(private SessionService $sessionService) {}

    public function index(Request $request)
    {
        $userId = Session::get('user_id');
        $currentSessionId = Session::getId();

        $sessions = UserSession::where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(fn($s) => [
                'id'               => $s->id,
                'ip_address'       => $s->ip_address,
                'user_agent'       => $s->user_agent,
                'created_at'       => $s->created_at?->toIso8601String(),
                'last_activity_at' => $s->last_activity_at?->toIso8601String(),
                'expires_at'       => $s->expires_at?->toIso8601String(),
                'is_current'       => $s->session_id === $currentSessionId,
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function destroy(UserSession $session)
    {
        $userId = Session::get('user_id');
        $currentSessionId = Session::getId();

        if ($session->user_id !== $userId) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($session->session_id === $currentSessionId) {
            return response()->json([
                'error' => 'No puedes cerrar tu sesión actual desde aquí. Usa Cerrar Sesión.'
            ], 403);
        }

        $this->sessionService->killSession($session);
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    public function destroyOthers()
    {
        $userId = Session::get('user_id');
        $user = \App\Models\User::findOrFail($userId);
        $currentSessionId = Session::getId();

        $count = $this->sessionService->killAllUserSessions($user, $currentSessionId);
        return response()->json(['message' => "Se cerraron {$count} sesiones", 'count' => $count]);
    }
}
