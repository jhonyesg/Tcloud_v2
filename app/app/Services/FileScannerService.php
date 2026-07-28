<?php

namespace App\Services;

class FileScannerService
{
    private const MAX_DEPTH = 30;

    /**
     * Escanea un directorio devolviendo un resultado que distingue "vacio" de
     * "no fiable".
     *
     * Antes devolvia [] tanto para un directorio realmente vacio como para
     * cualquier fallo de acceso, y StorageSyncService no podia diferenciarlos:
     * un montaje NFS caido parecia una carpeta vacia y provocaba el borrado del
     * arbol en BD. Ver ScanResult.
     */
    public function scanDirectory(string $basePath, int $depth = 0): ScanResult
    {
        $entries = [];

        if ($depth > self::MAX_DEPTH) {
            return ScanResult::failed(ScanResult::DEPTH_EXCEEDED, $basePath);
        }

        // NFS cachea atributos: sin esto, is_dir()/is_readable() pueden devolver
        // datos obsoletos de antes de que el montaje se cayera.
        clearstatcache(true, $basePath);

        if (!is_dir($basePath)) {
            return ScanResult::failed(ScanResult::NOT_A_DIRECTORY, $basePath);
        }

        if (!is_readable($basePath)) {
            return ScanResult::failed(ScanResult::NOT_READABLE, $basePath);
        }

        try {
            $items = scandir($basePath);
            if ($items === false) {
                return ScanResult::failed(ScanResult::SCANDIR_FAILED, $basePath);
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $fullPath = rtrim($basePath, '/') . '/' . $item;
                $relativePath = $item;

                if (is_dir($fullPath)) {
                    $entries[] = [
                        'name' => $item,
                        'path' => $relativePath,
                        'parent_path' => '',
                        'is_folder' => true,
                        'size' => 0,
                        'mime_type' => 'folder',
                        'modified_at' => filemtime($fullPath),
                    ];
                } else {
                    $entries[] = [
                        'name' => $item,
                        'path' => $relativePath,
                        'parent_path' => '',
                        'is_folder' => false,
                        'size' => filesize($fullPath),
                        'mime_type' => $this->getMimeType($item),
                        'modified_at' => filemtime($fullPath),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Un fallo a mitad del recorrido deja $entries parcial: descartarlo.
            // Devolver entradas parciales como si fueran fiables haria que la
            // purga borrase todo lo que no dio tiempo a leer.
            \Illuminate\Support\Facades\Log::warning('storage_sync.scan_exception', [
                'path' => $basePath,
                'error' => $e->getMessage(),
            ]);

            return ScanResult::failed(ScanResult::EXCEPTION, $basePath);
        }

        return ScanResult::ok($entries, $basePath);
    }

    public function scanSubdirectory(string $basePath, string $relativePath, int $depth = 0): ScanResult
    {
        $fullPath = rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');

        if (!$this->isPathWithinBase($basePath, $fullPath)) {
            return ScanResult::failed(ScanResult::NOT_A_DIRECTORY, $fullPath);
        }

        $realPath = realpath($fullPath);
        if (!$realPath || !is_dir($realPath)) {
            return ScanResult::failed(ScanResult::NOT_A_DIRECTORY, $fullPath);
        }

        return $this->scanDirectory($realPath, $depth);
    }

    public function getMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            '7z' => 'application/x-7z-compressed',
            'php' => 'text/x-php',
            'js' => 'application/javascript',
            'ts' => 'application/typescript',
            'java' => 'text/x-java',
            'py' => 'text/x-python',
            'rb' => 'text/x-ruby',
            'c' => 'text/x-c',
            'cpp' => 'text/x-c++',
            'css' => 'text/css',
            'html' => 'text/html',
            'xml' => 'text/xml',
            'json' => 'application/json',
            'yaml' => 'text/yaml',
            'yml' => 'text/yaml',
            'sql' => 'text/x-sql',
            'sh' => 'application/x-sh',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    public function isPathWithinBase(string $basePath, string $fullPath): bool
    {
        $realBasePath = realpath($basePath);
        $realFullPath = realpath($fullPath);

        if (!$realBasePath || !$realFullPath) {
            return false;
        }

        return str_starts_with($realFullPath, $realBasePath);
    }
}
