<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained('transcriptions')->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->foreignId('segment_id')->constrained('transcription_segments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('snippet', 500);
            $table->timestamp('matched_at')->useCurrent();

            $table->index(['user_id', 'matched_at']);
            $table->index(['transcription_id', 'user_id']);
            $table->index('keyword_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_matches');
    }
};