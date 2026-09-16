<?php

namespace Tests\Feature;

use App\Services\Ia\DictionaryAudit;
use Tests\LaravelTestCase;

/**
 * Tests del DictionaryAudit (changes/2026-08-02-corrections-dictionary-atomicity).
 *
 * La mayoría son tests de firma + docblock; los métodos que hacen queries
 * a BD (totals, effectiveness, etc.) se validan vía tests de integración
 * manuales dado que esta suite corre sin BD.
 */
class DictionaryAuditTest extends LaravelTestCase
{
    public function test_audit_class_exists_and_is_instantiable(): void
    {
        $this->assertTrue(class_exists(DictionaryAudit::class));
        $audit = new DictionaryAudit();
        $this->assertInstanceOf(DictionaryAudit::class, $audit);
    }

    public function test_run_signature(): void
    {
        $ref = new \ReflectionMethod(DictionaryAudit::class, 'run');
        $this->assertTrue($ref->isPublic());
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('useCache', $params[0]->getName());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertTrue($params[0]->getDefaultValue(), 'useCache default debe ser true');
    }

    public function test_public_methods_signatures(): void
    {
        $methods = [
            'totals' => 0,
            'effectivenessDistribution' => 0,
            'topUnigrams' => 1,
            'topBigrams' => 1,
            'topTrigrams' => 1,
            'topNgrams' => 2,
            'duplicatesAndConflicts' => 0,
            'clusters' => 2,
            'riskDistribution' => 0,
        ];
        foreach ($methods as $method => $expectedParams) {
            $ref = new \ReflectionMethod(DictionaryAudit::class, $method);
            $this->assertTrue($ref->isPublic(), "$method debe ser público");
            $this->assertCount($expectedParams, $ref->getParameters(), "$method debe tener $expectedParams params");
        }
    }

    public function test_topNgrams_signature(): void
    {
        $ref = new \ReflectionMethod(DictionaryAudit::class, 'topNgrams');
        $params = $ref->getParameters();
        $this->assertSame('n', $params[0]->getName());
        $this->assertSame('limit', $params[1]->getName());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertSame(30, $params[1]->getDefaultValue());
    }
}
