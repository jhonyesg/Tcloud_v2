<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\Keyword;
use App\Services\Concerns\CallsLlmChatCompletion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suggester LLM-powered de correcciones EN↔ES con contexto y
 * defensa-en-profundidad para no tocar marcas/nombres propios.
 *
 * Complementa al `EnEsMixMiner` rule-based: mientras el miner cubre
 * patrones estructurales conocidos (`in the world` → `en el mundo`),
 * este suggester detecta el long-tail contextual (palabras sueltas,
 * frases hechas) que la heurística no puede capturar.
 *
 * Pipeline:
 *   1. Muestra N segmentos de los últimos M días.
 *   2. Filtra los ya procesados hoy via cache (idempotencia).
 *   3. Construye prompt con system rules (exclusión de marcas).
 *   4. Llama al LLM (OpenAI-compatible via Kilo Gateway, default minimax-m3).
 *   5. Post-filtro PHP: descarta marcas/siglas/nombres propios
 *      que el LLM pudo haber dejado pasar.
 *   6. Retorna candidatos con `strategy='ai-suggest'` listos para
 *      que CorrectionService::aiSuggestEnEsMix() los inserte.
 */
class LlmCorrectionSuggester
{
    use CallsLlmChatCompletion;

    /**
     * System prompt versionado. Cambiar esto requiere bumpear
     * config('llm-correction.prompt_version') para auditoría.
     */
    public function getSystemPrompt(): string
    {
        $protectedList = $this->renderProtectedBrandsList();

        return <<<PROMPT
You are a bilingual EN↔ES correction analyst for a Spanish transcription system.

TASK: Detect English words or phrases that have been incorrectly inserted into
Spanish transcriptions. For each English insertion found, suggest a natural
Spanish replacement.

CRITICAL RULES — DO NOT VIOLATE:

1. NEVER propose changes to brand names, product names, or company names.
   These MUST stay as-is in their original language.
   Examples (non-exhaustive, this is the protected brands list):
{$protectedList}

2. NEVER propose changes to acronyms in all-caps (≥ 2 chars all caps).
   Examples: ONU, EE.UU., USA, API, JSON, SQL, HTTP, CC, CE, BPA, IVA, GPS.

3. NEVER propose changes to person names (first or last).

4. NEVER propose changes to single capitalized words that look like proper nouns.

5. For English technical terms already established in Spanish by use
   (e.g., screenshot → captura de pantalla), you MAY propose the Spanish
   equivalent IF it's clearly a typo/ASR artifact.

6. For English words alone in Spanish context ("the meeting", "schedule",
   "statement"), propose natural Spanish equivalents.

7. ONLY suggest changes when the English is clearly an ASR-inserted word
   in Spanish narration. DO NOT propose changes to legitimate bilingual
   Spanish-English speech (a speaker who is naturally code-switching).

OUTPUT FORMAT:
Return ONLY valid JSON, no prose around it. Schema:

{
  "candidates": [
    {
      "wrong": "exact English phrase as it appears in the segment",
      "correct": "natural Spanish replacement",
      "freq": 1,
      "reason": "short justification (max 80 chars)",
      "atomic_candidates": [
        {"wrong": "touristic attractives", "correct": "atractivos turísticos"}
      ]
    }
  ]
}

`atomic_candidates` es opcional: solo incluirlo si la regla principal tiene
>4 palabras y sus constituyentes son traducibles de forma estable. Si no hay
sub-reglas atómicas, omitir el campo o devolver array vacío.

If no candidates found, return: {"candidates": []}

Rules on duplicates:
- If the same `wrong` appears in multiple segments, INCREASE freq.
- Cap freq at the count of distinct segments where the wrong appears in
  this sample; do not hallucinate.

Rules on granularity:
- Prefer phrase-level over word-level when the phrase makes sense
  ("in the world" → "en el mundo", not just "in" → "en").
- Word-level is OK when no phrase context is needed.

ATOMICITY RULE (changes/2026-08-02-corrections-dictionary-atomicity):
- PREFERÍ siempre la versión más corta que capture la corrección. Si una
  palabra suelta es traducible de forma estable (aparece ≥3 veces en el
  corpus analizado), proponé ESA, no la frase entera.
- SOLO proponé una frase de >4 palabras si aparece ≥8 veces textuales en
  el corpus Y sus palabras constituyentes NO son traducibles individualmente.
- Para cada frase larga que SÍ propongas, listá también en `atomic_candidates`
  los bigramas/trigramas que la componen (campo nuevo del JSON, schema abajo).
- Penalizá frases con >8 palabras salvo que la frecuencia sea ≥15.
- NO propongas nunca frases con >12 palabras (descartadas por el filtro de longitud).

NEW OUTPUT FIELD:
Cada candidato principal puede llevar un campo opcional `atomic_candidates`
con un array de {wrong, correct} para bigramas/trigramas atómicos que la
componen. Ejemplo:
{
  "candidates": [
    {
      "wrong": "touristic attractives of the country",
      "correct": "atractivos turísticos del país",
      "freq": 12,
      "reason": "frase larga estable",
      "atomic_candidates": [
        {"wrong": "touristic attractives", "correct": "atractivos turísticos"},
        {"wrong": "of the country", "correct": "del país"}
      ]
    }
  ]
}

Be conservative. High precision over recall. Better to miss a pattern
than to propose false-positive corrections (especially on brand names).
PROMPT;
    }

