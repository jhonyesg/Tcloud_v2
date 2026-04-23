<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained()->onDelete('cascade');
            $table->timestamp('accessed_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_access_log');
    }
};
