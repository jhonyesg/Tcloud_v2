<?php

namespace App\Services\Ia;

use App\Models\Correction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Deep module: corrección IA de un ejemplo individual del modal de Contexto,
 * con ventana de vecinos ±N.
 *
 * (change: corrections-ai-context-aware-with-mark-curation) Una sola entrada
 * pública: `correctExample()`. Toda la complejidad (vecinos, prompt, gate,
 * cache, post-filtro, llamada LLM, mapeo de errores, persistencia, telemetría)
 * queda oculta tras la interfaz, siguiendo la skill codebase-design.
 *
 * Los vecinos ±5 llegan al prompt como snippet etiquetado para que el LLM
 * reconstruya el segmento en español sin inventar. El admin persiste
 * "segmento completo → traducción" como regla `wrong → correct`.
 *
 * Cache: ai_context_aware:{correction_id}:{example_id}:{neighbor_window}:{YYYY-MM-DD}
 * TTL configurable (config('corrections.ai_context_aware.cache_ttl', 86400)).
 */
class AiContextAwareService
{
    use \App\Services\Concerns\CallsLlmChatCompletion;

    private const DEFAULT_NEIGHBOR_WINDOW = 5;
    private const DEFAULT_CACHE_TTL = 86400;
    private const DEFAULT_RISK = 'medium';

    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente que corrige segmentos de transcripción de audio en español colombiano al español natural, usando el contexto adyacente cuando esté disponible.

Tarea: recibirás UNO o MÁS segmentos consecutivos de la misma transcripción (los marcados con `#[OBJETIVO]` son los que debes corregir; los demás son contexto). Cada segmento está aproximadamente como lo transcribió un sistema ASR: puede estar en inglés mal transcrito, en espanglish, o en español con calcos del inglés. Tu trabajo es producir una versión corregida en español natural del segmento OBJETIVO, conservando la información que aparece en el snippet de contexto.

Reglas estrictas:
1. Devuelve EXCLUSIVAMENTE un objeto JSON válido con esta forma exacta y nada más:
   {
     "wrong": "<texto del segmento OBJETIVO tal como lo recibiste>",
     "correct": "<versión corregida en español natural>",
     "reason": "<una línea: principal decisión (dominio del contexto, terminología, estructura)>",
     "risk": "<low | medium | high>"
   }
2. NO modifiques marcas, nombres propios ni siglas. Si necesitas dejar intacto un término en el `correct`, hazlo y devuelve risk="high" si tu propuesta original los cambiaba; el sistema descartará el candidato. Si ya existe en el bloque "MARCAS PROTEGIDAS" del user prompt, NO LO TRADUZCAS NI LO REEMPLACES, déjalo byte por byte igual en `correct`.
3. NO inventes información que no esté en el snippet. Si los vecinos no aportan suficiente contexto para traducir, devuelve `correct = wrong` (conserva el original) y un `reason` honesto: "Sin contexto adicional suficiente para reconstruir; se conserva el original.".
4. Si el segmento OBJETIVO ya está en español natural y es correcto, devuelve `correct = wrong` y reason = "El segmento ya está en español natural.".
5. NO agregues explicaciones, encabezados, listas ni texto fuera del JSON. Tu respuesta COMPLETA debe ser un único objeto JSON parseable.
6. `risk`: "high" cuando propones cambiar marca/sigla; "medium" cuando reescribes estructura oracional o sustituyes términos; "low" cuando solo cambias mayúsculas/tildes/puntuación o conservas el original.
PROMPT;

    /**
     * Devuelve el código de respuesta HTTP apropiado para una salida dada.
     * @param array{ok: bool, ...}  $candidate
     */
    public function correctExample(
        Correction $parent,
        array $example,
        bool $forceFresh = false,
        ?int $neighborWindow = null
    ): array {
        $neighborWindow = $neighborWindow ?? self::DEFAULT_NEIGHBOR_WINDOW;
        $settings = app(\App\Services\Ia\LlmCorrectionSettings::class);

        if (!$settings->bool('enabled')) {
            return [
                'ok' => false,
                'reason' => 'Suggest deshabilitado desde Configuración / IA Suggest.',
                'hint' => 'Activa el toggle "Habilitado" en el tab IA Suggest.',
            ];
        }

        if ($settings->apiKey() === '') {
            return [
                'ok' => false,
                'reason' => 'LLM_API_KEY no configurada.',
                'hint' => 'Pegala en el campo "API key" del tab IA Suggest → Guardar key.',
                'api_key_source' => $settings->apiKeySource(),
            ];
        }

        $cacheKey = $this->cacheKey($parent, $example, $neighborWindow);

        if ($forceFresh) {
            Cache::forget($cacheKey);
        } else {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cache'] = 'hit';
                return $cached;
            }
        }

