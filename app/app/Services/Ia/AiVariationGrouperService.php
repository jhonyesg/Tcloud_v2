<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Deep module: agrupación LLM-powered de variantes literales dentro del
 * Variation Finder. Recibe la lista de variantes "Sin regla" descubiertas
 * por SQL y pide al LLM que las agrupe por nombre canónico + proponga la
 * normalización.
 *
 * (cambia corrections-variation-finder-ai-suggest) Una sola entrada pública
 * `groupVariants()` que oculta: gate del master switch + API key, prompt
 * especializado con instrucciones anti-marca, cache por (word, since, limit),
 * parsing defensivo de JSON con/sin markdown fences.
 *
 * NO crea reglas: sólo sugiere. La creación la hace el admin vía el botón
 * "Confirmar" → POST /ia/correcciones/variations/bulk-create.
 */
class AiVariationGrouperService
{
    use \App\Services\Concerns\CallsLlmChatCompletion;

    private const CACHE_TTL = 1800; // 30 min — el LLM puede mejorar con modelos nuevos, no cacheamos mucho tiempo

    /**
     * Prompt del sistema. Instrucciones anti-marca (no agrupar nombres
     * distintos) + JSON schema estricto para que el parser no se rompa.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
Sos un asistente de normalización de nombres para un diccionario de transcripciones en español.

El admin te va a entregar:
1. Una PALABRA OBJETIVO (la que él buscó en el Variation Finder).
2. Una lista de VARIANTES LITERALES encontradas en su corpus, cada una con su frecuencia (cuántas veces aparece).

Tu tarea es AGRUPAR las variantes que son typos fonéticos o tipográficos DEL MISMO nombre, y proponer una CANONICAL_CORRECT razonable para cada grupo.

Reglas OBLIGATORIAS:
1. NO agrupes nombres distintos aunque compartan palabras.
   Ejemplo MAL: agrupar "Alberto de las Pellas" con "Abelardo de las Pellas" — son personas diferentes.
   Ejemplo BIEN: agrupar "Abelardo de las Prieyas" con "Abelardo de las Prias" — son typos del mismo nombre.
2. La canonical_correct debe ser la forma canónica en español más probable.
3. Asigná un `confidence` 0-1 a cada variante según qué tan seguro estés de que pertenece al grupo:
   - 1.0: certainty absoluta
   - 0.8-0.99: alta confianza
   - 0.5-0.79: dudoso, puede ser otro nombre
   - <0.5: probablemente NO pertenece al grupo (no incluir en el grupo)
4. Si una variante no encaja en ningún grupo, NO la agrupes.

Devolvé EXCLUSIVAMENTE un objeto JSON con esta forma ESTRICTA:
{
  "groups": [
    {
      "canonical_correct": "Abelardo de la Espriella",
      "reason": "Todas son variantes fonéticas de 'Espriella'",
      "variants": [
        {"wrong": "Abelardo de las Prias",   "count": 4, "confidence": 0.95},
        {"wrong": "Abelardo de las Prieya",  "count": 4, "confidence": 0.92}
      ]
    }
  ]
}

NO devuelvas texto fuera del JSON. NO expliques. NO uses markdown fences. NO inventes variantes que no te entregué.
PROMPT;

    /**
     * Agrupa las variantes pasadas y devuelve los grupos propuestos.
     *
     * @param string $word La palabra que el admin buscó (ej: "abelardo").
     * @param array<int, array{wrong: string, count: int}> $variants
     *        Variantes "Sin regla" descubiertas por SQL.
     * @param string|null $since ISO 8601 opcional, para cachear distinto por scope.
     * @param int $limit El limit pasado a findVariations, para cachear.
     * @param string $provider 'primary'|'secondary'|... (default: 'primary').
     * @return array{
     *   ok: bool,
     *   groups?: array<int, array{canonical_correct: string, reason: string, variants: array<int, array{wrong: string, count: int, confidence: float}>}>,
     *   reason?: string,
     *   hint?: string,
     *   tokens_used?: int,
     *   latency_ms?: int,
     *   model?: string,
     *   cache?: string,
     *   raw_excerpt?: string
     * }
     */
    public function groupVariants(string $word, array $variants, ?string $since = null, int $limit = 100, string $provider = 'primary'): array
    {
        if (empty($variants)) {
            return ['ok' => false, 'reason' => 'Sin variantes para agrupar.'];
        }

        $settings = app(LlmCorrectionSettings::class);
        if (!$settings->bool('enabled')) {
            return [
                'ok' => false,
                'reason' => 'switch_off',
                'hint' => 'Activá el switch maestro en IA Suggest para usar AI Suggest en Variation Finder.',
            ];
        }
        if ($settings->apiKey() === '') {
            return [
                'ok' => false,
                'reason' => 'no_api_key',
                'hint' => 'Configurá la API key en IA Suggest antes de usar AI Suggest aquí.',
            ];
        }

        // Cache key incluye todos los inputs que afectan el resultado.
        $cacheKey = $this->cacheKey($word, $since, $limit, $variants);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['ok']) && $cached['ok']) {
            $cached['cache'] = 'hit';
            return $cached;
        }

        $userMessage = $this->buildUserMessage($word, $variants);
        $started = microtime(true);

        try {
            $decoded = $this->callChatCompletion(self::SYSTEM_PROMPT, $userMessage, true, $provider);
        } catch (\Throwable $e) {
            Log::warning('ai_variation_group.llm_failure', ['error' => $e->getMessage(), 'word' => $word]);
            return [
                'ok' => false,
                'reason' => 'timeout_or_network',
                'hint' => 'La IA no respondió. Reintentá o revisá la API key.',
            ];
        }

        $parseResult = $this->parseGroups($decoded);
        if (!$parseResult['ok']) {
            return [
                'ok' => false,
                'reason' => 'parse_failed',
                'raw_excerpt' => mb_substr($parseResult['raw'] ?? '', 0, 200),
                'hint' => 'La IA devolvió un JSON inválido. Reintentá; si persiste, reportá al admin.',
            ];
        }

        $payload = [
            'ok' => true,
            'groups' => $parseResult['groups'],
            'tokens_used' => $this->extractTokensUsed($decoded),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $this->extractModel($decoded, $provider),
        ];
        Cache::put($cacheKey, $payload, self::CACHE_TTL);
        Log::info('ai_variation_group.served', [
            'word' => $word,
            'variants_count' => count($variants),
            'groups_count' => count($parseResult['groups']),
            'tokens_used' => $payload['tokens_used'],
            'latency_ms' => $payload['latency_ms'],
        ]);
        return $payload;
    }

    /**
     * Estima input tokens del prompt. Aproximación: 4 chars/token + 200 fixed.
     */
    public function estimateTokens(array $variants): int
    {
        $variantsText = '';
        foreach ($variants as $v) {
            $variantsText .= "{$v['wrong']} ({$v['count']})\n";
        }
        $userMessageLen = mb_strlen($this->buildUserMessage('X', $variants));
        $systemPromptLen = mb_strlen(self::SYSTEM_PROMPT);
        $total = $systemPromptLen + $userMessageLen + 200; // 200 del overhead JSON + role markers
        return (int) ceil($total / 4);
    }

    /**
     * Estima el costo en USD según el modelo activo. Tabla hardcoded simple;
     * en el futuro podría leerla de la config del provider.
     */
    public function estimateCostUsd(int $tokens, ?string $model = null): float
    {
        $rates = [
            'gpt-4o-mini' => 0.00015,        // $0.15/1M input tokens
            'gpt-4o' => 0.0025,             // $2.5/1M input tokens
            'gpt-4-turbo' => 0.01,           // $10/1M
            'claude-3-haiku' => 0.00025,     // $0.25/1M
            'claude-3-sonnet' => 0.003,      // $3/1M
            'glm-4.5' => 0.0001,
            'default' => 0.001,
        ];
        $rate = $rates['default'];
        if ($model !== null) {
            $m = mb_strtolower($model);
            foreach ($rates as $key => $r) {
                if ($key !== 'default' && mb_strpos($m, $key) !== false) {
                    $rate = $r;
                    break;
                }
            }
        }
        return round(($tokens / 1_000_000) * $rate, 4);
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function cacheKey(string $word, ?string $since, int $limit, array $variants): string
    {
        $variantHash = sha1(json_encode(array_map(fn ($v) => $v['wrong'] . '|' . $v['count'], $variants)));
        return 'ai_variation_group:' . sha1($word) . ':' . sha1($since ?? '') . ':' . $limit . ':' . $variantHash;
    }

    private function buildUserMessage(string $word, array $variants): string
    {
        $lines = ["PALABRA OBJETIVO: \"{$word}\"", '', 'VARIANTES (wrong | count):'];
        foreach ($variants as $v) {
            $lines[] = "- \"{$v['wrong']}\" ({$v['count']})";
        }
        $lines[] = '';
        $lines[] = 'Devolvé el JSON con los grupos propuestos. NO agrupes nombres distintos.';
        return implode("\n", $lines);
    }

    /**
     * Parsea la respuesta del LLM. Acepta JSON directo o envuelto en
     * ```json ... ``` fences. Si el LLM devuelve texto adicional fuera
     * del JSON, intenta extraerlo con regex.
     *
     * @return array{ok: bool, groups?: array, raw?: string}
     */
    private function parseGroups(array $decoded): array
    {
        // Si callChatCompletion ya parseó a array, usarlo directo.
        if (isset($decoded['groups']) && is_array($decoded['groups'])) {
            $validated = $this->validateGroups($decoded['groups']);
            // Distinguir null (validación estructural fallida) de []
            // (todos los grupos descartados por baja confidence — válido pero vacío).
            if ($validated === null) {
                return ['ok' => false, 'raw' => json_encode($decoded)];
            }
            return ['ok' => true, 'groups' => $validated];
        }

        // Si vino como 'raw' string (por ejemplo en caso de respuesta con fences), buscar JSON.
        if (isset($decoded['raw']['text'])) {
            $text = (string) $decoded['raw']['text'];
            $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $text) ?? $text;
            // Buscar primer objeto JSON balanceado.
            preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $clean, $matches);
            foreach ($matches[0] ?? [] as $candidate) {
                $d = json_decode($candidate, true);
                if (is_array($d) && isset($d['groups']) && is_array($d['groups'])) {
                    $validated = $this->validateGroups($d['groups']);
                    if ($validated !== null) {
                        return ['ok' => true, 'groups' => $validated];
                    }
                }
            }
            return ['ok' => false, 'raw' => mb_substr($text, 0, 200)];
        }

        return ['ok' => false, 'raw' => json_encode($decoded)];
    }

    /**
     * Valida que cada grupo tenga canonical_correct + variants[] con la
     * estructura mínima. Filtra variants con confidence < 0.5.
     * @return array|null Null si algún grupo falla validación estructural.
     */
    private function validateGroups(array $groups): ?array
    {
        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group)) return null;
            if (!isset($group['canonical_correct']) || !is_string($group['canonical_correct'])) return null;
            if (!isset($group['variants']) || !is_array($group['variants'])) return null;
            $validatedVariants = [];
            foreach ($group['variants'] as $v) {
                if (!is_array($v)) continue;
                if (!isset($v['wrong'], $v['confidence'])) continue;
                $confidence = (float) $v['confidence'];
                if ($confidence < 0.5) continue; // filtro defensivo: muy inciertos
                $validatedVariants[] = [
                    'wrong' => (string) $v['wrong'],
                    'count' => (int) ($v['count'] ?? 0),
                    'confidence' => $confidence,
                ];
            }
            if (empty($validatedVariants)) continue; // grupo sin variants válidos se descarta
            $out[] = [
                'canonical_correct' => trim($group['canonical_correct']),
                'reason' => (string) ($group['reason'] ?? ''),
                'variants' => $validatedVariants,
            ];
        }
        return $out;
    }

    private function extractTokensUsed(array $decoded): int
    {
        return (int) ($decoded['usage']['total_tokens'] ?? $decoded['usage']['prompt_tokens'] ?? 0);
    }

    private function extractModel(array $decoded, string $provider): string
    {
        return (string) ($decoded['model'] ?? $provider);
    }
}
