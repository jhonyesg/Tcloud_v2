<?php

namespace Tests\Feature;

use App\Services\Ia\LlmCorrectionSettings;
use Tests\LaravelTestCase;

/**
 * Tests de LlmCorrectionSettings: validación, persistencia y round-trip
 * BD > config > default. NO tocan BD real (mockean config y SystemSetting
 * indirectamente via cache).
 */
class LlmCorrectionSettingsTest extends LaravelTestCase
{
    /**
     * Keys de system_settings que tests escriben. Limpiamos antes Y después
     * para evitar state leakage entre tests corriendo en el mismo proceso.
     */
    private const SYSTEM_SETTING_KEYS_TO_CLEAN = [
        'llm-correction.enabled',
        'llm-correction.model',
        'llm-correction.base_url',
        'llm-correction.days_back',
        'llm-correction.sample_size',
        'llm-correction.temperature',
        'llm-correction.timeout_seconds',
        'llm-correction.max_tokens',
        'llm-correction.custom_model_ids',
        'llm-correction.api_key',
    ];

    private const CACHE_KEYS_TO_CLEAN = [
        'llm_correction:settings',
        'llm_correction:available_models',
        'llm_correction:api_key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanState();
    }

    protected function tearDown(): void
    {
        $this->cleanState();
        parent::tearDown();
    }

    private function cleanState(): void
    {
        foreach (self::CACHE_KEYS_TO_CLEAN as $ck) {
            \Illuminate\Support\Facades\Cache::forget($ck);
        }
        \Illuminate\Support\Facades\DB::table('system_settings')
            ->whereIn('key', self::SYSTEM_SETTING_KEYS_TO_CLEAN)
            ->delete();
    }

    private function makeSettings(): LlmCorrectionSettings
    {
        return new LlmCorrectionSettings();
    }

    // ============ schema ============

    public function test_keys_includes_all_expected_settings(): void
    {
        $s = $this->makeSettings();
        $this->assertContains('enabled', $s->keys());
        $this->assertContains('model', $s->keys());
        $this->assertContains('base_url', $s->keys());
        $this->assertContains('days_back', $s->keys());
        $this->assertContains('sample_size', $s->keys());
        $this->assertContains('temperature', $s->keys());
        $this->assertContains('timeout_seconds', $s->keys());
        $this->assertContains('max_tokens', $s->keys());
    }

    public function test_has_returns_true_for_known_keys(): void
    {
        $s = $this->makeSettings();
        $this->assertTrue($s->has('model'));
        $this->assertTrue($s->has('enabled'));
        $this->assertFalse($s->has('nonexistent_key'));
    }

    // ============ default resolution ============

    public function test_default_resolution_for_model(): void
    {
        config()->set('llm-correction.model', null);
        config()->set('llm-correction.model', 'minimax/minimax-m3');
        $s = $this->makeSettings();
        $this->assertSame('minimax/minimax-m3', $s->str('model'));
    }

    public function test_api_key_always_from_env_never_db(): void
    {
        // Limpiar cualquier override cifrado de runs anteriores.
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();
        config()->set('llm-correction.api_key', 'sk-from-env');
        $s = $this->makeSettings();
        $this->assertSame('sk-from-env', $s->apiKey());
    }

    public function test_api_key_empty_when_not_configured(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();
        config()->set('llm-correction.api_key', null);
        $s = $this->makeSettings();
        $this->assertSame('', $s->apiKey());
    }

    // ============ type coercion ============

    public function test_str_helper_coerces_to_string(): void
    {
        $this->makeSettings()->flush();
        config()->set('llm-correction.base_url', 'https://test.example/v1');
        $s = $this->makeSettings();
        $this->assertSame('https://test.example/v1', config('llm-correction.base_url'));
        $this->assertSame('https://test.example/v1', $s->str('base_url'));
    }

    public function test_int_helper_coerces_to_int(): void
    {
        $this->makeSettings()->flush();
        config()->set('llm-correction.days_back', 5);
        $s = $this->makeSettings();
        $this->assertSame(5, $s->int('days_back'));
    }

