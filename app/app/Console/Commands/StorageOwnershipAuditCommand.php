<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Models\SystemSetting;
use App\Services\StorageOwnershipProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Auditoría de ownership/permisos del filesystem bajo las raíces de StorageProvider.
 *
 * Detecta archivos o directorios cuyo owner no es el usuario de PHP-FPM
 * (`www` por convención), lo cual bloquea operaciones de delete/move/rename
 * (ver regresión 2026-10-04: carpetas en /209 QHB Medellin/ con owner root
 * no se podían borrar).
 *
 * Reglas operativas:
 *  - Scope: solo `base_path` de StorageProviders con `type='local'` y `enabled=true`.
 *  - Filtro: `find` con `-not -user www` (configurable via PHP-FPM_USER).
 *  - Modos:
 *      default (sin --apply) -> dry-run: lista hasta N paths y sale con count.
 *      --apply              -> chmod g+rw recursivo + chgrp storage-admins
 *                              sobre los paths problemáticos, hasta N reparados.
 *      --storage=ID         -> scope a un storage específico.
 *      --limit=N            -> máximo de paths reportados/reparados (default 100).
 *      --send-mail=EMAIL    -> enviar resumen por mail al final.
 *
 *  - Operación I/O: usa `find` del sistema (más rápido que RecursiveDirectoryIterator
 *    sobre árboles multi-TB). Timeout: 30 min por storage.
 *
 *  - Concurrencia: lock `storage:ownership-audit:lock` (5 min TTL) para evitar
 *    que dos corran simultáneos.
 *
 *  - Reporte: log con tag `storage.ownership_audit` (encontrados / reparados / errores).
 *    El admin recibe el output completo si corrió manualmente.
 *
 * Output examples:
 *   $ php artisan storage:audit-ownership
 *   $ php artisan storage:audit-ownership --apply --limit=500
 *   $ php artisan storage:audit-ownership --storage=1104 --send-mail=admin@mediaserver.com.co
 */
class StorageOwnershipAuditCommand extends Command
{
    protected $signature = 'storage:audit-ownership
                            {--apply : Reparar permisos (chmod g+rw + chgrp storage-admins)}
                            {--storage= : Scope a un StorageProvider específico por ID}
                            {--limit=100 : Máximo de paths a reportar/reparar}
                            {--send-mail= : Email destino para el resumen (opcional)}
                            {--fpm-user=www : Usuario esperado de PHP-FPM}
                            {--admin-group=storage-admins : Grupo que recibe permisos de escritura}
                            {--scope=all : Modo de detección: all|untraversable}';

    protected $description = 'Detecta y opcionalmente repara archivos con owner != PHP-FPM user en las raíces de StorageProvider.';

    public function handle(): int
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('storage:ownership-audit:lock', 300);
        if (!$lock->get()) {
            $this->warn('[skip] otra instancia de storage:audit-ownership en curso');
            return self::SUCCESS;
        }

