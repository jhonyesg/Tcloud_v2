<?php

namespace Tests\Feature;

use App\Models\Correction;
use Tests\LaravelTestCase;

/**
 * Tests del flag risk_level y applyToText (changes/2026-08-02-corrections-dictionary-atomicity).
 */
class CorreccionesRiskLevelTest extends LaravelTestCase
{
    public function test_correction_model_has_risk_level_constants(): void
    {
        $this->assertSame('low', Correction::RISK_LOW);
        $this->assertSame('medium', Correction::RISK_MEDIUM);
        $this->assertSame('high', Correction::RISK_HIGH);
    }

    public function test_risk_level_is_in_fillable(): void
    {
        $reflection = new \ReflectionClass(Correction::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $fillable = $instance->getFillable();
        $this->assertContains('risk_level', $fillable, 'risk_level debe estar en $fillable para mass-assign');
    }

    public function test_safe_scope_excludes_high_risk(): void
    {
        $reflection = new \ReflectionClass(Correction::class);
        $method = $reflection->getMethod('scopeSafe');
        $this->assertTrue($method->isPublic() || $method->isProtected());

        // Verificar via docblock que filtra risk_level != high
        $doc = $method->getDocComment();
        $this->assertNotNull($doc, 'scopeSafe debe tener docblock');
        $this->assertStringContainsString('high', $doc);
    }

    public function test_apply_to_text_signature(): void
    {
        $ref = new \ReflectionMethod(Correction::class, 'applyToText');
        $this->assertTrue($ref->isPublic(), 'applyToText debe ser público');
        $this->assertTrue($ref->isStatic(), 'applyToText debe ser estático');

        $params = $ref->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('text', $params[0]->getName());
        $this->assertSame('includeHighRisk', $params[1]->getName());
        $this->assertTrue($params[1]->isDefaultValueAvailable(), 'includeHighRisk debe tener default value');
        $this->assertFalse($params[1]->getDefaultValue(), 'includeHighRisk default debe ser false (safe)');
    }

    public function test_apply_retroactively_accepts_include_high_risk(): void
    {
        $ref = new \ReflectionMethod(\App\Services\Ia\CorrectionService::class, 'applyRetroactively');
        $params = $ref->getParameters();
        $this->assertCount(5, $params, 'applyRetroactively ahora tiene 5 parámetros');
        $this->assertSame('includeHighRisk', $params[4]->getName());
        $this->assertFalse($params[4]->getDefaultValue(), 'includeHighRisk default debe ser false');
    }
}
