<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path', 500)->unique();
            $table->bigInteger('size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->foreignId('storage_provider_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('files')->onDelete('cascade');
            $table->boolean('is_folder')->default(false);
            $table->boolean('is_personal')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
