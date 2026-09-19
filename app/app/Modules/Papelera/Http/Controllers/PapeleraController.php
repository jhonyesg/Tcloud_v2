<?php

namespace App\Modules\Papelera\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\User;
use App\Modules\Papelera\Services\PapeleraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Papelera de reciclaje — endpoints web.
 *
 * Permisos:
 *  - index: autenticado ve lo suyo; admin ve todo.
 *  - restore: solo owner o admin.
 *  - destroy: solo owner o admin.
 *  - empty: solo el dueno; admin usa destroy por file_id para casos raros.
 */
class PapeleraController extends Controller
{
    public function __construct(private readonly PapeleraService $service)
    {
    }

    /**
     * GET /papelera — vista HTML de la papelera (modo navegador).
     * El mismo endpoint devuelve JSON cuando el cliente manda Accept: application/json
     * o X-Requested-With: XMLHttpRequest (consumido por Alpine en papelera.index).
     *
     * La vista Blade vive en app/resources/views/papelera/index.blade.php
     * (ubicación estándar del proyecto: debe ser descubrible por view('papelera.index')).
     */
    public function index(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }
            return redirect('/login');
        }

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return $this->indexJson($request, $user);
        }

        return view('papelera.index');
    }

    private function indexJson(Request $request, User $user): \Illuminate\Http\JsonResponse
    {
        $perPage = min(200, max(10, (int) $request->get('per_page', 50)));
        $page = max(1, (int) $request->get('page', 1));

        $query = File::trashed()->orderByDesc('deleted_at');

        // canonical-owner: filtro por acceso al STORAGE (no owner_id)
        if (!$user->isAdmin()) {
            $userStorageIds = $user->userStorages()->pluck('storage_provider_id')->all();
            $query->whereIn('storage_provider_id', $userStorageIds);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function (File $f) {
            return [
                'id' => $f->id,
                'name' => $f->name,
                'path' => $f->path,
                'is_folder' => (bool) $f->is_folder,
                'size' => (int) $f->size,
                'mime_type' => $f->mime_type,
                'storage_provider_id' => $f->storage_provider_id,
                'owner_id' => $f->owner_id,
                'deleted_at' => $f->deleted_at?->toIso8601String(),
                'days_remaining' => $this->service->daysRemaining($f),
                'original_parent_id' => $f->original_parent_id,
                'is_urgent' => $this->service->daysRemaining($f) <= (int) config('trash.urgent_threshold_days', 3),
            ];
        })->values();

        return response()->json([
            'items' => $items,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'stats' => $this->service->statsFor($user->id),
        ]);
    }

    public function restore(int $file, Request $request): JsonResponse
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $item = File::trashed()->find($file);
        if (!$item) {
            return response()->json(['error' => 'not_found_or_not_trashed'], 404);
        }

        // canonical-owner: filtro por acceso al STORAGE del item (no owner_id del item)
        if (!$user->isAdmin() && !$user->hasStoragePermission($item->storage_provider_id, 'read')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $restored = $this->service->restore($item, $user->id);

        // Invalidar el cache del folder de destino para que el archivo
        // restaurado aparezca en el browser sin esperar el TTL (60-300s).
        // Si el destino es root (parent_id NULL después del restore porque
        // el parent original estaba missing/trashed), invalidamos el root
        // listing. Mismo patrón que FileController@destroy.
        $syncService = app(\App\Services\StorageSyncService::class);
        $syncService->invalidateFolderCache(
            (int) $restored->storage_provider_id,
            $restored->parent_id
        );

        return response()->json([
            'message' => 'Restored',
            'file' => [
                'id' => $restored->id,
                'name' => $restored->name,
                'parent_id' => $restored->parent_id,
            ],
        ]);
    }

    public function destroy(int $file, Request $request): JsonResponse
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $item = File::trashed()->find($file);
        if (!$item) {
            return response()->json(['error' => 'not_found_or_not_trashed'], 404);
        }

        // canonical-owner: filtro por acceso al STORAGE del item (no owner_id del item)
        if (!$user->isAdmin() && !$user->hasStoragePermission($item->storage_provider_id, 'read')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $ok = $this->service->hardDelete($item, $user->id);
        if (!$ok) {
            return response()->json(['error' => 'has_active_links'], 409);
        }

        return response()->json(['message' => 'Hard deleted']);
    }

    public function empty(Request $request): JsonResponse
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $deleted = $this->service->emptyFor($user);

        return response()->json(['message' => 'Trash emptied', 'deleted' => $deleted]);
    }

    /**
     * POST /papelera/restore-many
     * Body: { "ids": [1,2,3,...] }
     * Restaura un subconjunto seleccionado de items en papelera.
     */
    public function restoreMany(Request $request): JsonResponse
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $ids = $request->input('ids');
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['error' => 'ids_required'], 422);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (empty($ids)) {
            return response()->json(['error' => 'ids_empty'], 422);
        }

        // Tope defensivo: si mandan 50k ids en una sola request, cortamos.
        if (count($ids) > 5000) {
            $ids = array_slice($ids, 0, 5000);
        }

        $result = $this->service->restoreMany($ids, $user);

        return response()->json([
            'message' => 'Restored batch',
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
        ]);
    }

    /**
     * POST /papelera/restore-all
     * Restaura todos los items en papelera del actor (o de todos si admin).
     */
    public function restoreAll(Request $request): JsonResponse
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $result = $this->service->restoreAll($user);

        return response()->json([
            'message' => 'Restored all',
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
            'total' => $result['total'] ?? 0,
        ]);
    }
}
