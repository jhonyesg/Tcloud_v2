<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El guardrail --confirm de las dos reglas rule-based desprogramadas
 * (change: corrections-manual-only-and-context-search): sin crons, el único
 * riesgo es una corrida manual con ventana amplia que escriba cientos de miles
 * de filas. Ventana > 24h con escritura exige --confirm; sin él, degrada a
 * dry-run sin tocar la BD.
 */
class CorrectionsCronGuardrailTest extends TestCase
{
    public function test_detect_command_has_confirm_option_and_window_guard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Console/Commands/DetectEnglishResidualCommand.php');

        $this->assertStringContainsString('{--confirm', $source);
        $this->assertStringContainsString('CONFIRM_WINDOW_HOURS = 24', $source);
        // Debe degradar a dry-run sin modificar la BD.
        $this->assertStringContainsString('Degradado a dry-run', $source);
    }

    public function test_cycle_command_has_confirm_option_and_window_guard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Console/Commands/CycleSuggestionsCommand.php');

        $this->assertStringContainsString('{--confirm', $source);
        $this->assertStringContainsString('CONFIRM_WINDOW_HOURS = 24', $source);
        $this->assertStringContainsString('Degradado a dry-run', $source);
    }

    public function test_routes_console_no_longer_schedules_the_two_crons(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/console.php');

        // No debe quedar ningún Schedule activo para las dos reglas
        // rule-based del módulo. El comentario histórico documenta la
        // desprogramación, pero las llamadas reales a Schedule::command
        // sobre estos dos nombres no deben existir.
        $this->assertDoesNotMatchRegularExpression(
            "/Schedule::command\([^)]*corrections:detect-english-residual[^)]*\)->everyFourHours/",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            "/Schedule::command\([^)]*corrections:cycle-suggestions[^)]*\)->everyFourHours/",
            $source
        );

        // Las tareas operativas legítimas siguen agendadas.
        $this->assertStringContainsString("corrections:cleanup-undo-log", $source);
        $this->assertStringContainsString("corrections:triage-pending --dry-run", $source);
    }
}
