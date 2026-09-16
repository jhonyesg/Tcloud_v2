<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `status` a `users` para el ciclo de vida de la cuenta:
 *   - `pending`  → creada por el admin pero el usuario aún no ha
 *                  establecido su contraseña vía el mail de bienvenida.
 *                  No puede iniciar sesión.
 *   - `active`   → contraseña materializada, login permitido.
 *   - `disabled` → bloqueada por admin (no usada por este change, pero
 *                  reservada para futuro).
 *
 * Default `active` para preservar operatividad de los usuarios ya
 * existentes (todos tienen password_hash usable hoy). Los nuevos que se
 * creen por el admin nacerán en `pending` vía código (User model boot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('role');
            $table->index('status');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('pending', 'active', 'disabled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
