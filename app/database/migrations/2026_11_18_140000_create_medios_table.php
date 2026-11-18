<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change `solicitud-puntual-tv-radio` (T1 — Grupo 1: Fundaciones de esquema).
 *
 * Catálogo explícito de medios direccionables.
 *
 * Por qué una tabla propia y no filas de `storage_providers`:
 *  - Los sub-canales de los contenedores Emisoras 01/02/03/04 (RD1, RD4,
 *    RD6, Radios/Bogota — ~84 en total) viven DENTRO del `base_path` del
 *    storage que los contiene: `.../Disco_I/television/RD1/Antioquia/BLU`.
 *    No son storages, así que hoy no son direccionables.
 *  - Crearles un `storage_providers` propio NO sirve: el cron
 *    `storage:sync --all` (cada 15 min) recorre el árbol FÍSICO del storage
 *    contenedor y vuelve a crear sus filas bajo el contenedor. El resultado
 *    sería el mismo archivo catalogado dos veces (ruta relativa al 49 y
 *    ruta relativa al 999) y una navegación BD-first huérfana.
 *    Precedente: el change `solicitud-backup-mis-archivos` ya descartó ese
 *    patrón explícitamente.
 *
 * Por eso el medio es un ÍNDICE sobre `(storage_id, subpath)`: no duplica
 * contenido, no toca `files` y no requiere cambios en el sync.
 *
 * `subpath` es la ruta interna del canal dentro del storage, vacía cuando el
 * medio ES el storage completo (caso de los canales planos como `01 Caracol
 * Tv`). `name` es el nombre legible que ve el cliente; `region` desambigua
 * los homónimos (`Antioquia/Caracol` vs `Radios/Bogota/CARACOL`).
 *
 * El índice único es PARCIAL a propósito: una fila dada de baja (`activo =
 * false`) o todavía sin nombre no bloquea el remapeo posterior de ese mismo
 * sub-canal.
 *
 * Reversible: el down() elimina la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medios', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('storage_id');
            $table->string('subpath', 500)->default('');
            $table->string('name', 255);
            $table->string('media_kind', 10);
            $table->string('region', 120)->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->foreign('storage_id')->references('id')->on('storage_providers')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE medios
            ADD CONSTRAINT medios_media_kind_check
            CHECK (media_kind IN ('tv', 'radio'))
        SQL);

        // Un medio activo por (storage, subpath). Parcial: las filas dadas de
        // baja o sin nombre no bloquean un remapeo posterior.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_medios_unique_activo
            ON medios (storage_id, subpath)
            WHERE activo = true AND name <> ''
        SQL);

        // Listado del módulo cliente: medios de un storage, activos.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_medios_storage_activo
            ON medios (storage_id, activo)
        SQL);

        // Filtro por tipo (TV / Radio) del catálogo del cliente.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_medios_media_kind
            ON medios (media_kind)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('medios');
    }
};
