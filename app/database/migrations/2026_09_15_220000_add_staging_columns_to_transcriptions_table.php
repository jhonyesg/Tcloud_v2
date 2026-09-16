<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transcriptor-two-phase-staging: columnas de la fase local del pipeline.
 *
 * El diseño de dos fases separa la conversion local (ffmpeg, costosa en CPU)
 * del envio a la API remota (barato, limitado por el headroom de la cola
 * remota):
 *
 *   1. STAGE  -> ffmpeg convierte el audio de origen al WAV/opus de envio y lo
 *                deja en /dev/shm (tmpfs), manteniendo el consumo de CPU en
 *                goteo. Marca `staged_*`.
 *   2. SEND   -> cuando /api/metrics/overview reporta headroom en la cola
 *                remota, hace el POST multipart del archivo YA convertido y
 *                borra el staged.
 *
 * Por que columnas y no una tabla nueva: la fila `transcriptions` YA es la
 * unidad de trabajo y su unico id es la clave natural del staged. Una tabla
 * aparte obligaria a un JOIN extra en el camino caliente del worker.
 *
 * `staged_bytes` se usa para el presupuesto del ramdisk: el stager no empieza
 * un archivo si `sum(staged_bytes) + estimado > budget`, asi el tmpfs no se
 * llena y el envio nunca falla por ENOSPC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('transcriptions', 'staged_path')) {
                // Ruta absoluta del audio convertido dentro de /dev/shm.
                $table->string('staged_path', 500)->nullable()->after('recorded_at');
            }
            if (!Schema::hasColumn('transcriptions', 'staged_bytes')) {
                // Tamano real del archivo staged, para el presupuesto del tmpfs.
                $table->bigInteger('staged_bytes')->nullable()->after('staged_path');
            }
            if (!Schema::hasColumn('transcriptions', 'staged_at')) {
                // Cuando se completo la conversion. Sirve para TTL/purga.
                $table->timestampTz('staged_at')->nullable()->after('staged_bytes');
            }
        });

        // Indice parcial para el stager: candidatos a convertir (pending sin
        // staged todavia, del dia). Mantiene el SELECT en index-only scan.
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS transcriptions_stage_queue_idx
  ON transcriptions (recorded_at DESC)
  WHERE state = 'pending' AND staged_path IS NULL
SQL);

        // Indice para el sender: filas ya convertidas listas para POST.
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS transcriptions_send_ready_idx
  ON transcriptions (staged_at)
  WHERE state = 'pending' AND staged_path IS NOT NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS transcriptions_send_ready_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS transcriptions_stage_queue_idx');

        Schema::table('transcriptions', function (Blueprint $table) {
            foreach (['staged_path', 'staged_bytes', 'staged_at'] as $col) {
                if (Schema::hasColumn('transcriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * CREATE INDEX CONCURRENTLY no puede correr dentro de transaccion.
     */
    public $withinTransaction = false;
};