    /**
     * Toma una muestra aleatoria de segmentos del corpus reciente.
     *
     * @return array<int, array{id:int, text:string}>
     */
    public function sampleSegments(int $days, int $sampleSize): array
    {
        $since = now()->subDays(max(1, $days));
        return DB::table('transcription_segments')
            ->where('created_at', '>=', $since)
            ->whereNotNull('text_raw')
            ->where('text_raw', '!=', '')
            ->inRandomOrder()
            ->limit($sampleSize)
            ->get(['id', 'text_raw'])
            ->map(fn($r) => ['id' => (int) $r->id, 'text' => (string) $r->text_raw])
            ->all();
    }

    public function alreadyProcessedToday(int $segmentId): bool
    {
        return Cache::has($this->cacheKey($segmentId));
    }

    public function markProcessedToday(int $segmentId): void
    {
        Cache::put($this->cacheKey($segmentId), true, now()->addHours(25));
    }

    private function cacheKey(int $segmentId): string
    {
        return 'ai_suggest:processed:' . $segmentId . ':' . now()->toDateString();
    }

    /**
     * Construye el user prompt con los segmentos a revisar.
     *
     * @param array<int, array{id:int, text:string}> $segments
     */
    public function buildUserPrompt(array $segments, int $days): string
    {
        $lines = [];
        $lines[] = "Below are " . count($segments) . " transcription segments from a Spanish radio/news broadcast recorded in the last {$days} day(s).";
        $lines[] = "Find English intrusions in Spanish narration. Apply the rules above strictly. Return ONLY the JSON.";
        $lines[] = "";
        $lines[] = "Segments (one per line, format: `<n>: <text>`):";

        foreach ($segments as $i => $segment) {
            // Truncar segmentos absurdamente largos para no saturar el prompt.
            $text = mb_substr($segment['text'], 0, 800);
            $lines[] = "{$i}: {$text}";
        }

        return implode("\n", $lines);
    }

