<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_tool_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->enum('type', ['viewer', 'editor', 'player']);
            $table->json('supported_mimes');
            $table->json('resources');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_tool_plugins');
    }
};
