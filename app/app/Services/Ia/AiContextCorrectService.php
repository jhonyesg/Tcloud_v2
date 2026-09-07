<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Services\Concerns\CallsLlmChatCompletion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Corrección inline de un ejemplo individual del modal "Contexto" con LLM.
 *
 * (change: corrections-ai-context-correct-inline) Toma la frase del
 * `text_raw` del ejemplo y la regla padre, devuelve una corrección atómica
 * en español bien. Aprobada por el admin, persiste como pending con
 * source='ai-context-correct-YYYY-MM-DD'. Manual-only: el admin es quien
 * dispara cada llamada; no hay cron que la invoque.
 *
 * Reutiliza `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` como
 * post-filtro defensivo contra marcas / siglas / nombres propios, y el
 * trait `CallsLlmChatCompletion` para la llamada HTTP al gateway.
 *
 * Cache por `(correction_id, example_id, today)` con TTL configurable
 * (`config('corrections.ai_context_correct.cache_ttl', 86400)`). El método
 * "Reintentar" pasa `forceFresh=true` y limpia la entrada antes de llamar.
 */
class AiContextCorrectService
{
    use CallsLlmChatCompletion;

    /** Fallback del TTL (24h) si la config no está presente. */
    private const DEFAULT_CACHE_TTL = 86400;

    /** Riesgo base cuando el LLM no lo declara explícitamente. */
    private const DEFAULT_RISK = 'medium';

    /**
     * Prompt de sistema para la corrección atómica contextualizada.
     * Construido una sola vez; idéntico entre llamadas para que el admin
     * obtenga un comportamiento consistente.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente que corrige segmentos de transcripción de audio en español colombiano al español natural, contextualizando el contenido.

Tarea: recibirás UN SOLO segmento (una sola frase o pocas frases) tal como lo transcribió un sistema automático de reconocimiento de voz (ASR). El segmento puede estar en inglés mal transcrito, en espanglish, o en español con calcos del inglés. Tu trabajo es producir una versión corregida en español natural que conserve la información del original sin inventar contenido que no esté allí.

Reglas estrictas:
1. Devuelve EXCLUSIVAMENTE un objeto JSON válido con esta forma exacta y nada más:
   {
     "wrong": "<texto original tal como lo recibiste>",
     "correct": "<versión corregida en español natural>",
     "reason": "<una sola línea explicando la decisión principal (dominio, terminología, estructura)>",
     "risk": "<uno de: low | medium | high>"
   }
2. NO modifiques marcas, nombres propios ni siglas. Si el segmento contiene una marca o nombre propio (incluso si el LLM cree saber la traducción), DEJA el término intacto tanto en `wrong` como en `correct`. Si tu propuesta los cambia, devuelve risk="high" para que el post-filtro del sistema la descarte.
3. NO inventes información que no esté en el original. Si el ASR transcribió un galimatías, devuelve la versión más cercana al español natural preservando el contenido reconocible, no rellenes.
4. NO agregues explicaciones, encabezados, listas, ni texto fuera del JSON. Tu respuesta COMPLETA debe ser un único objeto JSON parseable.
5. `risk`:
   - "high" cuando propones cambiar una marca, sigla o nombre propio.
   - "medium" cuando reescribes estructura oracional o sustituyes términos.
   - "low" cuando solo cambias mayúsculas/tildes/puntuación.
PROMPT;

    /**
     * Construye la corrección IA para un ejemplo individual.
     *
     * @param  array{segment_id:int, transcription_id:int, segment_index:int, text_raw:string, text:string, file_name?:string}  $example
     * @param  array{wrong_text:string, correct_text:string, wrong_normalized?:string, risk_level?:string}  $parent
     */
    public function suggest(array $example, array $parent, bool $forceFresh = false): array
    {
        /** @var \App\Services\Ia\LlmCorrectionSettings $settings */
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

        $cacheKey = $this->cacheKey($example['segment_id'] ?? null, $parent['id'] ?? null);

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
        $userPrompt = $this->buildUserPrompt($example, $parent);

        try {
            $decoded = $this->callChatCompletion(self::SYSTEM_PROMPT, $userPrompt, true, 'primary');
        } catch (\Throwable $e) {
            Log::warning('ai_context_correct.llm_failure', [
                'example_id' => $example['segment_id'] ?? null,
                'parent_id' => $parent['id'] ?? null,
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
            'cache' => 'miss',
        ];

        Cache::put($cacheKey, $row, $this->cacheTtl());

        Log::info('ai_context_correct.served', [
            'example_id' => $example['segment_id'] ?? null,
            'parent_id' => $parent['id'] ?? null,
            'model' => $row['model'],
            'tokens_used' => $row['tokens_used'],
            'latency_ms' => $row['latency_ms'],
            'risk' => $row['risk'],
        ]);

        return $row;
    }

    /**
     * Persiste la corrección IA como pending. Idempotente por
     * wrong_normalized+status: si ya existe pending o approved con el mismo
     * normalized, devuelve `conflict` con el id existente en lugar de
     * insertar un duplicado.
     *
     * @return array{ok: bool, status: string, correction?: int, existing_id?: int, error?: string}
     */
    public function approve(array $example, array $parent, string $wrong, string $correct, ?int $adminId = null): array
    {
        $wrong = trim($wrong);
        $correct = trim($correct);

        if ($wrong === '' || $correct === '') {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'wrong y correct no pueden estar vacíos.'];
        }

        if ($wrong === $correct) {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'wrong y correct son idénticos; nada que aprobar.'];
        }

        $normalized = $this->normalize($wrong);

        $existing = Correction::whereIn('status', [Correction::STATUS_PENDING, Correction::STATUS_APPROVED])
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

        $today = now()->toDateString();
        $correction = Correction::create([
            'wrong_text' => $wrong,
            'correct_text' => $correct,
            'wrong_normalized' => $normalized,
            'status' => Correction::STATUS_PENDING,
            'risk_level' => self::DEFAULT_RISK,
            'proposed_by' => $adminId ?: (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1),
            'source' => "ai-context-correct-{$today}",
            'applies_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('ai_context_correct.approved', [
            'correction_id' => (int) $correction->id,
            'example_id' => $example['segment_id'] ?? null,
            'parent_id' => $parent['id'] ?? null,
            'admin_id' => $adminId,
            'wrong' => $wrong,
            'correct' => $correct,
        ]);

        return [
            'ok' => true,
            'status' => 'inserted',
            'correction_id' => (int) $correction->id,
        ];
    }

