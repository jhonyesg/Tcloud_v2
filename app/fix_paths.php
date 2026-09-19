<?php
// Fix paths de archivos reparentados: ajusta path para que sea relativo al nuevo base_path.
// Ej: path='Antioquia/Colmundo/15092026/file.mp3' bajo storage 73 base='...Antioquia/Colmundo'
//     -> path='15092026/file.mp3'

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);

echo "[" . date('H:i:s') . "] Fix path para reparentados (dryRun=" . ($dryRun ? 'true' : 'false') . ")\n";

// Encontrar todos los files donde el path NO empieza relativo al base_path del storage
// (es decir, el primer segmento del path NO es un folder ddmmyyyy válido directamente bajo base_path)
$rows = DB::table('files as f')
    ->join('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
    ->where('sp.transcription_enabled', true)
    ->where('f.is_folder', false)
    ->where('f.is_trashed', false)
    ->whereNull('f.deleted_at')
    ->whereIn('sp.id', [47, 49, 53, 55, 134, 66, 72, 73, 74, 75, 76, 77, 82, 84, 85, 86, 88, 89, 91, 92, 93, 94, 71])
    ->select('f.id', 'f.path', 'f.name', 'sp.base_path')
    ->orderBy('f.id')
    ->get();

echo "[" . date('H:i:s') . "] Files a revisar: " . count($rows) . "\n";

$fixes = [];
foreach ($rows as $r) {
    $basePath = rtrim($r->base_path, '/');
    // Si path ya empieza con base_path (con o sin slash), está OK
    if (str_starts_with($r->path . '/', $basePath . '/') || str_starts_with($r->path, $basePath . '/')) {
        continue;
    }
    // Buscar cuál storage hijo/padre más profundo contiene el path
    // Para simplificación: si path='Antioquia/Colmundo/15092026/x.mp3' y basePath='...Antioquia/Colmundo',
    // el path correcto es '15092026/x.mp3'
    // Encontrar el prefijo más largo en común
    $candidatePrefixes = DB::table('storage_providers')
        ->where('transcription_enabled', true)
        ->where('id', '!=', (int) $r->id === (int) $r->id ? -1 : -1) // sin filtro de storage
        ->where('id', '!=', DB::raw('-1'))
        ->pluck('base_path')
        ->unique()
        ->sortByDesc(fn($p) => strlen($p))
        ->all();

    $found = false;
    foreach ($candidatePrefixes as $candBase) {
        $candBase = rtrim($candBase, '/');
        if ($candBase === $basePath) continue; // skip self
        // Si el path empieza con candBase (relativo)
        // Comparar el absolute path: base_path + path
        $abs = $basePath . '/' . $r->path;
        // Si abs starts with candBase, el path debería ser (abs sin candBase prefix) con leading slash
        if (str_starts_with($abs, $candBase . '/')) {
            $rel = substr($abs, strlen($candBase) + 1);
            // Ahora debemos encontrar el storage cuyo base_path = $rel_no_used_yet
            // Es decir, queremos encontrar un storage cuyo base_path SÍ termina donde está el archivo
            // En realidad: queremos encontrar EL storage provider cuyo base_path es el más específico
            // que aún contiene este archivo. Eso es el storage 73 actual.
            // Lo que necesitamos: que el path NO tenga el prefijo del padre (que fue storage 49, etc.)
            // Vamos a quitar el segmento inicial si coincide con un sub-storage
            $firstSeg = explode('/', $r->path)[0];
            $matchedSub = DB::table('storage_providers')
                ->where('transcription_enabled', true)
                ->where('base_path', 'LIKE', $candBase . '/%')
                ->whereRaw('? LIKE (base_path || \'/%\')', [$basePath . '/' . $firstSeg])
                ->first();
            // Simplificar: si el primer segmento del path es un sub-folder conocido
            // que pertenece al base_path de un storage hijo anterior (que ya no es este),
            // entonces ESTE path fue reparentado y hay que quitarle ese prefijo.
            $found = true;
            break;
        }
    }

    // Hacer lo más simple: encontrar el segment del path que es exactamente el relative path
    // desde la base_path del CURRENT storage
    // Si la base_path termina en "/Antioquia/Colmundo" y el path es "Antioquia/Colmundo/15092026/file.mp3",
    // entonces nuevo path = "15092026/file.mp3"
    // Estrategia: encontrar la posición donde path "converge" con base_path

    $baseParts = array_filter(explode('/', $basePath));
    $pathParts = array_filter(explode('/', $r->path));

    // Encontrar el segment en pathParts que matchea el último segment de basePath
    $lastBaseSeg = end($baseParts);
    $foundIdx = null;
    foreach ($pathParts as $i => $p) {
        if ($p === $lastBaseSeg) {
            // Check if subsequent path segments match the tail of basePath
            $match = true;
            $baseTail = array_slice($baseParts, -count($pathParts) - 1 + $i - count($pathParts));
            // Quick heuristic: if the file is exactly at basePath/<rest> after stripping "Antioquia/Colmundo"
            // from the start of path, accept
            $expectedNew = array_slice($pathParts, $i);
            $expectedNewStr = implode('/', $expectedNew);
            if ($expectedNewStr === $r->path) {
                // No multiple matches possible
            }
            $foundIdx = $i;
            break;
        }
    }

    if ($foundIdx === null || $foundIdx === 0) {
        // No fix needed or can't determine
        continue;
    }

    $newPath = implode('/', array_slice($pathParts, $foundIdx));
    if ($newPath !== $r->path) {
        $fixes[(int) $r->id] = [
            'old_path' => $r->path,
            'new_path' => $newPath,
            'storage_id' => (int) $r->id === (int) $r->storage_provider_id ?? null,
        ];
    }
}

// Reset and use direct query approach
$fixes = [];
$candidateRows = DB::select("
    SELECT f.id, f.storage_provider_id, f.path, sp.base_path, sp.name
    FROM files f
    JOIN storage_providers sp ON sp.id = f.storage_provider_id
    WHERE sp.transcription_enabled = true
      AND f.is_folder = false AND f.is_trashed = false AND f.deleted_at IS NULL
      AND sp.id IN (47, 49, 53, 55, 134, 66, 72, 73, 74, 75, 76, 77, 82, 84, 85, 86, 88, 89, 91, 92, 93, 94, 71)
");
echo "[" . date('H:i:s') . "] Re-revisando " . count($candidateRows) . " files\n";

foreach ($candidateRows as $r) {
    $basePath = rtrim($r->base_path, '/');
    $baseParts = array_values(array_filter(explode('/', $basePath), fn($s) => $s !== ''));
    $pathParts = array_values(array_filter(explode('/', $r->path), fn($s) => $s !== ''));

    $baseTail = array_slice($baseParts, -2); // últimos 2 segmentos del base_path
    $pathHead = array_slice($pathParts, 0, 2);

    // Si los primeros 2 segmentos del path NO son iguales al tail del base_path,
    // y los primeros 2 segmentos SÍ matchean un storage más ancestral, hay que corregir.
    // Simplificación: si basePath ends con 'Antioquia/Colmundo' y path empieza con 'Antioquia/Colmundo'
    if (count($pathParts) >= 2 && count($baseTail) >= 2
        && $pathParts[0] === $baseTail[count($baseTail)-2]
        && $pathParts[1] === $baseTail[count($baseTail)-1]
    ) {
        $newPath = implode('/', array_slice($pathParts, 2));
        if ($newPath !== $r->path) {
            $fixes[(int) $r->id] = ['old' => $r->path, 'new' => $newPath];
        }
    }
}

echo "[" . date('H:i:s') . "] Paths a corregir: " . count($fixes) . "\n";

if (count($fixes) === 0) {
    echo "Nada que hacer.\n";
    exit(0);
}

if ($dryRun) {
    foreach (array_slice($fixes, 0, 5, true) as $id => $f) {
        echo "  file $id: '{$f['old']}' -> '{$f['new']}'\n";
    }
    echo "[DRY-RUN] No se aplicaron cambios.\n";
    exit(0);
}

// Aplicar
$totalUpdated = 0;
$chunks = array_chunk($fixes, 200, true);
foreach ($chunks as $i => $chunk) {
    $cases = [];
    $bindings = [];
    foreach ($chunk as $id => $f) {
        $cases[] = "WHEN ? THEN ?";
        $bindings[] = $id;
        $bindings[] = $f['new'];
    }
    $idsStr = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "UPDATE files SET path = CASE id " . implode(' ', $cases) . " END WHERE id IN ($idsStr)";
    $bindings = array_merge($bindings, array_keys($chunk));
    $affected = DB::update($sql, $bindings);
    $totalUpdated += $affected;
    echo "[" . date('H:i:s') . "] Chunk " . ($i + 1) . "/" . count($chunks) . ": {$affected} actualizados\n";
}
echo "[" . date('H:i:s') . "] TOTAL paths actualizados: $totalUpdated\n";