        try {
            $fpmUser = $this->option('fpm-user');
            $adminGroup = $this->option('admin-group');
            $limit = (int) $this->option('limit');
            $apply = (bool) $this->option('apply');
            $sendTo = $this->option('send-mail') ?: null;

            $query = StorageProvider::query()
                ->where('type', 'local')
                ->where('enabled', true)
                ->whereNotNull('base_path');

            if ($id = $this->option('storage')) {
                $query->where('id', (int) $id);
            }

            $storages = $query->get();
            if ($storages->isEmpty()) {
                $this->warn('No hay StorageProviders local+enabled que auditar.');
                return self::SUCCESS;
            }

            $this->info(sprintf(
                "Auditando %d storage(s) como user=%s, group=%s%s",
                $storages->count(),
                $fpmUser,
                $adminGroup,
                $apply ? ' [apply]' : ' [dry-run]'
            ));

            $totalFound = 0;
            $totalRepaired = 0;
            $totalErrors = 0;
            $totalUntravsersable = 0;
            $totalMetadataSkip = 0;
            $allFindings = [];
            $untraversableByStorage = [];

            $scope = $this->option('scope') ?: 'all';
            if (!in_array($scope, ['all', 'untraversable'], true)) {
                $this->error("--scope debe ser 'all' o 'untraversable', recibido: {$scope}");
                return self::FAILURE;
            }
            $runOwnershipPhase = ($scope === 'all');

            foreach ($storages as $storage) {
                $basePath = $storage->base_path;
                if (!is_dir($basePath)) {
                    $this->line(sprintf("  [skip] storage %d (%s): base_path no existe [%s]",
                        $storage->id, $storage->name, $basePath));
                    continue;
                }

                $this->line(sprintf("  storage %d (%s): escaneando %s",
                    $storage->id, $storage->name, $basePath));

                if ($runOwnershipPhase) {
                    // find -not -user www -not -path '*/\.*' -> todos los archivos no-www, sin dotfiles
                    $cmd = sprintf(
                        'find %s -not -user %s -not -path "*/\\.Recycle_bin/*" 2>/dev/null | head -n %d',
                        escapeshellarg($basePath),
                        escapeshellarg($fpmUser),
                        $limit + 1
                    );
                    $result = Process::timeout(1800)->run($cmd);
                    if (!$result->successful()) {
                        $this->error(sprintf("    [error] find falló para %s: %s", $basePath, $result->errorOutput()));
                        $totalErrors++;
                        Log::error('storage.ownership_audit.find_failed', [
                            'storage_id' => $storage->id,
                            'base_path' => $basePath,
                            'stderr' => $result->errorOutput(),
                        ]);
                    } else {
                        $paths = array_filter(explode("\n", trim($result->output())));
                        $count = count($paths);
                        $totalFound += $count;

                        if ($count === 0) {
                            $this->line("    ok (0 archivos con owner != {$fpmUser})");
                            Log::info('storage.ownership_audit.storage_ok', [
                                'storage_id' => $storage->id,
                                'base_path' => $basePath,
                            ]);
                        } else {
                            $this->warn(sprintf("    encontrados %d path(s) con owner != %s", $count, $fpmUser));
                            $shown = 0;
                            foreach ($paths as $p) {
                                if ($shown >= 10) {
                                    $this->line("      ... (mostrando primeros 10)");
                                    break;
                                }
                                $this->line("      - " . $p);
                                $shown++;
                            }

                            $allFindings = array_merge($allFindings, array_map(fn($p) => [
                                'storage_id' => $storage->id,
                                'storage_name' => $storage->name,
                                'path' => $p,
                            ], $paths));

                            if ($apply && $count > 0) {
                                $this->line("    [apply] ajustando permisos recursivamente...");
                                $applyCmd = sprintf(
                                    'chgrp -R %s %s && chmod -R g+rwX %s',
                                    escapeshellarg($adminGroup),
                                    escapeshellarg($basePath),
                                    escapeshellarg($basePath)
                                );
                                $applyResult = Process::timeout(3600)->run($applyCmd);
                                if ($applyResult->successful()) {
                                    $totalRepaired += $count;
                                    $this->info("    [apply] OK ({$count} paths)");
                                    Log::info('storage.ownership_audit.repaired', [
                                        'storage_id' => $storage->id,
                                        'storage_name' => $storage->name,
                                        'base_path' => $basePath,
                                        'count' => $count,
                                        'group' => $adminGroup,
                                    ]);
                                } else {
                                    $totalErrors++;
                                    $this->error("    [apply] falló: " . $applyResult->errorOutput());
                                    Log::error('storage.ownership_audit.repair_failed', [
                                        'storage_id' => $storage->id,
                                        'base_path' => $basePath,
                                        'stderr' => $applyResult->errorOutput(),
                                    ]);
                                }
                            }
                        }
                    }
                }

                // === FASE 2: detección de hijos no traversables ===
                // Complementa al `find` (que es ciego a directorios 700 ajenos):
                // scandir + opendir en cada hijo directo, cross-check con BD.
                $untraversable = StorageOwnershipProbe::detectUntravsersable($storage, $limit);
                $metadataSkipped = StorageOwnershipProbe::detectMetadata($storage);
                $totalMetadataSkip += count($metadataSkipped);

                if (count($untraversable) === 0) {
                    $this->line(sprintf("    untraversable: 0"));
                } else {
                    $this->warn(sprintf("    untraversable: %d (hijos directos con modo que excluye a %s)",
                        count($untraversable), $fpmUser));
                    $shown = 0;
                    foreach ($untraversable as $u) {
                        if ($shown >= 10) {
                            $this->line("      ... (mostrando primeros 10)");
                            break;
                        }
                        $this->line(sprintf("      - %s  [mode=%04o owner=%d group=%d bd_count=%d]",
                            $u['path'], $u['mode'], $u['owner'], $u['group'], $u['bd_count']));
                        $shown++;
                    }

                    $untraversableByStorage[$storage->id] = array_map(fn($u) => [
                        'name' => $u['name'],
                        'path' => $u['path'],
                        'mode' => $u['mode'],
                        'bd_count' => $u['bd_count'],
                    ], $untraversable);
                    $totalUntravsersable += count($untraversable);

                    Log::warning('storage.ownership_audit.untraversable_detected', [
                        'storage_id' => $storage->id,
                        'storage_name' => $storage->name,
                        'base_path' => $basePath,
                        'count' => count($untraversable),
                        'paths' => array_map(fn($u) => $u['path'], $untraversable),
                    ]);
                }

                if (count($metadataSkipped) > 0) {
                    $this->line(sprintf("    metadata_skip: %d (%s)",
                        count($metadataSkipped),
                        implode(', ', array_slice($metadataSkipped, 0, 5))));
                }

                // Persistir métricas por storage para el dashboard admin.
                SystemSetting::set("storage_health:{$storage->id}:untraversable_count", (string) count($untraversable));
                SystemSetting::set("storage_health:{$storage->id}:owner_mismatch_count", (string) ($totalFound ?? 0));
                SystemSetting::set("storage_health:{$storage->id}:untraversable_paths", json_encode($untraversableByStorage[$storage->id] ?? [], JSON_UNESCAPED_SLASHES));
            }

            $this->newLine();
            $this->line("=== HALLAZGOS ===");
            $this->line("  owner_mismatch: {$totalFound}");
            $this->line("  untraversable:  {$totalUntravsersable}");
            $this->line("  metadata_skip:  {$totalMetadataSkip}");
            $this->newLine();
            $this->line("=== RESUMEN ===");
            $this->line("  encontrados: {$totalFound}");
            $this->line("  reparados:   {$totalRepaired}");
            $this->line("  errores:     {$totalErrors}");

            SystemSetting::set('storage_health:audit_finished_at', now()->toIso8601String());

            Log::info('storage.ownership_audit.summary', [
                'mode' => $apply ? 'apply' : 'dry-run',
                'scope' => $scope,
                'fpm_user' => $fpmUser,
                'admin_group' => $adminGroup,
                'found' => $totalFound,
                'repaired' => $totalRepaired,
                'errors' => $totalErrors,
                'untraversable' => $totalUntravsersable,
                'metadata_skip' => $totalMetadataSkip,
                'findings_count' => count($allFindings),
            ]);

            if ($sendTo) {
                $body = $this->buildReport($apply, $fpmUser, $adminGroup, $totalFound, $totalRepaired, $totalErrors, $allFindings);
                try {
                    \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($sendTo, $apply) {
                        $message->to($sendTo)
                            ->subject(sprintf('[Tcloud] Auditoría ownership %s - %s',
                                $apply ? 'APLICADA' : 'dry-run',
                                now()->format('Y-m-d H:i')));
                    });
                    $this->info("  resumen enviado a {$sendTo}");
                } catch (\Throwable $e) {
                    $this->error("  mail falló: " . $e->getMessage());
                    Log::error('storage.ownership_audit.mail_failed', ['error' => $e->getMessage()]);
                }
            }

            return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function buildReport(bool $apply, string $fpmUser, string $adminGroup, int $found, int $repaired, int $errors, array $findings): string
    {
        $lines = [];
        $lines[] = "Auditoría de ownership Tcloud";
        $lines[] = "Modo: " . ($apply ? 'APPLY' : 'DRY-RUN');
        $lines[] = "PHP-FPM user: {$fpmUser}";
        $lines[] = "Admin group:  {$adminGroup}";
        $lines[] = "Encontrados:  {$found}";
        $lines[] = "Reparados:    {$repaired}";
        $lines[] = "Errores:      {$errors}";
        $lines[] = "";
        if (count($findings) > 0) {
            $lines[] = "Primeros findings:";
            foreach (array_slice($findings, 0, 50) as $f) {
                $lines[] = sprintf("  storage=%d (%s) %s",
                    $f['storage_id'],
                    $f['storage_name'],
                    $f['path']);
            }
        }
        return implode("\n", $lines);
    }
}