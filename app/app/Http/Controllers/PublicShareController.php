<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Models\ShareAccessLog;
use App\Models\File;
use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicShareController extends Controller
{
    /**
     * Vista publica de un enlace.
     *
     * La cache `share:meta:{token}` guarda SOLO escalares del enlace
     * (identificador, recurso, vencimiento, permiso y hash). La fila del
     * recurso y su relacion se resuelven en CADA respuesta a partir de la fila
     * vigente: cachear el modelo con relaciones congelaba el nombre y los
     * metadatos del archivo, y un archivo renombrado se mostraba con el nombre
     * viejo hasta una hora despues.
     */
    public function show(Request $request, string $token)
    {
        $cacheKey = "share:meta:{$token}";

        $meta = Cache::remember($cacheKey, 3600, fn () => $this->shareMeta($token));

        // La cache puede contener una entrada del formato anterior (el modelo
        // completo) si el TTL de una hora no ha expirado tras el despliegue.
        // Se descarta y se recalcula: un share existente jamas debe dar 404.
        if (! is_array($meta)) {
            Cache::forget($cacheKey);
            $meta = Cache::remember($cacheKey, 3600, fn () => $this->shareMeta($token));
        }

        // Revalidacion: un enlace revocado no puede resolver por efecto de la
        // cache. Se relee la fila vigente con sus relaciones.
        $share = $meta
            ? Share::with(['file.storageProvider', 'creator'])->find($meta['id'])
            : null;

        if (! $share) {
            Cache::forget($cacheKey);

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
            if (! $request->hasHeader('X-Share-Password') && ! $request->session()->has("share_auth_{$token}")) {
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['error' => 'Password required', 'requires_password' => true], 401);
                }
                return view('shares.public-password', ['token' => $token]);
            }

            if ($request->hasHeader('X-Share-Password')) {
                $password = $request->header('X-Share-Password');
                if (! Hash::check($password, $share->password_hash)) {
                    if ($request->ajax() || $request->wantsJson()) {
                        return response()->json(['error' => 'Invalid password'], 401);
                    }
                    return view('shares.public-password', ['token' => $token, 'error' => 'Contraseña incorrecta']);
                }
                $request->session()->put("share_auth_{$token}", true);
            }
        }

        $this->logAccess($share->id, $request->ip());

        $file = $share->file;

        if (! $file) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'File not found'], 404);
            }
            return view('shares.public-not-found');
        }

        if ($file->is_folder) {
            $this->autoSyncFolder($file, $request->boolean('refresh'));
            $listing = $this->folderContents($file);
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
                'folderContents' => $listing['items'],
                'folderListingTotal' => $listing['total'],
                'folderListingTruncated' => $listing['truncated'],
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

    /**
     * Metadatos escalares del enlace para la cache publica.
     *
     * Deliberadamente NO se cachea el modelo con sus relaciones: la fila del
     * recurso debe resolverse en cada respuesta para que un renombrado o un
     * cambio de disponibilidad se vea de inmediato (contrato
     * `share-security`, "Metadatos publics frescos").
     *
     * @return array{id:int, file_id:int, expires_at:?string, permissions:string, password_hash:?string}|null
     */
    private function shareMeta(string $token): ?array
    {
        $share = Share::where('token', $token)->first();

        if (! $share) {
            return null;
        }

        return [
            'id' => (int) $share->id,
            'file_id' => (int) $share->file_id,
            'expires_at' => $share->expires_at?->format('Y-m-d H:i:s'),
            'permissions' => $share->permissions,
            'password_hash' => $share->password_hash,
        ];
    }

    /**
     * Autenticacion HTML por contrasena de share.
     *
     * El formulario `shares.public-password` postea aqui. Sin este endpoint,
     * TODO enlace protegido por contrasena era insusable: el visitante
     * escribia la contrasena, enviaba el formulario y recibia un HTTP 500.
     *
     * Contrato (`share-security`):
     *  - contrasena correcta -> se crea `share_auth_{token}` y se redirige al
     *    GET publico.
     *  - contrasena incorrecta -> `401` para clientes JSON o el formulario con
     *    error, y NO se crea la sesion.
     *  - share expirado -> `410` o la vista de expirado, sin evaluar la
     *    contrasena.
     */
    public function authenticate(Request $request, string $token)
    {
        $share = Share::where('token', $token)->first();

        if (! $share) {
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

        if (! $share->password_hash) {
            return redirect('/s/' . $token);
        }

        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->input('password'), $share->password_hash)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'Invalid password'], 401);
            }

            return response()->view('shares.public-password', [
                'token' => $token,
                'error' => 'Contrasena incorrecta',
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
            return view('shares.public-password', ['token' => $token]);
        }

        $rootFolder = File::find($share->file_id);
        if (!$rootFolder) {
            return view('shares.public-not-found');
        }

        $currentFolder = File::find($folder_id);
        if (!$currentFolder || !$currentFolder->is_folder) {
            return view('shares.public-not-found');
        }

        if (!$this->isDescendantOf($currentFolder, $rootFolder)) {
            return view('shares.public-not-found');
        }

        $this->autoSyncFolder($currentFolder, $request->boolean('refresh'));
        $listing = $this->folderContents($currentFolder);

        $breadcrumbs = $this->buildBreadcrumbs($currentFolder, $rootFolder);

        return view('shares.public', [
            'share' => $share,
            'file' => $currentFolder,
            'mimeType' => 'folder',
            'isPreviewable' => false,
            'fileUrl' => null,
            'folderContents' => $listing['items'],
            'folderListingTotal' => $listing['total'],
            'folderListingTruncated' => $listing['truncated'],
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $rootFolder = File::find($share->file_id);
        $file = File::find($file_id);

        if (!$file) {
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $rootFolder = File::find($share->file_id);
        $file = File::find($file_id);

        if (!$file) {
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
            return response()->json(['error' => 'Password required'], 401);
        }

        $rootFolder = File::find($share->file_id);
        if (!$rootFolder) {
            return response()->json(['error' => 'Shared folder not found in DB; the matcher may need to run'], 404);
        }

        if ($fileId) {
            $file = File::find($fileId);
            if (!$file) {
                return response()->json(['error' => 'File not found'], 404);
            }
            if (!$this->isDescendantOf($file, $rootFolder)) {
                return response()->json(['error' => 'File not in shared folder'], 403);
            }
        } else {
            $file = $rootFolder;
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
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
            'is_personal' => false,
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
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

        if (!is_dir($fullPath)) {
            if (!is_writable(dirname($fullPath))) {
                Log::warning('share.folder_create_failed', [
                    'share_token' => $token,
                    'fullPath' => $fullPath,
                    'reason' => 'parent_not_writable',
                    'uid' => posix_geteuid(),
                ]);
                return response()->json(['error' => 'El directorio no es escribible por el usuario del servidor'], 500);
            }
            try {
                mkdir($fullPath, 0755, true);
            } catch (\Throwable $e) {
                Log::warning('share.folder_create_failed', [
                    'share_token' => $token,
                    'fullPath' => $fullPath,
                    'reason' => 'mkdir_exception',
                    'message' => $e->getMessage(),
                ]);
                return response()->json(['error' => 'No se pudo crear el directorio en el almacenamiento: ' . $e->getMessage()], 500);
            }
        }

        $newFolder = File::create([
            'name' => $request->name,
            'path' => $relativePath,
            'size' => 0,
            'mime_type' => null,
            'storage_provider_id' => $storageProvider->id,
            'owner_id' => $targetFolder->owner_id,
            'parent_id' => $targetFolder->id,
            'is_folder' => true,
            'is_personal' => false,
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
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

        if ($share->password_hash && !$request->session()->has("share_auth_{$token}")) {
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

    /**
     * Contenido visible de una carpeta en un enlace publico.
     *
     * Reune dos fuentes:
     *  1. Los hijos DIRECTOS de la fila de la carpeta (`parent_id = folder.id`).
     *  2. Las filas raiz (`parent_id IS NULL`) de los storages cuyo
     *     `base_path` cae DENTRO de la ruta fisica de la carpeta. Esos
     *     storages tienen su propia raiz fisica dentro de la carpeta
     *     compartida, asi que sus contenidos no aparecen como hijos de la
     *     fila de la carpeta: viven en otro storage del arbol.
     *
     * El storage propio de la carpeta queda excluido salvo que su
     * `base_path` sea EXACTAMENTE la ruta fisica de la carpeta: en ese caso
     * la carpeta ES la raiz de ese storage y sus raices son sus hijos.
     *
     * Deduplica por nombre. Ante el mismo nombre en dos storages gana la fila
     * del storage mas especifico (`base_path` mas largo), que es la que
     * describe la ruta mas cercana a la operacion real.
     *
     * El resultado se limita a `shares.folder_listing_max_items`: una carpeta
     * que sea la raiz fisica de un sub-storage puede tener decenas de miles de
     * entradas y la vista las serializa todas en el DOM. Cuando se alcanza el
     * tope, el listado se marca como truncado para que la vista lo diga en
     * vez de mostrar un recorte silencioso.
     *
     * @return array{items: Collection<int,File>, total: int, truncated: bool}
     */
    private function folderContents(File $folder): array
    {
        $rows = $folder->children()->get()
            ->map(fn (File $child) => ['file' => $child, 'depth' => $this->storageDepth($child)]);

        $folderPhysical = $folder->physicalPath();

        if ($folderPhysical !== null) {
            $storageIds = $this->storageIdsUnder($folderPhysical);

            if ($storageIds !== []) {
                $roots = File::whereIn('storage_provider_id', $storageIds)
                    ->whereNull('parent_id')
                    ->get()
                    ->map(fn (File $root) => ['file' => $root, 'depth' => $this->storageDepth($root)]);

                $rows = $rows->concat($roots);
            }
        }

        $deduped = $rows
            ->sortByDesc(fn (array $row) => $row['depth'])
            ->groupBy(fn (array $row) => (string) $row['file']->name)
            ->map(fn (Collection $group) => $group->first()['file'])
            ->values();

        $total = $deduped->count();
        $limit = max(1, (int) config('shares.folder_listing_max_items', 2000));

        return [
            'items' => $deduped->take($limit)->values(),
            'total' => $total,
            'truncated' => $total > $limit,
        ];
    }

    /**
     * Storages cuya raiz fisica cae dentro de la ruta fisica indicada.
     *
     * Incluye el storage cuyo `base_path` es EXACTAMENTE la ruta (su raiz es
     * esa carpeta) y todos los mas especificos. Excluye los storages
     * ANTERIORES en el arbol: su contenido esta por encima, no dentro.
     *
     * @return array<int,int>
     */
    private function storageIdsUnder(string $physicalPath): array
    {
        $physicalPath = rtrim($physicalPath, '/');

        return StorageProvider::query()
            ->whereNotNull('base_path')
            ->whereRaw("rtrim(base_path, '/') <> ''")
            ->whereRaw("(rtrim(base_path, '/') = ? OR rtrim(base_path, '/') LIKE ?)", [
                $physicalPath,
                $physicalPath . '/%',
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Profundidad de especificidad del storage de un recurso: la longitud de
     * su `base_path`. Sirve para desempatar por nombre entre storages.
     */
    private function storageDepth(File $file): int
    {
        $storage = $file->relationLoaded('storageProvider')
            ? $file->storageProvider
            : $file->storageProvider()->first();

        return $storage === null ? 0 : strlen((string) $storage->base_path);
    }

    /**
     * Migas de pan de la carpeta actual hasta la carpeta raiz del enlace.
     *
     * Caso normal: la cadena `parent_id` llega a la raiz.
     *
     * Caso cross-storage: la carpeta actual vive en un sub-storage y su
     * `parent_id` es NULL, asi que la cadena no llega a la raiz. En ese caso
     * las midas se derivan de la RUTA FISICA: cada segmento intermedio se
     * busca como fila de `files` en el storage de la carpeta actual; si no
     * existe fila para ese segmento, se usa una mida sin enlace (la vista no
     * genera un link a un id inexistente).
     */
    private function buildBreadcrumbs(File $currentFolder, File $rootFolder): array
    {
        $chain = [];
        $crumb = $currentFolder;

        while ($crumb && $crumb->id !== $rootFolder->id) {
            array_unshift($chain, $crumb);
            $crumb = $crumb->parent;
        }

        if ($crumb !== null) {
            array_unshift($chain, $rootFolder);

            return $chain;
        }

        // Cross-storage: derivar de la ruta fisica.
        $rootPhysical = $rootFolder->physicalPath();
        $currentPhysical = $currentFolder->physicalPath();

        if ($rootPhysical === null || $currentPhysical === null) {
            return [$rootFolder];
        }

        $rootPhysical = rtrim($rootPhysical, '/');
        $currentPhysical = rtrim($currentPhysical, '/');

        if (! str_starts_with($currentPhysical . '/', $rootPhysical . '/')) {
            return [$rootFolder];
        }

        $relative = trim(substr($currentPhysical, strlen($rootPhysical)), '/');
        $segments = $relative === '' ? [] : explode('/', $relative);

        $crumbs = [$rootFolder];
        $storageId = $currentFolder->storage_provider_id;
        $accumulated = '';

        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated . '/' . $segment;

            $row = File::where('storage_provider_id', $storageId)
                ->where('path', $accumulated)
                ->where('is_folder', true)
                ->first();

            $crumbs[] = $row ?? (object) ['id' => null, 'name' => $segment];
        }

        return $crumbs;
    }

    private function isDescendantOf(File $file, File $ancestor): bool
    {
        // 1) Arbol de catalogacion: cadena `parent_id` dentro del storage.
        $current = $file;
        while ($current) {
            if ($current->id === $ancestor->id) {
                return true;
            }
            $current = $current->parent;
        }

        // 2) Equivalencia fisica: el recurso vive en un storage cuyo
        //    `base_path` cae dentro de la ruta fisica del ancestro. Sin esta
        //    comprobacion, las acciones sobre archivos de un sub-storage
        //    respondian 403 aunque estuvieran dentro de la carpeta compartida.
        $filePhysical = $file->physicalPath();
        $ancestorPhysical = $ancestor->physicalPath();

        if ($filePhysical === null || $ancestorPhysical === null) {
            return false;
        }

        return str_starts_with(
            rtrim($filePhysical, '/') . '/',
            rtrim($ancestorPhysical, '/') . '/'
        );
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