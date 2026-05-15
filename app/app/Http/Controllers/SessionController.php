<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class SessionController extends Controller
{
    public function __construct(private SessionService $sessionService) {}

    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $sessions = UserSession::with('user')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('last_activity_at')
                ->get()
                ->map(fn($s) => [
                    'id'               => $s->id,
                    'user_id'          => $s->user_id,
                    'user_email'       => $s->user?->email,
                    'user_username'    => $s->user?->username,
                    'ip_address'       => $s->ip_address,
                    'user_agent'       => $s->user_agent,
                    'created_at'       => $s->created_at?->toIso8601String(),
                    'last_activity_at' => $s->last_activity_at?->toIso8601String(),
                    'expires_at'       => $s->expires_at?->toIso8601String(),
                    'is_current'       => $s->session_id === Session::getId(),
                ]);

            return response()->json([
                'sessions'      => $sessions,
                'global_max'    => SystemSetting::get('global_max_sessions', 6),
                'global_lifetime' => SystemSetting::get('global_session_lifetime', 120),
            ]);
        }

        return view('admin.sessions');
    }

    public function destroy(UserSession $session)
    {
        $this->sessionService->killSession($session);
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    public function destroyByUser(User $user)
    {
        $count = $this->sessionService->killAllUserSessions($user);
        return response()->json(['message' => "Se cerraron {$count} sesiones del usuario"]);
    }

    public function updateGlobalSettings(Request $request)
    {
        $request->validate([
            'global_max_sessions'    => 'required|integer|min:0',
            'global_session_lifetime' => 'required|integer|min:0',
        ]);

        SystemSetting::set('global_max_sessions', $request->global_max_sessions);
        SystemSetting::set('global_session_lifetime', $request->global_session_lifetime);

        return response()->json(['message' => 'Configuración global actualizada']);
    }
}
