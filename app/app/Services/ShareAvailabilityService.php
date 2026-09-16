<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Collection;

class ShareAvailabilityService
{
    /**
     * Verify each referenced File at most once. A missing parent or inaccessible
     * storage is unknown, not missing, because the filesystem gave no reliable
     * answer about the resource itself.
     *
     * @param  Collection<int, File>  $files
     * @return array{checked:int, available:int, missing:int, unknown:int, details:list<array<string,mixed>>}
     */
    public function verify(Collection $files): array
    {
        $summary = [
            'checked' => 0,
            'available' => 0,
            'missing' => 0,
            'unknown' => 0,
            'details' => [],
        ];

        foreach ($files->filter()->unique('id') as $file) {
            $summary['checked']++;
            $result = $this->verifyFile($file);
            $state = $result['state'];
            $summary[$state]++;
            $summary['details'][] = [
                'file_id' => $file->id,
                'state' => $state,
                'reason' => $result['reason'],
            ];
        }

        return $summary;
    }

    /** @return array{state:'available'|'missing'|'unknown', reason:string} */
    private function verifyFile(File $file): array
    {
        $storage = $file->storageProvider;

        if (!$storage || $storage->type !== 'local') {
            $this->markUnknown($file);

            return ['state' => 'unknown', 'reason' => 'storage_not_local'];
        }

        if ($storage->is_accessible === false) {
            $this->markUnknown($file);

            return ['state' => 'unknown', 'reason' => 'storage_inaccessible'];
        }

        $basePath = realpath((string) $storage->base_path);
        if (!$basePath || !is_dir($basePath)) {
            $this->markUnknown($file);

            return ['state' => 'unknown', 'reason' => 'storage_path_unavailable'];
        }

        $candidate = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($file->path, DIRECTORY_SEPARATOR);
        $parentPath = realpath(dirname($candidate));

        if (!$parentPath || !$this->isWithin($parentPath, $basePath)) {
            $this->markUnknown($file);

            return ['state' => 'unknown', 'reason' => 'parent_path_unavailable'];
        }

        if (file_exists($candidate)) {
            $file->forceFill([
                'availability_state' => 'available',
                'last_verified_at' => now(),
                'missing_since_at' => null,
            ])->saveQuietly();

            return ['state' => 'available', 'reason' => 'path_exists'];
        }

        $file->forceFill([
            'availability_state' => 'missing',
            'last_verified_at' => now(),
            'missing_since_at' => $file->missing_since_at ?: now(),
        ])->saveQuietly();

        return ['state' => 'missing', 'reason' => 'path_not_found'];
    }

    private function markUnknown(File $file): void
    {
        $file->forceFill([
            'availability_state' => 'unknown',
            'last_verified_at' => null,
            'missing_since_at' => null,
        ])->saveQuietly();
    }

    private function isWithin(string $path, string $base): bool
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path . DIRECTORY_SEPARATOR, $base);
    }
}
