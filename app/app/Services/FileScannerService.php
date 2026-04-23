<?php

namespace App\Services;

class FileScannerService
{
    private const MAX_DEPTH = 30;

    public function scanDirectory(string $basePath, int $depth = 0): array
    {
        $entries = [];

        if ($depth > self::MAX_DEPTH) {
            return $entries;
        }

        if (!is_dir($basePath)) {
            return $entries;
        }

        if (!is_readable($basePath)) {
            return $entries;
        }

        try {
            $items = scandir($basePath);
            if ($items === false) {
                return $entries;
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
        } catch (\Exception $e) {
            return $entries;
        }

        return $entries;
    }

    public function scanSubdirectory(string $basePath, string $relativePath, int $depth = 0): array
    {
        $fullPath = rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');

        if (!$this->isPathWithinBase($basePath, $fullPath)) {
            return [];
        }

        $realPath = realpath($fullPath);
        if (!$realPath || !is_dir($realPath)) {
            return [];
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
