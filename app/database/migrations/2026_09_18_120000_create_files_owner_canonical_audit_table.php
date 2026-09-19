<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change `files-canonical-owner-by-storage` (2026-09-18):
 *
 * Tabla de auditoria para reversibilidad de la migracion canonica de
 * `files.owner_id`. Antes del UPDATE, `tcloud:files-canonicalize --apply`
 * inserta un row por file modificado con su `old_owner_id`. La migration
 * rollback de `canonicalize_files_owner_id` restaura desde esta tabla.
 *
 * Append-only en la practica (no se actualiza, no se borra). Retencion:
 * limpiar despues de 30 dias post-rollback confirmado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('files_owner_canonical_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('file_id');
            $table->unsignedBigInteger('storage_provider_id');
            $table->unsignedBigInteger('old_owner_id');
            $table->unsignedBigInteger('canonical_owner_id')->nullable();
            $table->timestamp('captured_at')->useCurrent();
            $table->index('file_id');
            $table->index('storage_provider_id');
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files_owner_canonical_audit');
    }
};
