<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correo_log', function (Blueprint $table) {
            $table->id();
            $table->string('destinatario');
            $table->string('plantilla');
            $table->string('asunto');
            $table->text('body_sent')->nullable();
            $table->string('estado', 20);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_log');
    }
};
