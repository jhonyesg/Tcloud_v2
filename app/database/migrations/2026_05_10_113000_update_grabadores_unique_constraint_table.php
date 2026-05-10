<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grabadores', function (Blueprint $table) {
            $table->dropUnique(['ip', 'puerto']);
            $table->unique(['ip', 'puerto', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('grabadores', function (Blueprint $table) {
            $table->dropUnique(['ip', 'puerto', 'tipo']);
            $table->unique(['ip', 'puerto']);
        });
    }
};
