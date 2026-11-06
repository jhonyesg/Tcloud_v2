<?php
/**
 * Hermes Fix v2: Repuntar archivos del real al folder espejo.
 *
 * Estrategia:
 *   Para cada (espejo E, real R) donde E tiene 0 hijos y R tiene >0:
 *     - UPDATE files SET parent_id = E.id WHERE parent_id = R.id AND deleted_at IS NULL
 *     - Marcar en file_mirror_audit_log la operación
 *   Al terminar:
 *     - El folder espejo tiene los mismos archivos que el real
 *     - El folder real queda vacío (puede eliminarse en clean-up posterior)
 *
 * Riesgo mínimo: no se borra, solo se re-asigna parent_id.
 * Reversibilidad: UPDATE inverso deja todo como estaba.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = ($argv[1] ?? '') !== '--apply';
$batchSize = 500;

echo "=== Hermes Fix v2: Repuntar archivos al folder espejo ===\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN (no modifica)' : 'APPLY (modifica)') . "\n\n";

// Paso 1: Mapear todos los pares (espejo, real) donde espejo no tiene hijos
echo "Paso 1: Identificando pares (espejo vacío, real con hijos)...\n";

$pares = DB::select("
  WITH RECURSIVE null_parents AS (
    SELECT e.id AS espejo_id, e.name AS fecha, e.storage_provider_id AS sid, sp.parent_storage_id AS psid
    FROM files e
    JOIN storage_providers sp ON sp.id = e.storage_provider_id
    WHERE e.parent_id IS NULL
      AND e.is_folder = true
      AND e.deleted_at IS NULL
      AND e.name ~ '^[0-9]{8}\$'
      AND sp.parent_storage_id IS NOT NULL
  )
  SELECT np.espejo_id, np.fecha, np.sid,
    (SELECT id FROM files WHERE deleted_at IS NULL AND parent_id IS NOT NULL
       AND is_folder = true AND name = np.fecha
       AND storage_provider_id = np.psid LIMIT 1) AS real_id
  FROM null_parents np
");

$paresValidos = array_filter($pares, fn($p) => $p->real_id !== null);

echo "Pares encontrados: " . count($paresValidos) . "\n\n";

if (count($paresValidos) === 0) {
    echo "Nada que hacer.\n";
    exit(0);
}

// Paso 2: Para cada par, contar archivos del real y hacer UPDATE si >0
echo "Paso 2: Aplicando reparent...\n";
$totalArchivosReparent = 0;
$paresReparados = 0;

foreach ($paresValidos as $par) {
    $archivos = DB::table('files')
        ->where('parent_id', $par->real_id)
        ->whereNull('deleted_at')
        ->count();

    if ($archivos === 0) continue;

    if ($dryRun) {
        echo "  [DRY] espejo={$par->espejo_id} ({$par->fecha}) → real={$par->real_id}, archivos=$archivos\n";
        $totalArchivosReparent += $archivos;
        $paresReparados++;
        continue;
    }

    // Repuntar
    $affected = DB::table('files')
        ->where('parent_id', $par->real_id)
        ->whereNull('deleted_at')
        ->update(['parent_id' => $par->espejo_id, 'updated_at' => now()]);

    DB::table('file_mirror_audit_log')->insert([
        'action' => 'reparent_to_espejo',
        'mirror_file_id' => $par->real_id,
        'canonical_file_id' => $par->espejo_id,
        'metadata' => json_encode([
            'storage_id' => $par->sid,
            'fecha' => $par->fecha,
            'files_reparented' => $affected,
            'method' => 'v2_reparent_to_espejo',
        ]),
        'created_at' => now(),
    ]);

    $totalArchivosReparent += $affected;
    $paresReparados++;
}

echo "\n=== Resultado ===\n";
echo "Pares espejo-real reparados: $paresReparados\n";
echo "Archivos reparentados: $totalArchivosReparent\n";

if ($dryRun) {
    echo "\nDRY-RUN completado. Use --apply para ejecutar.\n";
    exit(0);
}

// Paso 3: Verificación post-fix
echo "\nPaso 3: Verificación post-fix...\n";
$restantesVacios = DB::table('files as e')
    ->leftJoin('files as children', 'children.parent_id', '=', 'e.id')
    ->leftJoin('storage_providers as sp', 'sp.id', '=', 'e.storage_provider_id')
    ->whereNull('e.parent_id')
    ->where('e.is_folder', true)
    ->whereNull('e.deleted_at')
    ->where('e.name', '~', '^[0-9]{8}$')
    ->whereNull('children.id')
    ->whereNotNull('sp.parent_storage_id')
    ->count();

echo "Espejos sin hijos restantes: $restantesVacios (debería ser ~0)\n";
echo "\n=== Hermes Fix v2 aplicado. ===\n";
