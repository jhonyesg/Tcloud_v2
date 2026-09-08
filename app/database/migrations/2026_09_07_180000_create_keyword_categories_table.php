<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_categories', function (Blueprint $table) {
            $table->id();
            $table->char('owner_scope', 8);
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 96);
            $table->char('color_hex', 7)->default('#4654a8');
            $table->timestamps();

            $table->unique(['owner_scope', 'owner_id', 'slug'], 'keyword_categories_scope_owner_slug_uniq');
            $table->index(['owner_scope', 'owner_id'], 'keyword_categories_scope_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_categories');
    }
};
