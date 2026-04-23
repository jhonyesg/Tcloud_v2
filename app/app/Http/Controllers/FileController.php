<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;

class FileController extends Controller
{
    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    private function checkFilePermission(File $file, string $permission): bool
    {
        $user = $this->getUser();
        if (!$user) return false;

        if ($user->isAdmin()) return true;

        if ($file->storage_provider_id) {
            return $user->hasStoragePermission($file->storage_provider_id, $permission);
        }

        return $file->owner_id === $user->id;
    }

    public function index(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            return redirect('/login');
        }

        if ($request->ajax() || $request->wantsJson()) {
            $parentId = $request->has('parent_id') ? $request->parent_id : null;
            $storageId = $request->has('storage_id') ? $request->storage_id : null;

            if ($parentId !== null) {
                $parentFile = File::find($parentId);
                if ($parentFile) {
                    $storageId = $parentFile->storage_provider_id;
                }
            }

            if ($storageId !== null) {
                $storage = StorageProvider::find($storageId);
                if ($storage && $storage->type === 'local') {
                    $syncService = app(StorageSyncService::class);
                    $files = $syncService->syncFolder($storage, $parentId, $user->id);
                    return response()->json($files);
                }
            }

            $query = File::query();

            if ($request->has('parent_id')) {
                $query->where('parent_id', $request->parent_id);
            } else {
                $query->whereNull('parent_id');
            }

            if ($request->has('storage_id')) {
                $query->where('storage_provider_id', $request->storage_id);
            }

            if (!$user->isAdmin()) {
                $userStorageIds = $user->userStorages()->pluck('storage_provider_id')->toArray();
                $query->where(function ($q) use ($user, $userStorageIds) {
                    $q->where('owner_id', $user->id)
                      ->orWhereIn('storage_provider_id', $userStorageIds);
                });
            }

            $files = $query->orderBy('is_folder', 'desc')->orderBy('name')->get();
            return response()->json($files);
        }

