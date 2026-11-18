<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change `solicitud-puntual-tv-radio` (T1 — Grupo 1: Fundaciones de esquema).
 *
 * Tablas de la solicitud puntual de TV y Radio.
 *
 * `solicitud_puntual` es la fuente de verdad de la entrega, NO el share:
 *  - `shares.file_id` es FK `ON DELETE CASCADE`. Cuando el prune borra la
 *    fila de `files` (el árbol rota: ~48 días de retención), el share muere
 *    solo. El control contable tiene que sobrevivir a eso.
 *  - La vigencia y la revocación se administran desde la solicitud, no desde
 *    el enlace.
 *  - El motivo y el detalle de lo entregado no viven en `shares` (que es un
 *    catálogo de enlaces, no un buzón).
 *
 * `status` (vocabulario cerrado, con CHECK):
 *  - `ready`       → el material cubrió el rango pedido; hay enlaces vivos.
 *  - `partial`    → el material cubrió PARTE del rango; hay enlaces vivos y
 *                    el hueco está cuantificado en `cobertura`.
 *  - `unavailable` → el material no existe (fuera de retención, o nunca se
 *                    emitió). NO hay enlace.
 *  - `revoked`     → el solicitante revocó la solicitud.
 *
 * El estado `expired` NO se guarda: se DERIVA de `link_expires_at`, igual que
 * en `backup_requests`. El historial expone el vocabulario cerrado
 * `ready|expired|unavailable|revoked` combining lo guardado con lo derivado.
 *
 * `solicitud_puntual_item` es el detalle por archivo entregado, con SNAPSHOT
 * de `name`, `path` y `size`: el registro contable no puede depender de que
 * la fila de `files` siga existiendo.
 *
 * `file_id` y `share_id` son `ON DELETE SET NULL`: un archivo o un enlace
 * borrados no borran el registro, solo lo dejan sin enlace.
 *
 * Reversible: el down() elimina ambas tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_puntual', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medio_id');
            $table->unsignedBigInteger('share_id')->nullable();

            $table->date('fecha');
            $table->string('modo', 10);
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->integer('duracion_minutos')->default(0);
            $table->string('status', 20)->default('unavailable');
            $table->text('motivo');
            $table->jsonb('cobertura')->nullable();

            $table->integer('archivos_total')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->integer('links_total')->default(0);

            $table->timestampTz('link_expires_at')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('medio_id')->references('id')->on('medios')->cascadeOnDelete();
            $table->foreign('share_id')->references('id')->on('shares')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE solicitud_puntual
            ADD CONSTRAINT solicitud_puntual_modo_check
            CHECK (modo IN ('franja', 'dia'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE solicitud_puntual
            ADD CONSTRAINT solicitud_puntual_status_check
            CHECK (status IN ('ready', 'partial', 'unavailable', 'revoked'))
        SQL);

        // Historial del cliente: siempre "mis solicitudes, más recientes
        // primero".
        DB::statement(<<<'SQL'
            CREATE INDEX idx_solicitud_puntual_user_created
            ON solicitud_puntual (user_id, created_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX idx_solicitud_puntual_user_status
            ON solicitud_puntual (user_id, status)
        SQL);

        // Control contable: agregación por cliente y período.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_solicitud_puntual_medio_fecha
            ON solicitud_puntual (medio_id, fecha)
        SQL);

        Schema::create('solicitud_puntual_item', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('solicitud_id');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('share_id')->nullable();

            // Slot nominal de la grilla de emisión del medio.
            $table->timestampTz('slot_inicio');
            $table->timestampTz('slot_fin');
            // Instantes REALES de captura (del nombre del archivo y del mtime).
            $table->timestampTz('capture_inicio')->nullable();
            $table->timestampTz('capture_fin')->nullable();

            // SNAPSHOT del archivo entregado: sobrevive al prune del catálogo.
            $table->string('name', 255);
            $table->string('path', 500);
            $table->unsignedBigInteger('size')->default(0);

            $table->timestamps();

            $table->foreign('solicitud_id')->references('id')->on('solicitud_puntual')->cascadeOnDelete();
            $table->foreign('file_id')->references('id')->on('files')->nullOnDelete();
            $table->foreign('share_id')->references('id')->on('shares')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_solicitud_puntual_item_unique_slot
            ON solicitud_puntual_item (solicitud_id, slot_inicio)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX idx_solicitud_puntual_item_solicitud
            ON solicitud_puntual_item (solicitud_id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_puntual_item');
        Schema::dropIfExists('solicitud_puntual');
    }
};