    /**
     * Punto de entrada principal.
     *
     * Cambios/2026-08-02-corrections-dictionary-atomicity: el suggest ahora
     * filtra por longitud atómica, reporta rejected_by_length y
     * promoted_to_atomic, y extrae los atomic_candidates que el LLM devuelva.
     *
     * @return array{
     *   candidates: array<int, array{wrong:string, correct:string, freq:int, reason:string, strategy:string, confidence:string}>,
     *   atomic_candidates: array<int, array{wrong:string, correct:string, parent_wrong:string}>,
     *   rejected_by_filter: array<int, array{wrong:string, reason:string}>,
     *   rejected_by_length: array<int, array{wrong:string, reason:string, word_count:int}>,
     *   segments_processed: int,
     *   cached_today: int,
     *   source: string
     * }
     */
    public function suggest(int $days, int $sampleSize): array
    {
        $source = 'ai-suggest-' . now()->toDateString();
        $segments = $this->sampleSegments($days, $sampleSize);

        $unprocessed = [];
        $cachedToday = 0;
        foreach ($segments as $s) {
            if ($this->alreadyProcessedToday($s['id'])) {
                $cachedToday++;
                continue;
            }
            $unprocessed[] = $s;
        }

        if (empty($unprocessed)) {
            return [
                'candidates' => [],
                'atomic_candidates' => [],
                'rejected_by_filter' => [],
                'rejected_by_length' => [],
                'segments_processed' => 0,
                'cached_today' => $cachedToday,
                'source' => $source,
            ];
        }

        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->buildUserPrompt($unprocessed, $days);

        try {
            $raw = $this->callChatCompletion($systemPrompt, $userPrompt, true);
        } catch (\Throwable $e) {
            Log::warning('AI suggest failed: ' . $e->getMessage());
            return [
                'candidates' => [],
                'atomic_candidates' => [],
                'rejected_by_filter' => [],
                'rejected_by_length' => [],
                'segments_processed' => 0,
                'cached_today' => $cachedToday,
                'source' => $source,
                'error' => $e->getMessage(),
            ];
        }

        // Parsing robusto: el modelo puede retornar JSON estricto
        // o prosa con JSON embebido (modelos Ollama/local suelen hacer esto).
        $llmCandidates = [];
        if (isset($raw['unparsed']) && isset($raw['raw']['text'])) {
            $llmCandidates = $this->extractCandidatesFromText((string) $raw['raw']['text']);
        } elseif (isset($raw['candidates']) && is_array($raw['candidates'])) {
            $llmCandidates = $raw['candidates'];
        }

        // Marcar como procesados hoy (cache hit para próximas corridas).
        foreach ($unprocessed as $s) {
            $this->markProcessedToday($s['id']);
        }

        $accepted = [];
        $rejectedByFilter = [];
        $rejectedByLength = [];
        $atomicExtracted = [];
        foreach ($llmCandidates as $candidate) {
            $wrong = trim((string) ($candidate['wrong'] ?? ''));
            $correct = trim((string) ($candidate['correct'] ?? ''));
            if ($wrong === '' || $correct === '') {
                continue;
            }

            $freq = max(1, (int) ($candidate['freq'] ?? 1));

            // Filtro de longitud atómica (changes/2026-08-02).
            $wordCount = str_word_count($wrong, 0, 'áéíóúñüÁÉÍÓÚÑÜ');
            $lengthRejected = false;
            if ($wordCount > 12) {
                $rejectedByLength[] = ['wrong' => $wrong, 'reason' => 'too_long', 'word_count' => $wordCount];
                $lengthRejected = true;
            } elseif ($wordCount > 8 && $freq < 15) {
                $rejectedByLength[] = ['wrong' => $wrong, 'reason' => 'too_long_low_freq', 'word_count' => $wordCount];
                $lengthRejected = true;
            } elseif ($wordCount > 6 && $freq < 8) {
                $rejectedByLength[] = ['wrong' => $wrong, 'reason' => 'too_long_low_freq', 'word_count' => $wordCount];
                $lengthRejected = true;
            }
            if ($lengthRejected) {
                continue;
            }

            if ($this->looksLikeBrandOrProperNoun($wrong, $freq)) {
                $rejectedByFilter[] = ['wrong' => $wrong, 'reason' => 'looksLikeBrandOrProperNoun'];
                continue;
            }

            // Rechazo de traducciones EN->ES (2026-08-11).
            //
            // La defensa anterior era una DENYLIST — `protected_brands` en config
            // más `correction_protected_terms` en BD — que enumeraba qué NO
            // traducir. Es parcheo uno a uno por diseño: llegó a tener 5 términos
            // ('burner boy', 'jimmy', 'x-ray doll') mientras entraban 2.465 reglas
            // de traducción por la puerta de al lado.
            //
            // Aquí se invierte: se rechaza la CATEGORÍA. El diccionario corrige
            // español (tildes, typos del ASR); traducir es trabajo del ASR, que
            // es donde está la causa real de que llegue texto en inglés.
            $bucket = app(EnEsRuleClassifier::class)->classify($wrong, $correct)['bucket'];

            if ($bucket === EnEsRuleClassifier::NOISE) {
                $rejectedByFilter[] = ['wrong' => $wrong, 'reason' => 'no_op'];
                continue;
            }

            if ($bucket === EnEsRuleClassifier::QUARANTINE) {
                $rejectedByFilter[] = ['wrong' => $wrong, 'reason' => 'traduccion_en_es'];
                continue;
            }

            // Mínimo de palabras (2026-08-13). Sin excepciones: por debajo del
            // umbral la regla dispara en todo el corpus sin mirar el contexto y
            // el resultado es espanglish. Si el admin quiere una corrección
            // corta concreta, la da de alta a mano, que no pasa por aquí.
            $minWords = (int) config('corrections.min_suggestion_words', 3);

            if ($wordCount < $minWords) {
                $rejectedByFilter[] = ['wrong' => $wrong, 'reason' => 'frase_demasiado_corta'];
                continue;
            }

            // Red de seguridad por si se baja el umbral en config: un reemplazo
            // de una o dos palabras solo vale si es un arreglo ortográfico puro,
            // que no cambia el significado en ninguna aparición.
            if ($wordCount <= 2 && !$this->isSpellingFix($wrong, $correct)) {
                $rejectedByFilter[] = ['wrong' => $wrong, 'reason' => 'palabra_suelta_sin_contexto'];
                continue;
            }

            // Idempotencia contra approved: el rule-based miner o admin
            // ya las cubren.
            if ($this->isApproved($wrong)) {
                $rejectedByFilter[] = ['wrong' => $wrong, 'reason' => 'already_approved'];
                continue;
            }

            $accepted[] = [
                'wrong' => $wrong,
                'correct' => $correct,
                'freq' => $freq,
                'reason' => mb_substr((string) ($candidate['reason'] ?? ''), 0, 200),
                'strategy' => 'ai-suggest',
                'confidence' => 'normal',
            ];

            // Extraer atomic_candidates del LLM (si están) — se insertan como
            // correcciones standalone adicionales con source tagged.
            foreach ((array) ($candidate['atomic_candidates'] ?? []) as $atomic) {
                $atomicWrong = trim((string) ($atomic['wrong'] ?? ''));
                $atomicCorrect = trim((string) ($atomic['correct'] ?? ''));
                if ($atomicWrong === '' || $atomicCorrect === '') {
                    continue;
                }
                if ($this->looksLikeBrandOrProperNoun($atomicWrong)) {
                    continue;
                }
                if ($this->isApproved($atomicWrong)) {
                    continue;
                }
                $atomicExtracted[] = [
                    'wrong' => $atomicWrong,
                    'correct' => $atomicCorrect,
                    'parent_wrong' => $wrong,
                ];
            }
        }

        return [
            'candidates' => $accepted,
            'atomic_candidates' => $atomicExtracted,
            'rejected_by_filter' => $rejectedByFilter,
            'rejected_by_length' => $rejectedByLength,
            'segments_processed' => count($unprocessed),
            'cached_today' => $cachedToday,
            'source' => $source,
        ];
    }

