<?php

namespace App\Services\Ia;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Configuración del suggester LLM-powered con overrides en BD.
 *
 * Sigue el mismo patrón que TranscriptorSettings:
 *   1. Fila en system_settings con clave `llm_correction.<key>`
 *   2. config("llm-correction.<key>")  — capa env de siempre
 *   3. Default del esquema
 *
 * Permite cambiar el modelo, base_url y defaults desde la UI sin redeploy.
 * La `api_key` queda EXCLUIDA a propósito: las credenciales son deploy-level
 * (van en .env / secretos), nunca en BD.
 *
 * Memoria compartida entre php-fpm y CLI: el store de cache es Redis, una
 * escritura desde la UI propaga a todos los procesos en TTL + 30s memo.
 *
 * Companion a TranscriptorSettings; no se fusionaron porque tienen
 * dominios distintos (transcriptor pipeline vs LLM suggester) y las
 * evoluciones futuras no tienen por qué coincidir.
 */
class LlmCorrectionSettings
{
    private const CACHE_KEY = 'llm_correction:settings';
    private const CACHE_TTL_SECONDS = 60;
    private const MEMO_TTL_SECONDS = 30;

    /**
     * Prefijo de clave compartido entre:
     *   - system_settings (BD) — para los overrides de UI
     *   - config/llm-correction.php — para los defaults / env fallbacks
     *
     * Usar dash (-) para match exacto con el nombre del archivo de config.
     * Si se cambiara el KEY_PREFIX, las filas existentes en system_settings
     * quedarían huérfanas (no se leerían). Como el feature es nuevo, no hay
     * legado que migrar.
     */
    private const KEY_PREFIX = 'llm-correction.';

    /**
     * type: bool | int | float | str
     * source: env (.env, deploy-level) | bd (override via UI) | archivo (config literal)
     */
    private const SCHEMA = [
        'enabled' => [
            'type' => 'bool', 'default' => true,
            'env_key' => 'LLM_CORRECTION_ENABLED',
            'label' => 'Habilitado',
            'help' => 'Switch maestro. false = el suggester sale sin gastar tokens, manual o vía cron.',
        ],
        'model' => [
            'type' => 'str', 'default' => 'minimax/minimax-m3',
            'env_key' => 'LLM_MODEL',
            // Las opciones llegan dinámicamente del gateway via /v1/models;
            // si la API falla, el fallback en availableModels() cubre los más comunes.
            'options_source' => 'gateway',
            'label' => 'Modelo LLM',
            'help' => 'Modelo a invocar vía chat completions. Lista fetched de {base_url}/models.',
        ],
        'base_url' => [
            'type' => 'str', 'default' => 'https://api.kilo.ai/api/gateway',
            'env_key' => 'LLM_BASE_URL',
            'label' => 'Base URL del gateway',
            'help' => 'Endpoint OpenAI-compatible. Default Kilo Gateway (https://api.kilo.ai/api/gateway). Cambiar para apuntar a otro proveedor sin redeploy.',
        ],
        'days_back' => [
            'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 90,
            'env_key' => 'LLM_DAYS_BACK_DEFAULT',
            'label' => 'Ventana de análisis (días)',
            'help' => 'Cuántos días hacia atrás se muestrean segmentos. Default: 1 (solo hoy). Coherente con quickActionWindows.',
        ],
        'sample_size' => [
            'type' => 'int', 'default' => 200, 'min' => 10, 'max' => 1000,
            'env_key' => 'LLM_SAMPLE_SIZE_DEFAULT',
            'label' => 'Muestra de segmentos',
            'help' => 'Cuántos segmentos del período se envían al LLM. Default: 200 (~3-5k tokens prompt).',
        ],
        'temperature' => [
            'type' => 'float', 'default' => 0.2, 'min' => 0.0, 'max' => 1.0,
            'env_key' => 'LLM_TEMPERATURE',
            'label' => 'Temperatura',
            'help' => '0.0 = determinista, 1.0 = creativo. Default 0.2 minimiza alucinaciones.',
        ],
        'auto_approve' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Auto-aprobar correcciones del AI Suggest',
            'help' => 'Si true, el suggester inserta con status=approved en lugar de pending. El filtro defensivo de marcas sigue aplicando; el admin puede revertir cualquier auto-aprobación con el botón Eliminar de la tabla Aprobadas. Apágalo desde aquí si prefieres revisar manualmente antes de aprobar.',
        ],
        'timeout_seconds' => [
            'type' => 'int', 'default' => 60, 'min' => 10, 'max' => 600,
            'env_key' => 'LLM_TIMEOUT_SECONDS',
            'label' => 'Timeout HTTP (s)',
            'help' => 'Tiempo máximo de espera al gateway antes de cortar la request.',
        ],
        'max_tokens' => [
            'type' => 'int', 'default' => 4000, 'min' => 500, 'max' => 16000,
            'env_key' => 'LLM_MAX_TOKENS',
            'label' => 'Max tokens en la respuesta',
            'help' => 'Tope del response (evita runaway del modelo que dispara costo).',
        ],
        'custom_model_ids' => [
            'type' => 'str', 'default' => '',
            // No tiene env_key: es solo UI-managed (admin lo edita a mano).
            'label' => 'Modelos personalizados (CSV)',
            'help' => 'IDs adicionales que el admin conoce de su cuenta (BYOK como OllamaCloud, modelos privados, etc.). Separados por coma o salto de línea. No aparecen en el /models público.',
        ],
        'quick_action_windows' => [
            'type' => 'str', 'default' => '1, 3, 7',
            // Lista de días para los botones 1-click del header. Admin la edita
            // desde AI Settings → "Botones rápidos". Vacío = solo el botón 'Hoy'.
            'label' => 'Botones rápidos (días)',
            'help' => 'Lista de ventanas en días para los botones 1-click del header (⚡). Ej: "1, 3, 7" muestra 3 botones: Hoy, 3d, 7d. Vacío = solo Hoy (1d). Rango válido: 1-90 días.',
        ],

