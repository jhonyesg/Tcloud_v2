<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_file_tool_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('file_tool_plugin_id')->constrained()->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'file_tool_plugin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_file_tool_plugins');
    }
};
