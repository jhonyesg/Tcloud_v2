<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\StorageProvider;
use App\Models\SystemSetting;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class StorageProviderController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            // Cache 60s (change 2026-09-13-perf-audit-and-improve).
            // `withCount('files')` ejecuta un subquery por storage sobre 190
            // filas, lo que pesa ~1.2s cold segun Playwright. Con cache, los
            // requests siguientes son <100ms.
            $ttl = self::resolveAdminStoragesCacheTtl();
            if ($ttl === 0) {
                return response()->json(self::computeAdminStorages());
            }
            $payload = Cache::remember('admin:storages:index', $ttl, fn () => self::computeAdminStorages());
            return response()->json($payload);
        }

        return view('admin.storages');
    }

    private static function computeAdminStorages(): array
    {
        return StorageProvider::withCount('files')->get()->toArray();
    }

    private static function resolveAdminStoragesCacheTtl(): int
    {
        // Default 60s. Rango [0, 600]. 0 = bypass (freno de emergencia).
        $raw = SystemSetting::get('admin_storages_cache_ttl');
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return 60;
        }
        $ttl = (int) $raw;
        if ($ttl < 0 || $ttl > 600) {
            return 60;
        }
        return $ttl;
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:local,s3',
            'config' => 'nullable|array',
            'base_path' => 'required_if:type,local|nullable|string|max:500',
            'enabled' => 'nullable|boolean',
        ]);

        // Change `storage-physical-path-normalization` (2026-09-17): bloquear
        // la creacion de storages con base_path duplicado. El constraint del
        // modelo es: cada (kind, physical_path_normalized) tiene UN storage
        // canónico (no mergeado). Si ya existe uno, devolvemos HTTP 409 con
        // sugerencia accionable; el operador puede reusarlo o elegir otro path.
        $basePath = $request->input('base_path');
        $kind = $request->input('type', 'local');
        if (!empty($basePath) && $kind === 'local') {
            $normalized = strtolower(rtrim($basePath, '/'));
            $existing = StorageProvider::findByNormalizedPath($normalized, $kind);
            if ($existing !== null) {
                return response()->json([
                    'error' => 'duplicate_storage_path',
                    'message' => "ya existe storage #{$existing->id} '{$existing->name}' en ese path; use ese en su lugar o cree un path distinto.",
                    'existing_storage_id' => $existing->id,
                    'existing_storage_name' => $existing->name,
                    'attempted_path' => $basePath,
                ], 409);
            }
        }

        $storage = StorageProvider::create([
            'name' => $request->input('name'),
            'type' => $kind,
            'config' => $request->input('config', []),
            'base_path' => $basePath,
            'enabled' => $request->boolean('enabled', true),
        ]);

        // guardarrail (change 2026-09-16-transcriptor-physical-file-identity):
        // calcular la jerarquia al guardar base_path, para que el operador vea
        // el scope que hereda y si queda solapado con un ancestro habilitado.
        $hierarchy = $this->recomputeHierarchy($storage);

        // Change 2026-09-13-perf-audit-and-improve: creacion invalida el cache del listado.
        Cache::forget('admin:storages:index');

        return response()->json(array_merge($storage->toArray(), ['hierarchy' => $hierarchy]), 201);
    }

    public function show(int $id)
    {
        $storage = StorageProvider::withCount('files')->findOrFail($id);
        return response()->json($storage);
    }

    public function update(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:local,s3',
            'config' => 'nullable|array',
            'base_path' => 'nullable|string|max:500',
            'enabled' => 'nullable|boolean',
        ]);

        $data = [];
        if ($request->has('name')) $data['name'] = $request->input('name');
        if ($request->has('type')) $data['type'] = $request->input('type');
        if ($request->has('config')) $data['config'] = $request->input('config');
        if ($request->has('base_path')) $data['base_path'] = $request->input('base_path');
        if ($request->has('enabled')) $data['enabled'] = $request->boolean('enabled');

        // Change `storage-physical-path-normalization` (2026-09-17):
        // si el `base_path` cambia, validar que el nuevo path no este ya
        // ocupado por OTRO storage no mergeado. Tambien manejamos el caso
        // "un-merge": si este storage estaba mergeado y se le asigna un
        // nuevo `base_path`, limpiamos `duplicate_of_storage_id`/`merged_at`/
        // `merged_reason` para rehabilitarlo.
        if ($request->has('base_path') && $data['base_path'] !== $storage->base_path) {
            $newBase = $data['base_path'];
            $kind = $data['type'] ?? $storage->type;
            if (!empty($newBase) && $kind === 'local') {
                $normalized = strtolower(rtrim($newBase, '/'));
                $existing = StorageProvider::findByNormalizedPath($normalized, $kind);
                if ($existing !== null && $existing->id !== $storage->id) {
                    return response()->json([
                        'error' => 'duplicate_storage_path',
                        'message' => "ya existe storage #{$existing->id} '{$existing->name}' en ese path.",
                        'existing_storage_id' => $existing->id,
                        'attempted_path' => $newBase,
                    ], 409);
                }
            }
            if ($storage->duplicate_of_storage_id !== null) {
                $data['duplicate_of_storage_id'] = null;
                $data['merged_at'] = null;
                $data['merged_reason'] = null;
                $data['enabled'] = $request->boolean('enabled', true);
            }
        }

        // El root anterior puede cambiar si se mueve base_path: hay que
        // invalidar su scope heredado (cache).
        $rootAnterior = $this->hierarchyService()->rootIdOf((int) $storage->id);

        $storage->update($data);

        $hierarchy = $this->recomputeHierarchy($storage);
        $rootNuevo = $this->hierarchyService()->rootIdOf((int) $storage->id);

        if ($rootAnterior !== $rootNuevo) {
            StorageProvider::forgetInheritedTranscriptionScope($rootAnterior);
            StorageProvider::forgetInheritedTranscriptionScope($rootNuevo);
        }

        // Change 2026-09-13-perf-audit-and-improve: cambio invalida el cache del listado.
        Cache::forget('admin:storages:index');

        return response()->json(array_merge($storage->fresh()->toArray(), ['hierarchy' => $hierarchy]));
    }

    /**
     * Recalcula `parent_storage_id` y devuelve la jerarquia resultante para la
     * respuesta del guardarrail. Invalida las caches de scope afectadas.
     *
     * @return array{
     *     parent_storage_id: ?int,
     *     ancestor: ?array{id:int,name:string},
     *     descendants: list<array{id:int,name:string,transcription_enabled:bool}>,
     *     equivalent_nodes: list<array{id:int,name:string,transcription_enabled:bool}>,
     *     overlap_warning: bool
     * }
     */
    private function recomputeHierarchy(StorageProvider $storage): array
    {
        $svc = $this->hierarchyService();
        $svc->recomputeParent($storage);

        $info = $svc->hierarchyInfo((int) $storage->id);

        // El scope heredado del root cambio: invalidar la cache.
        StorageProvider::forgetInheritedTranscriptionScope($svc->rootIdOf((int) $storage->id));

        $map = static fn (StorageProvider $s) => [
            'id' => (int) $s->id,
            'name' => (string) $s->name,
            'transcription_enabled' => (bool) $s->transcription_enabled,
        ];

        return [
            'parent_storage_id' => $storage->parent_storage_id !== null ? (int) $storage->parent_storage_id : null,
            'ancestor' => $info['ancestor'] !== null ? $map($info['ancestor']) : null,
            'descendants' => array_map($map, $info['descendants']),
            'equivalent_nodes' => array_map($map, $info['equivalent_nodes']),
            'overlap_warning' => (bool) $info['overlap_warning'],
        ];
    }

    private function hierarchyService(): \App\Services\Ia\StorageHierarchyService
    {
        return app(\App\Services\Ia\StorageHierarchyService::class);
    }

    public function destroy(int $id)
    {
        $storage = StorageProvider::findOrFail($id);
        $storage->delete();
        // Change 2026-09-13-perf-audit-and-improve: borrado invalida el cache del listado.
        Cache::forget('admin:storages:index');
        return response()->json(['message' => 'Storage deleted']);
    }

    public function test(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        if ($storage->type === 'local') {
            $path = $storage->base_path;
            $exists = file_exists($path) && is_dir($path) && is_readable($path);
            $storage->update([
                'is_accessible' => $exists,
                'last_checked_at' => now(),
            ]);
            return response()->json([
                'success' => $exists,
                'message' => $exists ? 'La ruta local es accesible' : 'La ruta local no es accesible',
            ]);
        }

        if ($storage->type === 's3') {
            $config = $storage->config ?? [];
            $required = ['region', 'version', 'credentials'];
            foreach ($required as $key) {
                if (!isset($config[$key])) {
                    $storage->update([
                        'is_accessible' => false,
                        'last_checked_at' => now(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => "Configuración S3 incompleta: falta campo {$key}",
                    ]);
                }
            }

            $creds = $config['credentials'];
            if (!isset($creds['key']) || !isset($creds['secret'])) {
                $storage->update([
                    'is_accessible' => false,
                    'last_checked_at' => now(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales S3 inválidas: falta key o secret',
                ]);
            }

            try {
                $s3 = new S3Client([
                    'region' => $config['region'],
                    'version' => $config['version'] ?? 'latest',
                    'credentials' => [
                        'key' => $creds['key'],
                        'secret' => $creds['secret'],
                    ],
                ]);

                $bucket = $config['bucket'] ?? null;
                if ($bucket) {
                    $result = $s3->headBucket(['Bucket' => $bucket]);
                    $storage->update([
                        'is_accessible' => true,
                        'last_checked_at' => now(),
                    ]);
                    return response()->json([
                        'success' => true,
                        'message' => "Bucket S3 '{$bucket}' es accesible",
                    ]);
                }

                $storage->update([
                    'is_accessible' => true,
                    'last_checked_at' => now(),
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Las credenciales S3 son válidas',
                ]);
            } catch (\Aws\Exception\AwsException $e) {
                $storage->update([
                    'is_accessible' => false,
                    'last_checked_at' => now(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error de conexión S3: ' . $e->getAwsErrorMessage(),
                ]);
            } catch (\Exception $e) {
                $storage->update([
                    'is_accessible' => false,
                    'last_checked_at' => now(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error de conexión S3: ' . $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Tipo de storage desconocido',
        ]);
    }

    /**
     * Despacha `storage:reconcile` para un storage. Solo `kind='external'`
     * tiene sentido (un local no necesita reconciliación: su accesibilidad
     * es trivial). Si el storage ya está healthy, el reconciliador corre
     * un fullSync con force=true para re-verificar.
     */
    public function reconcile(int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        if ($storage->kind !== 'external') {
            return response()->json([
                'success' => false,
                'message' => 'Solo storages externos (kind=external) admiten reconciliación',
            ], 422);
        }

        \Illuminate\Support\Facades\Process::start([
            PHP_BINARY,
            base_path('artisan'),
            'storage:reconcile',
            '--storage=' . $storage->id,
            '--no-pacing',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Reconciliación disparada para {$storage->name}",
        ], 202);
    }

    public function searchUsers(Request $request)
    {
        $query = $request->input('q', '');

        $users = User::orderBy('username');

        if (strlen($query) >= 1) {
            $users->where(function ($q) use ($query) {
                $q->where('username', 'like', '%' . $query . '%')
                  ->orWhere('email', 'like', '%' . $query . '%');
            });
        }

        $users->limit(30);

        return response()->json(
            $users->get(['id', 'username', 'email'])
        );
    }

    public function users(int $id, Request $request)
    {
        $storage = StorageProvider::findOrFail($id);

        if ($request->ajax()) {
            $userStorages = $storage->userStorages()
                ->with('user')
                ->get()
                ->map(function ($us) {
                    return [
                        'user_id' => $us->user_id,
                        'user_username' => $us->user->username,
                        'user_email' => $us->user->email,
                        'storage_provider_id' => $us->storage_provider_id,
                        'permissions' => $us->permissions,
                        'can_create_shares' => $us->can_create_shares,
                        'assigned_at' => $us->assigned_at,
                    ];
                });
            return response()->json($userStorages);
        }

        $allUsers = \App\Models\User::select('id', 'username', 'email')->orderBy('username')->get();
        $userStorages = $storage->userStorages()->with('user')->get()->map(function ($us) {
            return [
                'user_id' => $us->user_id,
                'user_username' => $us->user->username,
                'user_email' => $us->user->email,
                'permissions' => $us->permissions,
                'can_create_shares' => $us->can_create_shares,
                'assigned_at' => $us->assigned_at,
            ];
        });

        return view('admin.storage-users', [
            'storage' => $storage,
            'allUsers' => $allUsers,
            'userStorages' => $userStorages,
        ]);
    }

    public function assignUser(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'permissions' => 'required|in:read,write,upload,full',
            'can_create_shares' => 'nullable|boolean',
        ]);

        $existing = \App\Models\UserStorage::where('user_id', $request->user_id)
            ->where('storage_provider_id', $id)
            ->first();

        if ($existing) {
            return response()->json(['error' => 'User already has this storage assigned'], 409);
        }

        $userStorage = \App\Models\UserStorage::create([
            'user_id' => $request->user_id,
            'storage_provider_id' => $id,
            'permissions' => $request->permissions,
            'can_create_shares' => $request->boolean('can_create_shares', false),
        ]);

        $userStorage->load('user');

        return response()->json([
            'user_id' => $userStorage->user_id,
            'user_username' => $userStorage->user->username,
            'user_email' => $userStorage->user->email,
            'storage_provider_id' => $userStorage->storage_provider_id,
            'permissions' => $userStorage->permissions,
            'can_create_shares' => $userStorage->can_create_shares,
            'assigned_at' => $userStorage->assigned_at,
        ], 201);
    }

    public function updateUserAssignment(Request $request, int $id, int $userId)
    {
        $storage = StorageProvider::findOrFail($id);

        $request->validate([
            'permissions' => 'sometimes|in:read,write,upload,full',
            'can_create_shares' => 'nullable|boolean',
        ]);

        $userStorage = \App\Models\UserStorage::where('user_id', $userId)
            ->where('storage_provider_id', $id)
            ->firstOrFail();

        $userStorage->update($request->only(['permissions', 'can_create_shares']));

        return response()->json([
            'user_id' => $userStorage->user_id,
            'user_username' => $userStorage->user->username,
            'user_email' => $userStorage->user->email,
            'storage_provider_id' => $userStorage->storage_provider_id,
            'permissions' => $userStorage->permissions,
            'can_create_shares' => $userStorage->can_create_shares,
            'assigned_at' => $userStorage->assigned_at,
        ]);
    }

    public function removeUserAssignment(int $id, int $userId)
    {
        $storage = StorageProvider::findOrFail($id);

        $userStorage = \App\Models\UserStorage::where('user_id', $userId)
            ->where('storage_provider_id', $id)
            ->firstOrFail();

        $userStorage->delete();

        // Quitarle el acceso a un cliente no cambia si el canal se transcribe:
        // eso lo decide API Transcriptor sobre el storage. Son cosas distintas.
        return response()->json(['message' => 'User assignment removed']);
    }

    public function assignAll(int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $assignedIds = $storage->userStorages()->pluck('user_id')->toArray();

        $users = User::whereNotIn('id', $assignedIds)->get();

        if ($users->isNotEmpty()) {
            $now = now();
            $records = $users->map(fn($user) => [
                'user_id'              => $user->id,
                'storage_provider_id'  => $id,
                'permissions'          => 'read',
                'can_create_shares'    => false,
                'assigned_at'          => $now,
            ])->toArray();

            // insert() masivo: no dispara eventos de modelo. Las filas nacen con
            // transcription_enabled=false, así que la derivación no cambia y no
            // hace falta recalcular aquí (a diferencia de los borrados).
            \App\Models\UserStorage::insert($records);
        }

        return response()->json(['message' => 'All users assigned', 'count' => $users->count()]);
    }

    public function removeAll(int $id)
    {
        StorageProvider::findOrFail($id);

        \App\Models\UserStorage::where('storage_provider_id', $id)->delete();

        return response()->json(['message' => 'All user assignments removed']);
    }
}