    public function test_float_helper_coerces_to_float(): void
    {
        $this->makeSettings()->flush();
        config()->set('llm-correction.temperature', 0.3);
        $s = $this->makeSettings();
        $this->assertSame(0.3, $s->float('temperature'));
    }

    public function test_bool_helper_coerces_correctly(): void
    {
        config()->set('llm-correction.enabled', '1');
        $s = $this->makeSettings();
        $this->assertTrue($s->bool('enabled'));
    }

    // ============ validation ============

    public function test_validate_passes_for_valid_values(): void
    {
        $s = $this->makeSettings();
        [$clean, $errors] = $s->validate([
            'enabled' => true,
            'model' => 'minimax/minimax-m3',
            'days_back' => 7,
            'sample_size' => 500,
            'temperature' => 0.5,
        ]);
        $this->assertEmpty($errors);
        $this->assertSame(true, $clean['enabled']);
        $this->assertSame('minimax/minimax-m3', $clean['model']);
        $this->assertSame(7, $clean['days_back']);
    }

    public function test_validate_fails_for_unknown_key(): void
    {
        $s = $this->makeSettings();
        [$clean, $errors] = $s->validate([
            'nonexistent' => 'whatever',
        ]);
        $this->assertArrayHasKey('nonexistent', $errors);
        $this->assertEmpty($clean);
    }

    public function test_validate_model_no_longer_blocked_by_static_options(): void
    {
        // Desde 2026-08-01 (gateway /models), la lista de modelos es
        // dinámica — la validación local ya NO bloquea modelos no listados.
        // Esta es la garantía de que el dropdown refleja el gateway en
        // lugar de una whitelist hardcoded que se desactualizaría.
        $s = $this->makeSettings();
        [$clean, $errors] = $s->validate([
            'model' => 'cualquier-string-pasa-porque-gateway-autoriza',
        ]);
        $this->assertEmpty($errors, 'options_source=gateway: validación local no debe rechazar modelos custom');
    }

    public function test_validate_accepts_model_in_options(): void
    {
        $s = $this->makeSettings();
        [$clean, $errors] = $s->validate([
            'model' => 'gpt-4o-mini',
        ]);
        $this->assertEmpty($errors);
        $this->assertSame('gpt-4o-mini', $clean['model']);
    }

    public function test_validate_clamps_days_back_to_range(): void
    {
        $s = $this->makeSettings();

        // Bajo el mínimo.
        [$clean1] = $s->validate(['days_back' => 0]);
        $this->assertArrayNotHasKey('days_back', $clean1); // falla validación, no entra

        // Sobre el máximo.
        [$clean2, $errors2] = $s->validate(['days_back' => 100]);
        $this->assertArrayHasKey('days_back', $errors2);
    }

    public function test_validate_clamps_sample_size_range(): void
    {
        $s = $this->makeSettings();
        [$clean, $errors] = $s->validate(['sample_size' => 5000]);
        $this->assertArrayHasKey('sample_size', $errors);

        [$clean2] = $s->validate(['sample_size' => 100]);
        $this->assertSame(100, $clean2['sample_size']);
    }

    public function test_validate_accepts_valid_temperature_range(): void
    {
        $s = $this->makeSettings();
        [$clean1] = $s->validate(['temperature' => 0.0]);
        [$clean2] = $s->validate(['temperature' => 1.0]);
        [$clean3] = $s->validate(['temperature' => 0.5]);
        $this->assertSame(0.0, $clean1['temperature']);
        $this->assertSame(1.0, $clean2['temperature']);
        $this->assertSame(0.5, $clean3['temperature']);

        [$_, $errors] = $s->validate(['temperature' => 1.5]);
        $this->assertArrayHasKey('temperature', $errors);
    }

    public function test_validation_rules_returns_one_per_schema_key(): void
    {
        $s = $this->makeSettings();
        $rules = $s->validationRules();
        foreach ($s->keys() as $key) {
            $this->assertArrayHasKey($key, $rules, "validationRules debe incluir {$key}");
        }
    }

    // ============ effective ============

