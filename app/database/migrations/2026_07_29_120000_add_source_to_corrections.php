<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('corrections', 'source')) {
            Schema::table('corrections', function (Blueprint $table) {
                $table->string('source', 64)
                    ->nullable()
                    ->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('corrections', 'source')) {
            Schema::table('corrections', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};