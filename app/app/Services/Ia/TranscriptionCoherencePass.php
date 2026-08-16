<?php

namespace App\Services\Ia;

use App\Services\Concerns\CallsLlmChatCompletion;
use Illuminate\Support\Facades\Log;

/**
 * Pase de coherencia IA sobre segmentos con inglés residual
 * (changes/2026-08-15-transcription-ai-coherence-pass).
 *
 * El diccionario de correcciones (CorrectionService) corrige frases exactas,
 * pero el transcriptor ASR produce spanglish (mezcla EN/ES) que el diccionario
 * no cubre. Este servicio llama al LLM configurado para corregir el texto de
 * los segmentos con inglés residual a español coherente, listo para producción.
 *
 * Flujo (ver TranscriptionProcessor::persistSegmentsAndUpdate):
 *   1. Diccionario primero (rápido/gratis).
 *   2. Detectar segmentos con inglés residual (EnglishResidualSegmentDetector).
 *   3. Corregir con IA solo los flagged, en batch (una llamada).
 *   4. Fallback seguro: si el LLM falla, se conserva el texto del diccionario.
 *
 * Config (DB-overridable via TranscriptorSettings):
 *   - ai_coherence_enabled
 *   - ai_coherence_threshold
 *   - ai_coherence_max_segments
 *   - ai_coherence_model (vacío = usa llm-correction.model)
 */
class TranscriptionCoherencePass
{
    use CallsLlmChatCompletion;

    public function __construct(
        private EnglishResidualSegmentDetector $detector,
        private TranscriptorSettings $settings,
        private LlmCorrectionSettings $llmSettings,
    ) {}

    /**
     * Palabras de contenido en inglés que indican spanglish cuando aparecen
     * en un contexto en español. Complementa la lista conservadora de
     * `en_functions` del detector (que solo cubre funciones gramaticales).
     * Estas son palabras léxicas que el ASR mete en inglés por interferencia.
     */
    private const EN_CONTENT_WORDS = [
        'approximately','magnitude','official','equips','equipment','moment',
        'tragedy','report','city','government','authorities','world','night',
        'year','day','idea','course','region','people','country','president',
        'minister','police','national','international','social','media','news',
        'program','situation','emergency','rescue','disaster','earthquake',
        'victims','families','community','health','education','economy',
        'business','company','market','money','election','campaign','vote',
        'candidate','party','congress','senate','house','court','justice','law',
        'security','defense','army','military','force','officer','investigation',
        'crime','hospital','doctor','patient','medical','treatment','vaccine',
        'virus','pandemic','technology','digital','internet','computer','system',
        'data','information','toneladas','equipos','concretes','rotomartillos',
        'penetration','equippers','mototrozadores','donaciones','bancos',
        'sangre','momento','tragedia','magnitud','oficial','aproximadamente',
    ];

