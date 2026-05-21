<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

class SessionService
{
    public function getEffectiveMaxSessions(User $user): int
    {
        if ($user->max_sessions !== null) {
            return (int) $user->max_sessions;
        }
        return (int) SystemSetting::get('global_max_sessions', 6);
    }

    public function getEffectiveLifetimeMinutes(User $user): int
    {
        if ($user->session_lifetime_minutes !== null) {
            return (int) $user->session_lifetime_minutes;
        }
        return (int) SystemSetting::get('global_session_lifetime', 120);
    }

    public function countActiveSessions(User $user): int
    {
        $sessions = UserSession::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            try {
                if (Redis::exists($session->session_id)) {
                    $count++;
                }
            } catch (\Exception) {
                $count++; // conservador: si Redis falla, contar como activa
            }
        }

        return $count;
    }

    public function createSession(User $user, Request $request): UserSession
    {
        $lifetimeMinutes = $this->getEffectiveLifetimeMinutes($user);
        $expiresAt = $lifetimeMinutes > 0 ? now()->addMinutes($lifetimeMinutes) : null;

        return UserSession::create([
            'user_id'          => $user->id,
            'session_id'       => Session::getId(),
            'ip_address'       => $request->ip(),
            'user_agent'       => $request->userAgent(),
            'created_at'       => now(),
            'last_activity_at' => now(),
            'expires_at'       => $expiresAt,
        ]);
    }

    public function killSession(UserSession $session): void
    {
        Cache::forget("session_valid:{$session->session_id}");

        try {
            Redis::del($session->session_id);
        } catch (\Exception $e) {
            Log::warning('SessionService: failed to delete Redis key', [
                'session_id' => $session->session_id,
                'error'      => $e->getMessage(),
            ]);
        }
        $session->delete();
    }

    public function killAllUserSessions(User $user, ?string $exceptSessionId = null): int
    {
        $query = UserSession::where('user_id', $user->id);
        if ($exceptSessionId) {
            $query->where('session_id', '!=', $exceptSessionId);
        }

        $sessions = $query->get();
        foreach ($sessions as $session) {
            $this->killSession($session);
        }

        return $sessions->count();
    }

    public function cleanExpired(): int
    {
        return UserSession::where('expires_at', '<', now())->delete();
    }

    public function cleanOrphans(): int
    {
        $count = 0;

        UserSession::chunk(100, function ($sessions) use (&$count) {
            foreach ($sessions as $session) {
                try {
                    $exists = Redis::exists($session->session_id);
                    if (!$exists) {
                        $session->delete();
                        $count++;
                    }
                } catch (\Exception) {
                }
            }
        });

        return $count;
    }

    public function getRedisSessionPrefix(): string
    {
        return config('database.redis.options.prefix', 'tcloud_');
    }
}
