<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('canales')
            ->whereNull('usuario_id')
            ->delete();
    }

    public function down(): void
    {
        // Los canales huérfanos no se pueden restaurar
    }
};
