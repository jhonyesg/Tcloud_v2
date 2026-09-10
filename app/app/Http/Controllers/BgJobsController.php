<?php

namespace App\Http\Controllers;

use App\Services\BgJobRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint unificado de jobs en background activos.
 *
 * Consumido por el widget flotante del layout global. Devuelve un shape
 * normalizado independiente del módulo que originó el job.
 */
class BgJobsController extends Controller
{
    public function __construct(private readonly BgJobRegistry $registry)
    {
    }

    /**
     * GET /bg-jobs/active
     *
     * Retorna `{jobs: [...]}` con todos los jobs activos. Nunca lanza
     * excepciones al cliente: si el registry falla, devuelve array vacío
     * para que el polling del widget no se rompa.
     */
    public function active(Request $request): JsonResponse
    {
        try {
            $jobs = $this->registry->discover();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("bg_jobs.endpoint_failed: {$e->getMessage()}");
            $jobs = [];
        }

        return response()->json([
            'jobs' => $jobs,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