        // === Segundo proveedor (Ollama Cloud) para distribuir carga ===
        // (2026-08-16) El gateway de Kilo limita la tasa (HTTP 429). Ollama Cloud
        // con deepseek-v4-flash es más rápido y no comparte el rate limit. El
        // pase de coherencia alterna entre proveedores (round-robin) para
        // duplicar el throughput.
        'secondary_enabled' => [
            'type' => 'bool', 'default' => false,
            'env_key' => 'LLM_SECONDARY_ENABLED',
            'label' => 'Segundo proveedor habilitado',
            'help' => 'Habilita Ollama Cloud como segundo proveedor para distribuir la carga del pase de coherencia.',
        ],
        'secondary_base_url' => [
            'type' => 'str', 'default' => 'https://ollama.com/v1',
            'env_key' => 'LLM_SECONDARY_BASE_URL',
            'label' => 'Base URL del segundo proveedor',
            'help' => 'Endpoint OpenAI-compatible de Ollama Cloud.',
        ],
        'secondary_api_key' => [
            'type' => 'str', 'default' => '',
            'env_key' => 'LLM_SECONDARY_API_KEY',
            'label' => 'API key del segundo proveedor',
            'help' => 'API key de Ollama Cloud.',
        ],
        'secondary_model' => [
            'type' => 'str', 'default' => 'deepseek-v4-flash:0731',
            'env_key' => 'LLM_SECONDARY_MODEL',
            'label' => 'Modelo del segundo proveedor',
            'help' => 'Modelo a invocar en Ollama Cloud (ej. deepseek-v4-flash:0731).',
        ],

