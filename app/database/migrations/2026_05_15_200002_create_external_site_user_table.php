<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_site_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_site_id')->constrained('external_sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['external_site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_site_user');
    }
};