        $started = microtime(true);
        $targetIndex = (int) ($example['segment_index'] ?? 0);
        $transcriptionId = (int) ($example['transcription_id'] ?? 0);

        $neighbors = $transcriptionId > 0
            ? $this->findNeighbors($transcriptionId, $targetIndex, $neighborWindow)
            : [];

        $userPrompt = $this->buildUserPrompt($neighbors, $parent, $example);

        try {
            $decoded = $this->callChatCompletion(self::SYSTEM_PROMPT, $userPrompt, true, 'primary');
        } catch (\Throwable $e) {
            Log::warning('ai_context_aware.llm_failure', [
                'example_id' => $example['segment_id'] ?? null,
                'parent_id' => (int) $parent->id,
                'error' => $e->getMessage(),
            ]);
            return $this->mapLlmError($e);
        }

        $candidate = $this->extractCandidate($decoded, $example);
        if ($candidate === null) {
            return [
                'ok' => false,
                'reason' => 'El LLM no devolvió un objeto JSON con el shape esperado. Vuelve a intentarlo o ajusta el contexto.',
            ];
        }

        $brandHit = $this->brandReason($candidate['correct'] ?? '', $candidate['wrong'] ?? '');
        if ($brandHit !== null) {
            return [
                'ok' => false,
                'reason' => 'El LLM propuso modificar una marca o nombre propio; candidato descartado.',
                'reject_reason' => $brandHit,
                'raw' => $candidate,
            ];
        }

        $row = [
            'ok' => true,
            'wrong' => $candidate['wrong'],
            'correct' => $candidate['correct'],
            'reason' => $candidate['reason'] ?? '',
            'risk' => in_array($candidate['risk'] ?? null, ['low', 'medium', 'high'], true)
                ? $candidate['risk']
                : self::DEFAULT_RISK,
            'model' => $settings->str('model'),
            'tokens_used' => $this->extractTokensUsed($decoded),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'neighbor_window' => $neighborWindow,
            'cache' => 'miss',
        ];

        Cache::put($cacheKey, $row, $this->cacheTtl());

        Log::info('ai_context_aware.served', [
            'example_id' => $example['segment_id'] ?? null,
            'parent_id' => (int) $parent->id,
            'model' => $row['model'],
            'tokens_used' => $row['tokens_used'],
            'latency_ms' => $row['latency_ms'],
            'risk' => $row['risk'],
            'neighbor_window' => $neighborWindow,
        ]);

