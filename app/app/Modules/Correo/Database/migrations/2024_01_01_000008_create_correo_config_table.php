<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correo_config', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->integer('port')->default(587);
            $table->boolean('secure')->default(false);
            $table->string('user')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('from_name');
            $table->string('from_email');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_config');
    }
};
