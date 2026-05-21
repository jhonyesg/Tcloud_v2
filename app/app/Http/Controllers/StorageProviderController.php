<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\StorageProvider;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StorageProviderController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $storages = StorageProvider::withCount('files')->get();
            return response()->json($storages);
        }

        return view('admin.storages');
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

        $storage = StorageProvider::create([
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'config' => $request->input('config', []),
            'base_path' => $request->input('base_path'),
            'enabled' => $request->boolean('enabled', true),
        ]);

        return response()->json($storage, 201);
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

        $storage->update($data);

        return response()->json($storage);
    }

    public function destroy(int $id)
    {
        $storage = StorageProvider::findOrFail($id);
        $storage->delete();
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

        \App\Models\File::where('storage_provider_id', $id)
            ->where('owner_id', $userId)
            ->whereNull('parent_id')
            ->where('is_folder', true)
            ->delete();

        $userStorage->delete();

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