    public function test_effective_returns_meta_for_each_key(): void
    {
        $s = $this->makeSettings();
        $eff = $s->effective();

        $this->assertArrayHasKey('model', $eff);
        $entry = $eff['model'];
        $this->assertSame('model', $entry['key']);
        $this->assertSame('minimax/minimax-m3', $entry['default']);
        $this->assertContains($entry['source'], ['bd', 'env', 'archivo']);
        $this->assertSame('str', $entry['type']);
        $this->assertNotEmpty($entry['label']);
        $this->assertNotEmpty($entry['help']);
    }

    public function test_validate_accepts_model_in_dynamic_options(): void
    {
        // Con options_source: 'gateway' el schema NO tiene 'options' estático,
        // así que cualquier string es aceptado en validación. La API real
        // devolverá 4xx si el modelo no existe.
        $s = $this->makeSettings();
        [$clean, $errors] = $s->validate([
            'model' => 'gpt-5-future-model-that-doesnt-exist',
        ]);
        $this->assertEmpty($errors, 'Cualquier string debe pasar la validación cuando options_source=gateway');
        $this->assertSame('gpt-5-future-model-that-doesnt-exist', $clean['model']);
    }

    // ============ availableModels (dynamic /v1/models) ============

    public function test_available_models_returns_fallback_when_api_key_missing(): void
    {
        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.base_url', 'http://invalid-host-that-will-fail');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');
        $s = $this->makeSettings();
        $models = $s->availableModels();
        $this->assertNotEmpty($models, 'Fallback debe devolver al menos un modelo');
        $this->assertContains('minimax/minimax-m3', array_column($models, 'id'));
    }

    public function test_available_models_fallback_includes_current_model(): void
    {
        // Forzar base_url inválida para garantizar que fetchModelsFromApi falla
        // y se active la rama del fallback.
        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.model', 'totally-custom-model');
        config()->set('llm-correction.base_url', 'http://invalid-host-that-will-fail');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        $s = $this->makeSettings();
        $models = $s->availableModels();
        $ids = array_column($models, 'id');
        $this->assertContains('totally-custom-model', $ids,
            'Fallback debe incluir el modelo actualmente configurado aunque no esté en el hardcoded');
    }

