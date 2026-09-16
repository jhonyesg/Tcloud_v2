<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idioma esperado de cada canal.
 *
 * Sin esto el corrector trataba por igual un noticiero en español y Teleislas,
 * que emite en criollo raizal, o una emisora poniendo música en inglés. El
 * diccionario "arreglaba" esas transcripciones correctas y producía espanglish
 * donde no había ningún error (medido el 2026-08-13: uniminuto 28 % de inglés,
 * teleisla 25 %, ambos legítimos).
 *
 * El slug sale de `transcriptions.original_name`, no de la tabla `canales`: esa
 * es otro subsistema (24 slots de grabación con nombres tipo `Puntual_05`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_languages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('label', 160)->nullable();
            $table->string('language', 8)->default('es');
            // Cuando es false el diccionario no toca los segmentos del canal.
            // Es la protección concreta contra reescribir contenido correcto.
            $table->boolean('apply_corrections')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('language');
            $table->index('apply_corrections');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_languages');
    }
};