    /**
     * Post-filtro defensivo: detecta candidatos cuyo `wrong` parece
     * una marca, sigla todo-mayúsculas, o nombre propio.
     *
     * Esta es la segunda barrera (la primera es el system prompt).
     * Aunque el LLM debería respetar las reglas, este filtro es la red
     * de seguridad por si el modelo se equivoca.
     *
     * Cambios/2026-08-02-corrections-dictionary-atomicity: también
     * filtra por longitud para descartar frases demasiado largas que
     * tienen baja probabilidad de ser atómicas (rejected_by_length).
     */
    /**
     * ¿El par es un arreglo ORTOGRÁFICO y no un cambio de palabra?
     *
     * Un arreglo ortográfico conserva el esqueleto de la palabra: quitando
     * diacríticos y normalizando, wrong y correct son casi el mismo string
     * (`quando`->`cuando`, `mas`->`más`, `difficultades`->`dificultades`).
     * Un cambio de palabra no (`top`->`cima`, `hours`->`horas`, `Good`->`Bien`).
     *
     * El umbral es deliberadamente alto (85%) porque en 1-2 tokens el coste de
     * un falso negativo es que un typo raro se quede sin regla, mientras que el
     * de un falso positivo es degradar todo el corpus.
     */
    private function isSpellingFix(string $wrong, string $correct): bool
    {
        $a = Keyword::asciiLower(trim($wrong));
        $b = Keyword::asciiLower(trim($correct));

        if ($a === '' || $b === '') {
            return false;
        }

        // Antes esto devolvía true: un par que no cambia nada se daba por
        // "arreglo ortográfico" y entraba al diccionario. De ahí salieron las
        // reglas inertes tipo `Powerball`->`Powerball` o `piedra`->`piedra`.
        if (trim($wrong) === trim($correct)) {
            return false;
        }

        similar_text($a, $b, $percent);

        if ($percent < 85.0) {
            return false;
        }

        // El umbral de similitud descarta lo evidente (`top`->`cima`,
        // `sound`->`sonido`) pero deja pasar `presidenta`->`presidente` con un
        // 90 %. Por encima del umbral hay que encajar además en un patrón
        // ortográfico concreto, o no es un arreglo de ortografía.
        return app(EnEsRuleClassifier::class)->isOrthographicVariant($wrong, $correct);
    }

