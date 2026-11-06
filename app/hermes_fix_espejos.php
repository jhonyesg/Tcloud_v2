<?php
/**
 * Hermes Fix: Aplicar parent_id al folder espejo y/o reparentar archivos.
 *
 * Estrategia mínima:
 *  Para cada carpeta espejo (parent_id NULL, storage_id X, name = DDMMYYYY)
 *   1. Buscar su "real" en storage padre (X.parent_storage_id).
 *      Real es la fila de files (parent_id NOT NULL) con mismo nombre y ese storage.
 *   2. Si el espejo tiene 0 hijos Y el real tiene hijos:
 *        - Crear entradas en file_mirror_audit_log con action='redir_espejo'
 *        - Si el folder espejo tiene menos archivos hijos de los esperados,
 *          repuntarlos al real (no al espejo) — estrategia: solo redirigir la NAVEGACIÓN.
 *      Estrategia simple: soft-delete el folder espejo (deleted_at = NOW()).
 *      Sólo después de confirmar que el real es accesible.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$dryRun = ($argv[1] ?? '') !== '--apply';
$batchSize = 200;
$totalMapeados = 0;
$totalReparados = 0;
$totalSoftDeleted = 0;

echo "=== Hermes Fix - Espejos sin hijos ===\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n\n";

/**
 * Estrategia corregida y simple:
 * Para cada carpeta espejo con parent_id NULL y name formato fecha:
 *   a) Si tiene archivos como hijos directos => no tocar (caso normal)
 *   b) Si NO tiene archivos hijos => buscar el REAL y hacer SOFT-DELETE del espejo
 *      para que la UI caiga al real por defecto.
 *
 * Pero: el espejo y el real son DIFERENTES parents en la UI. Soft-deleting
 * el espejo debería hacer que el listado por 'parent_id=NULL del storage'
 * deje de mostrar ese folder vacío.
 *
 * Para no romper navegación: usar deleted_at = NOW() (soft-delete) en espejos
 * con 0 hijos Y con un real existente.
 */

$statsByStorage = DB::table('files as e')
    ->join('storage_providers as sp', 'sp.id', '=', 'e.storage_provider_id')
    ->leftJoin('files as children', 'children.parent_id', '=', 'e.id')
    ->whereNull('e.parent_id')
    ->where('e.is_folder', true)
    ->whereNull('e.deleted_at')
    ->where('e.name', '~', '^[0-9]{8}$')
    ->whereNotNull('sp.parent_storage_id')
    ->whereNull('children.id')   // espejo sin hijos
    ->groupBy('e.storage_provider_id', 'e.name')
    ->selectRaw('e.id as eid, e.name as fecha, e.storage_provider_id, sp.parent_storage_id as parent_sid, COUNT(children.id) as cnt')
    ->get()
    ->groupBy('storage_provider_id');

echo "Espejos encontrados sin hijos: " . DB::table('files as e')
    ->join('storage_providers as sp', 'sp.id', '=', 'e.storage_provider_id')
    ->leftJoin('files as children', 'children.parent_id', '=', 'e.id')
    ->whereNull('e.parent_id')
    ->where('e.is_folder', true)
    ->whereNull('e.deleted_at')
    ->where('e.name', '~', '^[0-9]{8}$')
    ->whereNotNull('sp.parent_storage_id')
    ->whereNull('children.id')
    ->count() . "\n\n";

echo "Detalle por storage (top 10):\n";
foreach ($statsByStorage->take(10) as $sid => $rows) {
    echo "  storage_$sid: " . $rows->count() . " espejos sin hijos\n";
}

if ($dryRun) {
    echo "\n=== DRY-RUN no modifica nada. Use --apply para ejecutar el fix. ===\n";
    exit(0);
}

echo "\n=== Iniciando fix ===\n";

// Soft-delete de espejos con 0 hijos y un real existente
$softDeleted = 0;
DB::table('files as e')
    ->join('storage_providers as sp', 'sp.id', '=', 'e.storage_provider_id')
    ->leftJoin('files as children', 'children.parent_id', '=', 'e.id')
    ->leftJoin('files as real', function ($join) {
        $join->on('real.name', '=', 'e.name')
            ->where('real.is_folder', true)
            ->whereNull('real.deleted_at')
            ->whereNotNull('real.parent_id');
    })
    ->whereNull('e.parent_id')
    ->where('e.is_folder', true)
    ->whereNull('e.deleted_at')
    ->where('e.name', '~', '^[0-9]{8}$')
    ->whereNotNull('sp.parent_storage_id')
    ->whereNull('children.id')
    ->whereNotNull('real.id')
    ->whereRaw('real.storage_provider_id = sp.parent_storage_id')
    ->select('e.id', 'real.id as real_id', 'e.storage_provider_id', 'e.name')
    ->orderBy('e.id')
    ->chunk($batchSize, function ($rows) use (&$softDeleted) {
        foreach ($rows as $row) {
            DB::table('files')->where('id', $row->id)
                ->update(['deleted_at' => now()]);
            DB::table('file_mirror_audit_log')->insert([
                'action' => 'redir_espejo_softdelete',
                'mirror_file_id' => $row->id,
                'canonical_file_id' => $row->real_id,
                'metadata' => json_encode([
                    'storage_id' => $row->storage_provider_id,
                    'fecha' => $row->name,
                    'motivo' => 'espejo sin hijos, redirigido al real',
                ]),
                'created_at' => now(),
            ]);
            $softDeleted++;
        }
        echo "  Procesados: $softDeleted espejos\n";
    });

echo "\n=== Resultado ===\n";
echo "Espejos soft-deleted: $softDeleted\n";
echo "Auditados en file_mirror_audit_log\n\n";

// Verificación post-fix
$restantes = DB::table('files as e')
    ->join('storage_providers as sp', 'sp.id', '=', 'e.storage_provider_id')
    ->leftJoin('files as children', 'children.parent_id', '=', 'e.id')
    ->whereNull('e.parent_id')
    ->where('e.is_folder', true)
    ->whereNull('e.deleted_at')
    ->where('e.name', '~', '^[0-9]{8}$')
    ->whereNotNull('sp.parent_storage_id')
    ->whereNull('children.id')
    ->count();

echo "Espejos sin hijos restantes: $restantes (post-fix)\n";
echo "\n=== Listo. Hermes fix aplicado. ===\n";
