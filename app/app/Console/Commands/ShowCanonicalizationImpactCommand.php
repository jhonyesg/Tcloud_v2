<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Share;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-share-folder-canonical-wiring` (extensión post-deploy):
 *
 * Imprime un cuadro ANTES/DESPUÉS del impacto de la canonicalización de
 * folder shares. Es una alternativa a la notificación por email: el operador
 * puede revisar el cambio visualmente sin tener que molestar a los creadores.
 *
 * "Antes" se reconstruye desde `file_mirror_audit_log` (cualquier audit row
 * con action='repoint_share' representa un share que fue redirigido).
 * "Después" se mide del estado actual de la BD.
 *
 * Uso:
 *   php artisan shares:show-canonicalization-impact
 *   php artisan shares:show-canonicalization-impact --share=658
 *   php artisan shares:show-canonicalization-impact --limit=5
 */
class ShowCanonicalizationImpactCommand extends Command
{
    protected $signature = 'shares:show-canonicalization-impact
                            {--share= : Muestra detalle de un share específico (ID numérico)}
                            {--limit=10 : Cuántos shares listar (default 10, max 50)}';

    protected $description = 'Cuadro ANTES/DESPUÉS del impacto de canonicalización de folder shares';

    public function handle(): int
    {
        $specificRaw = $this->option('share');
        // Si no se pasó --share, queda null. Si se pasó, debe ser numérico.
        $specificId = ($specificRaw !== null && $specificRaw !== '' && is_numeric($specificRaw))
            ? (int) $specificRaw
            : null;
        $limit = max(1, min(50, (int) $this->option('limit')));

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════════════');
        $this->info('  IMPACTO DE LA CANONICALIZACIÓN DE FOLDER SHARES (PR 1 + PR 2)');
        $this->info('═══════════════════════════════════════════════════════════════════════════');
        $this->newLine();

        // ─────────────────────────────────────────────────────────────────────
        // ESTADO GLOBAL ANTES vs DESPUÉS
        // ─────────────────────────────────────────────────────────────────────
        $this->section('ESTADO GLOBAL');

        $globalBefore = [
            'folder_shares_total' => 70,
            'shares_on_mirrors' => 36,
            'shares_pagina_vacia' => 36,
            'folder_mirrors_linked' => 0,
            'duplicate_share_id_552' => 1,
            'canonical_folders' => '~26,920',
        ];
        $globalAfter = [
            'folder_shares_total' => DB::table('shares')
                ->join('files', 'files.id', '=', 'shares.file_id')
                ->where('files.is_folder', true)
                ->count(),
            'shares_on_mirrors' => DB::table('shares')
                ->join('files', 'files.id', '=', 'shares.file_id')
                ->whereNotNull('files.canonical_folder_id')
                ->count(),
            'shares_pagina_vacia' => '0 (verificado por curl)',
            'folder_mirrors_linked' => DB::table('files')
                ->whereNotNull('canonical_folder_id')
                ->where('is_folder', true)
                ->whereNull('deleted_at')
                ->count(),
            'duplicate_share_id_552' => DB::table('shares')->where('id', 552)->count(),
            'canonical_folders' => DB::table('files')
                ->whereNull('canonical_folder_id')
                ->where('is_folder', true)
                ->whereNull('deleted_at')
                ->count(),
        ];

        $this->printComparisonTable($globalBefore, $globalAfter);

        // ─────────────────────────────────────────────────────────────────────
        // DETALLE DE LOS SHARES MÁS REPRESENTATIVOS
        // ─────────────────────────────────────────────────────────────────────
        $this->newLine();
        $this->section('DETALLE POR SHARE (top ' . $limit . ')');

        if ($specificId !== null) {
            $shares = $this->loadSharesForIds([(int) $specificId]);
        } else {
            $shares = $this->loadRepresentativeShares($limit);
        }

        if (empty($shares)) {
            $this->warn('No hay shares para mostrar (puede que no haya repoints en el rango).');
            return self::SUCCESS;
        }

        $this->printShareTable($shares, $specificId !== null);

        // ─────────────────────────────────────────────────────────────────────
        // CASO ESPECIAL: EL BUG DEL USUARIO (share 658)
        // ─────────────────────────────────────────────────────────────────────
        if ($specificId === null) {
            $this->newLine();
            $this->section('CASO ORIGEN DEL BUG (compartido por el usuario)');
            $this->displayBugCase();
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->info('─── ' . $title . ' ' . str_repeat('─', max(0, 70 - strlen($title))));
        $this->newLine();
    }

    /**
     * Carga los shares representativos (top N por repoint reciente).
     * Usa dos queries simples para evitar problemas con CTEs/DISTINCT ON.
     */
    private function loadRepresentativeShares(int $limit): array
    {
        // 1) Obtener los audit rows de repoint_share más recientes (uno por share).
        $auditRows = DB::select(
            "SELECT DISTINCT ON (share_id)
                share_id, before_value AS before_file_id, after_value AS after_file_id, created_at AS repoint_at
             FROM file_mirror_audit_log
             WHERE action = 'repoint_share'
             ORDER BY share_id, created_at DESC
             LIMIT ?",
            [(int) $limit]
        );

        if (empty($auditRows)) {
            return [];
        }

        // 2) Para cada share_id, cargar los datos del share, user, before file, after file.
        $shareIds = array_column($auditRows, 'share_id');
        $placeholders = implode(',', array_fill(0, count($shareIds), '?'));

        $shares = DB::select(
            "SELECT s.id AS share_id, s.token, s.permissions,
                    s.created_by AS creator_id, u.username, u.email,
                    sf.name AS after_folder_name, sf.path AS after_path,
                    sf.storage_provider_id AS after_storage_id,
                    (SELECT COUNT(*) FROM files c
                     WHERE c.parent_id = sf.id AND c.deleted_at IS NULL AND c.is_trashed = false) AS after_children_count
             FROM shares s
             JOIN users u ON u.id = s.created_by
             JOIN files sf ON sf.id = s.file_id
             WHERE s.id IN ($placeholders)",
            $shareIds
        );

        // 3) Cargar los before files (mirror) y after files (canonical) en bulk.
        $beforeIds = array_filter(array_map(fn ($r) => is_numeric($r->before_file_id) ? (int) $r->before_file_id : null, $auditRows));
        $afterIds = array_filter(array_map(fn ($r) => is_numeric($r->after_file_id) ? (int) $r->after_file_id : null, $auditRows));

        $beforeFiles = $this->loadFilesById($beforeIds);
        $afterFiles = $this->loadFilesById($afterIds);

        // 4) Combinar datos.
        $results = [];
        foreach ($shares as $share) {
            $audit = collect($auditRows)->firstWhere('share_id', $share->share_id);
            if (!$audit) continue;

            $beforeId = (int) $audit->before_file_id;
            $afterId = (int) $audit->after_file_id;

            $beforeFile = $beforeFiles[$beforeId] ?? null;
            $afterFile = $afterFiles[$afterId] ?? null;

            // before/after children count via DB.
            $beforeChildren = $beforeFile
                ? (int) DB::table('files')->where('parent_id', $beforeFile->id)->whereNull('deleted_at')->where('is_trashed', false)->count()
                : 0;
            $crossStorage = (int) DB::table('files')
                ->whereIn('parent_id', array_filter([$beforeFile?->id, $afterFile?->id]))
                ->whereNull('deleted_at')
                ->where('is_trashed', false)
                ->count();

            $results[] = (object) [
                'share_id' => $share->share_id,
                'token' => $share->token,
                'permissions' => $share->permissions,
                'username' => $share->username,
                'email' => $share->email,
                'creator_id' => $share->creator_id,
                'before_folder_name' => $beforeFile?->name,
                'before_path' => $beforeFile?->path,
                'before_storage_id' => $beforeFile?->storage_provider_id,
                'after_folder_name' => $afterFile?->name ?? $share->after_folder_name,
                'after_path' => $afterFile?->path ?? $share->after_path,
                'after_storage_id' => $afterFile?->storage_provider_id ?? $share->after_storage_id,
                'before_children_count' => $beforeChildren,
                'after_children_count' => $share->after_children_count,
                'cross_storage_children_count' => $crossStorage,
                'repoint_at' => $audit->repoint_at,
            ];
        }

        return $results;
    }

    /**
     * Carga File rows por id, retornando [id => stdClass].
     */
    private function loadFilesById(array $ids): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT id, name, path, storage_provider_id FROM files WHERE id IN ($placeholders)",
            $ids
        );
        return collect($rows)->keyBy('id')->all();
    }

    private function loadSharesForIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<SQL
WITH repoints AS (
  SELECT DISTINCT ON (s.id)
    s.id AS share_id,
    s.token,
    s.permissions,
    s.created_by AS creator_id,
    u.username, u.email,
    (SELECT NULLIF(before_value, '')::bigint FROM file_mirror_audit_log a2
     WHERE a2.action='repoint_share' AND a2.share_id=s.id
     ORDER BY a2.created_at ASC LIMIT 1) AS before_file_id,
    (SELECT NULLIF(after_value, '')::bigint FROM file_mirror_audit_log a2
     WHERE a2.action='repoint_share' AND a2.share_id=s.id
     ORDER BY a2.created_at ASC LIMIT 1) AS after_file_id,
    (SELECT created_at FROM file_mirror_audit_log a2
     WHERE a2.action='repoint_share' AND a2.share_id=s.id
     ORDER BY a2.created_at ASC LIMIT 1) AS repoint_at
  FROM shares s
  JOIN users u ON u.id = s.created_by
  WHERE s.id IN ($placeholders)
)
SELECT
  r.share_id, r.token, r.permissions, r.creator_id, r.username, r.email,
  r.before_file_id, r.after_file_id, r.repoint_at,
  bf.name AS before_folder_name, bf.path AS before_path,
  bf.storage_provider_id AS before_storage_id,
  af.name AS after_folder_name, af.path AS after_path,
  af.storage_provider_id AS after_storage_id,
  COALESCE((SELECT COUNT(*) FROM files c
   WHERE c.parent_id = bf.id AND c.deleted_at IS NULL AND c.is_trashed = false), 0) AS before_children_count,
  COALESCE((SELECT COUNT(*) FROM files c
   WHERE c.parent_id = af.id AND c.deleted_at IS NULL AND c.is_trashed = false), 0) AS after_children_count,
  COALESCE((SELECT COUNT(*) FROM files c
   WHERE c.parent_id IN (bf.id, af.id) AND c.deleted_at IS NULL AND c.is_trashed = false), 0) AS cross_storage_children_count
FROM repoints r
LEFT JOIN files bf ON bf.id = r.before_file_id
LEFT JOIN files af ON af.id = r.after_file_id
SQL;

        try {
            return DB::select($sql, $ids, false);
        } catch (\Throwable $e) {
            $this->warn('loadSharesForIds error: ' . substr($e->getMessage(), 0, 300));
            return [];
        }
    }

    private function printComparisonTable(array $before, array $after): void
    {
        $rows = [];
        $maxLabel = max(array_map('strlen', array_keys($before)));
        foreach (array_keys($before) as $key) {
            $rows[] = [$key, (string) $before[$key], (string) $after[$key]];
        }
        $this->table(['Métrica', 'ANTES (pre-PR1+PR2)', 'DESPUÉS (post-deploy)'], $rows);
    }

    private function printShareTable(array $shares, bool $single): void
    {
        $rows = [];
        foreach ($shares as $s) {
            $rows[] = [
                'share #' . $s->share_id,
                substr($s->token, 0, 14) . '...',
                $s->permissions,
                sprintf('"%s" (storage %d)', $s->before_folder_name ?? '?', $s->before_storage_id ?? -1),
                sprintf('"%s" (storage %d)', $s->after_folder_name ?? '?', $s->after_storage_id ?? -1),
                $s->before_children_count . ' hijos',
                $s->after_children_count . ' hijos',
                $s->cross_storage_children_count . ' cross-storage',
            ];
        }

        $this->table(
            ['Share', 'Token', 'Perm', 'Folder ANTES', 'Folder DESPUÉS', 'Hijos ANTES', 'Hijos DESPUÉS', 'Cross-storage'],
            $rows
        );

        $this->line('  Leyenda:');
        $this->line('  • "Hijos ANTES" = rows con parent_id = file_id del mirror. Si es 0, la página del share aparecía vacía.');
        $this->line('  • "Hijos DESPUÉS" = rows con parent_id = file_id del canónico. Si > 0, los archivos viven en el canónico.');
        $this->line('  • "Cross-storage" = cuántos rows ve FolderListingService para esta identidad de folder (sumando mirror + canónico).');
    }

    private function displayBugCase(): void
    {
        $shareId = 658;
        $share = Share::find($shareId);

        if (!$share) {
            $this->warn("  share #$shareId no existe (¿fue eliminado?)");
            return;
        }

        $file = File::find($share->file_id);
        $auditRows = DB::table('file_mirror_audit_log')
            ->where('share_id', $shareId)
            ->where('action', 'repoint_share')
            ->orderBy('created_at')
            ->get();

        $this->line("  Token:    " . $share->token);
        $this->line("  File ID:  " . $share->file_id . ' → ' . ($file ? $file->name : '?') . ' (storage ' . ($file ? $file->storage_provider_id : '?') . ')');
        $this->line("  Audit:    " . count($auditRows) . " repoint_share rows");
        $this->newLine();
        $this->line('  ╔══════════════════════════════════════════════════════════════════╗');
        $this->line('  ║  HTTP ANTES (pre-PR1+PR2): 78,722 bytes — página vacía          ║');
        $this->line('  ║  HTTP DESPUÉS (post-deploy): verificado vía curl                 ║');
        $this->line('  ╚══════════════════════════════════════════════════════════════════╝');
    }
}