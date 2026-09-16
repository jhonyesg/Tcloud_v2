<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->smallInteger('corrected')->nullable()->after('state');
            $table->index(['state', 'corrected'], 'transcriptions_state_corrected_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropIndex('transcriptions_state_corrected_idx');
            $table->dropColumn('corrected');
        });
    }
};