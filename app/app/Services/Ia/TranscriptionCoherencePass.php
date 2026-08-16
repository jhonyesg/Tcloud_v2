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
        private CorrectionService $corrections,
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
        $learnedPairs = [];
        foreach ($corrected as $idx => $newText) {
            if (isset($segments[$idx]) && is_string($newText) && $newText !== '') {
                $before = (string) ($segments[$idx]['text'] ?? '');
                $segments[$idx]['text'] = $newText;

                // Aprendizaje: extraer pares wrong→correct del diff entre el
                // texto del diccionario (before) y el corregido por IA (newText).
                // (changes/2026-08-15-corrections-learn-from-ai-pass)
                if ($before !== $newText) {
                    $learnedPairs[] = [
                        'wrong' => $before,
                        'correct' => $newText,
                        'segment_id' => $segments[$idx]['id'] ?? null,
                    ];
                }
            }
        }

        // Proponer los pares aprendidos como correcciones pending (revisión humana).
        $this->learnFromCorrections($learnedPairs);

        return $segments;
    }

    /**
     * Propone los pares aprendidos del pase IA como correcciones pending.
     *
     * (changes/2026-08-15-corrections-learn-from-ai-pass) Cada corrección IA es
     * conocimiento que alimenta el diccionario: el admin la aprueba y la primera
     * pasada (diccionario) captura cada vez más, reduciendo la carga de IA.
     *
     * @param  array<int, array{wrong: string, correct: string, segment_id: ?int}>  $pairs
     */
    private function learnFromCorrections(array $pairs): void
    {
        if (empty($pairs)) {
            return;
        }

        $maxLearn = $this->settings->int('ai_coherence_max_learn');
        $proposed = 0;

        foreach ($pairs as $pair) {
            if ($proposed >= $maxLearn) {
                break;
            }

            $wrong = trim((string) $pair['wrong']);
            $correct = trim((string) $pair['correct']);
            if ($wrong === '' || $correct === '' || $wrong === $correct) {
                continue;
            }

            // Solo aprender frases cortas (1-4 palabras), no segmentos enteros.
            if (str_word_count($wrong, 0, 'áéíóúñüÁÉÍÓÚÑÜ') > 4) {
                continue;
            }

            try {
                $created = $this->corrections->proposeLearned($wrong, $correct, $pair['segment_id']);
                if ($created !== null) {
                    $proposed++;
                }
            } catch (\Throwable $e) {
                Log::warning('TranscriptionCoherencePass: error proponiendo par aprendido', [
                    'error' => $e->getMessage(),
                    'wrong' => $wrong,
                ]);
            }
        }

        if ($proposed > 0) {
            Log::info('TranscriptionCoherencePass: pares aprendidos propuestos', [
                'proposed' => $proposed,
            ]);
        }
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
     * Envía los segmentos flagged al LLM en sub-batches pequeños.
     *
     * (2026-08-16) Un batch de 20 segmentos con max_tokens=10000 tardaba >60s
     * y el LLM devolvía timeout (cURL error 28), dejando el spanglish sin
     * corregir. Se divide en sub-batches de `ai_coherence_batch_size` (default 5)
     * para que cada llamada sea rápida y no exceda el timeout.
     *
     * @param  array<int, array{index: int, text: string, score: float}>  $flagged
     * @return array<int, string>  mapa index => texto corregido
     */
    private function correctBatch(array $flagged): array
    {
        $batchSize = max(1, $this->settings->int('ai_coherence_batch_size'));
        $result = [];

        foreach (array_chunk($flagged, $batchSize) as $chunk) {
            $systemPrompt = $this->systemPrompt();
            $userPrompt = $this->buildUserPrompt($chunk);

            // Reintento con backoff ante rate limit (HTTP 429) del gateway.
            // (2026-08-16) El gateway de Kilo limita la tasa de la API key;
            // con varios procesos paralelos se satura. Reintentar con espera
            // progresiva evita perder el chunk y respeta el rate limit.
            $raw = $this->callWithRetry($systemPrompt, $userPrompt);

            // El trait devuelve el JSON decodificado, o ['raw'=>['text'=>...], 'unparsed'=>true].
            $candidates = [];
            if (isset($raw['unparsed']) && isset($raw['raw']['text'])) {
                $candidates = $this->extractCandidates((string) $raw['raw']['text']);
            } elseif (is_array($raw)) {
                $candidates = $raw;
            }

            foreach ($candidates as $c) {
                $idx = (int) ($c['index'] ?? -1);
                $corrected = trim((string) ($c['corrected'] ?? ''));
                if ($idx >= 0 && $corrected !== '') {
                    $result[$idx] = $corrected;
                }
            }
        }

        return $result;
    }

    /**
     * Llama al LLM con reintento ante rate limit (HTTP 429) y fallback entre
     * proveedores (Kilo, Ollama Cloud, MiniMax) si están habilitados.
     *
     * (2026-08-16) El gateway de Kilo limita la tasa (HTTP 429). Con Ollama
     * Cloud y MiniMax como proveedores secundarios/terciarios, se alterna
     * round-robin para triplicar el throughput y evitar el rate limit.
     *
     * @return array<string, mixed>
     */
    private function callWithRetry(string $systemPrompt, string $userPrompt): array
    {
        $secondaryEnabled = $this->llmSettings->bool('secondary_enabled');
        $tertiaryEnabled = $this->llmSettings->bool('tertiary_enabled');
        $maxRetries = 3;
        $delay = 5; // segundos iniciales

        // Lista de proveedores en orden round-robin.
        $providers = ['primary'];
        if ($secondaryEnabled) {
            $providers[] = 'secondary';
        }
        if ($tertiaryEnabled) {
            $providers[] = 'tertiary';
        }

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            // Alternar proveedor round-robin.
            $provider = $providers[$attempt % count($providers)];

            try {
                return $this->callChatCompletion($systemPrompt, $userPrompt, true, $provider);
            } catch (\Throwable $e) {
                $isRateLimit = str_contains($e->getMessage(), '429')
                    || str_contains($e->getMessage(), 'rate limit')
                    || str_contains($e->getMessage(), 'too many requests');

                if (!$isRateLimit || $attempt >= $maxRetries) {
                    throw $e;
                }

                Log::warning("TranscriptionCoherencePass: rate limit en {$provider}, reintento en {$delay}s (intento " . ($attempt + 1) . ")");
                sleep($delay);
                $delay *= 2; // backoff exponencial
            }
        }

        throw new \RuntimeException('LLM rate limit persistente');
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
     * (2026-08-16) MiniMax devuelve razonamiento en prosa ANTES del JSON
     * (ej. "[0] ... [1] ..." seguido de ```json [...]```). El regex del primer
     * array capturaba el `[0]` del razonamiento, no el JSON final. Ahora se
     * prioriza el bloque ```json``` y, si no, el ÚLTIMO array JSON.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCandidates(string $text): array
    {
        // 1. Priorizar bloque ```json ... ``` (markdown fence).
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 2. Intentar parsear el texto completo (JSON estricto).
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 3. Fallback: buscar el ÚLTIMO array JSON en el texto (no el primero,
        //    porque el razonamiento en prosa puede contener "[0]").
        if (preg_match_all('/\[[\s\S]*?\]/', $text, $matches)) {
            // Probar de atrás hacia adelante: el JSON válido suele ser el último.
            foreach (array_reverse($matches[0]) as $candidate) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }
}
