<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class RedisMonitorController extends Controller
{
    public function __construct(private SessionService $sessionService) {}

    public function index()
    {
        $config = [
            'host'     => config('database.redis.default.host', '127.0.0.1'),
            'port'     => config('database.redis.default.port', '6379'),
            'database' => config('database.redis.default.database', '0'),
            'password' => config('database.redis.default.password', ''),
        ];
        return view('admin.redis', compact('config'));
    }

    public function currentConfig()
    {
        return response()->json([
            'host'        => config('database.redis.default.host', '127.0.0.1'),
            'port'        => config('database.redis.default.port', '6379'),
            'database'    => config('database.redis.default.database', '0'),
            'has_password' => !empty(config('database.redis.default.password')),
        ]);
    }

    public function testConfig(Request $request)
    {
        $request->validate([
            'host'     => 'required|string',
            'port'     => 'required|integer',
            'database' => 'nullable|integer',
            'password' => 'nullable|string',
        ]);

        // If password field is empty, fall back to the currently configured password
        $password = $request->filled('password') ? $request->password : config('database.redis.default.password');

        try {
            $client = new \Redis();
            $client->connect($request->host, (int) $request->port, 3);
            if (!empty($password)) {
                $client->auth($password);
            }
            if ($request->filled('database')) {
                $client->select((int) $request->database);
            }
            $client->ping();
            $client->close();
            return response()->json(['success' => true, 'message' => 'Conexión exitosa a Redis']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function saveConfig(Request $request)
    {
        $request->validate([
            'host'     => 'required|string',
            'port'     => 'required|integer',
            'database' => 'nullable|integer',
            'password' => 'nullable|string',
        ]);

        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        $keys = [
            'REDIS_HOST' => $request->host,
            'REDIS_PORT' => $request->port,
            'REDIS_DB'   => $request->input('database', '0'),
        ];

        // Only update password if a new one was explicitly provided
        if ($request->filled('password')) {
            $keys['REDIS_PASSWORD'] = $request->password;
        }

        foreach ($keys as $key => $value) {
            $escaped = preg_quote($key, '/');
            if (preg_match("/^{$escaped}=.*$/m", $envContent)) {
                $envContent = preg_replace("/^{$escaped}=.*$/m", "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $envContent);

        return response()->json(['success' => true, 'message' => 'Configuración Redis guardada. Reinicia PHP-FPM para aplicar los cambios.']);
    }

    public function toggleSessionDriver()
    {
        $current = config('session.driver');
        $new = $current === 'redis' ? 'file' : 'redis';

        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        if (preg_match('/^SESSION_DRIVER=.*$/m', $envContent)) {
            $envContent = preg_replace('/^SESSION_DRIVER=.*$/m', "SESSION_DRIVER={$new}", $envContent);
        } else {
            $envContent .= "\nSESSION_DRIVER={$new}";
        }

        file_put_contents($envPath, $envContent);

        $msg = $new === 'redis'
            ? 'Redis activado para sesiones. Reinicia PHP-FPM para aplicar.'
            : 'Sesiones cambiadas a archivos locales. Las sesiones actuales serán invalidadas al reiniciar PHP-FPM.';

        return response()->json(['success' => true, 'driver' => $new, 'message' => $msg]);
    }

    public function status()
    {
        try {
            $info = Redis::connection('default')->client()->info();

            // Count only real session keys: check DB session IDs against Redis
            $dbSessions = UserSession::where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->pluck('session_id');

            $dbSessionCount    = $dbSessions->count();
            $inRedisCount      = 0;
            $orphanDbCount     = 0;

            foreach ($dbSessions as $sessionId) {
                try {
                    if (Redis::exists($sessionId)) {
                        $inRedisCount++;
                    } else {
                        $orphanDbCount++;
                    }
                } catch (\Exception) {}
            }

            $maxmemory = $info['maxmemory'] ?? 0;
            $usedMemory = $info['used_memory'] ?? 0;

            return response()->json([
                'connected'         => true,
                'session_driver'    => config('session.driver'),
                'version'           => $info['redis_version'] ?? 'N/A',
                'uptime_seconds'    => $info['uptime_in_seconds'] ?? 0,
                'uptime_human'      => $this->formatUptime($info['uptime_in_seconds'] ?? 0),
                'used_memory_human' => $info['used_memory_human'] ?? 'N/A',
                'maxmemory_human'   => $maxmemory > 0 ? $this->formatBytes($maxmemory) : 'Sin límite',
                'memory_pct'        => $maxmemory > 0 ? round(($usedMemory / $maxmemory) * 100, 1) : null,
                'connected_clients'   => $info['connected_clients'] ?? 0,
                'total_commands'      => $info['total_commands_processed'] ?? 0,
                'redis_session_count' => $inRedisCount,
                'db_session_count'    => $dbSessionCount,
                'orphan_db_count'     => $orphanDbCount,
                'desync'              => $orphanDbCount > 0,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'connected' => false,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    public function cleanExpired()
    {
        $count = $this->sessionService->cleanExpired();
        return response()->json(['message' => "Se eliminaron {$count} sesiones expiradas", 'count' => $count]);
    }

    public function cleanOrphans()
    {
        $count = $this->sessionService->cleanOrphans();
        return response()->json(['message' => "Se eliminaron {$count} sesiones huérfanas", 'count' => $count]);
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
        return round($seconds / 86400, 1) . 'd';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