    public function test_available_models_fetches_from_api_when_key_set(): void
    {
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        $fakeBody = json_encode([
            'object' => 'list',
            'data' => [
                ['id' => 'gpt-4o-mini', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'claude-3-haiku', 'object' => 'model', 'owned_by' => 'anthropic'],
                ['id' => 'minimax/minimax-m3', 'object' => 'model', 'owned_by' => 'kilo'],
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'example.test/*' => \Illuminate\Support\Facades\Http::response($fakeBody, 200),
        ]);

        $s = $this->makeSettings();
        $models = $s->availableModels();
        $this->assertCount(3, $models);
        $this->assertSame(['claude-3-haiku', 'gpt-4o-mini', 'minimax/minimax-m3'], array_column($models, 'id'),
            'Debe ordenar alfabéticamente para estabilidad UI');
    }

    public function test_available_models_returns_fallback_when_api_returns_error(): void
    {
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        \Illuminate\Support\Facades\Http::fake([
            'example.test/*' => \Illuminate\Support\Facades\Http::response('server error', 500),
        ]);

        $s = $this->makeSettings();
        $models = $s->availableModels();
        $this->assertNotEmpty($models, 'Fallback debe cubrir cuando la API falla');
        $this->assertContains('minimax/minimax-m3', array_column($models, 'id'));
    }

    public function test_available_models_returns_fallback_on_malformed_response(): void
    {
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        \Illuminate\Support\Facades\Http::fake([
            'example.test/*' => \Illuminate\Support\Facades\Http::response('{"foo":"bar"}', 200),
        ]);

        $s = $this->makeSettings();
        $models = $s->availableModels();
        $this->assertNotEmpty($models, 'Body sin campo `data` debe caer al fallback');
    }

    public function test_refresh_models_invalidates_cache(): void
    {
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');

        $callCount = 0;
        \Illuminate\Support\Facades\Http::fake([
            'example.test/*' => function () use (&$callCount) {
                $callCount++;
                return \Illuminate\Support\Facades\Http::response(json_encode([
                    'data' => [
                        ['id' => 'gpt-4o-mini', 'object' => 'model'],
                    ],
                ]), 200);
            },
        ]);

        $s = $this->makeSettings();
        $s->availableModels(); // 1
        $s->availableModels(); // 2 (cache hit)
        $s->refreshModels();    // invalida
        $s->availableModels(); // 3 (refetched)
        $this->assertGreaterThanOrEqual(2, $callCount, 'refreshModels debe forzar refetch');
    }

    public function test_effective_includes_dynamic_options_for_model(): void
    {
        config()->set('llm-correction.api_key', '');
        $s = $this->makeSettings();
        $eff = $s->effective();
        $this->assertArrayHasKey('options', $eff['model']);
        $this->assertNotEmpty($eff['model']['options']);
        $this->assertContains('minimax/minimax-m3', $eff['model']['options']);
    }

    public function test_effective_includes_options_meta_with_full_metadata(): void
    {
        config()->set('llm-correction.api_key', '');
        $s = $this->makeSettings();
        $eff = $s->effective();
        $this->assertArrayHasKey('options_meta', $eff['model']);
        $this->assertNotEmpty($eff['model']['options_meta']);
        $first = $eff['model']['options_meta'][0];
        // Cada modelo enriquecido debe tener al menos estos campos.
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('provider', $first);
        $this->assertArrayHasKey('context_length', $first);
        $this->assertArrayHasKey('is_free', $first);
        $this->assertArrayHasKey('supports_tools', $first);
        $this->assertArrayHasKey('supports_vision', $first);
    }

    // ============ Model normalization (enriched parsing) ============

    public function test_normalize_model_extracts_pricing_in_usd_per_mtok(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $normalized = $method->invoke($svc, [
            'id' => 'openai/gpt-4o-mini',
            'name' => 'GPT-4o Mini',
            'pricing' => ['prompt' => '0.00000015', 'completion' => '0.0000006'],
        ]);

        $this->assertSame(0.15, $normalized['pricing_prompt_usd_per_mtok'],
            'Pricing.prompt en Kilo viene en USD/token; debe convertirse a USD/MTok');
        $this->assertSame(0.6, $normalized['pricing_completion_usd_per_mtok']);
    }

    public function test_normalize_model_identifies_free_models(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $free = $method->invoke($svc, ['id' => 'stepfun/step-3.7-flash:free', 'isFree' => true]);
        $paid = $method->invoke($svc, ['id' => 'openai/gpt-4o-mini', 'isFree' => false]);

        $this->assertTrue($free['is_free']);
        $this->assertFalse($paid['is_free']);
    }

    public function test_normalize_model_extracts_modality_and_feature_flags(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $normalized = $method->invoke($svc, [
            'id' => 'anthropic/claude-sonnet-4',
            'architecture' => [
                'modality' => 'text+image+file->text',
                'input_modalities' => ['text', 'image', 'file'],
                'output_modalities' => ['text'],
            ],
            'supported_parameters' => ['tools', 'temperature', 'reasoning'],
        ]);

        $this->assertSame('text+image+file->text', $normalized['modality']);
        $this->assertSame(['text', 'image', 'file'], $normalized['input_modalities']);
        $this->assertTrue($normalized['supports_vision']);
        $this->assertTrue($normalized['supports_tools']);
        $this->assertTrue($normalized['supports_reasoning']);
    }

    public function test_normalize_model_returns_null_for_missing_id(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($svc, ['name' => 'no-id']));
        $this->assertNull($method->invoke($svc, []));
    }

    public function test_normalize_model_extracts_provider_from_id_prefix(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $cases = [
            'openai/gpt-4o-mini' => 'openai',
            'anthropic/claude-3.5' => 'anthropic',
            'google/gemini-pro' => 'google',
            'minimax/minimax-m3' => 'minimax',
            'no-slash-id' => null,
        ];

        foreach ($cases as $id => $expectedProvider) {
            $normalized = $method->invoke($svc, ['id' => $id]);
            $this->assertSame($expectedProvider, $normalized['provider'],
                "Provider for '{$id}' should be '{$expectedProvider}'");
        }
    }

    public function test_normalize_model_extracts_terminalbench_score(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $normalized = $method->invoke($svc, [
            'id' => 'anthropic/claude-sonnet-4.6',
            'terminalBench' => ['overallScore' => 0.5505618],
        ]);

        $this->assertSame(0.5506, $normalized['terminalbench_score']);
    }

