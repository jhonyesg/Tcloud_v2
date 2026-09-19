<?php
// Re-parent mis-parented files: cambia storage_provider_id del padre al hijo correcto
// SOLO cuando el hijo es único (la subcarpeta del path pertenece a UN solo child storage).
//
// Uso: php reparent.php [--dry-run] [--days=1]
//
// Por cada file sin transcribir hoy, busca el child storage más específico
// cuyo base_path es prefijo del absolute_path del file. Si hay UNO, lo repara.
// Si hay varios o ninguno, lo deja.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$days = 1;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int) $m[1];
    }
}

echo "[" . date('H:i:s') . "] Re-parenting (dryRun=" . ($dryRun ? 'true' : 'false') . ", days=$days)\n";

// Snapshot actual storage_provider_id assignments per file.id (for rollback if needed).
$snapshotKey = 'reparent:snapshot:' . date('Ymd_His');
$snapshot = [];
$rows = DB::table('files as f')
    ->join('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
    ->where('f.is_folder', false)
    ->where('f.is_trashed', false)
    ->whereNull('f.deleted_at')
    ->where('f.file_modified_at', '>=', now()->subDays($days))
    ->where('sp.transcription_enabled', true)
    ->select('f.id', 'f.storage_provider_id', 'f.path', 'sp.base_path')
    ->orderBy('f.id')
    ->get();

echo "[" . date('H:i:s') . "] Files de hoy en storages habilitados: " . count($rows) . "\n";

// Para cada file, encontrar el child storage (si hay uno solo).
$reparentings = []; // [file_id => new_storage_id]
$skipped = ['no_child' => 0, 'multiple_children' => 0, 'same_storage' => 0, 'conflict_duplicate' => 0];

// Pre-cache all child storages indexed by parent base_path
$allStorages = DB::table('storage_providers')
    ->where('transcription_enabled', true)
    ->where('allow_parent_overlap', false)
    ->select('id', 'name', 'base_path')
    ->get();

$childrenByParent = []; // parent_base_path => [child]
foreach ($allStorages as $s) {
    foreach ($allStorages as $candidate) {
        if ($candidate->id === $s->id) continue;
        if (str_starts_with($candidate->base_path, $s->base_path . '/')) {
            $childrenByParent[$s->base_path][] = $candidate;
        }
    }
}

foreach ($rows as $r) {
    $parentBase = $r->base_path;
    $children = $childrenByParent[$parentBase] ?? [];
    $matches = [];
    foreach ($children as $c) {
        // file.path debe comenzar con el relative path del child
        $rel = ltrim(substr($c->base_path, strlen($parentBase)), '/');
        if ($rel === '') continue;
        if (str_starts_with($r->path . '/', $rel . '/')) {
            $matches[] = $c;
        }
    }
    if (count($matches) === 0) {
        $skipped['no_child']++;
    } elseif (count($matches) > 1) {
        $skipped['multiple_children']++;
    } else {
        $new = $matches[0];
        if ((int) $new->id === (int) $r->storage_provider_id) {
            $skipped['same_storage']++;
        } else {
            // Evitar colision UNIQUE (storage_provider_id, path) — si destino ya tiene
            // el path, lo dejamos en el padre (StorageSyncService crea duplicados en algunos casos).
            $exists = \App\Models\File::where('storage_provider_id', $new->id)
                ->where('path', $r->path)
                ->exists();
            if ($exists) {
                $skipped['conflict_duplicate']++;
            } else {
                $reparentings[(int) $r->id] = [
                    'old_sid' => (int) $r->storage_provider_id,
                    'new_sid' => (int) $new->id,
                    'new_name' => $new->name,
                ];
            }
        }
    }
}

echo "[" . date('H:i:s') . "] Reparenings a aplicar: " . count($reparentings) . "\n";
echo "[" . date('H:i:s') . "] Skipped: " . json_encode($skipped) . "\n";

if (count($reparentings) === 0) {
    echo "Nada que reparentar.\n";
    exit(0);
}

// Group by new_sid for reporting
$byStorage = [];
foreach ($reparentings as $fid => $info) {
    $byStorage[$info['new_name']] = ($byStorage[$info['new_name']] ?? 0) + 1;
}
arsort($byStorage);
echo "[" . date('H:i:s') . "] Top 10 destinos:\n";
foreach (array_slice($byStorage, 0, 10, true) as $name => $n) {
    echo "  $name: $n files\n";
}

if ($dryRun) {
    echo "[DRY-RUN] No se hicieron cambios.\n";
    exit(0);
}

// Save snapshot
Cache::put($snapshotKey, $reparentings, now()->addDays(7));
echo "[" . date('H:i:s') . "] Snapshot guardado en cache key: $snapshotKey\n";

// Apply in chunks (UPDATE con CASE)
$chunkSize = 500;
$chunks = array_chunk($reparentings, $chunkSize, true);
$totalUpdated = 0;
foreach ($chunks as $i => $chunk) {
    $ids = array_keys($chunk);
    $cases = [];
    $bindings = [];
    foreach ($chunk as $fid => $info) {
        $cases[] = "WHEN ? THEN ?";
        $bindings[] = $fid;
        $bindings[] = $info['new_sid'];
    }
    $sql = "UPDATE files SET storage_provider_id = CASE id " . implode(' ', $cases) . " END::bigint WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
    $bindings = array_merge($bindings, $ids);
    $affected = DB::update($sql, $bindings);
    $totalUpdated += $affected;
    echo "[" . date('H:i:s') . "] Chunk " . ($i + 1) . "/" . count($chunks) . ": {$affected} actualizados\n";
}

echo "[" . date('H:i:s') . "] TOTAL actualizados: $totalUpdated\n";
echo "[" . date('H:i:s') . "] Rollback (si es necesario): UPDATE files SET storage_provider_id = CASE id ... usando cache key $snapshotKey\n";

// Invalidate caches de transcriptor y pendientes
App\Services\Ia\TodayPendingService::class; // ensure class loaded
try {
    $reflectionMethod = new ReflectionMethod(App\Services\Ia\TodayPendingService::class, 'forget');
    $reflectionMethod->invoke(app(App\Services\Ia\TodayPendingService::class));
    echo "[" . date('H:i:s') . "] TodayPendingService::forget() ejecutado\n";
} catch (\Throwable $e) {
    echo "[" . date('H:i:s') . "] TodayPendingService::forget() fallo: " . $e->getMessage() . "\n";
}
