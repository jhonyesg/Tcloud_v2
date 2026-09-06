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

    public function index(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $perPage = min(200, max(10, (int) $request->get('per_page', 50)));
        $page = max(1, (int) $request->get('page', 1));

        $query = File::trashed()->orderByDesc('deleted_at');

        if (!$user->isAdmin()) {
            $query->where('owner_id', $user->id);
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

        if (!$user->isAdmin() && $item->owner_id !== $user->id) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $restored = $this->service->restore($item, $user->id);

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

        if (!$user->isAdmin() && $item->owner_id !== $user->id) {
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
}
