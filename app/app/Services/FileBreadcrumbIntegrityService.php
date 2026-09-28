<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileBreadcrumbIntegrityService
{
    public static function chainFor(int $fileId): array
    {
        $rows = DB::select("
            WITH RECURSIVE chain AS (
                SELECT id, name, parent_id, storage_provider_id, 0 AS depth
                FROM files WHERE id = ?
                UNION ALL
                SELECT f.id, f.name, f.parent_id, f.storage_provider_id, c.depth + 1
                FROM files f INNER JOIN chain c ON f.id = c.parent_id
            )
            SELECT depth, id, name, parent_id, storage_provider_id FROM chain ORDER BY depth
        ", [$fileId]);

        return array_map(fn($r) => [
            'depth'     => (int) $r->depth,
            'id'        => (int) $r->id,
            'name'      => (string) $r->name,
            'parent_id' => $r->parent_id !== null ? (int) $r->parent_id : null,
            'storage_provider_id' => (int) $r->storage_provider_id,
        ], $rows);
    }

    public static function findConsecutiveDuplicates(array $chain): array
    {
        $dup = [];
        for ($i = 1; $i < count($chain); $i++) {
            if ($chain[$i]['name'] === $chain[$i-1]['name']) {
                $dup[] = $chain[$i]['depth'];
            }
        }
        return $dup;
    }

    public static function repairInPlace(int $fileId): array
    {
        try {
            $chain = self::chainFor($fileId);
        } catch (\Throwable $e) {
            Log::error('breadcrumb.repair_chain_failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return ['repaired' => false, 'reason' => 'chain_query_failed'];
        }

        $dup = self::findConsecutiveDuplicates($chain);

        if (count($dup) === 0) {
            return ['repaired' => false, 'reason' => 'no_duplicate'];
        }

        if (count($dup) > 1) {
            Log::warning('breadcrumb.repair_ambiguous', [
                'file_id' => $fileId,
                'duplicated_depths' => $dup,
            ]);
            return ['repaired' => false, 'reason' => 'ambiguous_multiple'];
        }

        $duplicateDepth = $dup[0];
        $duplicateIndex = null;
        for ($i = 1; $i < count($chain); $i++) {
            if ($chain[$i]['depth'] === $duplicateDepth) {
                $duplicateIndex = $i;
                break;
            }
        }

        if ($duplicateIndex === null || $duplicateIndex < 1) {
            return ['repaired' => false, 'reason' => 'depth_index_unresolved'];
        }

        if (!isset($chain[$duplicateIndex + 1])) {
            return ['repaired' => false, 'reason' => 'no_grandparent'];
        }

        $targetId = $chain[$duplicateIndex - 1]['id'];
        $oldParentId = $chain[$duplicateIndex]['id'];
        $newParentId = $chain[$duplicateIndex + 1]['id'];

        if ((int) DB::table('files')->where('id', $targetId)->value('parent_id') === $newParentId) {
            return ['repaired' => false, 'reason' => 'already_correct'];
        }

        DB::table('files')->where('id', $targetId)->update(['parent_id' => $newParentId]);

        $row = DB::table('files')->where('id', $targetId)->first();
        if ($row) {
            Cache::increment("folder_gen:{$row->storage_provider_id}:{$newParentId}");
        }

        Log::warning('breadcrumb.repair', [
            'file_id' => $targetId,
            'old_parent_id' => $oldParentId,
            'new_parent_id' => $newParentId,
            'duplicated_name' => $chain[$duplicateIndex]['name'],
            'storage_provider_id' => $row->storage_provider_id ?? null,
        ]);

        return [
            'repaired' => true,
            'file_id' => $targetId,
            'new_parent_id' => $newParentId,
        ];
    }

    public static function assertNoSelfNestedName(?int $storageId, ?int $parentId, string $name): array
    {
        if ($parentId === null || $storageId === null) {
            return ['ok' => true, 'reason' => 'no_parent'];
        }

        $parent = File::find($parentId);
        if (!$parent) {
            return ['ok' => true, 'reason' => 'parent_not_found'];
        }

        if ($parent->name !== $name) {
            return ['ok' => true, 'reason' => 'parent_name_differs'];
        }

        $existing = File::where('storage_provider_id', $storageId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->where('is_folder', true)
            ->where('id', '!=', $parentId)
            ->first();

        if ($existing) {
            return [
                'ok' => false,
                'reason' => 'duplicate_path_already_exists',
                'existing_id' => $existing->id,
                'path' => $existing->path,
            ];
        }

        return ['ok' => true, 'reason' => 'legitimate_lib_lib_case'];
    }

    public static function findAllCycles(): \Illuminate\Support\Collection
    {
        $rows = DB::select("
            SELECT f.id, f.name, f.parent_id, f.storage_provider_id, f.path
            FROM files f
            JOIN files p ON f.parent_id = p.id
            WHERE f.is_folder = true
              AND p.is_folder = true
              AND f.name = p.name
              AND f.storage_provider_id = p.storage_provider_id
            ORDER BY f.storage_provider_id, f.id
        ");

        return collect($rows);
    }
}
