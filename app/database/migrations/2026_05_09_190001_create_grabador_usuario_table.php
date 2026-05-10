<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grabador_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grabador_id')->constrained('grabadores')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('limite_canales')->default(10);
            $table->timestamps();

            $table->unique(['grabador_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grabador_usuario');
    }
};