        return $row;
    }

    /**
     * @return array<int, array{segment_id:int, segment_index:int, text:string}>
     */
    private function findNeighbors(int $transcriptionId, int $segmentIndex, int $halfWindow): array
    {
        if ($transcriptionId <= 0 || $segmentIndex <= 0) {
            return [];
        }
        $low = max(1, $segmentIndex - $halfWindow);
        $high = $segmentIndex + $halfWindow;

        $rows = DB::table('transcription_segments')
            ->select(['id', 'segment_index', 'text'])
            ->where('transcription_id', $transcriptionId)
            ->whereBetween('segment_index', [$low, $high])
            ->orderBy('segment_index')
            ->get();

        return $rows->map(fn ($r) => [
            'segment_id' => (int) $r->id,
            'segment_index' => (int) $r->segment_index,
            'text' => (string) ($r->text ?? ''),
        ])->all();
    }

    private function buildSystemPrompt(array $neighbors, $parent, array $targetExample): string
    {
        // El system prompt ya está en la constante. Aquí podríamos enriquecerlo
        // dinámicamente según la cantidad de vecinos disponibles.
        return self::SYSTEM_PROMPT;
    }

    private function buildUserPrompt(array $neighbors, $parent, array $targetExample): string
    {
        $parentBlock = sprintf(
            "Regla padre en evaluación (referencia, NO destino):\n  wrong: %s\n  correct: %s",
            $parent->wrong_text ?? '',
            $parent->correct_text ?? ''
        );

        $contextBlock = $this->formatNeighbors($neighbors, (int) ($targetExample['segment_index'] ?? 0));

        $meta = sprintf(
            "Archivo: %s\nSegmento objetivo (segment_index=%d):\n%s",
            $targetExample['file_name'] ?? 'n/a',
            (int) ($targetExample['segment_index'] ?? 0),
            $targetExample['text_raw'] ?? ''
        );

        // Lista las marcas protegidas activas (decisión manual del admin) y
        // exige al LLM preservarlas literales en `correct`. El bloque va al
        // FINAL para que el LLM lo vea antes de devolver el JSON.
        $protectedTerms = $this->activeProtectedTerms();
        $protectedBlock = empty($protectedTerms)
            ? ''
            : "\n\nMARCAS PROTEGIDAS (preservar literales en `correct`; NUNCA traducir ni sustituirlas):\n- " . implode("\n- ", $protectedTerms);

        return $parentBlock . "\n\n" . $contextBlock . "\n\n" . $meta . $protectedBlock;
    }

    /**
     * Lista actual de marcas activas. Combina la lista estática de
     * `config('llm-correction.protected_brands')` con la lista dinámica
     * de `CorrectionProtectedTermsService::terms()`.
     *
     * @return array<int, string>
     */
    private function activeProtectedTerms(): array
    {
        $static = (array) config('llm-correction.protected_brands', []);
        $dynamic = [];
        try {
            /** @var CorrectionProtectedTermsService $svc */
            $svc = app(CorrectionProtectedTermsService::class);
            $dynamic = (array) $svc->terms();
        } catch (\Throwable $e) {
            // Servicio no cargable: caemos a la lista estática.
        }

        $combined = array_values(array_unique(array_filter(array_merge($static, $dynamic))));
        sort($combined, SORT_NATURAL | SORT_FLAG_CASE);
        return $combined;
    }

    private function formatNeighbors(array $neighbors, int $targetIndex): string
    {
        if (empty($neighbors)) {
            return "Contexto adyacente: (sin vecinos disponibles; el segmento está al inicio o fin de la transcripción, o no hay referencia).";
        }
        $lines = ["Contexto adyacente (segmentos antes y después del objetivo):"];
        foreach ($neighbors as $n) {
            $tag = (int) $n['segment_index'] === $targetIndex ? '#[OBJETIVO]' : sprintf('#[%d]', (int) $n['segment_index']);
            $lines[] = sprintf("%s %s", $tag, $n['text']);
        }
        return implode("\n", $lines);
    }

    private function cacheKey(Correction $parent, array $example, int $neighborWindow): string
    {
        return sprintf(
            'ai_context_aware:%s:%s:%d:%s',
            (int) $parent->id,
            (int) ($example['segment_id'] ?? 0),
            $neighborWindow,
            now()->toDateString()
        );
    }

    private function cacheTtl(): int
    {
        $ttl = (int) config('corrections.ai_context_aware.cache_ttl', self::DEFAULT_CACHE_TTL);
        return max(60, $ttl);
    }

    /**
     * Post-filtro defensivo: detecta el problema más común (LLM modifica o
     * elimina una marca protegida del `correct`) y rechaza explícitamente.
     * Devuelve el motivo para que la UI lo muestre.
     */
    private function brandReason(string $correct, string $wrong): ?string
    {
        // Caso 1 (regla original, ya existente): el LLM propuso modificar
        // o añadir una marca/sigla. Lo detectamos comparando `correct`
        // contra la lista de marcas activas.
        if ($this->looksLikeProtectedBrand($correct)) {
            return 'La versión corregida añade o modifica una marca/sigla.';
        }
        if ($this->looksLikeProtectedBrand($wrong)) {
            return 'El segmento original contiene una marca/sigla.';
        }

        // Caso 2 (nuevo, crítico): el LLM ELIMINÓ una marca protegida
        // del texto. Si la marca está en `wrong` pero NO en `correct`,
        // el LLM la tradujo/sustituyó. Rechazamos y devolvemos un warning
        // para que el admin itere con "Reintentar" + chips re-aplicando
        // la marca visible.
        $removed = $this->findRemovedBrands($wrong, $correct);
        if (!empty($removed)) {
            return 'La corrección eliminó la(s) marca(s) protegida(s): ' . implode(', ', $removed)
                . '. Reintenta o ajusta la marca manualmente.';
        }

        return null;
    }

    /**
     * Devuelve las marcas que están en `wrong` (case-insensitive substring)
     * pero NO en `correct` — i.e. la respuesta del LLM las eliminó.
     *
     * @return array<int, string>
     */
    private function findRemovedBrands(string $wrong, string $correct): array
    {
        $terms = $this->activeProtectedTerms();
        if (empty($terms)) return [];

        $removed = [];
        $wrongLower = mb_strtolower($wrong);
        $correctLower = mb_strtolower($correct);

        foreach ($terms as $term) {
            $t = mb_strtolower(trim((string) $term));
            if ($t === '') continue;
            if (strlen($t) < 3) continue; // muy corto para palabra suelta — evita ruido en "de", "a", etc.
            if (str_contains($wrongLower, $t) && !str_contains($correctLower, $t)) {
                $removed[] = $term;
            }
        }
        return $removed;
    }

    /**
     * Defensa-en-profundidad específica de la corrección inline.
     * Reutiliza CorrectionProtectedTermsService::terms() + validación de
     * sigla/marca. NO incluye filtro de longitud atómica.
     */
    private function looksLikeProtectedBrand(string $value): bool
    {
        $valueTrim = trim($value);
        if ($valueTrim === '') {
            return true;
        }
        // 1. Sigla todo mayúsculas ≥ 2 chars.
        if (preg_match('/^[A-Z][A-Z\.]+$/', $valueTrim) && strlen($valueTrim) >= 2) {
            return true;
        }
        // 2. protected_brands (estática) + exclusiones dinámicas.
        $combined = [];
        try {
            /** @var \App\Services\Ia\CorrectionProtectedTermsService $svc */
            $svc = app(\App\Services\Ia\CorrectionProtectedTermsService::class);
            $combined = array_merge(
                (array) config('llm-correction.protected_brands', []),
                (array) $svc->terms()
            );
        } catch (\Throwable $e) {
            $combined = (array) config('llm-correction.protected_brands', []);
        }
        $combined = array_values(array_unique(array_filter($combined)));
        $lower = mb_strtolower($valueTrim);
        foreach ($combined as $brand) {
            $brandLower = mb_strtolower(trim((string) $brand));
            if ($brandLower === '') continue;
            if ($lower === $brandLower) return true;
            if (strlen($lower) <= 50 && str_contains($lower, $brandLower)) return true;
        }
        return false;
    }

    private function mapLlmError(\Throwable $e): array
    {
        $msg = $e->getMessage();
        $code = null;
        if (preg_match('/LLM HTTP (\d{3}):/', $msg, $m)) {
            $code = (int) $m[1];
        }
        if ($code === 401 || $code === 403) {
            return [
                'ok' => false,
                'reason' => 'El gateway rechazó la autenticación o el modelo requiere créditos.',
                'hint' => 'Revisa tu API key y los créditos de la cuenta.',
                'http_code' => $code,
            ];
        }
        if ($code !== null && $code >= 500) {
            return [
                'ok' => false,
                'reason' => 'El gateway de Kilo tuvo un error interno. Reintenta en unos minutos.',
                'http_code' => $code,
            ];
        }
        if (str_contains($msg, 'cURL error 28') || str_contains($msg, 'timeout')) {
            return ['ok' => false, 'reason' => 'El LLM no respondió dentro del tiempo esperado. Reintenta.'];
        }
        if (str_contains($msg, 'LLM response missing choices')) {
            return ['ok' => false, 'reason' => 'El LLM devolvió una respuesta sin contenido útil.'];
        }
        return ['ok' => false, 'reason' => 'Fallo inesperado al llamar al LLM: ' . mb_substr($msg, 0, 240)];
    }

    /**
     * Extrae {wrong, correct, reason, risk} del JSON del LLM, con fallback
     * regex si la respuesta viene en prosa.
     */
    private function extractCandidate(array $decoded, array $example): ?array
    {
        if (isset($decoded['wrong'], $decoded['correct'])) {
            return $this->normalizeCandidate($decoded, $example);
        }
        if (isset($decoded['candidates']) && is_array($decoded['candidates'])) {
            foreach ($decoded['candidates'] as $cand) {
                if (isset($cand['wrong'], $cand['correct'])) {
                    return $this->normalizeCandidate($cand, $example);
                }
            }
        }
        if (isset($decoded['raw']['text'])) {
            $text = (string) $decoded['raw']['text'];
            $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $text) ?? $text;
            preg_match_all('/\{[^{}]*\}/s', $clean, $matches);
            foreach ($matches[0] ?? [] as $candidate) {
                $d = json_decode($candidate, true);
                if (is_array($d) && isset($d['wrong'], $d['correct'])) {
                    return $this->normalizeCandidate($d, $example);
                }
            }
        }
        return null;
    }

    private function normalizeCandidate(array $cand, array $example): array
    {
        return [
            'wrong' => (string) $cand['wrong'],
            'correct' => (string) $cand['correct'],
            'reason' => isset($cand['reason']) ? (string) $cand['reason'] : '',
            'risk' => isset($cand['risk']) ? (string) $cand['risk'] : null,
        ];
    }

    private function extractTokensUsed(array $decoded): ?int
    {
        if (isset($decoded['usage']['total_tokens']) && is_int($decoded['usage']['total_tokens'])) {
            return (int) $decoded['usage']['total_tokens'];
        }
        if (isset($decoded['raw']['usage']['total_tokens']) && is_int($decoded['raw']['usage']['total_tokens'])) {
            return (int) $decoded['raw']['usage']['total_tokens'];
        }
        return null;
    }

    /**
     * (change: corrections-ai-context-aware-with-mark-curation) Persiste la
     * corrección IA como pending con wrong=segmento completo, correct=traducción.
     * Idempotente por wrong_normalized+status.
     *
     * @return array{ok: bool, status: string, correction_id?: int, existing_id?: int, error?: string}
     */
    public function approve(Correction $parent, array $example, string $wrong, string $correct, ?int $adminId = null): array
    {
        $wrong = trim($wrong);
        $correct = trim($correct);

        if ($wrong === '' || $correct === '') {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'wrong y correct no pueden estar vacíos.'];
        }
        if ($wrong === $correct) {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'wrong y correct son idénticos; nada que aprobar.'];
        }

        $normalized = mb_strtolower(trim($wrong));

        $existing = Correction::query()
            ->whereIn('status', [Correction::STATUS_PENDING, Correction::STATUS_APPROVED])
            ->where('wrong_normalized', $normalized)
            ->first();

        if ($existing) {
            return [
                'ok' => false,
                'status' => 'conflict',
                'existing_id' => (int) $existing->id,
                'error' => 'Ya existe una corrección pending o approved con el mismo wrong_normalized.',
            ];
        }

        $proposerId = $adminId > 0 ? $adminId : (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1);
        $today = now()->toDateString();

        $correction = Correction::create([
            'wrong_text' => $wrong,
            'correct_text' => $correct,
            'wrong_normalized' => $normalized,
            'status' => Correction::STATUS_PENDING,
            'risk_level' => self::DEFAULT_RISK,
            'proposed_by' => $proposerId,
            'source' => "ai-context-correct-context-{$today}",
            'applies_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('ai_context_aware.approved', [
            'correction_id' => (int) $correction->id,
            'parent_id' => (int) $parent->id,
            'example_id' => $example['segment_id'] ?? null,
            'admin_id' => $adminId,
            'wrong_len' => mb_strlen($wrong),
        ]);

        return [
            'ok' => true,
            'status' => 'inserted',
            'correction_id' => (int) $correction->id,
        ];
    }
}
