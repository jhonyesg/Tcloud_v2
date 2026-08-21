<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\Keyword;
use Illuminate\Support\Facades\DB;

/**
 * Detecta mezclas EN↔ES en el corpus de transcripciones. El ASR externo
 * mete chunks de inglés dentro de transcripciones en español (spanglish del
 * speaker, interferencia, o vocabulario técnico sin traducir). El miner
 * escanea el corpus y propone candidatos como pending para que el admin
 * los revise en bulk moderation.
 *
 * Estrategia única: KNOWN — lista hardcoded de frases EN→ES con mapeo
 * verificado manualmente. La antigua estrategia B (detección abierta de
 * bigramas `function_en + noun_es`) fue retirada en favor de
 * `LlmCorrectionSuggester` (long-tail con contexto) y porque emitía
 * candidatos de 2 palabras que contradicen el umbral transversal
 * `corrections.min_suggestion_words=3` (ver change
 * 2026-08-15-en-es-mix-miner-prune-open-strategy).
 *
 * Diseñado para correr batch (CLI), no en el path del webhook SRT.
 */
class EnEsMixMiner
{
    /**
     * Mapeos verificados manualmente a lo largo de bootstrapping (2026-07-29)
     * y rounds posteriores. Una frase EN aquí se cuenta en el corpus y, si
     * supera min_freq y no está en el diccionario, se propone como pending.
     *
     * Lista intencionalmente hardcoded: es la fuente de verdad curada por
     * humanos. Agregar entradas nuevas es seguro (no rompe min_freq de las
     * viejas) y no requiere deploy si vienen de admin config (futuro).
     */
    public const KNOWN_EN_ES_MAPPINGS = [
        // === Estructurales EN→ES (50 GRUPO A de bootstrapping 2026-07-29) ===
        'in the world'         => 'en el mundo',
        'of the world'         => 'del mundo',
        'at the end'           => 'al final',
        'all the time'         => 'todo el tiempo',
        'at the time'          => 'en ese momento',
        'of the people'        => 'de la gente',
        'of the year'          => 'del año',
        'at the moment'        => 'en este momento',
        'of the government'    => 'del gobierno',
        'in the history'       => 'en la historia',
        'of the day'           => 'del día',
        'in the region'        => 'en la región',
        'in the department'    => 'en el departamento',
        'of the president'     => 'del presidente',
        'in the city'          => 'en la ciudad',
        'of the night'         => 'de la noche',
        'of the department'    => 'del departamento',
        'and the people'       => 'y la gente',
        'in the market'        => 'en el mercado',
        'in the zone'          => 'en la zona',
        'of the community'     => 'de la comunidad',
        'of the state'         => 'del estado',
        'of the nation'        => 'de la nación',
        'at the same time'     => 'al mismo tiempo',
        'of the region'        => 'de la región',
        'in the territory'     => 'en el territorio',
        'in the area'          => 'en el área',
        'for the people'       => 'para la gente',
        'of the market'        => 'del mercado',
        'in the morning'       => 'en la mañana',
        'of the territory'     => 'del territorio',
        'with the people'      => 'con la gente',
        'and the government'   => 'y el gobierno',
        'in the country'       => 'en el país',
        'by the way'           => 'por cierto',
        'of the society'       => 'de la sociedad',
        'at the university'    => 'en la universidad',
        'with the community'   => 'con la comunidad',
        'for the moment'       => 'por el momento',
        'of the area'          => 'del área',
        'of the country'       => 'del país',
        'with the government'  => 'con el gobierno',
        'in the government'    => 'en el gobierno',
        'for the government'   => 'por el gobierno',
        'at the hospital'      => 'en el hospital',
        'at the beginning'     => 'al principio',
        'in the meantime'      => 'mientras tanto',

        // === Variantes adicionales (rounds 2-3) ===
        'in this moment'       => 'en este momento',
        'at this moment'       => 'en este momento',
        'in that moment'       => 'en ese momento',
        'at that moment'       => 'en ese momento',
        'in the system'        => 'en el sistema',
        'in the building'      => 'en el edificio',
        'to the world'         => 'al mundo',
        'for the world'        => 'para el mundo',
        'on the world'         => 'en el mundo',
        'with the world'       => 'con el mundo',
        'from the world'       => 'del mundo',
        'over the world'       => 'sobre el mundo',
        'around the world'     => 'por todo el mundo',
        'through the world'    => 'por el mundo',
        'into the world'       => 'en el mundo',
        'across the world'     => 'por todo el mundo',
        'throughout the world' => 'en todo el mundo',
        'within the world'     => 'dentro del mundo',
        'over and over'        => 'una y otra vez',
        'day and night'        => 'día y noche',
        'echo de menos'        => 'echado de menos',
    ];

    /**
     * Punto de entrada unificado: corre la estrategia KNOWN y retorna
     * la lista de candidatos detectados (SIN insertar).
     *
     * @return array<int, array{wrong:string, correct:string, freq:int, strategy:string}>
     */
    public function mine(int $daysBack, int $minFreq): array
    {
        return $this->mineKnown($daysBack, $minFreq);
    }

    /**
     * Estrategia A: para cada mapeo KNOWN, cuenta cuántos segmentos lo
     * contienen dentro de la ventana. Si supera min_freq y no está
     * approved, lo propone.
     *
     * @return array<int, array{wrong:string, correct:string, freq:int, strategy:string}>
     */
    public function mineKnown(int $daysBack, int $minFreq): array
    {
        $candidates = [];
        $since = now()->subDays(max(1, $daysBack));

        foreach (self::KNOWN_EN_ES_MAPPINGS as $wrong => $correct) {
            $count = DB::table('transcription_segments')
                ->where('created_at', '>=', $since)
                ->whereNotNull('text_raw')
                ->where('text_raw', 'ILIKE', '%' . $wrong . '%')
                ->count();

            if ($count < $minFreq) {
                continue;
            }

            if ($this->isApproved($wrong)) {
                continue;
            }

            $candidates[] = [
                'wrong' => $wrong,
                'correct' => $correct,
                'freq' => $count,
                'strategy' => 'known',
            ];
        }

        return $candidates;
    }

    /**
     * ¿Ya existe una corrección approved con ese wrong_normalized?
     */
    private function isApproved(string $wrong): bool
    {
        return Correction::approved()
            ->where('wrong_normalized', Keyword::asciiLower($wrong))
            ->exists();
    }

    /**
     * Busca el id del segmento de transcripción más reciente cuyo `text_raw`
     * contiene `wrong` (case-insensitive). Se usa antes de `propose()` para
     * poblar `corrections.source_segment_id` y dar contexto en la UI de
     * moderación (changes/2026-08-12-corrections-pending-segment-context).
     *
     * Devuelve `null` si el texto no aparece (caso raro: el segmento fue
     * purgado o el wrong_text no calza textualmente). El caller debe
     * tolerar `null` y continuar sin error.
     *
     * Performance: una query por candidato. 19.6M de filas, miner batch
     * (no hot path). Si duele, agregar índice GIN trigram en `text_raw`.
     */
    public function lookupSourceSegmentId(string $wrong): ?int
    {
        if ($wrong === '') {
            return null;
        }
        return DB::table('transcription_segments')
            ->whereRaw('text_raw ILIKE ?', ['%' . $this->escapeIlike($wrong) . '%'])
            ->orderByDesc('created_at')
            ->value('id');
    }

    /**
     * Escapa `%`, `_` y `\` para que se traten como literales dentro de
     * un patrón ILIKE bindeado.
     */
    public function escapeIlike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
