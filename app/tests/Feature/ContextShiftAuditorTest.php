<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Services\Ia\ContextShiftAuditor;
use Tests\LaravelTestCase;

/**
 * Tests del ContextShiftAuditor (changes/2026-08-02-corrections-dictionary-atomicity).
 *
 * NO requiere BD: usa stdClass para evaluateOne() (soporta tanto Correction
 * como objeto plano) y Reflection para inspeccionar applyToDb() sin tocar BD.
 */
class ContextShiftAuditorTest extends LaravelTestCase
{
    private ContextShiftAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new ContextShiftAuditor();
    }

    public function test_detects_actually_to_actualmente_as_high_risk(): void
    {
        $r = $this->auditor->evaluateOne((object) [
            'id' => 1,
            'wrong_text' => 'actually',
            'correct_text' => 'actualmente',
            'risk_level' => 'low',
        ]);

        $this->assertNotNull($r, 'Auditor debe detectar actually → actualmente como false friend');
        $this->assertSame('high', $r['risk']);
        $this->assertSame('false_friend', $r['type']);
        $this->assertSame('actually', $r['matched']);
        $this->assertStringContainsString('actualmente', $r['reason']);
        $this->assertContains('en realidad', $r['safe_translations']);
    }

    public function test_detects_eventually_to_finalmente_as_high_risk(): void
    {
        $r = $this->auditor->evaluateOne((object) [
            'id' => 2,
            'wrong_text' => 'eventually',
            'correct_text' => 'finalmente',
            'risk_level' => 'low',
        ]);

        $this->assertNotNull($r);
        $this->assertSame('high', $r['risk']);
        $this->assertSame('eventually', $r['matched']);
    }

    public function test_detects_like_in_wrong_as_high_risk(): void
    {
        $r = $this->auditor->evaluateOne((object) [
            'id' => 3,
            'wrong_text' => 'A shed is like a stand',
            'correct_text' => 'Un cobertizo es como un puesto',
            'risk_level' => 'low',
        ]);

        $this->assertNotNull($r);
        $this->assertSame('high', $r['risk']);
        $this->assertSame('filler', $r['type']);
        $this->assertSame('like', $r['matched']);
    }

    public function test_detects_you_know_as_high_risk(): void
    {
        $r = $this->auditor->evaluateOne((object) [
            'id' => 4,
            'wrong_text' => 'you know',
            'correct_text' => 'sabes',
            'risk_level' => 'low',
        ]);

        $this->assertNotNull($r);
        $this->assertSame('high', $r['risk']);
        $this->assertSame('you know', $r['matched']);
    }

    public function test_basically_returns_medium_risk(): void
    {
        $r = $this->auditor->evaluateOne((object) [
            'id' => 5,
            'wrong_text' => 'but basically',
            'correct_text' => 'pero básicamente',
            'risk_level' => 'low',
        ]);

        $this->assertNotNull($r);
        $this->assertSame('medium', $r['risk']);
        $this->assertSame('basically', $r['matched']);
    }

    public function test_returns_null_for_safe_corrections(): void
    {
        $cases = [
            ['the president', 'el presidente'],
            ['touristic attractives', 'atractivos turísticos'],
            ['and', 'y'],
            ['for', 'para'],
        ];
        foreach ($cases as [$w, $c]) {
            $r = $this->auditor->evaluateOne((object) [
                'id' => 0,
                'wrong_text' => $w,
                'correct_text' => $c,
                'risk_level' => 'low',
            ]);
            $this->assertNull($r, "Expected no warning for '{$w}' → '{$c}' but got: " . json_encode($r));
        }
    }

    public function test_actually_with_safe_translation_does_not_warn(): void
    {
        $r = $this->auditor->evaluateOne((object) [
            'id' => 99,
            'wrong_text' => 'actually',
            'correct_text' => 'en realidad',
            'risk_level' => 'low',
        ]);
        $this->assertNull($r, 'actually → en realidad es seguro, no debe warn');
    }

    public function test_method_signatures(): void
    {
        // Solo evaluateOne() queda en el servicio (cambios 2026-08-18:
        // audit() y applyToDb() eliminados tras quitar la vista
        // "Contexto sensible"). Esta prueba verifica que sigue existiendo
        // y es público para que buildContextWarning() la pueda llamar.
        $ref = new \ReflectionMethod($this->auditor, 'evaluateOne');
        $this->assertTrue($ref->isPublic());

        $params = $ref->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('r', $params[0]->getName());
        $this->assertSame('config', $params[1]->getName());
    }

    public function test_evaluate_one_returns_nullable_shape(): void
    {
        // Cambios 2026-08-18: applyToDb() se eliminó; este test se reemplaza
        // por una verificación de la firma de evaluateOne().
        $ref = new \ReflectionMethod($this->auditor, 'evaluateOne');
        $rt = $ref->getReturnType();
        $this->assertNotNull($rt, 'evaluateOne debe declarar tipo de retorno');
        $typeName = (string) $rt;
        $this->assertStringContainsString('array', $typeName);
        // ?array nullable: retorna null cuando no hay match.
        $this->assertTrue($rt->allowsNull());
    }
}
