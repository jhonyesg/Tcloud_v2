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
     * (changes/2026-08-15-corrections-learn-from-ai-pass + 2026-08-18) Cada
     * corrección IA es conocimiento que alimenta el diccionario: el admin la
     * aprueba y la primera pasada (diccionario) captura cada vez más,
     * reduciendo la carga de IA.
     *
     * Bug fixed el 2026-08-18: antes el `wrong` y `correct` eran los segmentos
     * enteros (de ahí las 6.035 reglas de 4-6 palabras inflando la cola).
     * Ahora extraemos solo el fragmento mínimo que cambió usando common-prefix/
     * suffix trim + split por cláusulas, y descartamos cláusulas >4 palabras.
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
        $discardedBySize = 0;
        $discardedByClassifier = 0;
        $discardedByBrand = 0;

        foreach ($pairs as $pair) {
            if ($proposed >= $maxLearn) {
                break;
            }

            $wrongFull = trim((string) $pair['wrong']);
            $correctFull = trim((string) $pair['correct']);
            if ($wrongFull === '' || $correctFull === '' || $wrongFull === $correctFull) {
                continue;
            }

            // Extraer el fragmento mínimo que cambió (common-prefix/suffix trim).
            $clausePairs = $this->extractClausePairs($wrongFull, $correctFull);
            if (empty($clausePairs)) {
                continue;
            }

            foreach ($clausePairs as $cp) {
                if ($proposed >= $maxLearn) {
                    break;
                }

                $wrong = $cp['wrong'];
                $correct = $cp['correct'];
                if ($wrong === '' || $correct === '' || $wrong === $correct) {
                    continue;
                }

                // Filtro de longitud: solo emitir pares con 5+ palabras.
                // Política consistente con el triage (cambios 2026-08-18):
                // las reglas de 1-4 palabras son find/replace demasiado
                // genérico, no preservan contexto (lesson del 2026-08-15-en-es-mix-miner-prune-open-strategy:
                // 2.465 reglas palabra-por-palabra auto-aprobadas, 205k
                // aplicaciones dañinas). Solo 5+ palabras tienen suficiente
                // contexto para preservar tono/intención. Aplicado en el
                // extractor (no solo en el triage) para NO GENERAR el ruido
                // que después tendríamos que descartar.
                if ($this->wordCount($wrong) < 5) {
                    $discardedBySize++;
                    continue;
                }

                // Filtro de marca/nombre propio (ya presente en
                // proposeLearned, pero duplicado aquí para tener métricas
                // de descarte en el log).
                if (app(\App\Services\Ia\LlmCorrectionSuggester::class)->looksLikeBrandOrProperNoun($wrong)) {
                    $discardedByBrand++;
                    Log::info('par descartado por brand/proper noun', [
                        'wrong' => $wrong,
                        'correct' => $correct,
                    ]);
                    continue;
                }

                try {
                    $created = $this->corrections->proposeLearned($wrong, $correct, null);
                    if ($created !== null) {
                        $proposed++;
                    } else {
                        // proposeLearned retorna null cuando EnEsRuleClassifier
                        // marca NOISE/QUARANTINE o ya existe el par.
                        $discardedByClassifier++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('TranscriptionCoherencePass: error proponiendo par aprendido', [
                        'error' => $e->getMessage(),
                        'wrong' => $wrong,
                    ]);
                }
            }
        }

        if ($proposed > 0 || $discardedBySize > 0 || $discardedByClassifier > 0 || $discardedByBrand > 0) {
            Log::info('TranscriptionCoherencePass: pares aprendidos', [
                'proposed' => $proposed,
                'discarded_by_size' => $discardedBySize,
                'discarded_by_classifier' => $discardedByClassifier,
                'discarded_by_brand' => $discardedByBrand,
            ]);
        }
    }

    /**
     * Extrae los pares wrong→correct a nivel de palabra.
     *
     * Cambio 2026-08-18: el extractor anterior emitía `wrong=$before; correct=$newText`
     * (segmento entero), generando reglas de 4-6 palabras que llenaban la cola
     * pending con traducciones literales. Esta implementación hace diff palabra
     * a palabra (alineamiento posicional 1:1) y emite pares solo donde difieren.
     *
     * Asume que la IA produce cambios 1:1 a nivel de palabra (sustituciones,
     * no reordenamientos significativos), lo cual es el patrón observado en
     * el pase de coherencia: corrige palabra EN por su equivalente ES en la
     * misma posición.
     *
     * Bugs evitados vs la versión char-level:
     *  - "motors" vs "motores": el char-level strip considera 's' como sufijo
     *    común y tritura demasiado. A nivel de palabra son tokens distintos
     *    que deben producir un par.
     *  - Prefijos como "The"/"Las" que comparten 0 chars pero son al inicio.
     *
     * @return array<int, array{wrong: string, correct: string}>
     */
    private function extractClausePairs(string $before, string $after): array
    {
        // Tokeniza por palabra; preserva puntuación final adjunta al token
        // (ej. "motors." → ["motors", "."]) para poder emparejar las pos.
        $tokenize = function (string $text): array {
            $parts = preg_split('/(\s+|[.;:!?])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            return is_array($parts) ? $parts : [];
        };

        $bTokens = $tokenize($before);
        $aTokens = $tokenize($after);

        if (empty($bTokens) || empty($aTokens)) {
            return [];
        }

        // Emparejar posiciones 1:1. Si las longitudes difieren (inserción o
        // borrado por la IA), los tokens faltantes se ignoran — un find/replace
        // sin match completo no sirve como regla del diccionario.
        $n = min(count($bTokens), count($aTokens));
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            $bw = (string) $bTokens[$i];
            $aw = (string) $aTokens[$i];

            // Saltar delimitadores (espacios, puntuación) — solo comparar
            // palabras o frases que estén en la misma posición textual.
            if (trim($bw) === '' || trim($aw) === '') {
                continue;
            }

            // Si coinciden, es parte estable del segmento (no es un cambio).
            if ($bw === $aw) {
                continue;
            }

            // Si bw es puntuación (un punto/coma), es ruido de tokenización;
            // saltarlo para no inventar pares como "."→"X".
            if (preg_match('/^[.;:!?]+$/u', $bw)) {
                continue;
            }

            $pairs[] = ['wrong' => $bw, 'correct' => $aw];
        }

        return $pairs;
    }

    /**
     * Cuenta palabras sobre Unicode español con strip de puntuación. Reemplaza
     * `str_word_count($wrong, 0, 'áéíóúñü...')` que se evadía con tildes.
     */
    private function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return 0;
        }
        $count = 0;
        foreach ($words as $w) {
            // descartar tokens puramente puntuación
            if (preg_match('/[\p{L}\p{N}]/u', $w)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Hidrata `source_segment_id` para las correcciones recién emitidas por
     * `learnFromCorrections()`. Se ejecuta DESPUÉS del INSERT de
     * transcription_segments (la hidratación en apply() es estructuralmente
     * imposible porque los segmentos no tienen id de BD todavía).
     *
     * Cambio 2026-08-18 (decisión 6 revisada): un único UPDATE-JOIN resuelve
     * cada `wrong_text` contra `position(c.wrong_text in ts.text_raw)`. La
     * cobertura es per-transcripción y filtra por created_at reciente para
     * no tocar correcciones anteriores.
     */
    public function hydrateCoherenceLearnedSourceSegments(int $transcriptionId): int
    {
        $hydrated = \Illuminate\Support\Facades\DB::statement(
            "UPDATE corrections c
             SET source_segment_id = ts.id
             FROM transcription_segments ts
             WHERE c.source = 'ai-coherence-learn'
               AND c.source_segment_id IS NULL
               AND c.status = 'pending'
               AND c.created_at > now() - interval '5 minutes'
               AND ts.transcription_id = ?
               AND position(c.wrong_text in ts.text_raw) > 0",
            [$transcriptionId]
        );

        $count = \Illuminate\Support\Facades\DB::table('corrections')
            ->where('source', 'ai-coherence-learn')
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinutes(5))
            ->whereNotNull('source_segment_id')
            ->count();

        if ($count > 0) {
            Log::info('TranscriptionCoherencePass: hydrated source_segment_id', [
                'transcription_id' => $transcriptionId,
                'hydrated' => $count,
            ]);
        }

        return $count;
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
        $quaternaryEnabled = $this->llmSettings->bool('quaternary_enabled');
        $maxRetries = 3;
        $delay = 5; // segundos iniciales

        // Lista de proveedores en orden round-robin.
        // Kilo (primary) tiene un rate limit mucho más bajo que los otros
        // proveedores. Si `primary_enabled` es false, se omite y solo se usan
        // los proveedores rápidos (Ollama, MiniMax, OpenCode).
        $providers = [];
        if ($this->llmSettings->bool('primary_enabled')) {
            $providers[] = 'primary';
        }
        if ($secondaryEnabled) {
            $providers[] = 'secondary';
            $providers[] = 'secondary';
        }
        if ($tertiaryEnabled) {
            $providers[] = 'tertiary';
            $providers[] = 'tertiary';
        }
        if ($quaternaryEnabled) {
            $providers[] = 'quaternary';
            $providers[] = 'quaternary';
        }

        if (empty($providers)) {
            throw new \RuntimeException('No hay proveedores LLM habilitados.');
        }

        // Offset aleatorio para el proveedor inicial: con varios procesos
        // paralelos, si todos empiezan en 'primary' saturan su rate limit.
        // Rotar el inicio distribuye la carga inicial entre todos los proveedores.
        $offset = random_int(0, count($providers) - 1);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            // Alternar proveedor round-robin con offset aleatorio.
            $provider = $providers[($offset + $attempt) % count($providers)];

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
