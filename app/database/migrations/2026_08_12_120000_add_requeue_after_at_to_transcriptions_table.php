<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->timestamp('requeue_after_at')->nullable()->after('finished_at');
            $table->index(['state', 'requeue_after_at'], 'transcriptions_state_requeue_after_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropIndex('transcriptions_state_requeue_after_at_idx');
            $table->dropColumn('requeue_after_at');
        });
    }
};