    public function test_fallback_models_have_minimal_schema(): void
    {
        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.base_url', 'http://invalid-host-that-will-fail');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        $s = $this->makeSettings();
        $models = $s->availableModels();
        $this->assertNotEmpty($models);

        // Fallback debe tener la misma estructura mínima que el live.
        foreach ($models as $m) {
            $this->assertArrayHasKey('id', $m);
            $this->assertArrayHasKey('name', $m);
            $this->assertArrayHasKey('is_free', $m);
            $this->assertArrayHasKey('supports_tools', $m);
        }
    }

    // ============ Custom model IDs (BYOK / privados) ============

    public function test_custom_model_ids_parses_csv_with_commas(): void
    {
        config()->set('llm-correction.custom_model_ids', 'ollamacloud/glm-5.2,openai/gpt-custom,o');
        $s = $this->makeSettings();
        $ids = $s->customModelIds();
        $this->assertSame(['ollamacloud/glm-5.2', 'openai/gpt-custom', 'o'], $ids);
    }

    public function test_custom_model_ids_parses_csv_with_newlines(): void
    {
        config()->set('llm-correction.custom_model_ids',
            "ollamacloud/glm-5.2\nopenai/gpt-custom\nanthropic/private\n"
        );
        $s = $this->makeSettings();
        $ids = $s->customModelIds();
        $this->assertSame(['ollamacloud/glm-5.2', 'openai/gpt-custom', 'anthropic/private'], $ids);
    }

    public function test_custom_model_ids_dedupes_case_insensitive(): void
    {
        config()->set('llm-correction.custom_model_ids',
            'OllamaCloud/GLM-5.2,ollamacloud/glm-5.2,OPENAI/gpt,openai/gpt'
        );
        $s = $this->makeSettings();
        $ids = $s->customModelIds();
        $this->assertCount(2, $ids);
        // Debe mantener el primer casing.
        $this->assertSame('OllamaCloud/GLM-5.2', $ids[0]);
    }

    public function test_custom_model_ids_empty_string_returns_empty(): void
    {
        config()->set('llm-correction.custom_model_ids', '');
        $s = $this->makeSettings();
        $this->assertSame([], $s->customModelIds());
    }

    public function test_merge_adds_custom_models_not_in_public_list(): void
    {
        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.base_url', 'http://invalid-host-that-will-fail');
        config()->set('llm-correction.custom_model_ids', 'ollamacloud/glm-5.2,myprovider/private');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        $s = $this->makeSettings();
        $all = $s->availableModels();

        // Deben aparecer los 7 del fallback + 2 custom = 9
        $this->assertGreaterThanOrEqual(9, count($all));

        $byId = array_column($all, null, 'id');
        $this->assertArrayHasKey('ollamacloud/glm-5.2', $byId);
        $this->assertSame('ollamacloud/glm-5.2', $byId['ollamacloud/glm-5.2']['name']);
        $this->assertTrue($byId['ollamacloud/glm-5.2']['is_custom']);
        $this->assertNull($byId['ollamacloud/glm-5.2']['pricing_prompt_usd_per_mtok']);
        $this->assertNotEmpty($byId['ollamacloud/glm-5.2']['description']);
    }

    public function test_merge_marks_existing_public_model_as_custom_no_duplicate(): void
    {
        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.base_url', 'http://invalid-host-that-will-fail');
        config()->set('llm-correction.custom_model_ids', 'minimax/minimax-m3,ollamacloud/glm-5.2');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        $s = $this->makeSettings();
        $all = $s->availableModels();

        // minimax/minimax-m3 está en el fallback → marcado is_custom=true, no duplicado.
        $countMmp = 0;
        foreach ($all as $m) {
            if ($m['id'] === 'minimax/minimax-m3') {
                $countMmp++;
                $this->assertTrue($m['is_custom'],
                    'minimax/minimax-m3 (ya en fallback) debe marcar is_custom=true');
            }
        }
        $this->assertSame(1, $countMmp, 'No debe duplicarse cuando ya está en la lista pública');

        // El custom-only sí se agrega nuevo.
        $countGlm = 0;
        foreach ($all as $m) {
            if ($m['id'] === 'ollamacloud/glm-5.2') {
                $countGlm++;
            }
        }
        $this->assertSame(1, $countGlm, 'Custom-only debe aparecer exactamente una vez');
    }

