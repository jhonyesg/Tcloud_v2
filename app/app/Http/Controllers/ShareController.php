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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Administracion de enlaces compartidos.
 *
 * Contrato: `openspec/specs/share-management`, `share-security`,
 * `share-file-availability`, `share-link-lifecycle` y `shares-tabs-bulk-delete`.
 *
 * Invariantes del modulo:
 *  - Una sola fuente de alcance (`shareQuery`). El listado, la previsualizacion,
 *    la depuracion y la verificacion resuelven SIEMPRE el mismo conjunto, de
 *    modo que lo que el usuario previsualiza es lo que se ejecuta.
 *  - Un solo serializador (`sharePayload`). Las cuatro respuestas que devuelven
 *    un share (index, show, store, update) tienen la misma forma y ninguna
 *    expone `password_hash`.
 *  - El estado de expiracion y el de disponibilidad son vocabularios cerrados:
 *    `never|active|expired` y `available|missing|unknown`. Un valor no
 *    reconocido se rechaza con 422 en lugar de ignorarse en silencio.
 */
class ShareController extends Controller
{
    /** Filtros de estado de expiracion reconocidos por el contrato. */
    private const STATUS_VALUES = ['active', 'expired', 'never'];

    /** Filtros de disponibilidad de recurso. */
    private const AVAILABILITY_VALUES = ['available', 'missing', 'unknown'];

    /** Campos de ordenamiento admitidos. */
    private const SORT_VALUES = ['name', 'created_at', 'expires_at', 'accesses', 'size', 'permission', 'status'];

    private NotificationService $notificationService;
    private ShareAvailabilityService $availabilityService;

    public function __construct(
        NotificationService $notificationService,
        ShareAvailabilityService $availabilityService
    ) {
        $this->notificationService = $notificationService;
        $this->availabilityService = $availabilityService;
    }

    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateListRequest($request);

        $query = $this->shareQuery($request, $user);
        $sort = (string) ($request->input('sort') ?: 'created_at');
        $direction = (string) ($request->input('direction') ?: 'desc');

        $counters = $this->shareCounters($query);
        $query = $this->applySort($query, $sort, $direction);

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

        $payload = [
            'data' => $paginator->getCollection()->map(fn (Share $share) => $this->sharePayload($share))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counters' => $counters,
        ];

