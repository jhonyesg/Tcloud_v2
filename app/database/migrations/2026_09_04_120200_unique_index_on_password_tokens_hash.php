<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte el índice no-único sobre `password_tokens.token_hash` en
 * único. Las colisiones son astronómicamente improbables con SHA-256 de
 * 32 bytes aleatorios, pero la unicidad del storage es la garantía
 * formal — el lookup por hash siempre devuelve a lo sumo un registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_tokens', function (Blueprint $table) {
            $table->dropIndex(['token_hash']);
            $table->unique('token_hash', 'password_tokens_token_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('password_tokens', function (Blueprint $table) {
            $table->dropUnique('password_tokens_token_hash_unique');
            $table->index('token_hash');
        });
    }
};
