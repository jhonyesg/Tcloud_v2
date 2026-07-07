<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->string('wrong_text', 500);
            $table->string('correct_text', 500);
            $table->string('wrong_normalized', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('source_segment_id')->nullable()->constrained('transcription_segments')->cascadeOnDelete();
            $table->integer('applies_count')->default(0);
            $table->timestamps();

            $table->index('proposed_by');
            $table->index('approved_by');
            $table->index('wrong_normalized');
        });

        // Indice unico parcial: solo una correction approved por wrong_normalized.
        DB::statement("CREATE UNIQUE INDEX corrections_wrong_active_unique ON corrections (wrong_normalized) WHERE status = 'approved'");
        // Cola de pendientes.
        DB::statement("CREATE INDEX corrections_pending_idx ON corrections (status) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('corrections');
    }
};