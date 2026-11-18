<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Sirve la previsualización de un archivo por su id.
 *
 * `FileController::preview()` hace lo mismo pero COMPROBANDO el permiso de
 * lectura. Esta ruta no lo hacía: cualquier usuario autenticado podía pedir
 * `/media/{id}/preview` con el id de un archivo y servirse el material de
 * un storage que no tenía asignado.
 *
 * El agujero era preexistente, pero el módulo de solicitud puntual lo
 * convertía en un oráculo: su ficha de cobertura devolvía el `file_id` y la
 * ruta física del material pedido, así que el `id` adivinable se
 * convertía en una ruta confirmada. Con la comprobación de permiso aquí, la
 * vigencia de los enlaces de entrega deja de ser cosmética: sin ella el
 * cliente podía saltarse los 3 días y la revocación.
 */
class MediaPreviewController extends Controller
{
    public function preview(Request $request, int $fileId)
    {
        $file = File::findOrFail($fileId);

        if (! $this->canRead($file)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $mimeType = $file->mime_type;

        $supported = $mimeType === 'application/pdf'
            || str_starts_with($mimeType, 'image/')
            || str_starts_with($mimeType, 'video/')
            || str_starts_with($mimeType, 'audio/');

        if (!$supported) {
            return response()->json(['error' => 'Preview not supported for this file type'], 400);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Storage not supported'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // For video/audio files, offload streaming to nginx via X-Accel-Redirect.
        // Nginx handles HTTP Range requests natively, which is required for mobile
        // playback when the moov atom is at the end of large MP4 files.
        if (str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/')) {
            $internalPath = '/internal-media' . $fullPath;
            return response('', 200, [
                'Content-Type' => $mimeType,
                'X-Accel-Redirect' => $internalPath,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        // BinaryFileResponse streams in 4KB chunks, handles Range requests natively,
        // and never loads the whole file into memory regardless of file size.
        // X-Accel-Buffering: no tells nginx not to buffer the FastCGI response so
        // data flows directly to the browser without accumulating in nginx memory.
        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file->name);
        $response->prepare($request);

        return $response;
    }

    public function thumbnail(int $fileId)
    {
        $file = File::findOrFail($fileId);

        if (! $this->canRead($file)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (!str_starts_with($file->mime_type, 'image/')) {
            return response()->json(['error' => 'Not an image'], 400);
        }

        $storage = $file->storageProvider;
        if (!$storage) {
            return response()->json(['error' => 'Storage not found'], 404);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $file->mime_type);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }

    /**
     * ¿El usuario de la sesión puede LEER este archivo?
     *
     * Misma regla que `FileController::checkFilePermission()`: el admin
     * puede todo; el resto necesita permiso de lectura sobre el storage del
     * archivo, y si el archivo no tiene storage, tiene que ser suyo.
     */
    private function canRead(File $file): bool
    {
        $userId = Session::get('user_id');

        if (! $userId) {
            return false;
        }

        $user = User::find($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($file->storage_provider_id) {
            return $user->hasStoragePermission($file->storage_provider_id, 'read');
        }

        return $file->owner_id === $user->id;
    }
}