        // La vista se monta sola con Alpine (`init()` -> `loadShares()`), que
        // pide este mismo endpoint con `Accept: application/json`. Una
        // navegacion normal del navegador NO es una peticion JSON: debe
        // devolver la pagina, no el volcado crudo del JSON.
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json($payload);
        }

        return view('shares.index', [
            'shares' => $paginator->getCollection(),
            'counters' => $counters,
        ]);
    }

    /**
     * Unica fuente de alcance del modulo.
     *
     * Aplica los filtros (texto, permiso, estado, disponibilidad, storage y
     * rangos de fechas) sobre los shares del usuario. NO aplica ordenamiento:
     * eso lo hace `applySort`, para que previsualizacion y ejecucion compartan
     * exactamente el mismo conjunto.
     */
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

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function (Builder $scope) use ($term) {
                $scope->where('shares.token', 'ilike', $term)
                    ->orWhereHas('file', function (Builder $fileQuery) use ($term) {
                        $fileQuery->where('name', 'ilike', $term)
                            ->orWhere('path', 'ilike', $term);
                    });
            });
        }

        $permission = $request->input('permission');
        if ($permission) {
            $query->where('shares.permissions', $permission);
        }

        $status = $request->input('status');
        if ($status) {
            // Los tres estados particionan el conjunto: `never` (sin fecha),
            // `expired` (fecha pasada) y `active` (fecha futura). `active` NO
            // incluye los enlaces sin vencimiento: el filtro debe seleccionar
            // exactamente un valor de `expiry_status`.
            match ($status) {
                'expired' => $query->whereNotNull('shares.expires_at')
                    ->where('shares.expires_at', '<', now()),
                'never' => $query->whereNull('shares.expires_at'),
                'active' => $query->whereNotNull('shares.expires_at')
                    ->where('shares.expires_at', '>=', now()),
                default => null,
            };
        }

        $availability = $request->input('availability');
        if ($availability) {
            $this->applyAvailabilityFilter($query, $availability);
        }

        if ($request->filled('storage_id')) {
            $storageId = (int) $request->input('storage_id');
            $query->whereHas('file', fn (Builder $fileQuery) => $fileQuery->where('storage_provider_id', $storageId));
        }

        $this->applyDateRange($query, 'shares.created_at', $request->input('created_from'), $request->input('created_to'));
        $this->applyDateRange($query, 'shares.expires_at', $request->input('expires_from'), $request->input('expires_to'));

        if ($request->filled('accessed_from') || $request->filled('accessed_to')) {
            $query->whereHas('accessLogs', function (Builder $logQuery) use ($request) {
                $this->applyDateRange($logQuery, 'accessed_at', $request->input('accessed_from'), $request->input('accessed_to'));
            });
        }

        return $query;
    }

    /**
     * El estado persistido puede ser NULL (recurso nunca verificado). Para el
     * filtro `unknown` eso equivale a "sin verificacion confiable", porque el
     * recurso NO esta confirmado ausente: un NULL nunca puede afirmar que el
     * archivo se borro.
     */
    private function applyAvailabilityFilter(Builder $query, string $availability): void
    {
        $query->whereHas('file', function (Builder $fileQuery) use ($availability) {
            if ($availability === 'unknown') {
                $fileQuery->where(function (Builder $scope) {
                    $scope->where('availability_state', 'unknown')
                        ->orWhereNull('availability_state');
                });

                return;
            }

            $fileQuery->where('availability_state', $availability);
        });
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

    /**
     * Un rango de fecha "2026-10-27" cubre el dia completo: desde las 00:00
     * hasta el inicio del dia siguiente. Un rango con hora se usa tal cual.
     */
    private function dateBoundary(mixed $value, bool $end): Carbon
    {
        $date = Carbon::parse((string) $value, config('app.timezone'));

        return strlen((string) $value) <= 10
            ? ($end ? $date->startOfDay()->addDay() : $date->startOfDay())
            : $date;
    }

    /**
     * Ordenamiento con la agrupacion que exige la spec:
     *  - `permission` agrupa por NIVEL (Lectura, Escritura/Subida, Completo),
     *    no por orden alfabetico de la cadena almacenada.
     *  - `status` agrupa por grupo (Sin vencimiento, Activo, Expirado), no por
     *    valor crudo de `expires_at`.
     *
     * El desempate es `shares.id`, siempre en la misma direccion, para que el
     * cursor por keyset sea consistente.
     */
    private function applySort(Builder $query, string $sort, string $direction, ?string $frozenNow = null): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $frozenNow = $frozenNow ?: now()->format('Y-m-d H:i:s');

        [$expression, $bindings] = $this->sortExpression($sort, $frozenNow);

        $query->orderByRaw($expression . ' ' . $direction, $bindings);
        $query->orderBy('shares.id', $direction);

        return $query;
    }

    /**
     * Expresion SQL de la clave de ordenamiento.
     *
     * Las claves NULLABLES se envuelven en COALESCE con un centinela para que
     * la comparacion del cursor sea determinista. Sin esto, una fila con
     * `expires_at` nulo hace que `expr = ?` sea NULL (no verdadero) y la fila
     * queda fuera del recorrido: el cursor se saltaria shares.
     *
     * El centinela debe coincidir EXACTAMENTE con el que usa `cursorValue()`.
     *
     * @return array{0: string, 1: array<int,mixed>}
     */
    private function sortExpression(string $sort, string $frozenNow): array
    {
        return match ($sort) {
            'name' => ["COALESCE((SELECT f.name FROM files f WHERE f.id = shares.file_id), '')", []],
            'size' => ['COALESCE((SELECT f.size FROM files f WHERE f.id = shares.file_id), 0)', []],
            'accesses' => ['(SELECT COUNT(*) FROM share_access_log l WHERE l.share_id = shares.id)', []],
            'expires_at' => ["COALESCE(shares.expires_at, TIMESTAMP '1970-01-01')", []],
            'permission' => [
                "CASE shares.permissions WHEN 'read' THEN 1 WHEN 'write' THEN 2 WHEN 'upload' THEN 2 WHEN 'full' THEN 3 ELSE 4 END",
                [],
            ],
            'status' => [
                'CASE WHEN shares.expires_at IS NULL THEN 1 WHEN shares.expires_at < ? THEN 3 ELSE 2 END',
                [$frozenNow],
            ],
            default => ["COALESCE(shares.created_at, TIMESTAMP '1970-01-01')", []],
        };
    }

    /**
     * Contadores de administracion.
     *
     * Se calculan con agregaciones SQL sobre el conjunto FILTRADO, sin
     * hidratar la coleccion completa de shares.
     */
    private function shareCounters(Builder $query): array
    {
        $count = fn (Builder $base) => (clone $base)->withoutEagerLoads()->reorder()->count('shares.id');

        $expired = (clone $query)->whereNotNull('shares.expires_at')->where('shares.expires_at', '<', now());
        $permanent = (clone $query)->whereNull('shares.expires_at');
        $missing = (clone $query)->whereHas('file', fn (Builder $f) => $f->where('availability_state', 'missing'));
        $unknown = (clone $query)->whereHas('file', function (Builder $f) {
            $f->where(fn (Builder $scope) => $scope->where('availability_state', 'unknown')->orWhereNull('availability_state'));
        });

        return [
            'total' => $count($query),
            'expired' => $count($expired),
            'permanent' => $count($permanent),
            'missing' => $count($missing),
            'unknown' => $count($unknown),
        ];
    }

    /**
     * Serializador unico de share. Toda respuesta que devuelva un share pasa
     * por aqui: la forma es identica y `password_hash` nunca sale.
     */
    private function sharePayload(Share $share): array
    {
        $file = $share->file;
        $isExpired = $share->isExpired();

        return [
            'id' => (int) $share->id,
            'file_id' => (int) $share->file_id,
            'token' => $share->token,
            'public_url' => url('/s/' . $share->token),
            'permissions' => $share->permissions,
            'expires_at' => $this->isoDate($share->expires_at),
            'created_at' => $this->isoDate($share->created_at),
            'updated_at' => $this->isoDate($share->updated_at),
            'is_expired' => $isExpired,
            'expiry_status' => $share->expires_at === null ? 'never' : ($isExpired ? 'expired' : 'active'),
            'has_password' => ! is_null($share->password_hash),
            'access_logs_count' => (int) ($share->access_logs_count ?? 0),
            'last_accessed_at' => $this->isoDate($share->access_logs_max_accessed_at),
            'file' => $file ? [
                'id' => (int) $file->id,
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

    private function isoDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon
            ? $value->toIso8601String()
            : Carbon::parse((string) $value, config('app.timezone'))->toIso8601String();
    }

    // ------------------------------------------------------------------
    // Validacion
    // ------------------------------------------------------------------

    private function validateListRequest(Request $request): void
    {
        $request->validate([
            'q' => 'nullable|string|max:200',
            'file_id' => 'nullable|integer|min:1',
            'permission' => 'nullable|in:read,write,upload,full',
            'status' => 'nullable|in:' . implode(',', self::STATUS_VALUES),
            'availability' => 'nullable|in:' . implode(',', self::AVAILABILITY_VALUES),
            'storage_id' => 'nullable|integer|min:1',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date',
            'expires_from' => 'nullable|date',
            'expires_to' => 'nullable|date',
            'accessed_from' => 'nullable|date',
            'accessed_to' => 'nullable|date',
            'sort' => 'nullable|in:' . implode(',', self::SORT_VALUES),
            'direction' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function validateBulkRequest(Request $request): void
    {
        $limit = $this->bulkLimit();

        $request->validate([
            'ids' => 'nullable|array|max:' . $limit,
            'ids.*' => 'integer|min:1',
            'all_matching' => 'nullable|boolean',
            'confirm_count' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:500',
            'cursor' => 'nullable|string|max:500',
        ]);

        if (! $request->boolean('all_matching') && ! count($request->input('ids', []))) {
            throw ValidationException::withMessages([
                'ids' => 'Selecciona al menos un enlace o la opcion de todos los resultados filtrados.',
            ]);
        }

        $this->validateListRequest($request);
    }

    private function bulkLimit(): int
    {
        return max(1, (int) config('shares.max_bulk_items', 200));
    }

    // ------------------------------------------------------------------
    // Alta / edicion / revocacion
    // ------------------------------------------------------------------

    public function store(Request $request)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'file_id' => 'required|exists:files,id',
            'permissions' => 'required|in:read,write,upload,full',
            'password' => 'nullable|string|min:4',
            'expires_at' => 'nullable|date',
            'never_expires' => 'nullable|boolean',
            'send_email' => 'nullable|boolean',
            'email_destinatario' => 'nullable|email',
        ]);

        $file = File::findOrFail($request->file_id);

        if (! $user->isAdmin()) {
            $ownsFile = (int) $file->owner_id === (int) $user->id;
            $hasPermission = $user->hasStoragePermission((int) $file->storage_provider_id, 'read');

            if (! $ownsFile && ! $hasPermission) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        }

        $share = Share::create([
            'file_id' => $file->id,
            'path_snapshot' => $file->path,
            'token' => Str::random(32),
            'password_hash' => $request->filled('password') ? Hash::make($request->input('password')) : null,
            'expires_at' => $this->resolveCreationExpiry($request),
            'permissions' => $request->input('permissions'),
            'created_by' => $user->id,
        ]);

        if ($request->boolean('send_email') && $request->filled('email_destinatario')) {
            try {
                $this->sendShareEmail($share, $file, $user, (string) $request->input('email_destinatario'));
            } catch (Throwable $e) {
                Log::warning('shares.store.email_failed', ['share_id' => $share->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json($this->sharePayload($this->reloadShare($share)), 201);
    }

    /**
     * Recarga el share con las relaciones y agregados que necesita el payload.
     */
    private function reloadShare(Share $share): Share
    {
        return Share::with(['file.storageProvider'])
            ->withCount('accessLogs')
            ->withMax('accessLogs', 'accessed_at')
            ->find($share->id) ?? $share;
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

    public function show(int $id)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $share = Share::with(['file.storageProvider'])->withCount('accessLogs')->withMax('accessLogs', 'accessed_at')->find($id);
        if (! $share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ((int) $share->created_by !== (int) $user->id && ! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($this->sharePayload($share));
    }

    public function update(Request $request, int $id)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $share = Share::with('file')->find($id);
        if (! $share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ((int) $share->created_by !== (int) $user->id && ! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'permissions' => 'nullable|in:read,write,upload,full',
            'password' => 'nullable|string|min:4',
            'expires_at' => 'nullable|date',
            'never_expires' => 'nullable|boolean',
        ]);

        $creator = User::find($share->created_by);
        $file = $share->file;

        if ($request->filled('permissions')) {
            $newPermission = $request->input('permissions');

            if ($creator && $file && ! $creator->isAdmin()) {
                $canSet = $creator->hasStoragePermission((int) $file->storage_provider_id, $newPermission);
                if (! $canSet) {
                    return response()->json(['error' => 'Forbidden'], 403);
                }
            }

            $data['permissions'] = $newPermission;
        }

        if ($request->has('password')) {
            $data['password_hash'] = $request->filled('password')
                ? Hash::make($request->input('password'))
                : null;
        }

        if ($request->boolean('never_expires') || $request->has('expires_at')) {
            $data['expires_at'] = $this->resolveUpdateExpiry($request);
        }

        $share->fill($data);
        $share->save();

        Cache::forget("share:meta:{$share->token}");

        return response()->json($this->sharePayload($this->reloadShare($share)));
    }

    public function destroy(int $id)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $share = Share::find($id);
        if (! $share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ((int) $share->created_by !== (int) $user->id && ! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Cache::forget("share:meta:{$share->token}");
        $share->delete();

        return response()->json(['message' => 'Share deleted']);
    }

    /**
     * Politica de expiracion al crear un enlace.
     *
     * El contrato (`share-link-lifecycle`) exige una politica vigente: si la
     * solicitud OMITE la fecha y NO marca la no expiracion explicita, se
     * aplica `SHARE_DEFAULT_EXPIRY_DAYS`. La ausencia de fecha NO significa
     * "nunca".
     */
    private function resolveCreationExpiry(Request $request): ?Carbon
    {
        if ($request->boolean('never_expires')) {
            return null;
        }

        if ($request->filled('expires_at')) {
            $expires = Carbon::parse((string) $request->input('expires_at'), config('app.timezone'));

            if ($expires->isPast()) {
                throw ValidationException::withMessages([
                    'expires_at' => 'La fecha de vencimiento debe ser futura.',
                ]);
            }

            return $expires;
        }

        return now()->addDays(max(0, (int) config('shares.default_expiry_days', 30)));
    }

    /**
     * Expiracion en la EDICION de un enlace.
     *
     * A diferencia del alta, aqui la ausencia de fecha significa "quedate
     * como estaba" y un valor nulo significa "limpiado explicitamente", es
     * decir, el enlace pasa a no expirar. La politica de 30 dias NO aplica:
     * el enlace ya existe y su vencimiento ya fue decidido.
     */
    private function resolveUpdateExpiry(Request $request): ?Carbon
    {
        if ($request->boolean('never_expires')) {
            return null;
        }

        if (! $request->has('expires_at')) {
            return null;
        }

        $raw = $request->input('expires_at');
        if ($raw === null || $raw === '') {
            return null;
        }

        $expires = Carbon::parse((string) $raw, config('app.timezone'));

        if ($expires->isPast()) {
            throw ValidationException::withMessages([
                'expires_at' => 'La fecha de vencimiento debe ser futura.',
            ]);
        }

        return $expires;
    }

    // ------------------------------------------------------------------
    // Operaciones masivas
    // ------------------------------------------------------------------

    /**
     * Previsualizacion de la depuracion. No muta nada.
     */
    public function bulkPreview(Request $request)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateBulkRequest($request);

        $query = $this->selectedSharesQuery($request, $user);
        $total = (clone $query)->count('shares.id');
        $limit = $this->bulkLimit();

        if ($total > $limit) {
            return response()->json([
                'error' => "La operacion esta limitada a {$limit} enlaces por vez. Afina los filtros para reducir el alcance.",
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

    /**
     * Depuracion bulk autorizada.
     *
     * Vuelve a resolver el alcance con los MISMOS filtros que la
     * previsualizacion y exige que el conjunto no haya cambiado. Si cambio,
     * responde 409 y no borra nada.
     */
    public function bulkDelete(Request $request)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateBulkRequest($request);

        $query = $this->selectedSharesQuery($request, $user);
        $allMatching = $request->boolean('all_matching');
        $requestedIds = collect($request->input('ids', []))->map(fn ($id) => (int) $id)->unique()->values();

        $total = (clone $query)->count('shares.id');
        $limit = $this->bulkLimit();

        if ($total > $limit) {
            return response()->json([
                'error' => "La operacion esta limitada a {$limit} enlaces por vez. Afina los filtros para reducir el alcance.",
                'count' => $total,
                'limit' => $limit,
            ], 422);
        }

        $confirmedCount = (int) $request->input('confirm_count', -1);
        if ($confirmedCount !== $total) {
            return response()->json([
                'error' => 'La seleccion cambio. Vuelve a previsualizar antes de confirmar.',
                'current_count' => $total,
            ], 409);
        }

        $shares = $query->get(['shares.id', 'shares.token']);
        $authorizedIds = $shares->pluck('id')->map(fn ($id) => (int) $id)->values();
        $omittedIds = $allMatching ? collect() : $requestedIds->diff($authorizedIds)->values();

        try {
            DB::transaction(function () use ($authorizedIds) {
                foreach ($authorizedIds->chunk(100) as $chunk) {
                    Share::whereIn('id', $chunk->all())->delete();
                }
            });
        } catch (Throwable $e) {
            Log::error('shares.bulk_delete.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'No se pudo completar la depuracion'], 500);
        }

        foreach ($shares as $share) {
            Cache::forget("share:meta:{$share->token}");
        }

        Log::warning('shares.bulk_delete.completed', [
            'user_id' => $user->id,
            'deleted_count' => $authorizedIds->count(),
            'omitted_count' => $omittedIds->count(),
            'all_matching' => $allMatching,
            'confirm_count' => $confirmedCount,
        ]);

        return response()->json([
            'message' => 'Depuracion completada',
            'deleted_count' => $authorizedIds->count(),
            'omitted_count' => $omittedIds->count(),
            'failed_count' => 0,
            'omitted_ids' => $omittedIds,
        ]);
    }

    /**
     * Verificacion de disponibilidad en lotes server-side.
     *
     * Recorre el conjunto con un cursor por keyset: cada share que cumple los
     * filtros se verifica EXACTAMENTE una vez y el recorrido termina. El
     * cursor es opaco y transporta la clave de ordenamiento, el id y el
     * instante congelado del sweep, para que la pagina siguiente compare
     * contra la misma referencia temporal.
     */
    public function verifyAvailability(Request $request)
    {
        $user = $this->getUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->validateBulkRequest($request);

        $sort = (string) ($request->input('sort') ?: 'created_at');
        $direction = (string) ($request->input('direction') ?: 'desc');
        $batchLimit = (int) $request->input('limit', (int) config('shares.availability_verification_limit', 100));
        $batchLimit = max(1, min($batchLimit, 500));

        $cursor = $this->decodeCursor($request->input('cursor'));
        $frozenNow = $cursor['n'] ?? now()->format('Y-m-d H:i:s');

        $query = $this->selectedSharesQuery($request, $user, $sort, $direction, $frozenNow);

        if ($cursor !== null) {
            $this->applyKeysetCursor($query, $cursor, $sort, $direction, $frozenNow);
        }

        $shares = $query->limit($batchLimit)->get(['shares.id', 'shares.file_id']);

        // La sonda de filesystem se hace por RECURSO (dos enlaces sobre el
        // mismo archivo comparten sonda), pero las cuentas del contrato son
        // por ENLACE: `checked` debe igualar el total del conjunto filtrado.
        $verification = $this->availabilityService->verify(
            $shares->map(fn (Share $share) => $share->file)->filter()
        );

        $checked = 0;
        $available = 0;
        $missing = 0;
        $unknown = 0;
        $details = [];

        foreach ($shares as $share) {
            $file = $share->file;
            $state = $file ? ($verification['states'][(int) $file->id] ?? 'unknown') : 'unknown';
            $reason = $file ? ($verification['reasons'][(int) $file->id] ?? 'no_resource') : 'no_resource';

            $checked++;
            match ($state) {
                'available' => $available++,
                'missing' => $missing++,
                default => $unknown++,
            };

            $details[] = [
                'share_id' => (int) $share->id,
                'file_id' => $file ? (int) $file->id : null,
                'state' => $state,
                'reason' => $reason,
            ];
        }

        $lastId = $shares->isNotEmpty() ? (int) $shares->last()->id : null;
        $nextCursor = $lastId !== null
            ? $this->encodeCursor([
                'v' => $this->cursorValue($shares->last(), $sort, $frozenNow),
                'id' => $lastId,
                'n' => $frozenNow,
            ])
            : null;

        return response()->json([
            'message' => 'Verificacion completada',
            'checked' => $checked,
            'available' => $available,
            'missing' => $missing,
            'unknown' => $unknown,
            'details' => $details,
            'limit' => $batchLimit,
            'has_more' => $shares->count() >= $batchLimit,
            'next_cursor' => $nextCursor,
        ]);
    }

    /**
     * Alcance de una operacion bulk: por IDs seleccionados o por todos los
     * resultados del filtro actual.
     */
    private function selectedSharesQuery(
        Request $request,
        User $user,
        ?string $sort = null,
        ?string $direction = null,
        ?string $frozenNow = null
    ): Builder {
        $allMatching = $request->boolean('all_matching');

        $query = $this->shareQuery($request, $user);

        if (! $allMatching) {
            $ids = collect($request->input('ids', []))->map(fn ($id) => (int) $id)->unique()->values();
            $query->whereIn('shares.id', $ids->all());
        }

        $sort = $sort ?: (string) ($request->input('sort') ?: 'created_at');
        $direction = $direction ?: (string) ($request->input('direction') ?: 'desc');

        return $this->applySort($query, $sort, $direction, $frozenNow);
    }

    /**
     * Keyset pagination sobre (clave de ordenamiento, id).
     *
     * El cursor por `id` solo NO alcanzaba: con un orden distinto de `id`, un
     * lote podia repetir filas ya verificadas y el recorrido no terminaba.
     * Con la clave completa, cada fila aparece exactamente una vez.
     */
    private function applyKeysetCursor(
        Builder $query,
        array $cursor,
        string $sort,
        string $direction,
        string $frozenNow
    ): void {
        [$expression, $bindings] = $this->sortExpression($sort, $frozenNow);

        $value = $cursor['v'] ?? null;
        $id = (int) ($cursor['id'] ?? 0);

        if ($value === null) {
            return;
        }

        $operator = $direction === 'asc' ? '>' : '<';

        $query->where(function (Builder $scope) use ($expression, $bindings, $value, $id, $operator) {
            $scope->whereRaw("{$expression} {$operator} ?", array_merge($bindings, [$value]))
                ->orWhere(function (Builder $tie) use ($expression, $bindings, $value, $id, $operator) {
                    $tie->whereRaw("{$expression} = ?", array_merge($bindings, [$value]))
                        ->where('shares.id', $operator, $id);
                });
        });
    }

    /**
     * Valor de la clave de ordenamiento para una fila, en el mismo formato que
     * se usa en el WHERE del cursor.
     *
     * `status` depende del instante: se usa el MISMO instante congelado del
     * sweep, para que la fila no cambie de grupo a mitad del recorrido.
     */
    private function cursorValue(Share $share, string $sort, string $frozenNow): mixed
    {
        // Los centinelas deben coincidir con los de `sortExpression()`.
        $epoch = '1970-01-01 00:00:00';

        return match ($sort) {
            'name' => (string) ($share->file?->name ?? ''),
            'size' => (int) ($share->file?->size ?? 0),
            'accesses' => (int) ($share->access_logs_count ?? 0),
            'expires_at' => $share->expires_at?->format('Y-m-d H:i:s') ?? $epoch,
            'permission' => match ($share->permissions) {
                'read' => 1,
                'write', 'upload' => 2,
                'full' => 3,
                default => 4,
            },
            'status' => $share->expires_at === null ? 1 : ($share->expires_at->format('Y-m-d H:i:s') < $frozenNow ? 3 : 2),
            default => $share->created_at?->format('Y-m-d H:i:s') ?? $epoch,
        };
    }

    private function encodeCursor(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    private function decodeCursor(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }
}
