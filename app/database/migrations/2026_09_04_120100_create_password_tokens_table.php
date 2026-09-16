<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `password_tokens` para los flows de `setup` (primer
 * establecimiento de contraseña tras bienvenida) y `reset` (recuperación
 * desde el login). Reemplaza el token en `$_SESSION` que tenía
 * AuthController::forgotPassword — el link de recovery ya no depende
 * del mismo navegador para funcionar.
 *
 * Solo se guarda el SHA-256 del token. El token raw solo existe en el
 * mail y nunca toca la BD; si la BD se compromete, los tokens no son
 * reutilizables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64);
            $table->string('type', 16);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_created', 45)->nullable();
            $table->string('ip_used', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('token_hash');
            $table->index(['user_id', 'type', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_tokens');
    }
};