    /**
     * Normalización ligera: lowercase + colapsa espacios. Coincide con la
     * convención del módulo (ascii lower). La normalización completa (strip
     * diacríticos, etc.) la hace el observer/cast del modelo en otras partes;
     * aquí solo necesitamos una clave razonable para el lookup de duplicados.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return $value;
    }

    private function buildUserPrompt(array $example, array $parent): string
    {
        $parentLine = sprintf(
            "Regla padre en evaluación:\n  wrong: %s\n  correct: %s\n  risk_level: %s",
            $parent['wrong_text'] ?? '',
            $parent['correct_text'] ?? '',
            $parent['risk_level'] ?? 'unknown'
        );

        $segmentLine = sprintf(
            "Segmento exacto (text_raw) a corregir:\n%s",
            $example['text_raw'] ?? ''
        );

        $metaLine = sprintf(
            "Contexto adicional: transcription_id=%s, segment_index=%s, file=%s",
            $example['transcription_id'] ?? 'n/a',
            $example['segment_index'] ?? 'n/a',
            $example['file_name'] ?? 'n/a'
        );

        return $parentLine . "\n\n" . $segmentLine . "\n\n" . $metaLine;
    }

    /**
     * Extrae el primer objeto con shape {wrong, correct, reason, risk} de la
     * respuesta del LLM. Soporta respuesta directa, envuelta en
     * {"candidate": {...}}, o fallback regex sobre texto prosa.
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
            if (!empty($matches[0])) {
                foreach ($matches[0] as $candidate) {
                    $decodedCandidate = json_decode($candidate, true);
                    if (is_array($decodedCandidate) && isset($decodedCandidate['wrong'], $decodedCandidate['correct'])) {
                        return $this->normalizeCandidate($decodedCandidate, $example);
                    }
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
     * Devuelve el motivo del rechazo si el `correct` toca una marca o sigla
     * que el LLM pretendió reescribir; null si la corrección parece limpia.
     *
     * Nota: NO usamos la heurística completa de `looksLikeBrandOrProperNoun`
     * porque esa incluye un filtro de longitud atómica diseñado para el
     * suggester global (que propone reglas `wrong→correct` de pocas
     * palabras). Aquí el `correct` es la corrección del SEGMENTO COMPLETO
     * (puede ser una oración larga) y los filtros de longitud son
     * inaplicables. Conservamos solo:
     *  - match contra `protected_brands` + exclusiones dinámicas
     *  - sigla todo-mayúsculas ≥ 2 chars
     *  - single-word con capitalización interna (probable marca)
     *  - frase corta ≤ 3 palabras capitalizada (probable nombre propio)
     */
    private function brandReason(string $correct, string $wrong): ?string
    {
        $suggester = app(\App\Services\Ia\LlmCorrectionSuggester::class);

        if ($this->looksLikeBrandOrProperNounLite($correct)) {
            return 'correct parece marca o nombre propio.';
        }
        if ($this->looksLikeBrandOrProperNounLite($wrong)) {
            return 'wrong parece marca o nombre propio.';
        }

        return null;
    }

