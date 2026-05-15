## 1. SessionTracker — Fuente de verdad DB

- [x] 1.1 En `app/Http/Middleware/SessionTracker.php`, modificar el bloque `if (!$record)`: si `Session::has('user_id')` es true, llamar `Session::flush()` y retornar `redirect('/login')` con mensaje de error; si no hay user_id, continuar con `return $next($request)` como ahora

## 2. SessionService — Conteo activo basado en Redis

- [x] 2.1 En `app/Services/SessionService.php`, reescribir `countActiveSessions()` para iterar los registros no expirados del usuario y verificar `Redis::exists($session->session_id)` en cada uno; retornar solo el conteo de los que existen en Redis; usar fallback conservador (contar como activo) si Redis lanza excepción

## 3. SessionService — Logging en killSession

- [x] 3.1 En `app/Services/SessionService.php`, en el catch de `killSession()`, agregar `Log::warning('SessionService: failed to delete Redis key', ['session_id' => $session->session_id, 'error' => $e->getMessage()])` antes de continuar (agregar `use Illuminate\Support\Facades\Log;` al import)

## 4. Scheduled Cleanup — Sesiones huérfanas y expiradas

- [x] 4.1 En `routes/console.php`, agregar `Schedule::call(function() { app(SessionService::class)->cleanOrphans(); app(SessionService::class)->cleanExpired(); })->everyThirtyMinutes()->name('sessions:cleanup')->withoutOverlapping()` (agregar `use App\Services\SessionService;` al import del archivo)