    public function looksLikeBrandOrProperNoun(string $wrong, ?int $freq = null): bool
    {
        $wrongTrim = trim($wrong);
        if ($wrongTrim === '') {
            return true;
        }

        // 1. Sigla todo mayúsculas sostenida (≥ 2 chars).
        if (preg_match('/^[A-Z][A-Z\.]+$/', $wrongTrim) && strlen($wrongTrim) >= 2) {
            return true;
        }
        // Variante "EE. UU." (sigla con puntos).
        if (preg_match('/^(\b[A-Z]{1,3}\.?\s*){2,}$/', $wrongTrim)) {
            return true;
        }

        // 2. Marca de la lista protegida estática + exclusiones dinámicas
        //    del admin (corrections-protected-terms-admin). El cache de 5 min
        //    del service evita el hit a la DB por cada candidato.
        $protectedBrands = config('llm-correction.protected_brands', []);
        $dynamicExcluded = [];
        try {
            /** @var \App\Services\Ia\CorrectionProtectedTermsService $svc */
            $svc = app(\App\Services\Ia\CorrectionProtectedTermsService::class);
            $dynamicExcluded = $svc->terms();
        } catch (\Throwable $e) {
            // Si la tabla no existe o la DB está caída, caemos en solo
            // protected_brands (defensa en profundidad).
            Log::warning('CorrectionProtectedTermsService no disponible', ['error' => $e->getMessage()]);
        }
        $combined = array_values(array_unique(array_filter(array_merge(
            is_array($protectedBrands) ? $protectedBrands : [],
            $dynamicExcluded
        ))));
        if (!empty($combined)) {
            $wrongLower = mb_strtolower($wrongTrim);
            foreach ($combined as $brand) {
                $brandLower = mb_strtolower(trim((string) $brand));
                if ($brandLower === '') {
                    continue;
                }
                // Match exacto.
                if ($wrongLower === $brandLower) {
                    return true;
                }
                // Match como sub-frase en frases cortas (< 50 chars).
                if (strlen($wrongLower) <= 50 && str_contains($wrongLower, $brandLower)) {
                    return true;
                }
            }
        }

        // 3. Single-word con capitalización interna (probable marca):
        //    "iPhone", "MacBook", "PowerPoint", "WordEnterprise".
        //    Una sola palabra (sin espacios) con letras mayúsculas después
        //    de la primera es muy probable marca.
        if (!str_contains($wrongTrim, ' ')) {
            if (preg_match('/^[A-Z][a-z]+[A-Z]/', $wrongTrim)) {
                return true;
            }
        }

        // 4. Palabra capitalized al INICIO de la frase cuando la frase
        //    es corta (≤ 3 palabras) — probable nombre propio / marca.
        if (strlen($wrongTrim) <= 30) {
            $words = preg_split('/\s+/', $wrongTrim);
            if (count($words) <= 3 && preg_match('/^[A-Z]/', $words[0])) {
                // ¿Algún otro word está en lowercase? Si los demás están en
                // lowercase, podría ser "The meeting" (la palabra capitalized
                // es solo el inicio de la oración). Pero para frases cortas
                // con la primera en mayúscula, asumimos nombre propio conservador.
                $restLower = true;
                foreach (array_slice($words, 1) as $w) {
                    if (preg_match('/^[A-Z]/', $w)) {
                        $restLower = false;
                        break;
                    }
                }
                if ($restLower && count($words) >= 2) {
                    return true;
                }
            }
        }

        // 5. Filtro de longitud atómica (changes/2026-08-02):
        //    - > 12 palabras: siempre fuera (demasiado inflada).
        //    - > 8 palabras y freq < 15: fuera (poco útil salvo confirmación muy alta).
        //    - > 6 palabras y freq < 8: fuera (frase rara y larga → overfit del LLM).
        $wordCount = str_word_count($wrongTrim, 0, 'áéíóúñüÁÉÍÓÚÑÜ');
        if ($wordCount > 12) {
            return true;
        }
        if ($wordCount > 8 && ($freq ?? 0) < 15) {
            return true;
        }
        if ($wordCount > 6 && ($freq ?? 0) < 8) {
            return true;
        }

        return false;
    }