    /**
     * Defensa-en-profundidad específica del flujo de corrección inline.
     * Reimplementa las primeras 4 verificaciones de
     * `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` y omite la
     * quinta (filtro de longitud atómica).
     */
    private function looksLikeBrandOrProperNounLite(string $value): bool
    {
        $valueTrim = trim($value);
        if ($valueTrim === '') {
            return true;
        }

        // 1. Sigla todo mayúsculas sostenida (≥ 2 chars).
        if (preg_match('/^[A-Z][A-Z\.]+$/', $valueTrim) && strlen($valueTrim) >= 2) {
            return true;
        }
        // Variante "EE. UU." (sigla con puntos).
        if (preg_match('/^(\b[A-Z]{1,3}\.?\s*){2,}$/', $valueTrim)) {
            return true;
        }

        // 2. protected_brands estática + exclusiones dinámicas.
        $protectedBrands = config('llm-correction.protected_brands', []);
        $dynamicExcluded = [];
        try {
            /** @var \App\Services\Ia\CorrectionProtectedTermsService $svc */
            $svc = app(\App\Services\Ia\CorrectionProtectedTermsService::class);
            $dynamicExcluded = $svc->terms();
        } catch (\Throwable $e) {
            // Si la tabla no existe, caemos en solo protected_brands.
        }
        $combined = array_values(array_unique(array_filter(array_merge(
            is_array($protectedBrands) ? $protectedBrands : [],
            $dynamicExcluded
        ))));
        if (!empty($combined)) {
            $lower = mb_strtolower($valueTrim);
            foreach ($combined as $brand) {
                $brandLower = mb_strtolower(trim((string) $brand));
                if ($brandLower === '') {
                    continue;
                }
                if ($lower === $brandLower) {
                    return true;
                }
                if (strlen($lower) <= 50 && str_contains($lower, $brandLower)) {
                    return true;
                }
            }
        }

        // 3. Single-word con capitalización interna ("iPhone", "MacBook").
        if (!str_contains($valueTrim, ' ')) {
            if (preg_match('/^[A-Z][a-z]+[A-Z]/', $valueTrim)) {
                return true;
            }
        }

        // 4. Frase corta (≤ 3 palabras) capitalizada al inicio: probable nombre propio.
        if (strlen($valueTrim) <= 30) {
            $words = preg_split('/\s+/', $valueTrim);
            if (count($words) <= 3 && preg_match('/^[A-Z]/', $words[0])) {
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

        return false;
    }

    /**
     * Mapea la excepción HTTP del LLM al contrato JSON que ve el admin.
     * 401/403 → 503 (auth/creditos son problema del setup local).
     * 5xx, timeout, parse → 502 (upstream falló).
     */
    private function mapLlmError(\Throwable $e): array
    {
        $msg = $e->getMessage();
        $httpCode = null;
        if (preg_match('/LLM HTTP (\d{3}):/', $msg, $m)) {
            $httpCode = (int) $m[1];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return [
                'ok' => false,
                'reason' => 'El gateway rechazó la autenticación o el modelo requiere créditos.',
                'hint' => 'Revisa tu API key y los créditos de la cuenta.',
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode !== null && $httpCode >= 500) {
            return [
                'ok' => false,
                'reason' => 'El gateway de Kilo tuvo un error interno. Reintenta en unos minutos.',
                'http_code' => $httpCode,
            ];
        }

        if (str_contains($msg, 'cURL error 28') || str_contains($msg, 'timeout')) {
            return [
                'ok' => false,
                'reason' => 'El LLM no respondió dentro del tiempo esperado. Reintenta.',
            ];
        }

        if (str_contains($msg, 'LLM response missing choices')) {
            return [
                'ok' => false,
                'reason' => 'El LLM devolvió una respuesta sin contenido útil.',
            ];
        }

        return [
            'ok' => false,
            'reason' => 'Fallo inesperado al llamar al LLM: ' . mb_substr($msg, 0, 240),
        ];
    }

    private function cacheKey($segmentId, $parentId): string
    {
        return sprintf(
            'ai_context_correct:%s:%s:%s',
            $parentId ?? 'unknown',
            $segmentId ?? 'unknown',
            now()->toDateString()
        );
    }

    private function cacheTtl(): int
    {
        $ttl = (int) config('corrections.ai_context_correct.cache_ttl', self::DEFAULT_CACHE_TTL);
        return max(60, $ttl);
    }
}
