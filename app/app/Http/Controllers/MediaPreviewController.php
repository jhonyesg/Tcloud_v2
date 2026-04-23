<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;

class MediaPreviewController extends Controller
{
    private const THUMB_TTL = 86400;
    private const STREAM_TTL = 3600;

    public function preview(Request $request, int $fileId)
    {
        $file = File::findOrFail($fileId);
        $mimeType = $file->mime_type;

        if (str_starts_with($mimeType, 'image/')) {
            return $this->previewImage($file, $mimeType);
        }

        if ($mimeType === 'application/pdf') {
            return $this->previewPdf($file);
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return $this->previewAudio($file, $mimeType);
        }

        if (str_starts_with($mimeType, 'video/')) {
            return $this->previewVideo($request, $file, $mimeType);
        }

        return response()->json(['error' => 'Preview not supported'], 400);
    }

    private function previewImage(File $file, string $mimeType)
    {
        $cacheKey = "file:thumb:{$file->id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response($cached, 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
                'X-Cache' => 'HIT',
            ]);
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Storage not supported'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $content = file_get_contents($fullPath);

        Cache::put($cacheKey, $content, self::THUMB_TTL);

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'X-Cache' => 'MISS',
        ]);
    }

    private function previewPdf(File $file)
    {
        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Storage not supported'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
        ]);
    }

    private function previewAudio(File $file, string $mimeType)
    {
        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Storage not supported'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
        ]);
    }

    private function previewVideo(Request $request, File $file, string $mimeType)
    {
        $cacheKey = "media:stream:{$file->id}";
        $range = $request->header('Range');

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Storage not supported'], 400);
        }

        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $fileSize = filesize($fullPath);

        if ($range) {
            return $this->streamVideoRange($fullPath, $fileSize, $mimeType, $range);
        }

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    private function streamVideoRange(string $path, int $fileSize, string $mimeType, string $range)
    {
        $parts = explode('=', $range);
        $ranges = explode('-', $parts[1]);

        $start = intval($ranges[0]);
        $end = isset($ranges[1]) && $ranges[1] !== '' ? intval($ranges[1]) : $fileSize - 1;

        $length = $end - $start + 1;

        $handle = fopen($path, 'rb');
        fseek($handle, $start);
        $chunk = fread($handle, $length);
        fclose($handle);

        return response($chunk, 206, [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function thumbnail(int $fileId)
    {
        $file = File::findOrFail($fileId);

        if (!str_starts_with($file->mime_type, 'image/')) {
            return response()->json(['error' => 'Not an image'], 400);
        }

        $cacheKey = "file:thumb:{$file->id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response($cached, 200, [
                'Content-Type' => $file->mime_type,
                'X-Cache' => 'HIT',
            ]);
        }

        $storage = $file->storageProvider;
        $fullPath = $storage->base_path . '/' . $file->path;

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $content = file_get_contents($fullPath);
        Cache::put($cacheKey, $content, self::THUMB_TTL);

        return response($content, 200, [
            'Content-Type' => $file->mime_type,
            'X-Cache' => 'MISS',
        ]);
    }
}
