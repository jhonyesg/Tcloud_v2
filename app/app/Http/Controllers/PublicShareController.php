<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Models\ShareAccessLog;
use App\Models\File;
use App\Services\StorageSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicShareController extends Controller
{
    public function show(Request $request, string $token)
    {
        // Always load current metadata. Caching the serialized File graph allowed
        // renamed or revoked resources to remain visible for up to an hour.
        $share = Share::where('token', $token)->with(['file', 'creator'])->first();

        if (!$share) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'Share not found'], 404);
            }
            return view('shares.public-not-found');
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'Share has expired'], 410);
            }
            return view('shares.public-expired');
        }

        if ($share->password_hash) {
            if (!$request->hasHeader('X-Share-Password') && !$request->session()->has("share_auth_{$token}")) {
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['error' => 'Password required', 'requires_password' => true], 401);
                }
                return view('shares.public-password', ['token' => $token]);
            }

            if ($request->hasHeader('X-Share-Password')) {
                $password = $request->header('X-Share-Password');
                if (!Hash::check($password, $share->password_hash)) {
                    if ($request->ajax() || $request->wantsJson()) {
                        return response()->json(['error' => 'Invalid password'], 401);
                    }
                    return view('shares.public-password', ['token' => $token, 'error' => 'Contraseña incorrecta']);
                }
                $request->session()->put("share_auth_{$token}", true);
            }
        }

        $file = $share->file;

        if (!$file || $file->availability_state === 'missing') {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'File not found'], 404);
            }

            return view('shares.public-not-found');
        }

        $this->logAccess($share->id, $request->ip());

        if ($file->is_folder) {
            $this->autoSyncFolder($file, $request->boolean('refresh'));
            $folderContents = $file->children()
                ->where(function ($query) {
                    $query->whereNull('availability_state')->orWhere('availability_state', '!=', 'missing');
                })
                ->orderBy('is_folder', 'desc')
                ->orderBy('name')
                ->get();
            $mimeType = 'folder';
            $isPreviewable = false;
            $fileUrl = null;
            $breadcrumbs = [$file];

            return view('shares.public', [
                'share' => $share,
                'file' => $file,
                'mimeType' => $mimeType,
                'isPreviewable' => $isPreviewable,
                'fileUrl' => $fileUrl,
                'folderContents' => $folderContents,
                'breadcrumbs' => $breadcrumbs,
            ]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'share' => $share,
                'file' => $share->file,
                'permissions' => $share->permissions,
            ]);
        }

        $mimeType = $file->mime_type ?? 'application/octet-stream';
        $isPreviewable = $this->isPreviewable($mimeType);
        $canStreamDirectly = str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/');
        $isInlineMedia = str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
        $fileUrl = $isInlineMedia ? "/s/{$token}/media/{$file->id}/preview" : "/s/{$token}/download";

        return view('shares.public', [
            'share' => $share,
            'file' => $file,
            'mimeType' => $mimeType,
            'isPreviewable' => $isPreviewable,
            'fileUrl' => $fileUrl,
            'folderContents' => collect(),
        ]);
    }

    public function authenticate(Request $request, string $token)
    {
        $share = Share::where('token', $token)->with('file')->first();

        if (!$share) {
            return $this->publicError($request, 'Share not found', 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return $this->publicError($request, 'Share has expired', 410, 'shares.public-expired');
        }

        if (!$share->password_hash) {
            return redirect('/s/' . $token);
        }

        $request->validate(['password' => 'required|string']);

        if (!Hash::check($request->input('password'), $share->password_hash)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'Invalid password'], 401);
            }

            return response()->view('shares.public-password', [
                'token' => $token,
                'error' => 'Contraseña incorrecta',
            ], 401);
        }

        $request->session()->put("share_auth_{$token}", true);

        return redirect('/s/' . $token);
    }

    public function folder(Request $request, string $token, int $folder_id)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return view('shares.public-not-found');
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return view('shares.public-expired');
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return view('shares.public-password', ['token' => $token]);
        }

        $rootFolder = File::find($share->file_id);
        if (!$rootFolder || $rootFolder->availability_state === 'missing') {
            return view('shares.public-not-found');
        }

        $currentFolder = File::find($folder_id);
        if (!$currentFolder || !$currentFolder->is_folder || $currentFolder->availability_state === 'missing') {
            return view('shares.public-not-found');
        }

        if (!$this->isDescendantOf($currentFolder, $rootFolder)) {
            return view('shares.public-not-found');
        }

        $this->autoSyncFolder($currentFolder, $request->boolean('refresh'));
        $folderContents = $currentFolder->children()
            ->where(function ($query) {
                $query->whereNull('availability_state')->orWhere('availability_state', '!=', 'missing');
            })
            ->orderBy('is_folder', 'desc')
            ->orderBy('name')
            ->get();

        $breadcrumbs = [];
        $crumb = $currentFolder;
        while ($crumb && $crumb->id !== $rootFolder->id) {
            array_unshift($breadcrumbs, $crumb);
            $crumb = $crumb->parent;
        }
        array_unshift($breadcrumbs, $rootFolder);

        return view('shares.public', [
            'share' => $share,
            'file' => $currentFolder,
            'mimeType' => 'folder',
            'isPreviewable' => false,
            'fileUrl' => null,
            'folderContents' => $folderContents,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function preview(Request $request, string $token, int $file_id)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $rootFolder = File::find($share->file_id);
        $file = File::find($file_id);

        if (!$file || $file->availability_state === 'missing') {
            return response()->json(['error' => 'File not found'], 404);
        }

        if (!$this->isDescendantOf($file, $rootFolder)) {
            return response()->json(['error' => 'File not in shared folder'], 403);
        }

        $mimeType = $file->mime_type ?? 'application/octet-stream';
        $isPreviewable = $this->isPreviewable($mimeType);

        if (!$isPreviewable) {
            return response()->json(['error' => 'File not previewable'], 400);
        }

        $this->logAccess($share->id, $request->ip());

        return view('shares.preview', [
            'share' => $share,
            'file' => $file,
            'mimeType' => $mimeType,
            'previewUrl' => "/s/{$token}/download/{$file->id}",
        ]);
    }

    public function mediaPreview(Request $request, string $token, int $file_id)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $rootFolder = File::find($share->file_id);
        $file = File::find($file_id);

        if (!$file || $file->availability_state === 'missing') {
            return response()->json(['error' => 'File not found'], 404);
        }

        if (!$this->isDescendantOf($file, $rootFolder)) {
            return response()->json(['error' => 'File not in shared folder'], 403);
        }

        $mimeType = $file->mime_type ?? 'application/octet-stream';
        $isStreamable = str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/');

        if (!$isStreamable && !str_starts_with($mimeType, 'image/') && $mimeType !== 'application/pdf') {
            return response()->json(['error' => 'Preview not supported'], 400);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Storage not supported'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $this->logAccess($share->id, $request->ip());

        if (str_starts_with($mimeType, 'video/')) {
            $range = $request->header('Range');
            if ($range) {
                $fileSize = filesize($fullPath);
                return $this->streamVideoRange($fullPath, $fileSize, $mimeType, $range);
            }
            return response()->file($fullPath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
                'Accept-Ranges' => 'bytes',
            ]);
        }

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
        ]);
    }

    private function streamVideoRange(string $path, int $fileSize, string $mimeType, string $range)
    {
        $parts = explode('=', $range);
        $ranges = explode('-', $parts[1]);

        $start = max(0, intval($ranges[0]));
        $end = (isset($ranges[1]) && $ranges[1] !== '') ? min(intval($ranges[1]), $fileSize - 1) : $fileSize - 1;
        $length = $end - $start + 1;

        return response()->stream(function () use ($path, $start, $length) {
            $handle = fopen($path, 'rb');
            fseek($handle, $start);
            $remaining = $length;
            $chunkSize = 1024 * 1024; // 1 MB
            while ($remaining > 0 && !feof($handle)) {
                $read = min($chunkSize, $remaining);
                echo fread($handle, $read);
                $remaining -= $read;
                flush();
            }
            fclose($handle);
        }, 206, [
            'Content-Type'  => $mimeType,
            'Content-Length' => $length,
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Accept-Ranges' => 'bytes',
        ]);
    }

    private function isPreviewable(string $mimeType): bool
    {
        $previewableTypes = [
            'image/' => true,
            'video/mp4' => true,
            'video/webm' => true,
            'video/ogg' => true,
            'video/x-matroska' => true,
            'audio/mpeg' => true,
            'audio/mp3' => true,
            'audio/mp4' => true,
            'audio/ogg' => true,
            'audio/wav' => true,
            'audio/wave' => true,
            'application/pdf' => true,
        ];

        foreach ($previewableTypes as $type => $_) {
            if (str_starts_with($mimeType, $type) || $mimeType === $type) {
                return true;
            }
        }

        return false;
    }

    public function download(Request $request, string $token, ?int $fileId = null)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if (!in_array($share->permissions, ['read', 'write', 'upload', 'full'])) {
            return response()->json(['error' => 'Download not allowed'], 403);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        if ($fileId) {
            $file = File::findOrFail($fileId);
            $rootFolder = File::findOrFail($share->file_id);
            if (!$this->isDescendantOf($file, $rootFolder)) {
                return response()->json(['error' => 'File not in shared folder'], 403);
            }
        } else {
            $file = File::findOrFail($share->file_id);
        }

        if ($file->availability_state === 'missing') {
            return response()->json(['error' => 'File not found on storage'], 404);
        }

        if ($file->is_folder) {
            return response()->json(['error' => 'Cannot download folder'], 400);
        }

        if ($file->storageProvider->type !== 'local') {
            return response()->json(['error' => 'Download not supported'], 400);
        }

        $fullPath = $file->storageProvider->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found on storage'], 404);
        }

        $this->logAccess($share->id, $request->ip());

        // Use null name to skip Laravel's Str::ascii() call (broken vendor data files),
        // and set Content-Disposition manually with RFC 5987 UTF-8 encoding.
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $file->name);
        $encodedName = rawurlencode($file->name);
        return response()->download($fullPath, null, [
            'Content-Disposition' => 'attachment; filename="' . addslashes($asciiName) . '"; filename*=UTF-8\'\'' . $encodedName,
        ]);
    }

    public function upload(Request $request, string $token)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if (!in_array($share->permissions, ['write', 'upload', 'full'])) {
            return response()->json(['error' => 'Upload not allowed'], 403);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $request->validate([
            'file' => 'required|file',
            'parent_id' => 'nullable|exists:files,id',
        ]);

        $targetFolder = $share->file;

        if ($request->parent_id) {
            $parentFile = File::findOrFail($request->parent_id);
            if (!$this->isDescendantOf($parentFile, $targetFolder)) {
                return response()->json(['error' => 'Invalid parent folder'], 400);
            }
            $targetFolder = $parentFile;
        }

        if (!$targetFolder->is_folder) {
            return response()->json(['error' => 'Cannot upload to a file'], 400);
        }

        $uploadedFile = $request->file('file');
        $storageProvider = $targetFolder->storageProvider;
        $fileName = $uploadedFile->getClientOriginalName();
        $relativePath = $targetFolder->path . '/' . $fileName;
        $fullPath = $storageProvider->base_path . '/' . $relativePath;

        if ($request->boolean('replace')) {
            File::where('parent_id', $targetFolder->id)
                ->where('name', $fileName)
                ->where('is_folder', false)
                ->delete();
        }

        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tmpPath = $uploadedFile->getRealPath();
        $result = copy($tmpPath, $fullPath);

        if (!$result) {
            return response()->json(['error' => 'Failed to save file to storage'], 500);
        }

        $newFile = File::create([
            'name' => $fileName,
            'path' => $relativePath,
            'size' => filesize($fullPath),
            'mime_type' => $uploadedFile->getMimeType(),
            'storage_provider_id' => $storageProvider->id,
            'owner_id' => $targetFolder->owner_id,
            'parent_id' => $targetFolder->id,
            'is_folder' => false,
            'availability_state' => 'available',
            'last_verified_at' => now(),
            'missing_since_at' => null,
        ]);

        $this->logAccess($share->id, $request->ip());

        return response()->json(['message' => 'File uploaded successfully', 'file' => $newFile], 201);
    }

    public function createFolder(Request $request, string $token)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if ($share->permissions !== 'full') {
            return response()->json(['error' => 'Create folder not allowed'], 403);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:files,id',
        ]);

        $targetFolder = $share->file;

        if ($request->parent_id) {
            $parentFile = File::findOrFail($request->parent_id);
            if (!$this->isDescendantOf($parentFile, $targetFolder)) {
                return response()->json(['error' => 'Invalid parent folder'], 400);
            }
            $targetFolder = $parentFile;
        }

        $existingFolder = File::where('parent_id', $targetFolder->id)
            ->where('name', $request->name)
            ->where('is_folder', true)
            ->first();

        if ($existingFolder) {
            return response()->json(['error' => 'Folder already exists'], 409);
        }

        $storageProvider = $targetFolder->storageProvider;
        $sanitizedName = basename($request->name);
        if ($sanitizedName !== $request->name || $sanitizedName === '' || $sanitizedName === '.') {
            return response()->json(['error' => 'Invalid name'], 400);
        }

        $relativePath = $targetFolder->path . '/' . $sanitizedName;
        $fullPath = $storageProvider->base_path . '/' . $relativePath;

        $realBase = realpath($storageProvider->base_path);
        $realNew = realpath(dirname($fullPath)) . '/' . $sanitizedName;
        if (!$realBase || !$this->isPathWithinStorage($realNew, $realBase)) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        mkdir($fullPath, 0755, true);

        $newFolder = File::create([
            'name' => $request->name,
            'path' => $relativePath,
            'size' => 0,
            'mime_type' => null,
            'storage_provider_id' => $storageProvider->id,
            'owner_id' => $targetFolder->owner_id,
            'parent_id' => $targetFolder->id,
            'is_folder' => true,
            'availability_state' => 'available',
            'last_verified_at' => now(),
            'missing_since_at' => null,
        ]);

        $this->logAccess($share->id, $request->ip());

        return response()->json(['message' => 'Folder created successfully', 'folder' => $newFolder], 201);
    }

    public function rename(Request $request, string $token, int $fileId)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if (!in_array($share->permissions, ['write', 'full'])) {
            return response()->json(['error' => 'Rename not allowed'], 403);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $file = File::findOrFail($fileId);
        $rootFolder = File::findOrFail($share->file_id);

        if (!$this->isDescendantOf($file, $rootFolder)) {
            return response()->json(['error' => 'File not in shared folder'], 403);
        }

        $sanitizedName = basename($request->name);
        if ($sanitizedName !== $request->name || $sanitizedName === '' || $sanitizedName === '.') {
            return response()->json(['error' => 'Invalid name'], 400);
        }

        $oldPath = $file->path;
        $parentPath = dirname($oldPath);
        $newPath = $parentPath . '/' . $sanitizedName;
        $fullOldPath = $file->storageProvider->base_path . '/' . $oldPath;
        $fullNewPath = $file->storageProvider->base_path . '/' . $newPath;

        $realBase = realpath($file->storageProvider->base_path);
        $realNew = realpath(dirname($fullNewPath)) . '/' . $sanitizedName;
        if (!$realBase || !$this->isPathWithinStorage($realNew, $realBase)) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        if ($file->is_folder) {
            rename($fullOldPath, $fullNewPath);
            $file->path = $newPath;
            $file->name = $request->name;
            $file->save();
            $this->renameDescendantPaths($file, $oldPath, $newPath);
        } else {
            rename($fullOldPath, $fullNewPath);
            $file->path = $newPath;
            $file->name = $request->name;
            $file->save();
        }

        $this->logAccess($share->id, $request->ip());

        return response()->json(['message' => 'Renamed successfully', 'file' => $file]);
    }

    public function delete(Request $request, string $token, int $fileId)
    {
        $share = Share::where('token', $token)->first();

        if (!$share) {
            return response()->json(['error' => 'Share not found'], 404);
        }

        if ($share->expires_at && $share->expires_at->isPast()) {
            return response()->json(['error' => 'Share has expired'], 410);
        }

        if (!in_array($share->permissions, ['write', 'full'])) {
            return response()->json(['error' => 'Delete not allowed'], 403);
        }

        if ($share->password_hash && !$this->passwordAuthorized($request, $share, $token)) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $file = File::findOrFail($fileId);
        $rootFolder = File::findOrFail($share->file_id);

        if (!$this->isDescendantOf($file, $rootFolder)) {
            return response()->json(['error' => 'File not in shared folder'], 403);
        }

        $fullPath = $file->storageProvider->base_path . '/' . $file->path;

        if ($file->is_folder) {
            $this->deleteRecursive($fullPath);
            $file->children()->each(function ($child) {
                $child->shares()->delete();
                $child->delete();
            });
        } else {
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        $file->shares()->delete();
        $file->delete();

        $this->logAccess($share->id, $request->ip());

        return response()->json(['message' => 'Deleted successfully']);
    }

    private function isDescendantOf(File $file, File $ancestor): bool
    {
        $current = $file;
        while ($current) {
            if ($current->id === $ancestor->id) {
                return true;
            }
            $current = $current->parent;
        }
        return false;
    }

    private function isPathWithinStorage(string $path, string $basePath): bool
    {
        $realPath = realpath($path);
        $realBase = realpath($basePath);
        if (!$realPath || !$realBase) {
            return false;
        }
        return str_starts_with($realPath, $realBase);
    }

    private function renameDescendantPaths(File $folder, string $oldPrefix, string $newPrefix): void
    {
        foreach ($folder->children as $child) {
            $childOldPath = $child->path;
            if (!str_starts_with($childOldPath, $oldPrefix)) {
                continue;
            }
            $childNewPath = $newPrefix . substr($childOldPath, strlen($oldPrefix));
            $child->path = $childNewPath;
            $child->save();

            if ($child->is_folder) {
                $this->renameDescendantPaths($child, $childOldPath, $childNewPath);
            }
        }
    }

    private function deleteRecursive(string $path): void
    {
        if (is_dir($path)) {
            $files = array_diff(scandir($path), ['.', '..']);
            foreach ($files as $file) {
                $this->deleteRecursive($path . '/' . $file);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function autoSyncFolder(File $folder, bool $force = false): void
    {
        $cacheKey = "share_folder_sync:{$folder->id}";
        if (!$force && Cache::has($cacheKey)) {
            return;
        }

        $storage = $folder->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return;
        }

        try {
            app(StorageSyncService::class)->syncFolder($storage, $folder->id);
            Cache::put($cacheKey, true, 60);
        } catch (\Exception $e) {
            // Never let sync crash the share view
        }
    }

    private function publicError(Request $request, string $message, int $status, ?string $view = null)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['error' => $message], $status);
        }

        return $view
            ? response()->view($view, [], $status)
            : response()->view('shares.public-not-found', [], $status);
    }

    private function passwordAuthorized(Request $request, Share $share, string $token): bool
    {
        if ($request->session()->has("share_auth_{$token}")) {
            return true;
        }

        $headerPassword = $request->header('X-Share-Password');
        if (!$headerPassword || !Hash::check($headerPassword, $share->password_hash)) {
            return false;
        }

        $request->session()->put("share_auth_{$token}", true);

        return true;
    }

    private function logAccess(int $shareId, ?string $ip): void
    {
        try {
            ShareAccessLog::create([
                'share_id'    => $shareId,
                'accessed_at' => now(),
                'ip_address'  => $ip,
            ]);
        } catch (\Exception $e) {
            // Never let access logging crash the response
        }
    }
}
