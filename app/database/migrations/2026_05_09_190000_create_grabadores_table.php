<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grabadores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('ip', 45);
            $table->integer('puerto')->default(5002);
            $table->string('base_url');
            $table->string('token')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['ip', 'puerto']);
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grabadores');
    }
};