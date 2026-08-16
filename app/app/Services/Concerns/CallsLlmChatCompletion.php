<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Trait compartido para servicios que llaman a un LLM via el endpoint
 * OpenAI-compatible /chat/completions. Usado por LlmCorrectionSuggester
 * y por futuros consumidores (futuro suggester PT↔ES, etc.).
 *
 * Asume que el modelo vive en config('llm-correction') (de momento es
 * el único proveedor; cuando se agregue anthropic o google-gemini este
 * trait se vuelve abstracto o se divide).
 *
 * Por qué trait y no clase: queremos que los servicios que llamen al LLM
 * tengan un solo punto de cambio (timeouts, headers, parsing) y evitemos
 * repetir la lógica. Si el contrato HTTP cambiara (ej. Anthropic usa
 * headers distintos), solo se toca aquí.
 */
trait CallsLlmChatCompletion
{
    /**
     * Llama al LLM configurado con system + user prompts.
     *
     * @param  string  $provider  'primary' (Kilo Gateway) o 'secondary' (Ollama Cloud).
     *         El segundo proveedor permite distribuir la carga y evitar el rate
     *         limit (HTTP 429) del primario.
     *
     * @return array{candidates?: array<int, array<string, mixed>>, raw?: array<string, mixed>}
     *         El shape final depende del system prompt del caller; este trait
     *         solo garantiza que `raw` contiene el JSON decodificado de la
     *         respuesta. Servicios específicos extraen `candidates` o similar.
     *
     * @throws RuntimeException si la respuesta HTTP no es 2xx, si el body no
     *         es JSON parseable, o si falta `choices[0].message.content`.
     */
    protected function callChatCompletion(string $systemPrompt, string $userPrompt, bool $forceJson = true, string $provider = 'primary'): array
    {
        /** @var \App\Services\Ia\LlmCorrectionSettings $settings */
        $settings = app(\App\Services\Ia\LlmCorrectionSettings::class);

        $isSecondary = $provider === 'secondary';
        $apiKey = $isSecondary ? $settings->str('secondary_api_key') : $settings->apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('LLM API key no configurada para el proveedor ' . $provider . '.');
        }

        $model = $isSecondary ? $settings->str('secondary_model') : $settings->str('model');
        $baseUrl = $isSecondary ? $settings->str('secondary_base_url') : $settings->str('base_url');

        // Importante: NO enviamos `response_format: {type: json_object}`.
        // Razón: gateways proxy (OllamaCloud, vLLM, local) NO soportan ese
        // parámetro OpenAI y devuelven 400 invalid_request_error sobre `param: response_format`.
        // En su lugar, pedimos JSON explícitamente en el system prompt y
        // hacemos parsing client-side con regex como fallback.
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $settings->float('temperature'),
            'max_tokens' => $settings->int('max_tokens'),
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($settings->int('timeout_seconds'))
            ->post(rtrim($baseUrl, '/') . '/chat/completions', $payload);

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'LLM HTTP %d: %s',
                $response->status(),
                substr($response->body(), 0, 500),
            ));
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('LLM response missing choices[0].message.content');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            // Si el modelo devolvió prosa en vez de JSON estricto (muchos
            // modelos Ollama/local hacen esto), el caller decidirá si
            // parsear con regex. Devolvemos `text` para que el suggester
            // haga fallback.
            return ['raw' => ['text' => $content], 'unparsed' => true];
        }

        return $decoded;
    }
}
