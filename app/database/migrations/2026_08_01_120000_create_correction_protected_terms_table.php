<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('correction_protected_terms')) {
            Schema::create('correction_protected_terms', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('term', 120);
                $table->string('category', 32)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('archived_at')->nullable();

                $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
                $table->index('category');
                // idx_term_active lo creamos vía SQL crudo abajo porque Laravel
                // no soporta partial unique indexes de forma nativa; necesitamos
                // UNIQUE(term) WHERE archived_at IS NULL.
            });

            // Partial unique: evita duplicados activos con el mismo term.
            // (Postgres feature; SQLite lo ignora en tests — funciona por el
            // check de aplicación en el service.)
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS correction_protected_terms_term_active_unique '
                . 'ON correction_protected_terms (term) WHERE archived_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('correction_protected_terms')) {
            DB::statement('DROP INDEX IF EXISTS correction_protected_terms_term_active_unique');
            Schema::drop('correction_protected_terms');
        }
    }
};
