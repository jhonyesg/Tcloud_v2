<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_bulk_actions', function (Blueprint $table) {
            $table->string('id', 64)->primary();         // ULID
            $table->string('action', 20);                 // 'bulk_approve' | 'bulk_reject' | 'bulk_destroy'
            $table->unsignedBigInteger('performed_by');
            $table->timestamp('performed_at');
            $table->timestamp('expires_at');
            $table->timestamp('undone_at')->nullable();
            $table->unsignedBigInteger('undone_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->integer('item_count')->default(0);
            $table->text('notes')->nullable();

            $table->foreign('performed_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('undone_by')->references('id')->on('users')->onDelete('restrict');
        });

        // Index para cleanup job: filas vivas (no undone, no superseded, ya expiradas)
        // y para query "última bulk action del admin activa".
        Schema::table('correction_bulk_actions', function (Blueprint $table) {
            $table->index(['expires_at'], 'idx_cba_expires');
            $table->index(['performed_by', 'performed_at'], 'idx_cba_performed');
        });

        Schema::create('correction_bulk_action_items', function (Blueprint $table) {
            $table->id();
            $table->string('bulk_action_id', 64);
            $table->unsignedBigInteger('correction_id');
            $table->string('previous_status', 20);
            $table->unsignedBigInteger('merge_target_id')->nullable();
            $table->text('merge_previous_correct_text')->nullable();
            // Para bulk_destroy: snapshot completo del row antes de DELETE.
            $table->json('destroy_snapshot')->nullable();
            $table->boolean('applied')->default(true);

            $table->foreign('bulk_action_id')
                ->references('id')
                ->on('correction_bulk_actions')
                ->onDelete('cascade');
            $table->index('bulk_action_id', 'idx_cbai_bulk_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_bulk_action_items');
        Schema::dropIfExists('correction_bulk_actions');
    }
};