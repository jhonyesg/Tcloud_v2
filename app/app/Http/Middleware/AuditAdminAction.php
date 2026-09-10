<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * avisos-scan-coverage-audit: registra la auditoría de operaciones admin
 * sensibles sobre watermarks (rewind, full scan).
 *
 * Aplica a los 2 endpoints que mutan keyword_scan_watermarks:
 *   POST /avisos-inteligentes/scan/rewind
 *   POST /avisos-inteligentes/scan/full
 *
 * Después de la response (no en error path), inserta una fila en
 * watermark_audit_log con actor=session('user_id'), action inferida del
 * path, y metadata completa del request. Si la response es error 4xx/5xx
 * también registra — un intento fallido también deja traza.
 *
 * Fire-and-forget: NO bloquea la response principal. Si watermark_audit_log
 * no existe todavía (entrega incremental pre-migración), la operación sigue
 * funcionando y se registra el skip silenciosamente.
 */
class AuditAdminAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $status = $response->getStatusCode();
            // Sólo registrar mutaciones exitosas o intentos fallidos significativos.
            // No registrar 4xx cliente-error triviales (e.g. validación).
            $shouldRecord = $status < 500 || $status >= 200;
            if (!$shouldRecord) {
                return $response;
            }

            $action = $this->resolveAction($request);
            if ($action === null) {
                return $response;
            }

            $this->audit($request, $action, $status);
        } catch (\Throwable $e) {
            Log::debug('audit_admin_action.skip', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function resolveAction(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        if (str_contains($path, '/scan/rewind')) {
            return 'rewind_pair';
        }
        if (str_contains($path, '/scan/full')) {
            return 'full_scan';
        }
        return null;
    }

    private function audit(Request $request, string $action, int $status): void
    {
        $actorId = $request->session()->get('user_id');
        $body = $request->all();

        DB::table('watermark_audit_log')->insert([
            'actor_user_id' => $actorId ? (int) $actorId : null,
            'action' => $action,
            'keyword_id' => isset($body['keyword_id']) ? (int) $body['keyword_id'] : null,
            'storage_id' => isset($body['storage_provider_id']) ? (int) $body['storage_provider_id'] : (isset($body['storageId']) ? (int) $body['storageId'] : null),
            'before_value' => null,
            'after_value' => null,
            'metadata' => json_encode([
                'path' => $request->path(),
                'method' => $request->method(),
                'status' => $status,
                'ip' => $request->ip(),
                'ua' => substr((string) $request->userAgent(), 0, 200),
                'body' => $body,
            ]),
            'created_at' => now(),
        ]);
    }
}
