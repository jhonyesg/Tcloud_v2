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

        $unreadable = [];

        try {
            $items = scandir($basePath);
            if ($items === false) {
                return ScanResult::failed(ScanResult::SCANDIR_FAILED, $basePath);
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                // Aislado POR ENTRADA a proposito. Antes el try/catch envolvia el
                // bucle entero y una sola entrada ilegible descartaba el
                // directorio completo: en los NFS de difusor01 hay archivos que
                // devuelven EIO, y por 4 de ellos se perdian de vista los 64
                // archivos sanos de la misma carpeta.
                $entry = $this->describeEntry($basePath, $item);

                if ($entry === null) {
                    $unreadable[] = $item;
                    continue;
                }

                $entries[] = $entry;
            }
        } catch (\Throwable $e) {
            // Solo se llega aqui si falla scandir() o algo ajeno a una entrada
            // concreta: entonces si que no se leyo nada creible y hay que
            // descartar el parcial, porque devolverlo como fiable haria que la
            // purga borrase lo que no dio tiempo a leer.
            \Illuminate\Support\Facades\Log::warning('storage_sync.scan_exception', [
                'path' => $basePath,
                'error' => $e->getMessage(),
            ]);

            return ScanResult::failed(ScanResult::EXCEPTION, $basePath);
        }

        return $unreadable === []
            ? ScanResult::ok($entries, $basePath)
            : ScanResult::partial($entries, $unreadable, $basePath);
    }

    /**
     * Describe una entrada del directorio, o null si no se pudo consultar.
     *
     * Los stat() se suprimen con @ y se comprueba el false en vez de confiar en
     * que el manejador de errores convierta el E_WARNING en excepcion: asi se
     * comporta igual dentro y fuera del framework, y los tests no dependen de
     * como PHPUnit trate los warnings.
     *
     * Un directorio ilegible tambien cae aqui: is_dir() devuelve false cuando el
     * stat falla, la entrada se trata como archivo y el filesize() la descarta.
     *
     * @return array<string,mixed>|null
     */
    private function describeEntry(string $basePath, string $item): ?array
    {
        $fullPath = rtrim($basePath, '/') . '/' . $item;

        try {
            // NFS cachea atributos; sin esto una entrada rota puede seguir
            // pareciendo legible con datos de una pasada anterior.
            clearstatcache(true, $fullPath);

            $isFolder = is_dir($fullPath);
            $modifiedAt = @filemtime($fullPath);
            $size = $isFolder ? 0 : @filesize($fullPath);

            if ($modifiedAt === false || $size === false) {
                return null;
            }

            return [
                'name' => $item,
                'path' => $item,
                'parent_path' => '',
                'is_folder' => $isFolder,
                'size' => $size,
                'mime_type' => $isFolder ? 'folder' : $this->getMimeType($item),
                'modified_at' => $modifiedAt,
            ];
        } catch (\Throwable $e) {
            return null;
        }
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
