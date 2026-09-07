<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests del controlador + rutas del nuevo flujo de corrección IA inline.
 * (change: corrections-ai-context-correct-inline)
 */
class CorreccionesAiContextCorrectControllerTest extends TestCase
{
    public function test_controlador_define_suggest_y_approve(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php');

        $this->assertStringContainsString('public function suggest(', $source);
        $this->assertStringContainsString('public function approve(', $source);
    }

    public function test_approve_reusa_settings_y_servicio_correctos(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php');

        $this->assertStringContainsString('use App\\Services\\Ia\\AiContextCorrectService', $source);
        $this->assertStringContainsString('use App\\Services\\Ia\\LlmCorrectionSettings', $source);
        $this->assertStringContainsString('use App\\Services\\Ia\\CorrectionContextFinder', $source);
    }

    public function test_validate_examples_pertenece_a_correction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php');

        // Validamos que el controlador busca el ejemplo por segment_id y devuelve 404 si no aparece.
        $this->assertStringContainsString('$examples = $finder->examples($correction)', $source);
        $this->assertStringContainsString("'El ejemplo no pertenece a esta corrección padre.'", $source);
    }

    public function test_rutas_registradas_en_web_php(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');

        // POST sin approve
        $this->assertStringContainsString("/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct'", $source);
        $this->assertStringContainsString("CorreccionesAiContextCorrectController::class, 'suggest'", $source);

        // POST con approve
        $this->assertStringContainsString("/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve'", $source);
        $this->assertStringContainsString("CorreccionesAiContextCorrectController::class, 'approve'", $source);

        // Restricción de parámetros numéricos.
        $this->assertStringContainsString("whereNumber('correctionId')", $source);
    }

    public function test_approve_rechaza_body_invalido(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php');

        $this->assertStringContainsString("'wrong' => 'required|string|max:2000'", $source);
        $this->assertStringContainsString("'correct' => 'required|string|max:2000'", $source);
    }

    public function test_approve_resuelve_admin_id_desde_session(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php');

        $this->assertStringContainsString("session('user_id')", $source);
        $this->assertStringContainsString("Session::get('user_id')", $source);
    }
}
