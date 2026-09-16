<?php

namespace Tests\Feature;

use App\Console\Commands\TranscriptionBackfillCoherenceCommand;
use App\Services\Ia\TranscriptorSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests del Artisan command transcription:backfill-coherence.
 * Cubren el guard del toggle maestro ai_coherence_enabled
 * (changes/2026-08-25 llm-coherence-manual-only-defaults-off): si el toggle
 * está apagado, el comando debe salir SUCCESS sin gastar tokens ni tocar BD.
 *
 * Nota: handle() usa facades (Log, DB) por lo que este test verifica la
 * estructura del guard vía reflection sobre el código fuente del método.
 * El end-to-end (boot + run) se cubre con `php artisan
 * transcription:backfill-coherence --dry-run` que ya valida manualmente.
 */
class TranscriptionBackfillCoherenceGuardTest extends TestCase
{
    public function test_command_has_expected_signature_options(): void
    {
        $command = new TranscriptionBackfillCoherenceCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('days'));
        $this->assertTrue($definition->hasOption('batch'));
        $this->assertTrue($definition->hasOption('sleep'));
        $this->assertTrue($definition->hasOption('dry-run'));
    }

    public function test_command_name_is_transcription_backfill_coherence(): void
    {
        $command = new TranscriptionBackfillCoherenceCommand();
        $this->assertSame('transcription:backfill-coherence', $command->getName());
    }

    public function test_handle_method_receives_transcriptor_settings(): void
    {
        $ref = new \ReflectionMethod(TranscriptionBackfillCoherenceCommand::class, 'handle');
        $params = $ref->getParameters();

        $names = array_map(fn ($p) => $p->getName(), $params);
        $this->assertContains('settings', $names, 'handle() debe inyectar TranscriptorSettings como segundo argumento');
    }

    public function test_handle_source_checks_ai_coherence_enabled_before_processing(): void
    {
        $ref = new \ReflectionMethod(TranscriptionBackfillCoherenceCommand::class, 'handle');
        $file = $ref->getFileName();
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();

        $source = '';
        for ($i = $start; $i <= $end; $i++) {
            $source .= "\n" . file($file)[$i - 1];
        }

        // El guard debe estar en las primeras líneas del método (antes de
        // cualquier acceso a BD o al LLM).
        $position = strpos($source, 'ai_coherence_enabled');
        $this->assertNotFalse($position, 'handle() debe consultar ai_coherence_enabled');

        $dbPosition = strpos($source, 'chunkById');
        if ($dbPosition !== false) {
            $this->assertLessThan(
                $dbPosition,
                $position,
                'el guard de ai_coherence_enabled debe estar antes de cualquier acceso a BD (chunkById)'
            );
        }

        $this->assertStringContainsString(
            'pase de coherencia IA está deshabilitado',
            $source,
            'el guard debe imprimir el WARNING de modo seguro'
        );
        $this->assertStringContainsString(
            'self::SUCCESS',
            $source,
            'el guard debe retornar SUCCESS (no FAILURE) cuando el toggle está apagado'
        );
    }
}