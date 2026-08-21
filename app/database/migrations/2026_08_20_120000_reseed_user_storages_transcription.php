<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recuperación del pipeline de transcripción, parado desde el 2026-08-18 22:50.
 *
 * La migración 2026_08_18_210000 convirtió `storage_providers.transcription_enabled`
 * en un valor derivado de `user_storages.transcription_enabled`, y su siembra
 * copiaba la bandera vieja al pivote. Esa siembra sembró 0 filas: cuando corrió
 * (segundo intento, tras un rollback — hueco en el id 77 de `migrations`) los 175
 * storages ya estaban en false, así que la única fuente de la copia estaba vacía.
 *
 * Resultado: 310 filas de user_storages y 175 storages en false, el scanner sin
 * storages que recorrer y `transcription:tune` apagando los 12 workers systemd.
 * 44 horas de emisión sin transcribir y ni un WARNING en laravel.log.
 *
 * El conjunto habilitado previo no quedó registrado en ninguna tabla, así que se
 * reconstruye desde el historial de `transcriptions` (qué storage produjo
 * transcripciones en los 90 días previos al corte). Da 36 de los 39 que reportaba
 * `transcription:tune` antes de morir; los 3 restantes no produjeron nada en 90
 * días y los reactiva el operador desde /ia/api-transcriptor.
 *
 * La derivación desde `user_storages` se retiró el mismo 2026-08-20: API
 * Transcriptor es un módulo independiente y su interruptor vuelve a escribir
 * directamente sobre `storage_providers.transcription_enabled`. Por eso esta
 * migración enciende el storage, no el pivote.
 */
return new class extends Migration
{
    /**
     * Storages que transcribían antes del corte, reconstruidos desde
     * `transcriptions` (ver cabecera). Se dejan literales a propósito: derivar
     * la lista con una consulta al vuelo la haría depender de cuánto historial
     * quede vivo cuando esta migración corra en otro entorno.
     *
     * Excluido a conciencia el id 5 ("00 Discos"): es la raíz de todo el árbol
     * de datos y solo tiene UNA transcripción suelta del 6 de julio. Habilitarlo
     * pondría al scanner a recorrer el disco entero.
     */
    private const STORAGE_IDS = [
        // Con tráfico en los 7 días previos al corte
        6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24,
        25, 26, 27, 28, 29, 30, 47, 49, 63, 127, 128, 133, 135, 169, 184,
        // Canales activos de baja cadencia (última tx dentro de los 90 días)
        51, // 03 Emisoras 02 (grouped_by_subfolder)
        62, // 02 Caracol Radio Bogota
    ];

    public function up(): void
    {
        // `storage_providers.transcription_enabled` es la bandera autoritativa:
        // se enciende y se apaga desde API Transcriptor, que es un módulo
        // independiente. La derivación desde user_storages que existió entre el
        // 18 y el 20 de agosto se retiró junto con este arreglo.
        $habilitados = DB::table('storage_providers')
            ->whereIn('id', self::STORAGE_IDS)
            ->where('transcription_enabled', false)
            ->update(['transcription_enabled' => true, 'updated_at' => now()]);

        $total = DB::table('storage_providers')
            ->where('transcription_enabled', true)
            ->count();

        echo "  Storages reactivados: {$habilitados}\n";
        echo "  Storages transcribiendo tras la resiembra: {$total}\n";

        // Una resiembra que deja el pipeline apagado es exactamente el fallo
        // silencioso que provocó este incidente. Aquí se rompe en voz alta.
        if ($total === 0) {
            throw new RuntimeException(
                'Resiembra fallida: ningún storage quedó transcribiendo. '
                . 'Revisar que los ids de STORAGE_IDS existan en storage_providers.'
            );
        }
    }

    public function down(): void
    {
        DB::table('storage_providers')
            ->whereIn('id', self::STORAGE_IDS)
            ->update(['transcription_enabled' => false, 'updated_at' => now()]);
    }
};
