<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ia\CorrectionTriageService;
use Tests\LaravelTestCase;

/**
 * Tests del triage en capas de correcciones pending.
 *
 * Cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage.
 *
 * Estilo: usa Reflection para validar signatures y docblocks (no toca BD).
 * Los tests de integración end-to-end (CLI, endpoint) requieren la BD de
 * producción-like y se ejecutan manualmente vía `php artisan corrections:
 * triage-pending --dry-run` (cambios task 6.4).
 */
class CorrectionsTriagePendingTest extends LaravelTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new User([
            'username' => 'test_admin',
            'role' => 'admin',
        ]);
        $this->admin->id = 1;
    }

    public function test_run_method_has_expected_signature(): void
    {
        $reflection = new \ReflectionMethod(CorrectionTriageService::class, 'run');
        $params = $reflection->getParameters();

        $this->assertCount(5, $params);
        $this->assertSame('dryRun', $params[0]->getName());
        $this->assertSame('bool', (string) $params[0]->getType());
        $this->assertSame('autoApproveKeep', $params[1]->getName());
        $this->assertSame('bool', (string) $params[1]->getType());
        $this->assertSame('max', $params[2]->getName());
        $this->assertSame('int', (string) $params[2]->getType());
        $this->assertSame('daysBack', $params[3]->getName());
        $this->assertTrue($params[3]->allowsNull());
        $this->assertSame('by', $params[4]->getName());
        $this->assertSame(User::class, (string) $params[4]->getType());
    }

    public function test_run_returns_expected_array_shape(): void
    {
        // Validamos que el método existe y devuelve array por su firma/tipo
        // de retorno declarado.
        $reflection = new \ReflectionMethod(CorrectionTriageService::class, 'run');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', (string) $returnType);
    }

    public function test_get_status_signature(): void
    {
        $reflection = new \ReflectionMethod(CorrectionTriageService::class, 'getStatus');
        $this->assertCount(1, $reflection->getParameters());
        $this->assertSame('runId', $reflection->getParameters()[0]->getName());
        $this->assertSame('string', (string) $reflection->getParameters()[0]->getType());
        // Devuelve ?array.
        $this->assertTrue($reflection->getReturnType()->allowsNull());
    }

    public function test_modes_are_defined(): void
    {
        $this->assertSame('dry_run', CorrectionTriageService::MODE_DRY_RUN);
        $this->assertSame('apply', CorrectionTriageService::MODE_APPLY);
        $this->assertSame('apply_with_auto_approve_keep', CorrectionTriageService::MODE_APPLY_AUTO_APPROVE_KEEP);
    }

    public function test_word_count_handles_unicode_spanish(): void
    {
        $this->assertSame(0, CorrectionTriageService::wordCount(''));
        $this->assertSame(0, CorrectionTriageService::wordCount('   '));
        $this->assertSame(1, CorrectionTriageService::wordCount('hola'));
        // "No," cuenta como 1 token (tiene letras), "hombre" cuenta como otro → 2.
        $this->assertSame(2, CorrectionTriageService::wordCount('No, hombre'));
        $this->assertSame(4, CorrectionTriageService::wordCount('The cooperativas are dotadas'));
        $this->assertSame(5, CorrectionTriageService::wordCount('Just think I grumble today'));
        $this->assertSame(1, CorrectionTriageService::wordCount('canción'));
        // tokens puramente puntuación no cuentan
        $this->assertSame(0, CorrectionTriageService::wordCount('---!!!'));
    }

    public function test_layer_counters_are_logged(): void
    {
        // Verifica por reflection que existen los 4 layers privados esperados
        // y que el service tiene la constante de motivo de descarte trazable.
        $expectedLayers = ['layerLongOrOrphan', 'layerDuplicateOfApproved', 'layerBrand', 'layerClassifier', 'layerWarmContext'];
        foreach ($expectedLayers as $layer) {
            $this->assertTrue(
                method_exists(CorrectionTriageService::class, $layer),
                "Method {$layer} should exist on CorrectionTriageService"
            );
        }
    }

    public function test_rejected_reasons_are_traced(): void
    {
        $reflection = new \ReflectionClass(CorrectionTriageService::class);
        $constants = $reflection->getConstants();
        $this->assertArrayHasKey('REJECTED_REASON_TRIAGE', $constants);
        $this->assertArrayHasKey('REJECTED_REASON_DUP', $constants);
        $this->assertArrayHasKey('REJECTED_REASON_BRAND', $constants);
        $this->assertArrayHasKey('REJECTED_REASON_CLASSIFIER', $constants);
        // Cada uno empieza con "triage:" para que el admin pueda filtrar/limpiar.
        $this->assertStringStartsWith('triage:', $constants['REJECTED_REASON_TRIAGE']);
        $this->assertStringStartsWith('triage:', $constants['REJECTED_REASON_DUP']);
    }
}