    public function test_merge_marks_existing_public_models_with_is_custom_false_when_not_in_custom_ids(): void
    {
        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.base_url', 'http://invalid-host-that-will-fail');
        config()->set('llm-correction.custom_model_ids', '');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');

        $s = $this->makeSettings();
        $all = $s->availableModels();

        foreach ($all as $m) {
            $this->assertFalse($m['is_custom'],
                'Sin custom_model_ids configurados, todos los públicos deben tener is_custom=false');
        }
    }

    public function test_normalize_model_includes_is_custom_field(): void
    {
        $svc = $this->makeSettings();
        $method = new \ReflectionMethod($svc, 'normalizeModel');
        $method->setAccessible(true);

        $normalized = $method->invoke($svc, [
            'id' => 'unknown/custom-only',
            'name' => 'Custom',
            'is_custom_only' => true,
        ]);
        $this->assertTrue($normalized['is_custom']);

        $normalized2 = $method->invoke($svc, ['id' => 'openai/gpt-4o-mini']);
        $this->assertFalse($normalized2['is_custom']);
    }

    public function test_custom_setting_persists_via_validation_rules(): void
    {
        config()->set('llm-correction.api_key', '');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');
        $s = $this->makeSettings();

        // Save custom_model_ids via service.
        $updated = $s->set([
            'custom_model_ids' => 'a/x, b/y , c/z',
        ]);
        $this->assertSame('a/x, b/y , c/z', $updated['custom_model_ids']);

        // Re-instantiate should pull from BD.
        $s2 = new \App\Services\Ia\LlmCorrectionSettings();
        $this->assertSame('a/x, b/y , c/z', $s2->str('custom_model_ids'));
        $this->assertSame(['a/x', 'b/y', 'c/z'], $s2->customModelIds());
    }

    // ============ quick_action_windows ============

    public function test_quick_action_windows_default(): void
    {
        $s = $this->makeSettings();
        $this->assertSame([1, 3, 7], $s->quickActionWindows(),
            'Defaults a [1, 3, 7] si el admin no configura nada');
    }

    public function test_quick_action_windows_parses_csv_with_commas(): void
    {
        config()->set('llm-correction.quick_action_windows', '1, 3, 7, 14');
        $s = $this->makeSettings();
        $this->assertSame([1, 3, 7, 14], $s->quickActionWindows());
    }

    public function test_quick_action_windows_parses_newlines_and_dedupes(): void
    {
        config()->set('llm-correction.quick_action_windows', "1\n3\n7\n3\n14");
        $s = $this->makeSettings();
        // Dedup + sort.
        $this->assertSame([1, 3, 7, 14], $s->quickActionWindows());
    }

    public function test_quick_action_windows_drops_out_of_range(): void
    {
        // 0, -1, 91, 999 están fuera de rango (1-90) y se descartan.
        config()->set('llm-correction.quick_action_windows', '0, 1, -1, 91, 999, 7');
        $s = $this->makeSettings();
        $this->assertSame([1, 7], $s->quickActionWindows());
    }

    public function test_quick_action_windows_accepts_up_to_90_days(): void
    {
        // Cubrir presets mensuales que el admin pidió (60, 90).
        config()->set('llm-correction.quick_action_windows', '1, 3, 5, 7, 15, 30, 60, 90');
        $s = $this->makeSettings();
        $this->assertSame([1, 3, 5, 7, 15, 30, 60, 90], $s->quickActionWindows());
    }

    public function test_quick_action_windows_returns_at_least_1_when_all_out_of_range(): void
    {
        config()->set('llm-correction.quick_action_windows', '0, 91, 999');
        $s = $this->makeSettings();
        // Fallback al default: al menos 1 debe quedar.
        $this->assertNotEmpty($s->quickActionWindows());
        $this->assertContains(1, $s->quickActionWindows());
    }

