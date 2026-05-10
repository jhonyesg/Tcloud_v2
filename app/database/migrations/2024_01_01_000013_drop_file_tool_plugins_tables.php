<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_file_tool_plugins');
        Schema::dropIfExists('file_tool_plugins');
    }

    public function down(): void
    {
        Schema::create('file_tool_plugins', function ($table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->enum('type', ['viewer', 'editor', 'player']);
            $table->json('supported_mimes');
            $table->json('resources');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('user_file_tool_plugins', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_tool_plugin_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'file_tool_plugin_id']);
        });
    }
};
