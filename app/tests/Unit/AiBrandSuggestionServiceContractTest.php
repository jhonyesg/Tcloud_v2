<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AiBrandSuggestionServiceContractTest extends TestCase
{
    public function test_solo_un_metodo_publico_suggestBrands(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        preg_match_all('/public function [a-zA-Z_]+\(/', $source, $matches);
        $publicFns = $matches[0];
        $this->assertContains('public function suggestBrands(', $publicFns);
        $this->assertCount(1, $publicFns, 'Solo suggestBrands debe ser público (deep module).');
    }

    public function test_suggestBrands_respeta_gate_master_switch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        $this->assertStringContainsString("if (!\$settings->bool('enabled'))", $source);
        $this->assertStringContainsString('Suggest deshabilitado desde Configuración / IA Suggest.', $source);
    }

    public function test_suggestBrands_respeta_gate_api_key(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        $this->assertStringContainsString("if (\$settings->apiKey() === '')", $source);
        $this->assertStringContainsString('LLM_API_KEY no configurada.', $source);
    }

    public function test_cache_por_hash_de_texto(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        $this->assertStringContainsString("'ai_brand_suggest:' . sha1(\$text)", $source);
        $this->assertStringContainsString('Cache::get($cacheKey)', $source);
        $this->assertStringContainsString('Cache::put($cacheKey, $payload, self::CACHE_TTL)', $source);
        $this->assertStringContainsString('private const CACHE_TTL = 3600;', $source);
    }

    public function test_post_filtro_excluye_marcas_protegidas(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        $this->assertStringContainsString('protectedTermsLower()', $source);
        $this->assertStringContainsString('CorrectionProtectedTermsService::class', $source);
        $this->assertStringContainsString('if (in_array($key, $protected, true)) continue;', $source);
    }

    public function test_prompt_especializado_en_deteccion(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        $this->assertStringContainsString('SYSTEM_PROMPT', $source);
        $this->assertStringContainsString('"candidates"', $source);
    }

    public function test_logs_métricas_sin_prompt_completo(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiBrandSuggestionService.php');
        if (!preg_match("/Log::info\\(\\s*'ai_brand_suggest\\.served',(.+?)\\)\\;/s", $source, $m)) {
            $this->fail('No se encontró el bloque ai_brand_suggest.served.');
        }
        $this->assertStringNotContainsString('prompt', $m[1]);
        $this->assertStringNotContainsString('api_key', $m[1]);
    }
}
