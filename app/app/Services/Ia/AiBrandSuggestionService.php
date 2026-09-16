<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Deep module: detección de marcas/siglas/nombres propios en un texto.
 *
 * (change: corrections-ai-context-aware-with-mark-curation) Una sola entrada
 * pública `suggestBrands(text)` que oculta: gate del master switch + API key,
 * llamada al LLM con prompt especializado, cache de 1 h por hash del texto,
 * y post-filtro que excluye las marcas ya protegidas.
 *
 * Devuelve una lista deduplicada de strings (sin forma canónica) que la UI
 * muestra como checkboxes para que el admin confirme y persista vía
 * `CorrectionProtectedTermsService::addFromModal`.
 */
class AiBrandSuggestionService
{
    use \App\Services\Concerns\CallsLlmChatCompletion;

    private const CACHE_TTL = 3600;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente que detecta posibles marcas, siglas o nombres propios dentro de un fragmento de texto (transcripción de audio ASR; puede estar en español, inglés o espanglish).

Tarea: del TEXTO que te entrego, extrae los tokens que parezcan:
- Marcas de productos o empresas (e.g. "ARMOFL", "Word", "Coca-Cola")
- Siglas en mayúsculas (e.g. "ONU", "EEUU", "S.A.")
- Nombres propios de personas (e.g. "Diego", "María")
- Nombres propios de instituciones (e.g. "Universidad Nacional")

Devuelve EXCLUSIVAMENTE un objeto JSON con la forma:
{
  "candidates": ["ARMOFL", "Word", ...]
}

Reglas:
1. NO incluyas stopwords (artículos, preposiciones, pronombres, números).
2. NO incluyas palabras comunes del español (e.g. "hola", "casa").
3. NO devuelvas explicaciones ni texto fuera del JSON.
4. Si no detectas ninguna, devuelve {"candidates": []}.
PROMPT;

    /**
     * Devuelve una lista (array<int,string>) de candidatos a marca/sigla
     * detectados por el LLM, deduplicados y sin marcas ya protegidas.
     *
     * @return array{ok: bool, candidates?: array<int,string>, reason?: string}
     */
    public function suggestBrands(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'reason' => 'Texto vacío.'];
        }

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
            ];
        }

        $cacheKey = $this->cacheKey($text);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['ok']) && $cached['ok']) {
            $cached['cache'] = 'hit';
            return $cached;
        }

        $started = microtime(true);

        try {
            $decoded = $this->callChatCompletion(self::SYSTEM_PROMPT, $text, true, 'primary');
        } catch (\Throwable $e) {
            Log::warning('ai_brand_suggest.llm_failure', ['error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'Fallo al consultar el LLM. Reintenta.'];
        }

        $raw = $this->extractCandidates($decoded);

        $protected = $this->protectedTermsLower();

        // Clasifica los candidatos detectados en dos grupos: nuevos (para
        // que el admin pueda decidir agregarlos) y ya protegidos (para que
        // vea el estado real del diccionario sin pedirlo).
        $newCandidates = [];
        $alreadyProtected = [];
        $seen = [];
        foreach ($raw as $token) {
            $t = trim((string) $token);
            if ($t === '') continue;
            if (mb_strlen($t) < 2) continue;
            $key = mb_strtolower($t);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if (in_array($key, $protected, true)) {
                $alreadyProtected[] = $t;
            } else {
                $newCandidates[] = $t;
            }
        }

        $payload = [
            'ok' => true,
            'candidates' => $newCandidates,
            'already_protected' => $alreadyProtected,
            'protected_terms_total' => count($protected),
            'tokens_used' => $this->extractTokensUsed($decoded),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        Cache::put($cacheKey, $payload, self::CACHE_TTL);

        Log::info('ai_brand_suggest.served', [
            'new_candidates' => count($newCandidates),
            'already_protected_count' => count($alreadyProtected),
            'tokens_used' => $payload['tokens_used'],
            'latency_ms' => $payload['latency_ms'],
        ]);

        return $payload;
    }

    private function cacheKey(string $text): string
    {
        return 'ai_brand_suggest:' . sha1($text) . ':' . now()->toDateString();
    }

    /** @return array<int,string> */
    private function protectedTermsLower(): array
    {
        try {
            /** @var CorrectionProtectedTermsService $svc */
            $svc = app(CorrectionProtectedTermsService::class);
            return (array) $svc->terms();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int, string> */
    private function extractCandidates(array $decoded): array
    {
        if (isset($decoded['candidates']) && is_array($decoded['candidates'])) {
            return array_values($decoded['candidates']);
        }
        if (isset($decoded['raw']['text'])) {
            $text = (string) $decoded['raw']['text'];
            $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $text) ?? $text;
            preg_match_all('/\{[^{}]*\}/s', $clean, $matches);
            foreach ($matches[0] ?? [] as $candidate) {
                $d = json_decode($candidate, true);
                if (is_array($d) && isset($d['candidates']) && is_array($d['candidates'])) {
                    return array_values($d['candidates']);
                }
            }
        }
        return [];
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
}
