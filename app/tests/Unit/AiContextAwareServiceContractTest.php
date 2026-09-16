<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests de contrato para AiContextAwareService.
 * (change: corrections-ai-context-aware-with-mark-curation)
 * Validamos via introspección de la fuente: el servicio toca HTTP/LLM/BD
 * que no levantamos en unit tests sin harness pesado.
 */
class AiContextAwareServiceContractTest extends TestCase
{
    public function test_solo_un_metodo_publico_correctExample_y_approve(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        preg_match_all('/public function [a-zA-Z_]+\(/', $source, $matches);
        $publicFns = $matches[0];
        $this->assertContains('public function correctExample(', $publicFns, 'correctExample debe ser público.');
        $this->assertContains('public function approve(', $publicFns, 'approve debe ser público.');
        // El resto debe ser privado (deep module: solo entradas públicas).
        $this->assertCount(2, $publicFns, 'Solo dos métodos públicos permitidos (correctExample + approve).');
    }

    public function test_suggest_retorna_gate_si_settings_deshabilitado(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString("if (!\$settings->bool('enabled'))", $source);
        $this->assertStringContainsString('Suggest deshabilitado desde Configuración / IA Suggest.', $source);
        $this->assertStringContainsString("'hint' => 'Activa el toggle \"Habilitado\" en el tab IA Suggest.'", $source);
    }

    public function test_suggest_retorna_gate_si_api_key_vacia(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString("if (\$settings->apiKey() === '')", $source);
        $this->assertStringContainsString('LLM_API_KEY no configurada.', $source);
    }

    public function test_suggest_reusa_cache_y_respeta_force_fresh(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('Cache::get($cacheKey)', $source);
        $this->assertStringContainsString("if (\$forceFresh) {", $source);
        $this->assertStringContainsString('Cache::forget($cacheKey)', $source);
    }

    public function test_findNeighbors_query_entre_indices_y_ordena_en_php(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('whereBetween(\'segment_index\',', $source);
        $this->assertStringContainsString('->orderBy(\'segment_index\')', $source);
        $this->assertStringContainsString('private function findNeighbors(', $source);
    }

    public function test_prompt_incluye_etiqueta_objetivo_y_vecinos_etiquetados(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('SYSTEM_PROMPT', $source);
        $this->assertStringContainsString('#[OBJETIVO]', $source);
        $this->assertStringContainsString("'#[%d]'", $source);
    }

    public function test_prompt_no_obliga_a_inventar_permite_conservar(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        // Regla 3: si no hay contexto, devuelve correct = wrong.
        $this->assertStringContainsString("correct = wrong", $source);
        $this->assertStringContainsString('Sin contexto adicional suficiente', $source);
    }

    public function test_post_filtro_reusa_protected_terms_service_sin_filtro_de_longitud(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('CorrectionProtectedTermsService::terms()', $source);
        // No debe llamar al heurístico completo (que filtra por longitud) —
        // sólo a la lista plana de marcas protegidas.
        $this->assertStringNotContainsString('looksLikeBrandOrProperNoun(', $source);
    }

    public function test_approve_insiste_a_source_correcto_y_idempotente(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('ai-context-correct-context-{$today}', $source);
        $this->assertStringContainsString('Correction::STATUS_PENDING', $source);
        $this->assertStringContainsString("'status' => 'conflict'", $source);
        $this->assertStringContainsString('existing_id', $source);
        $this->assertStringContainsString("'status' => 'invalid'", $source);
        $this->assertStringContainsString('wrong y correct son idénticos', $source);
    }

    public function test_cache_ttl_lee_desde_config(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString("config('corrections.ai_context_aware.cache_ttl', self::DEFAULT_CACHE_TTL)", $source);
    }

    public function test_logs_no_incluyen_api_key(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('ai_context_aware.served', $source);
        // Extrae el bloque served y verifica que no expone api_key ni el prompt completo.
        if (!preg_match("/Log::info\\(\\s*'ai_context_aware\\.served',(.+?)\\)\\;/s", $source, $m)) {
            $this->fail('No se encontró el bloque ai_context_aware.served.');
        }
        $this->assertStringNotContainsString('api_key', $m[1]);
        $this->assertStringNotContainsString('prompt', $m[1]);
        // neighbor_window sí puede estar — es métrica agregada.
        $this->assertStringContainsString('neighbor_window', $m[1]);
    }

    public function test_user_prompt_incluye_lista_marcas_protegidas_explicita(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('MARCAS PROTEGIDAS', $source);
        $this->assertStringContainsString('activeProtectedTerms()', $source);
        $this->assertStringContainsString('MARCAS PROTEGIDAS (preservar literales', $source);
    }

    public function test_post_filtro_detecta_marca_eliminada_en_correct(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('findRemovedBrands', $source);
        // Debe devolver motivo legible cuando el LLM elimina una marca.
        $this->assertStringContainsString('La corrección eliminó la(s) marca(s) protegida(s)', $source);
        $this->assertStringContainsString('str_contains($wrongLower, $t)', $source);
        $this->assertStringContainsString('str_contains($correctLower, $t)', $source);
    }

    public function test_post_filtro_longitud_minima_3_caracteres(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        $this->assertStringContainsString('strlen($t) < 3) continue;', $source);
    }

    public function test_system_prompt_incluye_instrucciones_marca_no_traducir(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/AiContextAwareService.php');
        // El system prompt tiene reglas explícitas para preservar marcas.
        $this->assertStringContainsString('NUNCA traducir ni sustituirlas', $source);
    }
}
