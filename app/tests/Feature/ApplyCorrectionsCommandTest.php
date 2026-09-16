<?php

namespace Tests\Feature;

use App\Services\Ia\CorrectionService;
use PHPUnit\Framework\TestCase;

/**
 * Tests de comportamiento del servicio retroactivo aplicado a un set
 * de segmentos sintéticos. Estos tests verifican la lógica del helper
 * privado `applyText` y la semántica de idempotencia/delta que
 * `applyRetroactively()` reutiliza.
 *
 * NO tocan BD: la integración real con `TranscriptionSegment::chunkById`
 * se valida manualmente en producción, dado que el proyecto no tiene
 * Laravel Testbench configurado.
 */
class ApplyCorrectionsCommandTest extends TestCase
{
    public function testApplyTextIdempotencyWhenNoCorrections(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $first = $applyText->invoke($service, 'texto sin cambio', collect([]));
        $second = $applyText->invoke($service, $first, collect([]));

        $this->assertSame('texto sin cambio', $first);
        $this->assertSame('texto sin cambio', $second);
    }

    public function testApplyTextAfterFirstPassIsNoOp(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('presedente', 'presidente'),
        ]);

        $raw = 'el presedente habla';
        $corrected = $applyText->invoke($service, $raw, $corrections);
        $this->assertSame('el presidente habla', $corrected);

        // Segunda pasada: como ya no hay 'presedente', nada cambia.
        $repass = $applyText->invoke($service, $corrected, $corrections);
        $this->assertSame($corrected, $repass);
    }

    public function testAppliesCountSimulatedOnlyOnRealChange(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('presedente', 'presidente'),
        ]);

        // Simula 5 segmentos, 4 con change y 1 sin.
        $rawSegments = [
            'el presedente',
            'el presedente otra vez',
            'texto limpio',
            'el presedente final',
            'otro presedente aqui',
        ];

        $appliedCount = 0;
        foreach ($rawSegments as $raw) {
            $original = $raw;
            $corrected = $applyText->invoke($service, $raw, $corrections);
            if ($corrected !== $original) {
                $appliedCount++;
            }
        }

        $this->assertSame(4, $appliedCount);
    }

    public function testMultipleCorrectionsComposedInOrder(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        // Caller ordena por longitud DESC: largas primero.
        $corrections = collect([
            $this->makeCorrection('valorar el tiempo', 'VALORAR_TIEMPO'),
            $this->makeCorrection('el tiempo', 'EL_TIEMPO'),
            $this->makeCorrection('el', 'XX'),
        ]);

        $result = $applyText->invoke($service, 'el presidente', $corrections);
        $this->assertSame('XX presidente', $result);
    }

    public function testApplyTextSkipsEmptyWrongNormalized(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('', 'should_not_appear'),
            $this->makeCorrection('foo', 'bar'),
        ]);

        $result = $applyText->invoke($service, 'foo baz', $corrections);
        $this->assertSame('bar baz', $result);
    }

    public function testCascadingReplacementsAreNowPreventedByWordBoundary(): void
    {
        // Desde 2026-07-29 (change corrections-dictionary-bootstrapping),
        // las correcciones usan \b word-boundary. Esto previene que la
        // segunda corrección matchee como substring DENTRO del resultado
        // de la primera. Ejemplo:
        //   - 'presedente' -> 'presidente'
        //   - 'ente' ya NO matchea dentro de 'presidente' porque no hay
        //     word-boundary antes de 'ente' (está precedida por 'd').
        //
        // Antes (con str_ireplace) el resultado era 'el presidENTE' (BUG).
        // Ahora (con preg_replace \b) el resultado es 'el presidente' (OK).
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('presedente', 'presidente'),
            $this->makeCorrection('ente', 'ENTE'),
        ]);

        $result = $applyText->invoke($service, 'el presedente', $corrections);
        $this->assertSame('el presidente', $result);
    }

    private function makeCorrection(string $wrong, string $correct): \stdClass
    {
        $c = new \stdClass();
        $c->wrong_normalized = $wrong;
        $c->correct_text = $correct;
        return $c;
    }
}
