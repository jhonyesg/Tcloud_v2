<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_storages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('storage_provider_id')->constrained()->onDelete('cascade');
            $table->string('permissions', 20);
            $table->boolean('can_create_shares')->default(false);
            $table->timestamp('assigned_at')->useCurrent();
            $table->unique(['user_id', 'storage_provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_storages');
    }
};
