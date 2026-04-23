<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\StorageProvider;
use App\Models\UserStorage;
use Illuminate\Http\Request;

class UserStorageController extends Controller
{
    public function index(int $userId, Request $request)
    {
        if ($request->ajax()) {
            $user = User::findOrFail($userId);
            $storages = $user->userStorages()->with('storageProvider')->get();
            return response()->json($storages);
        }
        $user = User::findOrFail($userId);
        $allStorages = StorageProvider::where('enabled', true)->get();
        $userStorages = $user->userStorages()->with('storageProvider')->get();
        return view('admin.user-storages', [
            'targetUser' => $user,
            'allStorages' => $allStorages,
            'userStorages' => $userStorages,
        ]);
    }

    public function store(Request $request, int $userId)
    {
        $user = User::findOrFail($userId);

        $request->validate([
            'storage_provider_id' => 'required|exists:storage_providers,id',
            'permissions' => 'required|in:read,write,upload,full',
            'can_create_shares' => 'nullable|boolean',
        ]);

        $existing = UserStorage::where('user_id', $userId)
            ->where('storage_provider_id', $request->storage_provider_id)
            ->first();

        if ($existing) {
            return response()->json(['error' => 'User already has this storage assigned'], 409);
        }

        $userStorage = UserStorage::create([
            'user_id' => $userId,
            'storage_provider_id' => $request->storage_provider_id,
            'permissions' => $request->permissions,
            'can_create_shares' => $request->can_create_shares ?? false,
        ]);

        $userStorage->load('storageProvider');

        return response()->json($userStorage, 201);
    }

    public function update(Request $request, int $userId, int $storageId)
    {
        $userStorage = UserStorage::where('user_id', $userId)
            ->where('storage_provider_id', $storageId)
            ->firstOrFail();

        $request->validate([
            'permissions' => 'sometimes|in:read,write,upload,full',
            'can_create_shares' => 'nullable|boolean',
        ]);

        $userStorage->update($request->only(['permissions', 'can_create_shares']));

        return response()->json($userStorage->load('storageProvider'));
    }

    public function destroy(int $userId, int $storageId)
    {
        $userStorage = UserStorage::where('user_id', $userId)
            ->where('storage_provider_id', $storageId)
            ->firstOrFail();

        $userStorage->delete();

        return response()->json(['message' => 'Storage assignment removed']);
    }
}
