<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained('transcriptions')->cascadeOnDelete();
            $table->integer('segment_index');
            $table->decimal('start_seconds', 10, 3);
            $table->decimal('end_seconds', 10, 3);
            $table->text('text_raw');
            $table->text('text');
            $table->timestamps();

            $table->index('transcription_id');
        });

        // Indice GIN trigram en `text` (corregido, usado para busqueda/matching).
        DB::statement('CREATE INDEX idx_transcription_segments_text_gin ON transcription_segments USING GIN (text gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_segments');
    }
};