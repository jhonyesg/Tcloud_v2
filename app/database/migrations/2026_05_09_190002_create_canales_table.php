<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grabador_id')->constrained('grabadores')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users')->onDelete('cascade');
            $table->string('slot_nombre', 50);
            $table->integer('api_canal_id')->nullable()->index();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['grabador_id', 'slot_nombre']);
            $table->unique(['grabador_id', 'api_canal_id']);
            $table->index(['usuario_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canales');
    }
};