        return view('files.index');
    }

    public function store(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:files,id',
            'storage_id' => 'nullable|exists:storage_providers,id',
            'is_folder' => 'nullable|boolean',
        ]);

        if ($request->is_folder) {
            if (!$request->has('storage_id')) {
                return response()->json(['error' => 'Storage ID required for folders'], 400);
            }

            if (!$user->hasStoragePermission($request->storage_id, 'write') && !$user->isAdmin()) {
                return response()->json(['error' => 'Write permission required'], 403);
            }

            $parentId = $request->parent_id;
            $storageId = $request->storage_id;
        } else {
            return response()->json(['error' => 'Use /files/upload for file uploads'], 400);
        }

        $existing = File::where('parent_id', $parentId)
            ->where('name', $request->name)
            ->where('storage_provider_id', $storageId)
            ->first();

        if ($existing) {
            return response()->json(['error' => 'A folder with this name already exists'], 409);
        }

        $storage = StorageProvider::find($storageId);
        $path = $this->generatePath($parentId, $request->name, $storage);

        $file = File::create([
            'name' => $request->name,
            'path' => $path,
            'size' => 0,
            'mime_type' => 'folder',
            'storage_provider_id' => $storageId,
            'owner_id' => $user->isAdmin() ? $user->id : $user->id,
            'parent_id' => $parentId,
            'is_folder' => true,
            'is_personal' => false,
        ]);

        return response()->json($file, 201);
    }

    public function show(int $id)
    {
        $file = File::with('owner')->findOrFail($id);

        if (!$this->checkFilePermission($file, 'read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($file);
    }

    public function update(Request $request, int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'full')) {
            return response()->json(['error' => 'Full permission required'], 403);
        }

        if ($file->owner_id !== Session::get('user_id') && Session::get('user_role') !== 'admin') {
            return response()->json(['error' => 'Only owner can rename'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        if ($request->has('name')) {
            $existing = File::where('parent_id', $file->parent_id)
                ->where('name', $request->name)
                ->where('id', '!=', $id)
                ->first();

            if ($existing) {
                return response()->json(['error' => 'A file with this name already exists'], 409);
            }

            $file->update(['name' => $request->name]);
        }

        return response()->json($file);
    }

    public function destroy(int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'full')) {
            return response()->json(['error' => 'Full permission required'], 403);
        }

        if ($file->owner_id !== Session::get('user_id') && Session::get('user_role') !== 'admin') {
            return response()->json(['error' => 'Only owner can delete'], 403);
        }

        if ($file->is_folder) {
            $this->deleteRecursive($file);
        } else {
            $this->deleteFile($file);
        }

        return response()->json(['message' => 'Deleted']);
    }

    public function upload(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'file' => 'required|file',
            'parent_id' => 'nullable|exists:files,id',
            'storage_id' => 'required|exists:storage_providers,id',
        ]);

        if (!$user->hasStoragePermission($request->storage_id, 'write') && !$user->isAdmin()) {
            return response()->json(['error' => 'Write permission required'], 403);
        }

        $file = $request->file('file');
        $parentId = $request->parent_id;
        $storageId = $request->storage_id;

        $storage = StorageProvider::find($storageId);
        $filename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        if ($user->personal_quota_bytes > 0 && !$parentId) {
            if ($user->personal_used_bytes + $size > $user->personal_quota_bytes) {
                return response()->json(['error' => 'Personal quota exceeded'], 413);
            }
        }

        $existing = File::where('parent_id', $parentId)
            ->where('name', $filename)
            ->where('storage_provider_id', $storageId)
            ->first();

        if ($existing) {
            return response()->json(['error' => 'File already exists'], 409);
        }

        $path = $this->generatePath($parentId, $filename, $storage);

        $storedFile = File::create([
            'name' => $filename,
            'path' => $path,
            'size' => $size,
            'mime_type' => $mimeType,
            'storage_provider_id' => $storageId,
            'owner_id' => $user->id,
            'parent_id' => $parentId,
            'is_folder' => false,
            'is_personal' => $parentId === null,
        ]);

        if ($parentId === null && $user->personal_quota_bytes > 0) {
            $user->increment('personal_used_bytes', $size);
        }

        return response()->json($storedFile, 201);
    }

    public function download(int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Download not supported for this storage type'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;
        $realBasePath = realpath($storage->base_path);
        $realFullPath = realpath($storage->base_path . '/' . $file->path);

        if (!$realFullPath || !$realBasePath || !str_starts_with($realFullPath, $realBasePath)) {
            return response()->json(['error' => 'Invalid file path'], 400);
        }

        if (!file_exists($realFullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->download($fullPath, $file->name, [
            'X-Accel-Redirect' => '/protected-files/' . $file->path,
        ]);
    }

    public function preview(int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $mimeType = $file->mime_type;

        if (str_starts_with($mimeType, 'image/')) {
            return response()->file($file->path, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
            ]);
        }

        return response()->json(['error' => 'Preview not supported for this file type'], 400);
    }

    public function view(int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'read')) {
            return redirect('/files')->with('error', 'No tienes permiso para ver este archivo');
        }

        return view('files.preview', ['fileId' => $id]);
    }

    public function storages(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $userStorages = $user->userStorages()->with('storageProvider')->get();

        $storages = $userStorages->map(function ($us) {
            return [
                'id' => $us->storageProvider->id,
                'name' => $us->storageProvider->name,
                'type' => $us->storageProvider->type,
                'permissions' => $us->permissions,
            ];
        });

        return response()->json(['storages' => $storages]);
    }

    private function generatePath(?int $parentId, string $name, StorageProvider $storage): string
    {
        if ($parentId) {
            $parent = File::find($parentId);
            return $parent ? $parent->path . '/' . $name : $name;
        }
        return $name;
    }

    private function deleteRecursive(File $folder): void
    {
        $children = File::where('parent_id', $folder->id)->get();
        foreach ($children as $child) {
            if ($child->is_folder) {
                $this->deleteRecursive($child);
            } else {
                $this->deleteFile($child);
            }
        }
        $folder->delete();
    }

    private function deleteFile(File $file): void
    {
        $user = User::find($file->owner_id);
        if ($user && $file->is_personal && $user->personal_quota_bytes > 0) {
            $user->decrement('personal_used_bytes', $file->size);
        }
        $file->delete();
    }
}
