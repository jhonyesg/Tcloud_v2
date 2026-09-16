<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `transcription_access` a `user_storages`. Es la base de la
 * orquestación de Avisos Inteligentes: el admin concede, storage por
 * storage, qué clientes pueden ver los resultados de la transcripción
 * que api-transcriptor ya produjo.
 *
 * Esta columna NO controla si el storage se transcribe:
 *   - `storage_providers.transcription_enabled` sigue siendo la bandera
 *     autoritativa del pipeline (la escribe únicamente
 *     ApiTranscriptorController::toggleStorage).
 *   - Esta columna solo controla visibilidad de resultados para el
 *     cliente. Default `false` (opt-in manual, sin siembra).
 *
 * Historia: entre el 2026-08-18 y el 2026-08-20 existió una columna
 * con nombre parecido (`transcription_enabled`) en el mismo pivote. Fue
 * revertida tras una caída de 44 horas. La nueva columna se llama
 * distinto a propósito para que ningún servicio del pipeline la lea
 * por accidente como si fuera el flag global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_storages', function (Blueprint $table) {
            $table->boolean('transcription_access')->default(false)->after('can_create_shares');
        });
    }

    public function down(): void
    {
        Schema::table('user_storages', function (Blueprint $table) {
            $table->dropColumn('transcription_access');
        });
    }
};
