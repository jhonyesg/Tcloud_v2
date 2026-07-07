<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->unique()->constrained('files')->cascadeOnDelete();
            $table->string('job_id', 64)->nullable();
            $table->string('node_url', 200)->nullable();
            $table->string('state', 20)->default('queued');
            $table->string('language', 5)->default('es');
            $table->text('srt_content')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('word_count')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retries')->default(0);
            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};