    public function test_quick_action_windows_empty_string_uses_default(): void
    {
        config()->set('llm-correction.quick_action_windows', '');
        $s = $this->makeSettings();
        $this->assertSame([1, 3, 7], $s->quickActionWindows());
    }

    public function test_effective_includes_quick_action_windows(): void
    {
        config()->set('llm-correction.quick_action_windows', '1, 3, 7');
        $s = $this->makeSettings();
        $eff = $s->effective();
        $this->assertArrayHasKey('quick_action_windows', $eff);
        $this->assertSame('1, 3, 7', $eff['quick_action_windows']['value']);
    }

    // ============ API key encrypted override (SystemSetting via Crypt) ============

    public function test_api_key_prefers_encrypted_db_override_over_env(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();

        config()->set('llm-correction.api_key', 'sk-from-env');
        $s = $this->makeSettings();
        $s->setApiKey('sk-from-ui-override');

        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        $this->assertSame('sk-from-ui-override', $s->apiKey(),
            'El override UI cifrado debe preceder sobre el .env');
    }

    public function test_set_api_key_persists_encrypted_value(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();

        $s = $this->makeSettings();
        $s->setApiKey('sk-test-secret-12345');

        $stored = \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->value('value');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('sk-test-secret-12345',
            $stored,
            'BD debe guardar el valor cifrado, NO la API key en plaintext');
        $this->assertSame('sk-test-secret-12345', \Illuminate\Support\Facades\Crypt::decryptString($stored));
    }

    public function test_set_api_key_with_empty_string_clears_override(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();

        config()->set('llm-correction.api_key', 'sk-from-env');
        $s = $this->makeSettings();
        $s->setApiKey('sk-ui-set');
        $this->assertSame('sk-ui-set', $s->apiKey());

        // Empty string → borra la fila → cae al .env
        $s->setApiKey('   ');
        $this->assertSame('sk-from-env', $s->apiKey());
        $storedAfterClear = \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->value('value');
        $this->assertNull($storedAfterClear,
            'Empty/whitespace setApiKey debe borrar la fila cifrada de SystemSetting');
    }

    public function test_api_key_source_reports_encrypted(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();

        config()->set('llm-correction.api_key', '');
        $s = $this->makeSettings();
        $this->assertSame('none', $s->apiKeySource());

        config()->set('llm-correction.api_key', 'sk-env');
        $this->assertSame('env', $s->apiKeySource());

        $s->setApiKey('sk-ui-override');
        $this->assertSame('override_encrypted', $s->apiKeySource());
    }

    public function test_api_key_falls_back_to_env_when_decrypt_fails(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');

        // Simular valor corrupto/no descifrable.
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();
        \App\Models\SystemSetting::set('llm-correction.api_key', 'invalid-encrypted-garbage');

        config()->set('llm-correction.api_key', 'sk-fallback-env');
        $s = $this->makeSettings();
        $this->assertSame('sk-fallback-env', $s->apiKey(),
            'Si decrypt falla, debe caer al .env en lugar de tirar excepción');
    }

    public function test_available_models_uses_encrypted_override(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:available_models');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();

        config()->set('llm-correction.api_key', '');
        config()->set('llm-correction.base_url', 'http://example.test');

        // Persistir key vía el camino normal (cifrado).
        $s = $this->makeSettings();
        $s->setApiKey('sk-test-from-ui');
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');

        // API mockeada que verifica que el Bearer token es exactamente el override.
        \Illuminate\Support\Facades\Http::fake([
            'example.test/models' => \Illuminate\Support\Facades\Http::response(json_encode([
                'data' => [
                    ['id' => 'gpt-4', 'object' => 'model'],
                    ['id' => 'gpt-3.5-turbo', 'object' => 'model'],
                ],
            ]), 200),
        ]);

        $models = $s->availableModels();
        $this->assertCount(2, $models);
        $this->assertSame(['gpt-3.5-turbo', 'gpt-4'], array_column($models, 'id'));
    }

    public function test_effective_source_is_archivo_when_no_db_or_env(): void
    {
        config()->set('llm-correction.model', 'minimax/minimax-m3');
        $s = $this->makeSettings();
        $eff = $s->effective();
        $this->assertSame('archivo', $eff['model']['source']);
    }
}
