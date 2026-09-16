<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Services\Ia\CorrectionService;
use PHPUnit\Framework\TestCase;
use Tests\LaravelTestCase;

/**
 * Tests para saveAiSuggestedCandidates() — el flujo optimizado de "Insertar
 * después de preview" en el modal del AI Suggest. Este flujo NO re-llama
 * al LLM: persiste directamente los candidatos ya validados por el filter
 * de marcas en la respuesta del suggester.
 */
class AiSuggestSaveTest extends LaravelTestCase
{
    private function dbAvailable(): bool
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            // Verificar que la tabla corrections existe (la BD de tests puede
            // estar vacía de schema aunque la conexión funcione).
            return \Illuminate\Support\Facades\Schema::hasTable('corrections');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->dbAvailable()) {
            $this->markTestSkipped('BD no disponible — los tests de saveAiSuggestedCandidates requieren conexión a corrections y users.');
        }
    }

    private function makeService(): CorrectionService
    {
        return new CorrectionService();
    }

    public function test_save_inserts_candidates_not_in_approved_or_pending(): void
    {
        // Limpiar cualquier corrección previa con el mismo wrong.
        Correction::where('wrong_normalized', 'TESTUNIQ_WRONG_one')->delete();
        Correction::where('wrong_normalized', 'TESTUNIQ_WRONG_two')->delete();

        // Crear un admin.
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'save-test-admin@tcloud.local'],
            ['name' => 'Save Test Admin', 'role' => 'admin', 'password' => bcrypt('x')]
        );

        $service = $this->makeService();
        $result = $service->saveAiSuggestedCandidates(
            [
                ['wrong' => 'TESTUNIQ_WRONG_one', 'correct' => 'TESTUNIQ_CORRECT_one'],
                ['wrong' => 'TESTUNIQ_WRONG_two', 'correct' => 'TESTUNIQ_CORRECT_two'],
            ],
            'test-source-2026-08-01',
            $admin,
        );

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['skipped_duplicate']);
        $this->assertSame(0, $result['skipped_empty']);

        // Verificar que están en pending.
        $this->assertSame(
            2,
            Correction::pending()
                ->whereIn('wrong_normalized', ['testuniq_wrong_one', 'testuniq_wrong_two'])
                ->count()
        );

        // Cleanup.
        Correction::where('wrong_normalized', 'TESTUNIQ_WRONG_one')->delete();
        Correction::where('wrong_normalized', 'TESTUNIQ_WRONG_two')->delete();
        $admin->delete();
    }

    public function test_save_idempotent_skips_existing_pending(): void
    {
        Correction::where('wrong_normalized', 'DUPWRONG_test')->delete();
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'save-test-dup@tcloud.local'],
            ['name' => 'Save Dup Admin', 'role' => 'admin', 'password' => bcrypt('x')]
        );
        $service = $this->makeService();

        // Primer save → 1 insertado.
        $r1 = $service->saveAiSuggestedCandidates(
            [['wrong' => 'DUPWRONG_test', 'correct' => 'first']],
            'test-source',
            $admin,
        );
        $this->assertSame(1, $r1['inserted']);

        // Segundo save con MISMAS candidates → 0 insertados, 1 duplicado.
        $r2 = $service->saveAiSuggestedCandidates(
            [['wrong' => 'DUPWRONG_test', 'correct' => 'second']],
            'test-source',
            $admin,
        );
        $this->assertSame(0, $r2['inserted']);
        $this->assertSame(1, $r2['skipped_duplicate']);

        // Cleanup.
        Correction::where('wrong_normalized', 'DUPWRONG_test')->delete();
        $admin->delete();
    }

    public function test_save_idempotent_skips_existing_approved(): void
    {
        Correction::where('wrong_normalized', 'APPROVED_TEST')->delete();

        // Admin real del sistema para que proposed_by/approved_by sean coherentes.
        $realAdmin = \App\Models\User::where('role', 'admin')->orderBy('id')->first();
        if (!$realAdmin) {
            $this->markTestSkipped('No admin user in DB');
        }

        $correction = new Correction([
            'wrong_text' => 'APPROVED_TEST',
            'correct_text' => 'approved_correct',
            'wrong_normalized' => 'approved_test',
            'status' => 'approved',
            'proposed_by' => $realAdmin->id,
            'approved_by' => $realAdmin->id,
            'approved_at' => now(),
            'source' => 'test-fixture',
        ]);
        $correction->save();

        $service = $this->makeService();
        $result = $service->saveAiSuggestedCandidates(
            [['wrong' => 'APPROVED_TEST', 'correct' => 'should not apply']],
            'test-source',
            $realAdmin,
        );

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped_duplicate']);

        // Cleanup.
        Correction::where('wrong_normalized', 'APPROVED_TEST')->delete();
    }

    public function test_save_skips_empty_candidates(): void
    {
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'save-empty@tcloud.local'],
            ['name' => 'Empty Test', 'role' => 'admin', 'password' => bcrypt('x')]
        );
        $service = $this->makeService();

        $result = $service->saveAiSuggestedCandidates(
            [
                ['wrong' => '', 'correct' => 'something'],
                ['wrong' => 'something', 'correct' => ''],
                ['wrong' => '  ', 'correct' => 'something'],
                ['wrong' => 'valid_one', 'correct' => 'valid_correction'], // única válida
            ],
            'test-source',
            $admin,
        );

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(3, $result['skipped_empty']);

        // Cleanup.
        Correction::where('wrong_normalized', 'valid_one')->delete();
        $admin->delete();
    }

    public function test_save_assigns_source_to_inserted_rows(): void
    {
        Correction::where('wrong_normalized', 'SOURCE_TEST')->delete();
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'save-source@tcloud.local'],
            ['name' => 'Source Test', 'role' => 'admin', 'password' => bcrypt('x')]
        );
        $service = $this->makeService();

        $result = $service->saveAiSuggestedCandidates(
            [['wrong' => 'SOURCE_TEST', 'correct' => 'source_correct']],
            'manual-test-source-abc',
            $admin,
        );

        $this->assertSame(1, $result['inserted']);

        $persisted = Correction::pending()
            ->where('wrong_normalized', 'source_test')
            ->first();
        $this->assertSame('manual-test-source-abc', $persisted->source);

        // Cleanup.
        Correction::where('wrong_normalized', 'SOURCE_TEST')->delete();
        $admin->delete();
    }
}
