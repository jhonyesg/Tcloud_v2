<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MediaPreviewController extends Controller
{
    public function preview(Request $request, int $fileId)
    {
        $file = File::findOrFail($fileId);
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

        // BinaryFileResponse streams in 4KB chunks, handles Range requests natively,
        // and never loads the full file into memory regardless of file size.
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
}
