<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use App\Modules\Correo\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ShareController extends Controller
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    public function index(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $base = Share::where('created_by', $user->id)->with('file');

        // Contadores totales del usuario (antes de paginar/filtros de búsqueda)
        $allUserShares = (clone $base)->get();
        $now = now();
        $counters = [
            'total'     => $allUserShares->count(),
            'expired'   => $allUserShares->filter(fn($s) => $s->expires_at && $s->expires_at->lt($now))->count(),
            'permanent' => $allUserShares->filter(fn($s) => !$s->expires_at)->count(),
            'missing'   => $allUserShares->filter(fn($s) => $s->file === null)->count(),
            'unknown'   => 0,
        ];

        $query = (clone $base);

        if ($request->filled('file_id')) {
            $query->where('file_id', $request->file_id);
        }
        if ($request->filled('permission')) {
            $query->where('permissions', $request->permission);
        }
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(function ($q) use ($term) {
                $q->where('token', 'ilike', $term)
                  ->orWhereHas('file', fn($f) => $f->where('name', 'ilike', $term)
                                                     ->orWhere('path', 'ilike', $term));
            });
        }
        if ($request->filled('status')) {
            if ($request->status === 'expired') {
                $query->whereNotNull('expires_at')->where('expires_at', '<', $now);
            } elseif ($request->status === 'permanent') {
                $query->whereNull('expires_at');
            } elseif ($request->status === 'active') {
                $query->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                });
            }
        }

        $sort = in_array($request->sort, ['created_at', 'expires_at']) ? $request->sort : 'created_at';
        $direction = $request->direction === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min(100, (int) $request->per_page ?: 25));
        $page    = max(1, (int) $request->page ?: 1);

        $query->orderBy($sort, $direction);
        $total = (clone $query)->count();
        $shares = $query->withCount('accessLogs')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function ($share) {
                $share->is_expired   = $share->isExpired();
                $share->has_password = !is_null($share->password_hash);
                $share->public_url   = url('/s/' . $share->token);
                return $share;
            });

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'data'     => $shares,
                'meta'     => [
                    'current_page' => $page,
                    'last_page'    => max(1, (int) ceil($total / $perPage)),
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
                'counters' => $counters,
            ]);
        }

        return view('shares.index', ['shares' => $shares]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'file_id' => 'required|exists:files,id',
            'permissions' => 'required|in:read,write,upload,full',
            'password' => 'nullable|string|min:4',
            'expires_at' => 'nullable|date|after:now',
            'send_email' => 'nullable|boolean',
            'email_destinatario' => 'nullable|email',
        ]);

        $file = File::findOrFail($request->file_id);

        if (!$user->isAdmin()) {
            if ($file->storage_provider_id) {
                if (!$user->canCreateSharesInStorage($file->storage_provider_id)) {
                    return response()->json(['error' => 'Cannot create shares in this storage'], 403);
                }

                $userPermission = $this->getUserPermissionLevel($user, $file);
                $requiredLevel = $this->getPermissionLevel($request->permissions);

                if ($userPermission < $requiredLevel) {
                    return response()->json(['error' => 'Cannot share with higher permissions than you have'], 403);
                }
            } else {
                if ($file->owner_id !== $user->id) {
                    return response()->json(['error' => 'Cannot share files you do not own'], 403);
                }
            }
        }

        $share = Share::create([
            'file_id' => $file->id,
            'path_snapshot' => $file->path,
            'token' => Str::random(32),
            'password_hash' => $request->password ? Hash::make($request->password) : null,
            'expires_at' => $request->expires_at,
            'permissions' => $request->permissions,
            'created_by' => $user->id,
        ]);

        if ($request->boolean('send_email') && $request->email_destinatario) {
            $this->sendShareEmail($share, $file, $user, $request->email_destinatario);
        }

        return response()->json($share, 201);
    }

    public function show(int $id)
    {
        $share = Share::with('file')->findOrFail($id);
        return response()->json($share);
    }

    public function update(Request $request, int $id)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $share = Share::findOrFail($id);

        if ($share->created_by !== $user->id && !$user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'permissions' => 'sometimes|in:read,write,upload,full',
            'password' => 'nullable|string|min:4',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $data = [];

        if ($request->has('permissions')) {
            $newPerm = $request->permissions;
            $creator = User::find($share->created_by);
            $file = $share->file;
            $canSet = false;
            if ($creator && $creator->isAdmin()) {
                $canSet = true;
            } elseif ($file && $file->storage_provider_id) {
                $canSet = $creator->hasStoragePermission($file->storage_provider_id, $newPerm);
            } elseif ($file) {
                $canSet = $file->owner_id === $share->created_by;
            }
            if (!$canSet) {
                return response()->json(['error' => 'Cannot set permission higher than your own level'], 403);
            }
            $data['permissions'] = $newPerm;
        }

        if ($request->has('expires_at')) {
            $data['expires_at'] = $request->expires_at;
        }

        if ($request->has('password')) {
            $data['password_hash'] = $request->password ? Hash::make($request->password) : null;
        }

        if (!empty($data)) {
            $share->update($data);
            Cache::forget("share:meta:{$share->token}");
        }

        return response()->json($share);
    }

    public function destroy(int $id)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $share = Share::findOrFail($id);

        if ($share->created_by !== $user->id && !$user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Cache::forget("share:meta:{$share->token}");
        Cache::forget("share:meta:{$share->token}");
        $share->delete();

        return response()->json(['message' => 'Share deleted']);
    }

    private function getUserPermissionLevel(User $user, File $file): int
    {
        if ($file->storage_provider_id) {
            $userStorage = $user->userStorages()->where('storage_provider_id', $file->storage_provider_id)->first();
            if (!$userStorage) return 0;
            return $this->getPermissionLevel($userStorage->permissions);
        }
        return $file->owner_id === $user->id ? 4 : 0;
    }

    private function sendShareEmail(Share $share, File $file, User $creator, string $destinatario): void
    {
        $this->notificationService->send(
            'compartir-enlace',
            $destinatario,
            [
                'nombre_destinatario' => $destinatario,
                'nombre_remitente' => $creator->email,
                'nombre_archivo' => $file->name,
                'enlace_compartido' => url('/s/' . $share->token),
            ]
        );
    }

    private function getPermissionLevel(string $permission): int
    {
        return match ($permission) {
            'read' => 1,
            'write' => 2,
            'upload' => 2,
            'full' => 3,
            default => 0,
        };
    }
}
