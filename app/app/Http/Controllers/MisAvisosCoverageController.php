<?php

namespace App\Http\Controllers;

use App\Services\Ia\WatermarkReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * avisos-scan-coverage-completion: cobertura para clientes (no admin).
 *
 * El cliente autenticado ve SOLO sus keywords con su cobertura por storage.
 * Puede solicitar rewind (con throttling estricto).
 *
 * Separado de AvisosInteligentesController (admin) para mantener la barrera
 * de permisos: este controller vive bajo el middleware 'auth' + 'misavisos'
 * (no 'admin'), mientras que AvisosInteligentesController vive bajo 'admin'.
 */
class MisAvisosCoverageController extends Controller
{
    /**
     * GET /mis-avisos/coverage?q=&storageId=&page=&per_page=
     * Devuelve SOLO las keywords del usuario autenticado.
     */
    public function index(Request $request)
    {
        $userId = (int) session('user_id');
        if (!$userId) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        $q = trim((string) $request->input('q', ''));
        $storageId = $request->input('storageId');
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100], true)
            ? (int) $request->input('per_page', 25)
            : 25;
        $page = max(1, (int) $request->input('page', 1));

        $builder = DB::table('keyword_scan_watermarks as w')
            ->join('keywords as k', 'k.id', '=', 'w.keyword_id')
            ->join('user_keyword as uk', function ($j) use ($userId) {
                $j->on('uk.keyword_id', '=', 'k.id')->where('uk.user_id', '=', $userId);
            })
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'w.storage_provider_id')
            ->orderBy('k.text')
            ->orderBy('sp.name');

        if ($storageId) {
            $builder->where('w.storage_provider_id', (int) $storageId);
        }
        if ($q !== '') {
            $builder->where('k.text', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
        }

        $paginator = $builder->paginate(perPage: $perPage, page: $page, columns: [
            'w.keyword_id',
            'w.storage_provider_id',
            'k.text as keyword_text',
            'sp.name as storage_name',
            'w.scanned_until',
            'w.last_hit_at',
            'w.hits_total',
        ]);

        return response()->json([
            'items' => collect($paginator->items())->map(fn ($r) => [
                'keyword_id' => (int) $r->keyword_id,
                'storage_provider_id' => (int) $r->storage_provider_id,
                'keyword_text' => $r->keyword_text,
                'storage_name' => $r->storage_name,
                'scanned_until' => $r->scanned_until ? (string) $r->scanned_until : null,
                'last_hit_at' => $r->last_hit_at ? (string) $r->last_hit_at : null,
                'hits_total' => (int) $r->hits_total,
                'pending_catchup' => $r->scanned_until === null,
            ])->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * POST /mis-avisos/rewind
     * Cliente solicita rewind de su keyword en un storage. Throttling 5/min.
     */
    public function rewind(Request $request)
    {
        $userId = (int) session('user_id');
        if (!$userId) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        $validated = $request->validate([
            'keyword_id' => 'required|integer|exists:keywords,id',
            'storage_provider_id' => 'required|integer|exists:storage_providers,id',
        ]);

        // Verificar que la keyword pertenece al cliente.
        $owns = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $validated['keyword_id'])
            ->exists();
        if (!$owns) {
            return response()->json(['error' => 'No tienes acceso a esa keyword'], 403);
        }

        $actorId = $userId; // El propio cliente es el actor.
        app(WatermarkReconciler::class)->rewindPair(
            (int) $validated['keyword_id'],
            (int) $validated['storage_provider_id'],
            $actorId,
        );

        return response()->json([
            'ok' => true,
            'message' => 'Watermark rewind solicitado. El catch-up se procesará en la próxima corrida.',
        ]);
    }
}
