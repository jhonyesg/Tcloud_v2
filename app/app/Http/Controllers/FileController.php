<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
            $searchTerm = $request->has('q') && strlen(trim($request->q)) >= 2 ? trim($request->q) : null;

            if ($searchTerm !== null) {
                $storageId = $request->has('storage_id') ? $request->storage_id : null;
                $parentId  = $request->has('parent_id')  ? $request->parent_id  : null;

                $query = File::query()->where('name', 'ilike', '%' . $searchTerm . '%');

                if ($parentId !== null) {
                    // Búsqueda recursiva dentro de la carpeta actual y sus subcarpetas
                    $folderRows = DB::select("
                        WITH RECURSIVE folder_tree AS (
                            SELECT id FROM files WHERE id = ?
                            UNION ALL
                            SELECT f.id FROM files f
                            INNER JOIN folder_tree ft ON f.parent_id = ft.id
                            WHERE f.is_folder = true
                        )
                        SELECT id FROM folder_tree
                    ", [$parentId]);
                    $folderIds = collect($folderRows)->pluck('id')->toArray();
                    $query->whereIn('parent_id', $folderIds);
                } elseif ($storageId !== null) {
                    $query->where('storage_provider_id', $storageId);
                }

                if (!$user->isAdmin()) {
                    $userStorageIds = $user->userStorages()->pluck('storage_provider_id')->toArray();
                    $query->where(function ($q) use ($user, $userStorageIds) {
                        $q->where('owner_id', $user->id)
                          ->orWhereIn('storage_provider_id', $userStorageIds);
                    });
                }

                $files = $query->orderBy('is_folder', 'desc')->orderBy('created_at', 'desc')->get();
                return response()->json($files);
            }

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

            $files = $query->orderBy('is_folder', 'desc')->orderBy('created_at', 'desc')->get();
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

        if ($storage->type === 'local') {
            $physicalPath = rtrim($storage->base_path, '/') . '/' . $path;
            if (!is_dir($physicalPath)) {
                if (!mkdir($physicalPath, 0755, true)) {
                    return response()->json(['error' => 'No se pudo crear el directorio'], 500);
                }
            }
        }

        $file = File::create([
            'name' => $request->name,
            'path' => $path,
            'size' => 0,
            'mime_type' => 'folder',
            'storage_provider_id' => $storageId,
            'owner_id' => $user->id,
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
        $destDir = dirname(rtrim($storage->base_path, '/') . '/' . $path);

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (!is_writable($destDir)) {
            return response()->json(['error' => 'El directorio de destino no tiene permisos de escritura'], 500);
        }

        $file->move($destDir, $filename);

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

        if (str_starts_with($storage->base_path ?? '', '/home/www/Usuarios_tcloud/')) {
            $user->increment('personal_used_bytes', $size);
        }

        return response()->json($storedFile, 201);
    }

    public function textContent(int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Not supported for this storage type'], 400);
        }

        $fullPath = rtrim($storage->base_path, '/') . '/' . $file->path;
        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found on disk'], 404);
        }

        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $mime = $file->mime_type ?? '';

        if ($mime === 'text/plain' || in_array($ext, ['txt', 'log', 'md', 'csv'])) {
            $content = @file_get_contents($fullPath);
            if ($content === false) {
                return response()->json(['error' => 'Cannot read file'], 500);
            }
            $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            return response()->json(['content' => $content, 'type' => 'text']);
        }

        if ($ext === 'odt' || $mime === 'application/vnd.oasis.opendocument.text') {
            $zip = new \ZipArchive();
            if ($zip->open($fullPath) !== true) {
                return response()->json(['error' => 'Cannot open ODT file'], 500);
            }
            $xml = $zip->getFromName('content.xml');
            $zip->close();

            if ($xml === false) {
                return response()->json(['error' => 'Cannot read ODT content'], 500);
            }

            $xml = preg_replace('/<\/text:p>/i', "\n", $xml);
            $xml = preg_replace('/<\/text:h[^>]*>/i', "\n", $xml);
            $xml = preg_replace('/<text:line-break[^>]*\/?>/i', "\n", $xml);
            $xml = preg_replace('/<text:tab[^>]*\/?>/i', "\t", $xml);
            $text = strip_tags($xml);
            $text = preg_replace('/\n{3,}/', "\n\n", trim($text));

            return response()->json(['content' => $text, 'type' => 'text']);
        }

        return response()->json(['error' => 'Unsupported file type for text preview'], 400);
    }

    public function saveTextContent(Request $request, int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'write')) {
            return response()->json(['error' => 'Write permission required'], 403);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Not supported for this storage type'], 400);
        }

        $realBase = realpath($storage->base_path);
        $fullPath = realpath(rtrim($storage->base_path, '/') . '/' . $file->path);

        if (!$fullPath || !$realBase || !str_starts_with($fullPath, $realBase)) {
            return response()->json(['error' => 'Invalid file path'], 400);
        }

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found on disk'], 404);
        }

        $content = $request->input('content', '');
        $bytes = file_put_contents($fullPath, $content);

        if ($bytes === false) {
            return response()->json(['error' => 'Cannot write to file — check permissions'], 500);
        }

        $file->update([
            'size' => $bytes,
            'file_modified_at' => now(),
        ]);

        return response()->json(['ok' => true, 'size' => $bytes]);
    }

    public function rotate(Request $request, int $file)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate(['degrees' => 'required|integer|in:90,180,270']);

        $fileModel = File::findOrFail($file);

        if (!$this->checkFilePermission($fileModel, 'write')) {
            return response()->json(['error' => 'Sin permisos de escritura'], 403);
        }

        if (!str_starts_with($fileModel->mime_type ?? '', 'image/')) {
            return response()->json(['error' => 'Solo se pueden rotar imágenes'], 400);
        }

        $storage = $fileModel->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Operación no soportada para este storage'], 400);
        }

        $fullPath = rtrim($storage->base_path, '/') . '/' . $fileModel->path;
        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'Archivo no encontrado en disco'], 404);
        }

        $degrees = (int) $request->degrees;
        // GD rota en sentido antihorario; negamos para obtener sentido horario (igual que CSS)
        $gdDegrees = (360 - $degrees) % 360;

        $img = imagecreatefromstring(file_get_contents($fullPath));
        if (!$img) {
            return response()->json(['error' => 'No se pudo leer la imagen'], 500);
        }

        $rotated = imagerotate($img, $gdDegrees, 0);
        imagedestroy($img);

        if (!$rotated) {
            return response()->json(['error' => 'Error al rotar la imagen'], 500);
        }

        $mime = $fileModel->mime_type;
        $saved = false;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $saved = imagejpeg($rotated, $fullPath, 92);
        } elseif ($mime === 'image/png') {
            imagesavealpha($rotated, true);
            $saved = imagepng($rotated, $fullPath, 6);
        } elseif ($mime === 'image/webp') {
            $saved = imagewebp($rotated, $fullPath, 92);
        } elseif ($mime === 'image/gif') {
            $saved = imagegif($rotated, $fullPath);
        } else {
            imagedestroy($rotated);
            return response()->json(['error' => 'Formato de imagen no soportado para guardar'], 400);
        }

        imagedestroy($rotated);

        if (!$saved) {
            return response()->json(['error' => 'No se pudo guardar la imagen rotada'], 500);
        }

        $newSize = filesize($fullPath);
        $fileModel->update(['size' => $newSize]);

        return response()->json(['ok' => true, 'size' => $newSize]);
    }

    public function download(Request $request, int $id)
    {
        $file = File::findOrFail($id);

        if (!$this->checkFilePermission($file, 'read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Download not supported for this storage type'], 400);
        }

        $realBasePath = realpath($storage->base_path);
        $realFullPath = realpath($storage->base_path . '/' . $file->path);

        if (!$realFullPath || !$realBasePath || !str_starts_with($realFullPath, $realBasePath)) {
            return response()->json(['error' => 'Invalid file path'], 400);
        }

        if (!file_exists($realFullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $response = new BinaryFileResponse($realFullPath);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->name);
        $response->prepare($request);
        return $response;
    }

    public function downloadFolder(Request $request, int $id)
    {
        $folder = File::findOrFail($id);

        if (!$folder->is_folder) {
            return response()->json(['error' => 'Not a folder'], 400);
        }

        if (!$this->checkFilePermission($folder, 'read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $storage = $folder->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Download not supported for this storage type'], 400);
        }

        $maxBytes = 2 * 1024 * 1024 * 1024; // 2 GB
        $totalSize = $this->getFolderSizeRecursive($folder->id);
        if ($totalSize > $maxBytes) {
            $gb = number_format($totalSize / (1024 ** 3), 2);
            return response()->json([
                'error' => "La carpeta pesa {$gb} GB. Solo se permite descargar carpetas de hasta 2 GB.",
                'size_exceeded' => true,
            ], 413);
        }

        if ($request->boolean('check')) {
            return response()->json(['ok' => true, 'size' => $totalSize]);
        }

        $realBasePath = realpath($storage->base_path);
        if (!$realBasePath) {
            return response()->json(['error' => 'Storage path not found'], 404);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'tcloud_folder_');
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Could not create ZIP'], 500);
        }

        $this->addFolderToZip($zip, $folder, $realBasePath, '');

        $zip->close();

        $zipName = $folder->name . '.zip';

        return response()->download($tmpFile, $zipName, [
            'Content-Type' => 'application/zip',
            'X-Accel-Buffering' => 'no',
        ])->deleteFileAfterSend(true);
    }

    private function getFolderSizeRecursive(int $folderId): int
    {
        $rows = DB::select("
            WITH RECURSIVE folder_tree AS (
                SELECT id FROM files WHERE id = ?
                UNION ALL
                SELECT f.id FROM files f
                INNER JOIN folder_tree ft ON f.parent_id = ft.id
                WHERE f.is_folder = true
            )
            SELECT COALESCE(SUM(f.size), 0) AS total
            FROM files f
            INNER JOIN folder_tree ft ON f.parent_id = ft.id
            WHERE f.is_folder = false
        ", [$folderId]);

        return (int) ($rows[0]->total ?? 0);
    }

    private function addFolderToZip(\ZipArchive $zip, File $folder, string $realBasePath, string $zipPrefix): void
    {
        $children = File::where('parent_id', $folder->id)->get();

        $folderPrefix = $zipPrefix . $folder->name . '/';
        $zip->addEmptyDir($folderPrefix);

        foreach ($children as $child) {
            if ($child->is_folder) {
                $this->addFolderToZip($zip, $child, $realBasePath, $folderPrefix);
            } else {
                $realFilePath = realpath($realBasePath . '/' . $child->path);
                if ($realFilePath && str_starts_with($realFilePath, $realBasePath) && file_exists($realFilePath)) {
                    $zip->addFile($realFilePath, $folderPrefix . $child->name);
                }
            }
        }
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

        return view('files.preview', [
            'fileId'   => $id,
            'fileMime' => $file->mime_type,
            'fileName' => $file->name,
        ]);
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
                'can_create_shares' => (bool) $us->can_create_shares,
                'accessible' => $us->storageProvider->is_accessible,
                'last_checked' => $us->storageProvider->last_checked_at?->format('d M, H:i'),
                'is_personal' => str_starts_with($us->storageProvider->base_path ?? '', '/home/www/Usuarios_tcloud/'),
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
        if ($user && $file->size > 0) {
            $storage = $file->storageProvider;
            if ($storage && str_starts_with($storage->base_path ?? '', '/home/www/Usuarios_tcloud/')) {
                $user->decrement('personal_used_bytes', $file->size);
            }
        }
        $this->deleteClipThumbs($file->id);
        $file->delete();
    }

    private function deleteClipThumbs(int $fileId): void
    {
        $dir = storage_path("app/clip-thumbs/{$fileId}");
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}
