<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use App\Modules\Correo\Services\NotificationService;
use App\Services\ShareAvailabilityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class ShareController extends Controller
{
    private const SORT_FIELDS = [
        'name',
        'created_at',
        'expires_at',
        'accesses',
        'size',
    ];

    public function __construct(
        private NotificationService $notificationService,
        private ShareAvailabilityService $availabilityService,
    ) {}

    private function getUser(): ?User
    {
        $userId = Session::get('user_id');

        return $userId ? User::find($userId) : null;
    }

    public function index(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$request->ajax() && !$request->wantsJson()) {
            return view('shares.index', ['shares' => collect()]);
        }

        $this->validateListRequest($request);
        $query = $this->shareQuery($request, $user);
        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Share $share) => $this->sharePayload($share))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'counters' => $this->shareCounters($query),
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'file_id' => 'required|exists:files,id',
            'permissions' => 'required|in:read,write,upload,full',
            'password' => 'nullable|string|min:4',
            'expires_at' => 'nullable|date|after:now',
            'never_expires' => 'nullable|boolean',
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
            } elseif ($file->owner_id !== $user->id) {
                return response()->json(['error' => 'Cannot share files you do not own'], 403);
            }
        }

        $share = Share::create([
            'file_id' => $file->id,
            'token' => Share::generateToken(),
            'password_hash' => $request->password ? Hash::make($request->password) : null,
            'expires_at' => $this->resolveCreationExpiry($request),
            'permissions' => $request->permissions,
            'created_by' => $user->id,
        ]);

        if ($request->boolean('send_email') && $request->email_destinatario) {
            $this->sendShareEmail($share, $file, $user, $request->email_destinatario);
        }

        return response()->json($this->sharePayload($share->load('file')), 201);
    }

    public function show(int $id)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $share = Share::with(['file.storageProvider'])->findOrFail($id);
        if (!$this->canManage($share, $user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($this->sharePayload($share));
    }

    public function update(Request $request, int $id)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $share = Share::findOrFail($id);
        if (!$this->canManage($share, $user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'permissions' => 'sometimes|in:read,write,upload,full',
            'password' => 'nullable|string|min:4',
            'expires_at' => 'nullable|date|after:now',
            'never_expires' => 'nullable|boolean',
        ]);

        $data = [];

        if ($request->has('permissions')) {
            $newPerm = $request->permissions;
            $creator = User::find($share->created_by);
            $file = $share->file;
            $canSet = false;

            if ($creator && $creator->isAdmin()) {
                $canSet = true;
            } elseif ($creator && $file && $file->storage_provider_id) {
                $canSet = $creator->hasStoragePermission($file->storage_provider_id, $newPerm);
            } elseif ($creator && $file) {
                $canSet = $file->owner_id === $share->created_by;
            }

            if (!$canSet) {
                return response()->json(['error' => 'Cannot set permission higher than your own level'], 403);
            }
            $data['permissions'] = $newPerm;
        }

        if ($request->boolean('never_expires')) {
            $data['expires_at'] = null;
        } elseif ($request->has('expires_at')) {
            $data['expires_at'] = $request->input('expires_at') ?: null;
        }

        if ($request->has('password')) {
            $data['password_hash'] = $request->password ? Hash::make($request->password) : null;
        }

        if ($data) {
            $share->update($data);
            Cache::forget("share:meta:{$share->token}");
        }

        return response()->json($this->sharePayload($share->fresh(['file.storageProvider'])));
    }

    public function destroy(int $id)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $share = Share::findOrFail($id);
        if (!$this->canManage($share, $user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Cache::forget("share:meta:{$share->token}");
        $share->delete();

        return response()->json(['message' => 'Share deleted']);
    }

    public function bulkPreview(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateBulkRequest($request);
        $query = $this->selectedSharesQuery($request, $user);
        $total = (clone $query)->count('shares.id');
        $limit = (int) config('shares.max_bulk_items', 500);

        if ($total > $limit) {
            return response()->json([
                'error' => "La operación está limitada a {$limit} enlaces por vez",
                'count' => $total,
                'limit' => $limit,
            ], 422);
        }

        $shares = $query->get(['shares.id', 'shares.permissions', 'shares.expires_at', 'shares.file_id']);
        $missing = $shares->filter(fn (Share $share) => $share->file?->availability_state === 'missing')->count();
        $unknown = $shares->filter(fn (Share $share) => ($share->file?->availability_state ?? 'unknown') === 'unknown')->count();

        return response()->json([
            'count' => $total,
            'summary' => [
                'expired' => $shares->filter(fn (Share $share) => $share->isExpired())->count(),
                'permanent' => $shares->whereNull('expires_at')->count(),
                'missing' => $missing,
                'unknown' => $unknown,
                'permissions' => $shares->groupBy('permissions')->map->count(),
            ],
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateBulkRequest($request);
        $query = $this->selectedSharesQuery($request, $user);
        $requestedIds = collect($request->input('ids', []))->map(fn ($id) => (int) $id)->unique()->values();
        $allMatching = $request->boolean('all_matching');
        $total = (clone $query)->count('shares.id');
        $limit = (int) config('shares.max_bulk_items', 500);

        if ($total > $limit) {
            return response()->json([
                'error' => "La operación está limitada a {$limit} enlaces por vez",
                'count' => $total,
                'limit' => $limit,
            ], 422);
        }

        $confirmedCount = (int) $request->input('confirm_count', -1);
        if ($confirmedCount !== $total) {
            return response()->json([
                'error' => 'La selección cambió. Vuelve a previsualizar antes de confirmar.',
                'current_count' => $total,
            ], 409);
        }

        $shares = $query->get(['shares.id', 'shares.token']);
        $authorizedIds = $shares->pluck('id')->map(fn ($id) => (int) $id);
        $omittedIds = $allMatching ? collect() : $requestedIds->diff($authorizedIds)->values();

        try {
            DB::transaction(function () use ($authorizedIds) {
                foreach ($authorizedIds->chunk(100) as $chunk) {
                    Share::whereIn('id', $chunk->all())->delete();
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'No se pudo completar la depuración'], 500);
        }

        foreach ($shares as $share) {
            Cache::forget("share:meta:{$share->token}");
        }

        \Illuminate\Support\Facades\Log::warning('shares.bulk_delete.completed', [
            'user_id' => $user->id,
            'deleted_count' => $authorizedIds->count(),
            'omitted_count' => $omittedIds->count(),
            'all_matching' => $allMatching,
            'confirm_count' => $confirmedCount,
        ]);

        return response()->json([
            'message' => 'Depuración completada',
            'deleted_count' => $authorizedIds->count(),
            'omitted_count' => $omittedIds->count(),
            'failed_count' => 0,
            'omitted_ids' => $omittedIds,
        ]);
    }

    public function verifyAvailability(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateBulkRequest($request);
        $query = $this->selectedSharesQuery($request, $user);
        $batchLimit = max(1, min((int) $request->input('limit', config('shares.availability_verification_limit', 100)), 200));
        $query->limit($batchLimit);

        $shares = $query->with(['file.storageProvider'])->get(['shares.id', 'shares.file_id']);
        $summary = $this->availabilityService->verify($shares->pluck('file'));

        return response()->json([
            'message' => 'Verificación completada',
            ...$summary,
            'limit' => $batchLimit,
            'has_more' => $shares->count() >= $batchLimit,
        ]);
    }

    private function validateListRequest(Request $request): void
    {
        $request->validate([
            'q' => 'nullable|string|max:200',
            'search' => 'nullable|string|max:200',
            'file_id' => 'nullable|integer',
            'permission' => 'nullable|in:read,write,upload,full',
            'permissions' => 'nullable|in:read,write,upload,full',
            'status' => 'nullable|in:active,expired,never',
            'availability' => 'nullable|in:available,missing,unknown',
            'storage_id' => 'nullable|integer',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date',
            'expires_from' => 'nullable|date',
            'expires_to' => 'nullable|date',
            'accessed_from' => 'nullable|date',
            'accessed_to' => 'nullable|date',
            'sort' => 'nullable|in:name,created_at,expires_at,accesses,size',
            'direction' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function validateBulkRequest(Request $request): void
    {
        $request->validate([
            'ids' => 'nullable|array|max:500',
            'ids.*' => 'integer|min:1',
            'all_matching' => 'nullable|boolean',
            'confirm_count' => 'nullable|integer|min:0',
        ]);

        if (!$request->boolean('all_matching') && !count($request->input('ids', []))) {
            throw ValidationException::withMessages(['ids' => 'Selecciona al menos un enlace o todos los resultados filtrados.']);
        }

        $this->validateListRequest($request);
    }

    private function shareQuery(Request $request, User $user): Builder
    {
        $query = Share::query()
            ->where('shares.created_by', $user->id)
            ->with(['file.storageProvider'])
            ->withCount('accessLogs')
            ->withMax('accessLogs', 'accessed_at');

        if ($request->filled('file_id')) {
            $query->where('shares.file_id', (int) $request->input('file_id'));
        }

        $search = trim((string) ($request->input('q') ?: $request->input('search', '')));
        if ($search !== '') {
            $query->whereHas('file', function (Builder $fileQuery) use ($search) {
                $fileQuery->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('path', 'ILIKE', "%{$search}%");
            });
        }

        $permission = $request->input('permission') ?: $request->input('permissions');
        if ($permission) {
            $query->where('shares.permissions', $permission);
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'expired' => $query->whereNotNull('shares.expires_at')->where('shares.expires_at', '<', now()),
                'never' => $query->whereNull('shares.expires_at'),
                'active' => $query->where(function (Builder $statusQuery) {
                    $statusQuery->whereNull('shares.expires_at')->orWhere('shares.expires_at', '>=', now());
                }),
            };
        }

        if ($request->filled('availability')) {
            $availability = $request->input('availability');
            $query->whereHas('file', fn (Builder $fileQuery) => $fileQuery->where('availability_state', $availability));
        }

        if ($request->filled('storage_id')) {
            $query->whereHas('file', fn (Builder $fileQuery) => $fileQuery->where('storage_provider_id', (int) $request->input('storage_id')));
        }

        $this->applyDateRange($query, 'shares.created_at', $request->input('created_from'), $request->input('created_to'));
        $this->applyDateRange($query, 'shares.expires_at', $request->input('expires_from'), $request->input('expires_to'));

        if ($request->filled('accessed_from') || $request->filled('accessed_to')) {
            $query->whereHas('accessLogs', function (Builder $logQuery) use ($request) {
                $this->applyDateRange($logQuery, 'accessed_at', $request->input('accessed_from'), $request->input('accessed_to'));
            });
        }

        $sort = $request->input('sort') ?: 'created_at';
        $direction = $request->input('direction') ?: 'desc';

        match ($sort) {
            'name' => $query->orderBy(File::select('name')->whereColumn('files.id', 'shares.file_id'), $direction),
            'size' => $query->orderBy(File::select('size')->whereColumn('files.id', 'shares.file_id'), $direction),
            'accesses' => $query->orderBy('access_logs_count', $direction),
            default => $query->orderBy("shares.{$sort}", $direction),
        };

        return $query->orderBy('shares.id', 'desc');
    }

    private function selectedSharesQuery(Request $request, User $user): Builder
    {
        $query = $this->shareQuery($request, $user)->reorder('shares.id');
        $allMatching = $request->boolean('all_matching');

        if (!$allMatching) {
            $query->whereIn('shares.id', collect($request->input('ids', []))->map(fn ($id) => (int) $id)->unique()->all());
        }

        return $query;
    }

    private function applyDateRange(Builder $query, string $column, mixed $from, mixed $to): void
    {
        if ($from) {
            $query->where($column, '>=', $this->dateBoundary($from, false));
        }
        if ($to) {
            $query->where($column, '<', $this->dateBoundary($to, true));
        }
    }

    private function dateBoundary(mixed $value, bool $end): Carbon
    {
        $date = Carbon::parse((string) $value, config('app.timezone'));

        return strlen((string) $value) <= 10
            ? ($end ? $date->startOfDay()->addDay() : $date->startOfDay())
            : $date;
    }

    private function shareCounters(Builder $query): array
    {
        $count = fn (Builder $base) => (clone $base)->withoutEagerLoads()->reorder()->count('shares.id');
        $expired = (clone $query)->whereNotNull('shares.expires_at')->where('shares.expires_at', '<', now());
        $permanent = (clone $query)->whereNull('shares.expires_at');
        $missing = (clone $query)->whereHas('file', fn (Builder $fileQuery) => $fileQuery->where('availability_state', 'missing'));
        $unknown = (clone $query)->whereHas('file', fn (Builder $fileQuery) => $fileQuery->where('availability_state', 'unknown'));

        return [
            'total' => $count($query),
            'expired' => $count($expired),
            'permanent' => $count($permanent),
            'missing' => $count($missing),
            'unknown' => $count($unknown),
        ];
    }

    private function sharePayload(Share $share): array
    {
        $file = $share->file;
        $isExpired = $share->isExpired();

        return [
            'id' => $share->id,
            'file_id' => $share->file_id,
            'token' => $share->token,
            'public_url' => url('/s/' . $share->token),
            'permissions' => $share->permissions,
            'expires_at' => $this->isoDate($share->expires_at),
            'created_at' => $this->isoDate($share->created_at),
            'updated_at' => $this->isoDate($share->updated_at),
            'is_expired' => $isExpired,
            'expiry_status' => $share->expires_at === null ? 'never' : ($isExpired ? 'expired' : 'active'),
            'has_password' => !is_null($share->password_hash),
            'access_logs_count' => (int) ($share->access_logs_count ?? 0),
            'last_accessed_at' => $this->isoDate($share->access_logs_max_accessed_at),
            'file' => $file ? [
                'id' => $file->id,
                'name' => $file->name,
                'path' => $file->path,
                'size' => (int) $file->size,
                'mime_type' => $file->mime_type,
                'is_folder' => (bool) $file->is_folder,
                'storage_provider_id' => $file->storage_provider_id,
                'availability_state' => $file->availability_state ?? 'unknown',
                'last_verified_at' => $this->isoDate($file->last_verified_at),
                'missing_since_at' => $this->isoDate($file->missing_since_at),
            ] : null,
        ];
    }

    private function canManage(Share $share, User $user): bool
    {
        return $share->created_by === $user->id || $user->isAdmin();
    }

    private function isoDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        return $value instanceof Carbon
            ? $value->toIso8601String()
            : Carbon::parse((string) $value, config('app.timezone'))->toIso8601String();
    }

    private function resolveCreationExpiry(Request $request): ?Carbon
    {
        if ($request->boolean('never_expires')) {
            return null;
        }

        if ($request->filled('expires_at')) {
            return Carbon::parse($request->input('expires_at'), config('app.timezone'));
        }

        return now()->addDays(max(0, (int) config('shares.default_expiry_days', 30)));
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
            'write', 'upload' => 2,
            'full' => 3,
            default => 0,
        };
    }
}
