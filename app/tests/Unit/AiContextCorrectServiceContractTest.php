<?php

namespace Tests\Unit;

use App\Models\Correction;
use App\Services\Ia\AiContextCorrectService;
use App\Services\Ia\LlmCorrectionSettings;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;

/**
 * Tests de contratos para AiContextCorrectService (change:
 * corrections-ai-context-correct-inline). Validamos via introspection y
 * reflexión de la fuente porque el servicio toca HTTP/LLM/DB que no
 * podemos levantar en unit tests sin harness pesado.
 */
class AiContextCorrectServiceContractTest extends TestCase
{
    public function test_suggest_retorna_gate_si_settings_esta_deshabilitado(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString("if (!\$settings->bool('enabled'))", $source);
        $this->assertStringContainsString('Suggest deshabilitado desde Configuración / IA Suggest.', $source);
        $this->assertStringContainsString("'hint' => 'Activa el toggle \"Habilitado\" en el tab IA Suggest.'", $source);
    }

    public function test_suggest_retorna_gate_si_api_key_vacia(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString("if (\$settings->apiKey() === '')", $source);
        $this->assertStringContainsString('LLM_API_KEY no configurada.', $source);
    }

    public function test_suggest_reusa_cache_cuando_existe(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString('Cache::get($cacheKey)', $source);
        $this->assertStringContainsString("\$cached['cache'] = 'hit'", $source);
    }

    public function test_force_fresh_invalida_cache(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString('if ($forceFresh) {', $source);
        $this->assertStringContainsString('Cache::forget($cacheKey)', $source);
    }

    public function test_post_filtro_reusa_looksLikeBrandOrProperNoun(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        // Defensa-en-profundidad: el servicio DEBE invocar el método del
        // suggester global, no duplicar la lógica de marcas.
        $this->assertStringContainsString('looksLikeBrandOrProperNoun', $source);
        $this->assertStringContainsString('LlmCorrectionSuggester::class', $source);
    }

    public function test_approve_persiste_pending_con_source_correcto(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString('Correction::STATUS_PENDING', $source);
        $this->assertStringContainsString('ai-context-correct-{$today}', $source);
    }

    public function test_approve_rechaza_duplicados_con_conflict(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString("'status' => 'conflict'", $source);
        $this->assertStringContainsString("'existing_id'", $source);
        $this->assertStringContainsString("'Ya existe una corrección pending o approved", $source);
    }

    public function test_approve_rechaza_wrong_y_correct_identicos(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString("'status' => 'invalid'", $source);
        $this->assertStringContainsString('wrong y correct son idénticos', $source);
    }

    public function test_usa_llm_primary_provider_por_default(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString("\$this->callChatCompletion(self::SYSTEM_PROMPT, \$userPrompt, true, 'primary')", $source);
    }

    public function test_logs_no_incluyen_api_key(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        // El log "served" debe contener métricas pero NUNCA la key ni el prompt completo.
        $this->assertStringContainsString("ai_context_correct.served", $source);
        // Aseguramos que en el array de contexto del log no aparezca api_key
        // como clave. Cobertura gruesa: verificamos que NO hay 'api_key' literal
        // en el contexto del log.
        $servedBlock = $this->extractServedLogBlock($source);
        $this->assertStringNotContainsString('api_key', $servedBlock);
        $this->assertStringNotContainsString('prompt', $servedBlock);
    }

    public function test_cache_ttl_lee_desde_config(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextCorrectService.php');

        $this->assertStringContainsString("config('corrections.ai_context_correct.cache_ttl', self::DEFAULT_CACHE_TTL)", $source);

        $config = file_get_contents(dirname(__DIR__, 2) . '/config/corrections.php');
        $this->assertStringContainsString("CORRECTIONS_AI_CONTEXT_CORRECT_CACHE_TTL", $config);
        $this->assertStringContainsString("'cache_ttl' =>", $config);
    }

    private function extractServedLogBlock(string $source): string
    {
        if (!preg_match("/Log::info\\(\\s*'ai_context_correct\\.served',(.+?)\\)\\;/s", $source, $m)) {
            $this->fail('No se encontró el bloque ai_context_correct.served.');
        }
        return $m[1];
    }
}
