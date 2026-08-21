<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REVERTIDA EL 2026-08-20. La columna sigue en la tabla pero está MUERTA: no la
 * lee ni la escribe nadie. No construir nada sobre ella.
 *
 * Intención original: la transcripción pasaba de ser una bandera del storage a
 * un servicio contratado por cliente, y `storage_providers.transcription_enabled`
 * quedaba como valor derivado (EXISTS sobre este pivote).
 *
 * Por qué se revirtió, en dos frases: acoplaba dos módulos que son
 * independientes — encender un canal es una decisión operativa de API
 * Transcriptor, mientras que Avisos Inteligentes y Correcciones solo consumen el
 * contenido que ese canal produce — y la indirección se llevó el pipeline por
 * delante: la siembra de aquí sembró 0 filas, la derivación apagó los 175
 * storages y estuvimos 44 horas sin transcribir.
 *
 * El interruptor volvió a ApiTranscriptorController::toggleStorage(), que
 * escribe `storage_providers.transcription_enabled` directamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_storages', function (Blueprint $table) {
            if (!Schema::hasColumn('user_storages', 'transcription_enabled')) {
                $table->boolean('transcription_enabled')
                    ->default(false)
                    ->after('can_create_shares');
            }
        });

        // SIEMBRA — no es opcional. La columna nace en false; si la derivación
        // entrara en vigor sin sembrar, todos los storages que hoy transcriben
        // se apagarían y el pipeline se detendría en producción.
        //
        // Es conservadora a propósito: asume que todos los clientes de un
        // storage activo lo tenían contratado. Es la única lectura que no
        // interrumpe el servicio; el admin depura después desde la ficha del
        // cliente, que es justo lo que esta pantalla habilita.
        $sembradas = DB::table('user_storages')
            ->whereIn('storage_provider_id', function ($q) {
                $q->select('id')->from('storage_providers')->where('transcription_enabled', true);
            })
            ->update(['transcription_enabled' => true]);

        // Índice parcial: la derivación consultaba EXISTS(... AND
        // transcription_enabled) por storage, así que solo interesaban las filas
        // en true. Con la derivación retirada este índice ya no sirve a nadie;
        // se deja porque su coste es despreciable y borrarlo no aporta.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_user_storages_tx_enabled
                       ON user_storages (storage_provider_id)
                       WHERE transcription_enabled');

        echo "  Filas de user_storages sembradas en true: {$sembradas}\n";
        echo "  (columna en desuso desde el 2026-08-20; el interruptor vive en API Transcriptor)\n";
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_user_storages_tx_enabled');

        // Solo se elimina la columna, que desde el 2026-08-20 ya no la lee nadie.
        // `storage_providers.transcription_enabled` es independiente y conserva
        // su valor: el rollback no apaga ningún storage.
        if (Schema::hasColumn('user_storages', 'transcription_enabled')) {
            Schema::table('user_storages', function (Blueprint $table) {
                $table->dropColumn('transcription_enabled');
            });
        }
    }
};