        // === Tercer proveedor (MiniMax.io) ===
        // (2026-08-16) MiniMax.io con MiniMax-M2.7 es idóneo para corrección de
        // texto. Se agrega como tercer proveedor para distribuir aún más la carga.
        'tertiary_enabled' => [
            'type' => 'bool', 'default' => false,
            'env_key' => 'LLM_TERTIARY_ENABLED',
            'label' => 'Tercer proveedor habilitado',
            'help' => 'Habilita MiniMax.io como tercer proveedor para distribuir la carga del pase de coherencia.',
        ],
        'tertiary_base_url' => [
            'type' => 'str', 'default' => 'https://api.minimax.io/v1',
            'env_key' => 'LLM_TERTIARY_BASE_URL',
            'label' => 'Base URL del tercer proveedor',
            'help' => 'Endpoint OpenAI-compatible de MiniMax.io.',
        ],
        'tertiary_api_key' => [
            'type' => 'str', 'default' => '',
            'env_key' => 'LLM_TERTIARY_API_KEY',
            'label' => 'API key del tercer proveedor',
            'help' => 'API key de MiniMax.io.',
        ],
        'tertiary_model' => [
            'type' => 'str', 'default' => 'MiniMax-M2.7',
            'env_key' => 'LLM_TERTIARY_MODEL',
            'label' => 'Modelo del tercer proveedor',
            'help' => 'Modelo a invocar en MiniMax.io (ej. MiniMax-M2.7, MiniMax-M2.5).',
        ],
    ];

    /** @var array<string,string> */
    private array $memo = [];
    private float $memoLoadedAt = 0.0;

    // ---------------------------------------------------------------- lectura

    /**
     * Lista de modelos disponibles en el gateway, fetched de la API,
     * mergeada con `custom_model_ids` configurados por el admin (BYOK
     * que el /models público no expone).
     *
     * Cache de 1h para la lista pública. Los custom se re-leen siempre
     * (BD es la fuente de verdad y el admin los edita vía UI).
     *
     * Cada modelo está enriquecido con metadatos relevantes para que la
     * UI pueda mostrar al admin contexto suficiente para elegir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableModels(): array
    {
        try {
            $cached = \Illuminate\Support\Facades\Cache::remember(
                'llm_correction:available_models',
                3600,
                fn () => $this->fetchModelsFromApi()
            );
        } catch (\Throwable $e) {
            $cached = [];
        }

        $public = !empty($cached) ? $cached : $this->fallbackModels();

        return $this->mergeCustomModels($public);
    }

    /**
     * Toma la lista del gateway (o fallback) y le agrega los custom_model_ids
     * que el admin mantiene manualmente. Reglas:
     *   - Si el id custom YA está en la lista pública, se marca `is_custom=true`
     *     pero NO se duplica.
     *   - Si no está, se crea un objeto normalizado con placeholders
     *     (name = id, precios null, etc.) y `is_custom=true`.
     *
     * @param array<int, array<string, mixed>> $public
     * @return array<int, array<string, mixed>>
     */
    private function mergeCustomModels(array $public): array
    {
        $customIds = $this->customModelIds();
        if (empty($customIds)) {
            // Aun así, marcamos los públicos con is_custom=false para UI.
            foreach ($public as &$m) {
                $m['is_custom'] = false;
            }
            unset($m);
            return $public;
        }

        // Indexar públicos por id para O(1) lookup.
        $byId = [];
        foreach ($public as $i => $m) {
            $byId[$m['id']] = $i;
            $m['is_custom'] = false;
            $public[$i] = $m;
        }

        $out = $public;
        foreach ($customIds as $cid) {
            if (isset($byId[$cid])) {
                // Ya existe en público → marcar como custom-aware pero no duplicar.
                $idx = $byId[$cid];
                $public[$idx]['is_custom'] = true;
                $out[$idx] = $public[$idx];
                continue;
            }
            // Custom-only: crear objeto normalizado placeholder.
            $out[] = $this->normalizeModel([
                'id' => $cid,
                'name' => $cid,
                'description' => 'Modelo personalizado (BYOK o agregado manualmente). Sin metadatos del gateway.',
                'architecture' => [],
                'top_provider' => [],
                'pricing' => [],
                'is_custom_only' => true,
            ]);
            // Sobrescribir is_custom (normalizeModel no lo setea; default false).
            $out[array_key_last($out)]['is_custom'] = true;
        }

        // Re-sort alfabéticamente preservando orden estable.
        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $out;
    }

    /**
     * Fuerza un refetch desde la API (botón "Refrescar modelos" en UI).
     *
     * @return array<int, array<string, mixed>>
     */
    public function refreshModels(): array
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');
        return $this->availableModels();
    }

    /**
     * GET {base_url}/models con auth opcional. Retorna array vacío si falla,
     * deja que el caller decida el fallback.
     *
     * Kilo Gateway expone /models como endpoint público (no requiere auth
     * según docs/gateway). Aun así, si hay api_key configurada, la enviamos
     * en el header — el gateway la ignora para este endpoint, pero permite
     * consistencia con futuras gateways que sí la requieran.
     *
     * Devuelve un array enriquecido por modelo con:
     *   id, name, provider, modality, input_modalities, output_modalities,
     *   context_length, max_completion_tokens, pricing (prompt/completion
     *   por MTok USD), is_free, may_train_on_prompts, terminalbench_score,
     *   supported_parameters, description (truncada).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchModelsFromApi(): array
    {
        $apiKey = $this->apiKey();
        $baseUrl = rtrim($this->str('base_url'), '/');
        if ($baseUrl === '') {
            return [];
        }

        try {
            $request = \Illuminate\Support\Facades\Http::acceptJson()->timeout(15);
            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }
            $resp = $request->get($baseUrl . '/models');
        } catch (\Throwable $e) {
            return [];
        }

        if (!$resp->successful()) {
            return [];
        }

        $body = $resp->json();
        $data = $body['data'] ?? null;
        if (!is_array($data) || empty($data)) {
            return [];
        }

        $models = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = $this->normalizeModel($entry);
            if ($normalized !== null) {
                $models[] = $normalized;
            }
        }

        usort($models, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $models;
    }

    /**
     * Convierte el shape crudo del gateway a un objeto normalizado con los
     * campos que la UI necesita para mostrar y filtrar.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private function normalizeModel(array $entry): ?array
    {
        $id = $entry['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $name = is_string($entry['name'] ?? null) ? $entry['name'] : $id;
        $description = is_string($entry['description'] ?? null) ? $entry['description'] : '';
        if (mb_strlen($description) > 240) {
            $description = mb_substr($description, 0, 237) . '...';
        }

        $arch = $entry['architecture'] ?? [];
        $top = $entry['top_provider'] ?? [];
        $pricing = $entry['pricing'] ?? [];
        $terminal = $entry['terminalBench'] ?? [];

        $provider = null;
        if (str_contains($id, '/')) {
            $provider = strtolower(strtok($id, '/')) ?: null;
        }

        $promptUsd = isset($pricing['prompt']) && is_numeric($pricing['prompt'])
            ? round(((float) $pricing['prompt']) * 1_000_000, 4)
            : null;
        $completionUsd = isset($pricing['completion']) && is_numeric($pricing['completion'])
            ? round(((float) $pricing['completion']) * 1_000_000, 4)
            : null;

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'provider' => $provider,
            'modality' => is_string($arch['modality'] ?? null) ? $arch['modality'] : null,
            'input_modalities' => is_array($arch['input_modalities'] ?? null) ? array_values(array_filter($arch['input_modalities'], 'is_string')) : [],
            'output_modalities' => is_array($arch['output_modalities'] ?? null) ? array_values(array_filter($arch['output_modalities'], 'is_string')) : [],
            'context_length' => is_int($arch['context_length'] ?? null) ? $arch['context_length'] : (is_int($top['context_length'] ?? null) ? $top['context_length'] : null),
            'max_completion_tokens' => is_int($top['max_completion_tokens'] ?? null) ? $top['max_completion_tokens'] : null,
            'pricing_prompt_usd_per_mtok' => $promptUsd,
            'pricing_completion_usd_per_mtok' => $completionUsd,
            'is_free' => (bool) ($entry['isFree'] ?? false),
            'may_train_on_prompts' => (bool) ($entry['mayTrainOnYourPrompts'] ?? false),
            'terminalbench_score' => isset($terminal['overallScore']) && is_numeric($terminal['overallScore']) ? round((float) $terminal['overallScore'], 4) : null,
            'supports_tools' => in_array('tools', $entry['supported_parameters'] ?? [], true),
            'supports_vision' => in_array('image', $arch['input_modalities'] ?? [], true),
            'supports_reasoning' => in_array('reasoning', $entry['supported_parameters'] ?? [], true),
            'preferred_index' => is_int($entry['preferredIndex'] ?? null) ? $entry['preferredIndex'] : null,
            'is_custom' => (bool) ($entry['is_custom_only'] ?? false),
        ];
    }

    /**
     * Fallback cuando /models falla. Shape equivalente al normalizado pero
     * con campos informativos vacíos — solo el id y nombre.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallbackModels(): array
    {
        $current = $this->str('model');
        $known = [
            ['id' => 'minimax/minimax-m3', 'name' => 'minimax/minimax-m3'],
            ['id' => 'minimax/minimax-m2', 'name' => 'minimax/minimax-m2'],
            ['id' => 'minimax/minimax-m', 'name' => 'minimax/minimax-m'],
            ['id' => 'gpt-4o-mini', 'name' => 'gpt-4o-mini'],
            ['id' => 'gpt-4o', 'name' => 'gpt-4o'],
            ['id' => 'claude-3-5-haiku-20241022', 'name' => 'claude-3-5-haiku-20241022'],
            ['id' => 'claude-3-5-sonnet-20241022', 'name' => 'claude-3-5-sonnet-20241022'],
        ];

        $out = [];
        foreach ($known as $entry) {
            $out[] = $this->normalizeModel(array_merge($entry, [
                'architecture' => [],
                'top_provider' => [],
                'pricing' => [],
            ]));
        }

        if ($current !== '' && !in_array($current, array_column($out, 'id'), true)) {
            array_unshift($out, $this->normalizeModel(array_merge(
                ['id' => $current, 'name' => $current],
                ['architecture' => [], 'top_provider' => [], 'pricing' => []],
            )));
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        $spec = self::SCHEMA[$key] ?? null;
        if ($spec === null) {
            return config(self::KEY_PREFIX . $key);
        }

        $map = $this->map();
        $prefixedKey = self::KEY_PREFIX . $key;

        $raw = $map[$prefixedKey]
            ?? config(self::KEY_PREFIX . $key, $spec['default']);

        return $this->coerce($raw, $spec);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function str(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * Modelos personalizados configurados por el admin (separados por coma
     * o salto de línea). Vacío por default. Se mergean con la lista del
     * gateway en `availableModels()` para que el admin pueda usar modelos
     * BYOK (ej. OllamaCloud en Kilo) que el /models público no expone.
     *
     * @return array<int, string>
     */
    public function customModelIds(): array
    {
        $raw = $this->str('custom_model_ids');
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Dedup + trim + lowercased-irrelevant; mantener orden.
        $seen = [];
        $out = [];
        foreach ($parts as $p) {
            $v = trim($p);
            if ($v === '') {
                continue;
            }
            $key = strtolower($v);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Ventanas en días para los botones 1-click del header del AI Suggest
     * tab. Default: [1, 3, 7] si el admin no ha configurado nada.
     *
     * El admin edita este setting en AI Settings → "Botones rápidos".
     * Parsea una lista separada por coma o salto de línea, deduplicando
     * y ordenando ascendentemente. Cada valor debe estar entre 1 y 30 días
     * (los valores fuera de rango se descartan silenciosamente para
     * evitar errores del admin).
     *
     * @return array<int, int>
     */
    public function quickActionWindows(): array
    {
        $raw = $this->str('quick_action_windows');
        if ($raw === '') {
            return [1, 3, 7];
        }
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $seen = [];
        $out = [];
        foreach ($parts as $p) {
            $v = (int) trim($p);
            // Rango útil: 1d–90d. Cubre presets comunes (1, 3, 7, 14, 30) y
            // mensuales (60, 90). Más de 90 ya es histórico puro y se cubre
            // con applyRetroactive sin pasar por el LLM.
            if ($v < 1 || $v > 90) {
                continue;
            }
            if (isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $out[] = $v;
        }
        sort($out);
        return $out ?: [1];
    }

    /**
     * API key con precedencia: SystemSetting (encrypted via Crypt) > .env.
     *
     * Razón: por seguridad la key NUNCA debería ir en plaintext en BD; pero
     * permitir setearla desde la UI (encriptada) es útil cuando el operador
     * no tiene acceso SSH al .env. La key queda cifrada con la APP_KEY de
     * Laravel; alguien con dump de BD + acceso a APP_KEY puede descifrarla
     * (es la misma garantía de .env cifrado, pero evita exponer la key en
     * processes compartidos / logs accidentales).
     */
    public function apiKey(): string
    {
        $encrypted = \Illuminate\Support\Facades\Cache::remember(
            'llm_correction:api_key',
            60,
            fn () => \App\Models\SystemSetting::where('key', self::KEY_PREFIX . 'api_key')
                ->value('value')
        );

        if (!empty($encrypted)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($encrypted);
            } catch (\Throwable $e) {
                // Si falla el decrypt (APP_KEY cambió o valor corrupto),
                // caemos al .env en vez de romper todo el sistema.
                Log::warning('LlmCorrectionSettings: api_key en BD no se pudo descifrar, usando .env: ' . $e->getMessage());
            }
        }

        return (string) (config('llm-correction.api_key') ?? '');
    }

    /**
     * Persiste la API key cifrada en SystemSetting. Si $value está vacío,
     * borra la fila (cayendo al .env).
     */
    public function setApiKey(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            \App\Models\SystemSetting::where('key', self::KEY_PREFIX . 'api_key')->delete();
            \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
            return false;
        }
        $encrypted = \Illuminate\Support\Facades\Crypt::encryptString($value);
        \App\Models\SystemSetting::set(self::KEY_PREFIX . 'api_key', $encrypted);
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        return true;
    }

    /**
     * Estado del origen de la API key para la UI (sin exponer el valor).
     * - 'override_encrypted': viene de SystemSetting cifrado
     * - 'env': viene de LLM_API_KEY en .env
     * - 'none': ninguna configurada
     */
    public function apiKeySource(): string
    {
        $row = \App\Models\SystemSetting::where('key', self::KEY_PREFIX . 'api_key')->value('value');
        if (!empty($row)) {
            return 'override_encrypted';
        }
        return !empty(config('llm-correction.api_key')) ? 'env' : 'none';
    }

    // --------------------------------------------------------------- escritura

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed> mapa efectivo tras aplicar
     *
     * @throws ValidationException
     */
    public function set(array $values): array
    {
        [$clean, $errors] = $this->validate($values);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($clean as $key => $value) {
            SystemSetting::set(
                self::KEY_PREFIX . $key,
                $this->serialize($value, self::SCHEMA[$key])
            );
        }

        $this->flush();

        return $this->effectiveValues();
    }

    /**
     * @param  list<string>  $keys  vacío = todas
     */
    public function reset(array $keys = []): array
    {
        $keys = $keys ?: array_keys(self::SCHEMA);
        $prefixed = array_map(
            fn (string $k) => self::KEY_PREFIX . $k,
            array_values(array_intersect($keys, array_keys(self::SCHEMA)))
        );

        if ($prefixed) {
            SystemSetting::whereIn('key', $prefixed)->delete();
        }

        $this->flush();

        return $this->effectiveValues();
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array{0: array<string,mixed>, 1: array<string,list<string>>}
     */
    public function validate(array $values): array
    {
        $clean = [];
        $errors = [];

        foreach ($values as $key => $value) {
            $spec = self::SCHEMA[$key] ?? null;
            if ($spec === null) {
                $errors[$key] = ["La clave '{$key}' no existe."];
                continue;
            }

            $error = $this->validateOne($key, $value, $spec);
            if ($error !== null) {
                $errors[$key] = [$error];
                continue;
            }

            $clean[$key] = $this->coerce($value, $spec);
        }

        return [$clean, $errors];
    }

    // ------------------------------------------------------------ introspeccion

    /** @return array<string,string> */
    public function validationRules(): array
    {
        $rules = [];
        foreach (self::SCHEMA as $key => $spec) {
            $r = ['sometimes'];
            if ($spec['type'] === 'bool') {
                $r[] = 'boolean';
            } elseif ($spec['type'] === 'int') {
                $r[] = 'integer';
                if (isset($spec['min'])) $r[] = 'min:' . $spec['min'];
                if (isset($spec['max'])) $r[] = 'max:' . $spec['max'];
            } elseif ($spec['type'] === 'float') {
                $r[] = 'numeric';
                if (isset($spec['min'])) $r[] = 'min:' . $spec['min'];
                if (isset($spec['max'])) $r[] = 'max:' . $spec['max'];
            } else {
                // Para strings largos (CSV de modelos), допускаем hasta 4000 chars.
                $r[] = 'string|max:4000';
                if (isset($spec['options'])) {
                    $r[] = 'in:' . implode(',', $spec['options']);
                }
            }
            $rules[$key] = implode('|', $r);
        }
        return $rules;
    }

    /**
     * @return array<string, array{
     *   key: string, value: mixed, default: mixed, source: 'bd'|'env'|'archivo',
     *   type: string, label: string, help: string,
     *   options?: list<string>, min?: int|float, max?: int|float
     * }>
     */
    public function effective(): array
    {
        $map = $this->map();
        $out = [];

        foreach (self::SCHEMA as $key => $spec) {
            $prefixedKey = self::KEY_PREFIX . $key;
            $hasDbRow = array_key_exists($prefixedKey, $map);

            $envValue = isset($spec['env_key']) ? env($spec['env_key']) : null;
            $source = $hasDbRow ? 'bd' : ($envValue !== null ? 'env' : 'archivo');

            $entry = [
                'key' => $key,
                'value' => $this->get($key),
                'default' => $spec['default'],
                'source' => $source,
                'type' => $spec['type'],
                'label' => $spec['label'],
                'help' => $spec['help'],
            ];
            if (isset($spec['options'])) $entry['options'] = $spec['options'];
            if (isset($spec['options_source']) && $spec['options_source'] === 'gateway') {
                // Lista dinámica del gateway — fallará al fallback si API no responde.
                $entry['options'] = array_column($this->availableModels(), 'id');
                $entry['options_source'] = 'gateway';
                $entry['options_meta'] = $this->availableModels();
            }
            if (isset($spec['min'])) $entry['min'] = $spec['min'];
            if (isset($spec['max'])) $entry['max'] = $spec['max'];

            $out[$key] = $entry;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function effectiveValues(): array
    {
        $out = [];
        foreach (array_keys(self::SCHEMA) as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::SCHEMA);
    }

    public function has(string $key): bool
    {
        return isset(self::SCHEMA[$key]);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->memo = [];
        $this->memoLoadedAt = 0.0;
    }

    // ---------------------------------------------------------------- internos

    /**
     * Cache compartido Redis con memo en proceso. Mismo TTL que TranscriptorSettings.
     * @return array<string,string>
     */
    private function map(): array
    {
        if ($this->memo !== [] && (microtime(true) - $this->memoLoadedAt) < self::MEMO_TTL_SECONDS) {
            return $this->memo;
        }

        try {
            $this->memo = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                fn () => SystemSetting::query()
                    ->where('key', 'like', self::KEY_PREFIX . '%')
                    ->pluck('value', 'key')
                    ->all()
            );
        } catch (\Throwable $e) {
            // Sin BD ni cache seguimos con defaults de config. Que artisan siga
            // funcionando con la base caida es deliberado (consistente con TranscriptorSettings).
            $this->memo = [];
        }

        $this->memoLoadedAt = microtime(true);

        return $this->memo;
    }

    private function validateOne(string $key, mixed $value, array $spec): ?string
    {
        if ($spec['type'] === 'int') {
            if (!is_numeric($value)) {
                return "Debe ser entero.";
            }
            $v = (int) $value;
            if (isset($spec['min']) && $v < $spec['min']) {
                return "Mínimo {$spec['min']}.";
            }
            if (isset($spec['max']) && $v > $spec['max']) {
                return "Máximo {$spec['max']}.";
            }
        } elseif ($spec['type'] === 'float') {
            if (!is_numeric($value)) {
                return "Debe ser numérico.";
            }
            $v = (float) $value;
            if (isset($spec['min']) && $v < $spec['min']) {
                return "Mínimo {$spec['min']}.";
            }
            if (isset($spec['max']) && $v > $spec['max']) {
                return "Máximo {$spec['max']}.";
            }
        } elseif ($spec['type'] === 'bool') {
            if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                return "Debe ser booleano.";
            }
        } else {
            if (!is_string($value)) {
                return "Debe ser texto.";
            }
            if (isset($spec['options']) && !in_array($value, $spec['options'], true)) {
                return "Debe ser uno de: " . implode(', ', $spec['options']) . '.';
            }
        }
        return null;
    }

    private function coerce(mixed $raw, array $spec): mixed
    {
        if ($spec['type'] === 'bool') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $spec['default'];
        }
        if ($spec['type'] === 'int') {
            return (int) $raw;
        }
        if ($spec['type'] === 'float') {
            return (float) $raw;
        }
        $v = (string) $raw;
        if (isset($spec['options']) && !in_array($v, $spec['options'], true)) {
            return $spec['default'];
        }
        return $v;
    }

    private function serialize(mixed $value, array $spec): string
    {
        if ($spec['type'] === 'bool') {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }
        return (string) $value;
    }
}