    /**
     * Corrige con IA los segmentos con inglés residual.
     *
     * @param  array<int, array{index: int, text: string}>  $segments  segmentos ya corregidos por el diccionario
     * @return array<int, array{index: int, text: string}>  segmentos con `text` corregido por IA donde aplica
     */
    public function apply(array $segments): array
    {
        if (!$this->settings->bool('ai_coherence_enabled')) {
            return $segments;
        }

        $maxSegments = $this->settings->int('ai_coherence_max_segments');

        // Detectar segmentos con inglés residual.
        // El score de proporción del detector (en/(en+es)) NO captura el
        // spanglish: texto mayormente español con palabras inglesas incrustadas
        // ("the siento", "in this moment") da score bajo porque la mayoría de
        // tokens quedan "unknown". Aquí detectamos la MEZCLA EN+ES: al menos
        // 1 token EN (función o contenido) en un contexto con tokens ES,
        // o un segmento mayormente EN.
        $flagged = [];
        foreach ($segments as $i => $seg) {
            $text = (string) ($seg['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $score = $this->detector->scoreSegment($text);
            $enContent = $this->countEnContentWords($text);
            $totalEn = $score['en'] + $enContent;
            $isMix = $totalEn >= 1 && $score['es'] > 0;
            $isMostlyEn = $score['score'] >= 0.5;
            if ($isMix || $isMostlyEn) {
                $flagged[] = [
                    'index' => $i,
                    'text' => $text,
                    'score' => $score['score'],
                ];
            }
        }

        if (empty($flagged)) {
            return $segments;
        }

        // Tope por transcripción (control de costo/latencia).
        if (count($flagged) > $maxSegments) {
            $flagged = array_slice($flagged, 0, $maxSegments);
        }

        try {
            $corrected = $this->correctBatch($flagged);
        } catch (\Throwable $e) {
            Log::warning('TranscriptionCoherencePass: fallo del LLM, se conserva texto del diccionario', [
                'error' => $e->getMessage(),
                'flagged' => count($flagged),
            ]);
            return $segments;
        }

        // Aplicar correcciones por índice.
        foreach ($corrected as $idx => $newText) {
            if (isset($segments[$idx]) && is_string($newText) && $newText !== '') {
                $segments[$idx]['text'] = $newText;
            }
        }

        return $segments;
    }

    /**
     * Cuenta cuántas palabras de contenido en inglés (spanglish) hay en el texto.
     */
    private function countEnContentWords(string $text): int
    {
        $words = preg_split('/[\s\p{P}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return 0;
        }
        $set = array_flip(self::EN_CONTENT_WORDS);
        $count = 0;
        foreach ($words as $w) {
            if (isset($set[$w])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Envía los segmentos flagged al LLM en una sola llamada batch.
     *
     * @param  array<int, array{index: int, text: string, score: float}>  $flagged
     * @return array<int, string>  mapa index => texto corregido
     */
    private function correctBatch(array $flagged): array
    {
        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->buildUserPrompt($flagged);

        $raw = $this->callChatCompletion($systemPrompt, $userPrompt, true);

        // El trait devuelve el JSON decodificado, o ['raw'=>['text'=>...], 'unparsed'=>true].
        $candidates = [];
        if (isset($raw['unparsed']) && isset($raw['raw']['text'])) {
            $candidates = $this->extractCandidates((string) $raw['raw']['text']);
        } elseif (is_array($raw)) {
            $candidates = $raw;
        }

        $result = [];
        foreach ($candidates as $c) {
            $idx = (int) ($c['index'] ?? -1);
            $corrected = trim((string) ($c['corrected'] ?? ''));
            if ($idx >= 0 && $corrected !== '') {
                $result[$idx] = $corrected;
            }
        }

        return $result;
    }

    private function systemPrompt(): string
    {
        return <<<EOT
Eres un transcriptor profesional de español. Recibes segmentos de transcripción automática (ASR) que contienen mezcla de inglés y español (spanglish) o errores de transcripción. Tu tarea es corregir CADA segmento para que quede en español coherente y natural, listo para producción.

REGLAS:
1. Traduce al español cualquier palabra o frase en inglés que esté mezclada en un contexto claramente en español.
2. Corrige errores obvios de transcripción (palabras mal escritas, frases sin sentido).
3. NO cambies nombres propios, marcas, lugares, ni citas textuales en inglés que sean intencionales.
4. NO inventes contenido. Si un segmento es una canción en inglés, déjalo como está.
5. Mantén el tono y significado original. No agregues ni quites información.
6. Conserva la puntuación y estructura.

Responde SOLO con un JSON array de objetos, uno por segmento, en el mismo orden:
[{"index":0,"corrected":"texto corregido"},{"index":1,"corrected":"..."}]
EOT;
    }

    /**
     * @param  array<int, array{index: int, text: string, score: float}>  $flagged
     */
    private function buildUserPrompt(array $flagged): string
    {
        $out = "Corrige estos segmentos de transcripción al español:\n\n";
        foreach ($flagged as $f) {
            $out .= "[{$f['index']}] {$f['text']}\n";
        }
        return $out;
    }

    /**
     * Extrae candidatos de una respuesta en prosa (fallback cuando el modelo
     * no devuelve JSON estricto). Busca bloques JSON embebidos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCandidates(string $text): array
    {
        // Quitar fences de markdown ```json ... ```
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: buscar el primer array JSON en el texto.
        if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
