<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de auditoría para `files:prune-unlinked-safe`.
 *
 * Cada batch de la fase 1 (mark) se registra aquí con su conteo y fecha.
 * La fase 2 (confirm-delete) actualiza deleted_at cuando el operador
 * confirma el DELETE físico.
 *
 * Sin esta tabla no hay forma de saber qué lote de filas en estado 'gone'
 * se marcó cuando, ni de auditar después quién confirmó el borrado.
 *
 * Sin FKs ni CASCADE: la tabla vive sola, es registro puro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files_prune_batches', function (Blueprint $table) {
            $table->string('batch_id')->primary();
            $table->foreignId('storage_id')->nullable();
            $table->integer('marked')->default(0);
            $table->timestamp('marked_at')->nullable();
            $table->integer('deleted')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->index('marked_at');
            $table->index('storage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files_prune_batches');
    }
};