    /**
     * Ya existe una corrección approved con ese wrong_normalized?
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
     * Devuelve `null` si el texto no aparece. El caller debe tolerar `null`.
     *
     * Performance: una query por candidato. El suggester ya hace N queries
     * al LLM y BD; +1 SELECT por candidato es marginal.
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

    /**
     * Fallback de extracción cuando el LLM devuelve prosa en lugar de JSON
     * estricto. Patrones observados en modelos Ollama/local/Mistral
     * (a través de gateways proxy como OllamaCloud):
     *
     *   - JSON envuelto en backticks markdown: `` ```json {...} ``` ``
     *   - JSON precedido por texto explicativo:  `Aquí está la lista: {...}`
     *   - JSON con líneas de razonamiento mezcladas
     *
     * Estrategia: extraer el PRIMER objeto JSON válido mediante regex
     * recursivo, intentando de dentro hacia afuera. Si nada parsea,
     * retornar lista vacía (el caller verá el toast "0 candidatos" pero
     * no fallará hard).
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCandidatesFromText(string $text): array
    {
        // Limpiar fences markdown de código (```json ... ```).
        $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $text);

        // Buscar el primer objeto JSON balanceado (greedy desde el primer {).
        // Estrategia robusta: encontrar candidatos de substring { ... } y
        // parsear el más largo que pase json_decode.
        $bestCandidates = [];
        $bestLen = 0;

        preg_match_all('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $clean, $matches);
        if (!empty($matches[0])) {
            foreach ($matches[0] as $candidate) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded) && isset($decoded['candidates']) && is_array($decoded['candidates'])) {
                    if (strlen($candidate) > $bestLen) {
                        $bestLen = strlen($candidate);
                        $bestCandidates = $decoded['candidates'];
                    }
                }
            }
        }

        // Si ningún bloque {...} funcionó, intentar decodificar el body entero.
        if (empty($bestCandidates)) {
            $decoded = json_decode(trim($clean), true);
            if (is_array($decoded) && isset($decoded['candidates']) && is_array($decoded['candidates'])) {
                return $decoded['candidates'];
            }
        }

        return $bestCandidates;
    }

    /**
     * Renderiza la lista combinada de marcas protegidas + exclusiones dinámicas
     * para inyectar en el prompt del LLM. La lista dinámica viene del
     * `CorrectionProtectedTermsService` (cache 5min) y refleja lo que el admin
     * configuró desde `/ia/correcciones → IA Suggest → Exclusiones`.
     */
    private function renderProtectedBrandsList(): string
    {
        $brands = config('llm-correction.protected_brands', []);
        if (!is_array($brands)) {
            $brands = [];
        }
        try {
            /** @var \App\Services\Ia\CorrectionProtectedTermsService $svc */
            $svc = app(\App\Services\Ia\CorrectionProtectedTermsService::class);
            $brands = array_values(array_unique(array_merge($brands, $svc->terms())));
        } catch (\Throwable $e) {
            // Si el service falla (DB caída, tabla faltante), mantenemos la
            // lista estática como defensa en profundidad.
            Log::warning('CorrectionProtectedTermsService no disponible para prompt', ['error' => $e->getMessage()]);
        }

        if (empty($brands)) {
            return '  (none configured)';
        }
        $lines = [];
        foreach ($brands as $brand) {
            $lines[] = '  - ' . $brand;
        }
        return implode("\n", $lines);
    }
}
