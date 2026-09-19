<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * transcriptor-physical-file-identity (D1): la jerarquia padre/hijo de
 * storage_providers pasa de ser un LIKE de base_path repetido en 4 sitios con
 * criterios divergentes, a ser un dato con FK.
 *
 * Antes:
 *   - StorageProvider::computeInheritedTranscriptionScope  -> LIKE sin orden
 *   - StorageFunnelService::computeRootIdFor               -> LIKE + LENGTH DESC
 *   - DiskScannerService::computeExcludedSubpaths          -> LIKE sin orden
 *   - StorageSyncService::findMoreSpecificStorage          -> str_starts_with
 *
 * Dos de esos eligen "el ancestro mas largo" y dos no ordenan, asi que podian
 * elegir ancestros distintos para la misma fila.
 *
 * Backfill: el ancestro es el storage cuyo base_path normalizado
 * (rtrim(base_path,'/')) es prefijo ESTRICTO del propio, eligiendo el prefijo
 * de mayor longitud (el ancestro INMEDIATO, no la raiz).
 *
 * Pares con base_path normalizado IDENTICO (misma ruta fisica, dos gestores):
 * NO se enlazan entre si (no hay prefijo estricto). Son "nodos equivalentes"
 * (design.md D10) y se reportan en log para que el operador los conozca. Caso
 * real detectado: 66 (06 Camara Fm, tx=true) y 146 (75 Camara Fm Medellin,
 * tx=false) comparten ruta; un enlace padre/hijo mal puesto degradaria a 66
 * de dueno a hijo.
 *
 * 190 filas a poblar. Sin efecto sobre `files`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('storage_providers', 'parent_storage_id')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->bigInteger('parent_storage_id')->nullable()->after('base_path');
                $table->index('parent_storage_id', 'storage_providers_parent_storage_id_idx');
            });
        }

        // FK en statement separado: la auto-referencia necesita la columna ya
        // creada y el nombre explicito para que down() pueda dropearla.
        DB::statement(<<<'SQL'
ALTER TABLE storage_providers
  DROP CONSTRAINT IF EXISTS storage_providers_parent_storage_id_fkey
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE storage_providers
  ADD CONSTRAINT storage_providers_parent_storage_id_fkey
  FOREIGN KEY (parent_storage_id) REFERENCES storage_providers(id)
  ON DELETE SET NULL
SQL);

        // Backfill: ancestro inmediato por prefijo mas largo.
        DB::statement(<<<'SQL'
WITH norm AS (
  SELECT id, rtrim(base_path, '/') AS p
  FROM storage_providers
  WHERE base_path IS NOT NULL AND rtrim(base_path, '/') <> ''
)
UPDATE storage_providers s
SET parent_storage_id = (
  SELECT n2.id
  FROM norm n2
  WHERE n2.id <> n.id AND n.p LIKE n2.p || '/%'
  ORDER BY LENGTH(n2.p) DESC
  LIMIT 1
)
FROM norm n
WHERE s.id = n.id
SQL);

        $this->reportEquivalentNodes();
    }

    /**
     * Reporta los grupos con base_path normalizado identico. No los enlaza:
     * son nodos equivalentes, no padre/hijo.
     */
    private function reportEquivalentNodes(): void
    {
        $rows = DB::select(<<<'SQL'
SELECT rtrim(base_path, '/') AS ruta,
       array_agg(id ORDER BY id)      AS ids,
       array_agg(name ORDER BY id)    AS nombres,
       array_agg(transcription_enabled ORDER BY id) AS tx
FROM storage_providers
WHERE base_path IS NOT NULL AND rtrim(base_path, '/') <> ''
GROUP BY 1
HAVING count(*) > 1
ORDER BY 1
SQL);

        if (empty($rows)) {
            return;
        }

        $grupos = array_map(static fn ($r) => [
            'ruta' => $r->ruta,
            'ids' => $r->ids,
            'nombres' => $r->nombres,
            'tx' => $r->tx,
        ], $rows);

        Log::warning('storage_providers.equivalent_nodes_report', [
            'count' => count($grupos),
            'nota' => 'misma ruta fisica, varios gestores: NO se enlazan como padre/hijo (design.md D10)',
            'grupos' => $grupos,
        ]);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE storage_providers
  DROP CONSTRAINT IF EXISTS storage_providers_parent_storage_id_fkey
SQL);

        Schema::table('storage_providers', function (Blueprint $table) {
            if (Schema::hasColumn('storage_providers', 'parent_storage_id')) {
                $table->dropIndex('storage_providers_parent_storage_id_idx');
                $table->dropColumn('parent_storage_id');
            }
        });
    }
};
