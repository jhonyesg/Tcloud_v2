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
    /**
     * Helpers privados para garantizar que toda consulta / borrado de sesiones
     * en Redis apunte a la MISMA conexión y use el MISMO prefijo que
     * `CacheBasedSessionHandler::write()` (Laravel) — la conexión `session`
     * configurada en `config/database.php`. Centralizado aquí para que un
     * cambio de prefijo o de conexión se propague a todos los call sites sin
     * riesgo de divergencia (que es exactamente el bug que esta clase tenía:
     * `sessionExistsInRedis` y `killSession` apuntaban a `cache`, una DB de
     * Redis que no contiene sesiones reales).
     */
    private function sessionRedisKey(string $sessionId): string
    {
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $cachePrefix = (string) config('cache.prefix', '');
        return $redisPrefix . $cachePrefix . $sessionId;
    }

    private function sessionRedisConnection()
    {
        return Redis::connection('session');
    }

    /**
     * ¿La sesión con id `$sessionId` está viva en Redis (DB donde Laravel
     * realmente guarda sesiones)?
     *
     * Público porque es útil para diagnóstico y harnesses. Toda la lógica de
     * producción (cleanOrphans, countActiveSessions, etc.) también pasa por aquí.
     */
    public function sessionExistsInRedis(string $sessionId): bool
    {
        return $this->sessionRedisConnection()->exists($this->sessionRedisKey($sessionId)) > 0;
    }

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
        $sessions = $user->sessions()
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            try {
                if ($this->sessionExistsInRedis($session->session_id)) {
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
            $this->sessionRedisConnection()->del($this->sessionRedisKey($session->session_id));
        } catch (\Throwable $e) {
            Log::warning('SessionService: failed to delete session from Redis', [
                'session_id' => $session->session_id,
                'error'      => $e->getMessage(),
            ]);
        }
        $session->delete();
    }

    public function killAllUserSessions(User $user, ?string $exceptSessionId = null): int
    {
        $query = $user->sessions();
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

    /**
     * Limpia sesiones huérfanas: filas de `user_sessions` cuya clave de sesión
     * ya no existe en Redis (evictada por TTL, reinicio de Redis, etc.).
     *
     * Guardarraíles:
     *  - Si `would_delete / scanned` supera `sessions_cleanup_max_ratio`
     *    (default 0.5), abortar SIN borrar y emitir warning. Esto protege
     *    contra el patrón "se borró todo de un golpe" que produjo el bug
     *    original (cleanOrphans borrando 100% de filas cada 30 min).
     *  - Si Redis lanza excepción, no borrar la fila (conservador).
     *  - Si `$dryRun` es true, no borra nada; retorna cuántas habría borrado.
     *
     * Métrica: emite `sessions.cleanup.completed`, `sessions.cleanup.dry_run`
     * o `sessions.cleanup.aborted_mass_delete` con `scanned`, `deleted`,
     * `ratio`, `duration_ms`.
     */
    public function cleanOrphans(bool $dryRun = false): int
    {
        $startedAt = microtime(true);
        $scanned = 0;
        $wouldDelete = 0;
        $deleted = 0;
        $maxRatio = (float) SystemSetting::get('sessions_cleanup_max_ratio', 0.5);

        $orphanIds = [];

        UserSession::chunk(100, function ($sessions) use (&$scanned, &$wouldDelete, &$orphanIds, $dryRun) {
            foreach ($sessions as $session) {
                $scanned++;
                try {
                    if (!$this->sessionExistsInRedis($session->session_id)) {
                        $wouldDelete++;
                        $orphanIds[] = $session->id;
                    }
                } catch (\Throwable) {
                    // Conservador: si Redis falla, NO marcar como huérfana.
                }
            }
        });

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $ratio = $scanned > 0 ? $wouldDelete / $scanned : 0.0;

        if ($dryRun) {
            Log::info('sessions.cleanup.dry_run', [
                'scanned'      => $scanned,
                'would_delete' => $wouldDelete,
                'ratio'        => round($ratio, 4),
                'duration_ms'  => $durationMs,
            ]);
            return $wouldDelete;
        }

        if ($wouldDelete > 0 && $ratio > $maxRatio) {
            Log::warning('sessions.cleanup.aborted_mass_delete', [
                'scanned'      => $scanned,
                'would_delete' => $wouldDelete,
                'ratio'        => round($ratio, 4),
                'threshold'    => $maxRatio,
                'duration_ms'  => $durationMs,
            ]);
            return 0;
        }

        // Borrar por IDs en bloque (evita N+1 y mantiene ratio check arriba intacto).
        if (!empty($orphanIds)) {
            UserSession::whereIn('id', $orphanIds)->delete();
            $deleted = count($orphanIds);
        }

        Log::info('sessions.cleanup.completed', [
            'scanned'     => $scanned,
            'deleted'     => $deleted,
            'ratio'       => round($ratio, 4),
            'duration_ms' => $durationMs,
        ]);

        return $deleted;
    }

    public function getRedisSessionPrefix(): string
    {
        return config('database.redis.options.prefix', 'tcloud_');
    }
}
