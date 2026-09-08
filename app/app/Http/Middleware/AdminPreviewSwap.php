<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserAlertsInteligente;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa impersonación por-request cuando un admin visita una ruta de Mis Avisos
 * con `?as_user={id}`. La sesión real del admin se swap-rea y restaura con
 * try/finally para que el cambio sea estrictamente per-request (no se pega entre
 * recargas ni pestañas). El flag `admin_previewing_id` lo lee la Blade para
 * decidir si dibujar el banner de "Viendo como X".
 *
 * Reglas (mirrored en openspec/specs/mis-avisos-admin-preview):
 *  - Solo aplica si sesión actual es admin (`session('user')?->role === 'admin'`).
 *  - Solo si `as_user` es entero positivo.
 *  - Solo si el target tiene `user_alerts_inteligentes.enabled = true`.
 *  - Si el admin impersona a sí mismo (`as_user === session('user_id')`),
 *    no-op silencioso (no log, no swap, no banner).
 *  - Log único a canal `admin_preview` por request (ok o rejected).
 */
class AdminPreviewSwap
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminUser = session('user');
        // El proyecto guarda la sesión con claves planas (user_role), no con un
        // objeto `user`. Aceptar ambos para compatibilidad.
        $sessionRole = is_object($adminUser) ? ($adminUser->role ?? null) : session('user_role');
        $isAdmin = $sessionRole === 'admin';

        $rawAsUser = $request->input('as_user');
        $asUserIsNumeric = is_string($rawAsUser) && ctype_digit($rawAsUser);
        $asUserId = $asUserIsNumeric ? (int) $rawAsUser : null;

        // Sin admin o sin parámetro: pasa de largo sin tocar la sesión.
        if (!$isAdmin || $asUserId === null || $asUserId <= 0) {
            return $next($request);
        }

        $originalUserId = (int) Session::get('user_id');

        // Self-impersonation: no-op silencioso.
        if ($asUserId === $originalUserId) {
            return $next($request);
        }

        // Validar que el target existe y tiene el módulo activado.
        $targetExists = User::where('id', $asUserId)->exists();
        $moduleOk = $targetExists && UserAlertsInteligente::where('user_id', $asUserId)
            ->where('enabled', true)
            ->exists();

        if (!$moduleOk) {
            $adminId = (int) (is_object($adminUser) ? ($adminUser->id ?? 0) : session('user_id'));
            $this->log($adminId, $asUserId, 'rejected', $targetExists ? 'module_disabled' : 'no_such_user', $request);
            abort(404, 'No encontrado');
        }

        // SWAP — todo dentro del try/finally para garantizar restore.
        $adminId = (int) (is_object($adminUser) ? ($adminUser->id ?? $originalUserId) : $originalUserId);
        try {
            Session::put('user_id', $asUserId);
            Session::put('admin_previewing_id', $asUserId);
            Session::put('admin_original_user_id', $originalUserId);

            $this->log($adminId, $asUserId, 'ok', null, $request);

            return $next($request);
        } finally {
            // Restore INTEMPORAL: estos flags se eliminan al final de la request,
            // nunca se quedan en la sesión para el siguiente request.
            Session::put('user_id', $originalUserId);
            Session::forget('admin_previewing_id');
            Session::forget('admin_original_user_id');
        }
    }

    private function log(int $adminId, int $impersonatedId, string $result, ?string $reason, Request $request): void
    {
        try {
            // Canal efímero definido por-request con Log::build() — sin
            // necesidad de agregar config/logging.php. Escribe a
            // storage/logs/admin-preview.log en formato single.
            $logger = Log::build([
                'driver'   => 'single',
                'path'     => storage_path('logs/admin-preview.log'),
                'level'    => 'info',
                'replace'  => [],
            ]);
            $logger->info('preview.applied', [
                'admin_id'        => $adminId,
                'impersonated_id' => $impersonatedId,
                'result'          => $result,
                'reason'          => $reason,
                'method'          => $request->method(),
                'path'            => $request->path(),
                'ip'              => $request->ip(),
                'ua'              => substr((string) $request->userAgent(), 0, 200),
            ]);
        } catch (\Throwable $e) {
            // El log nunca debe romper el request.
        }
    }
}
