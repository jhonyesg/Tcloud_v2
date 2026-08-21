<?php

namespace Tests\Unit;

use App\Models\Correction;
use App\Services\Ia\CorrectionService;
use App\Models\Keyword;
use PHPUnit\Framework\TestCase;

class CorrectionApplyToTextTest extends TestCase
{
    /**
     * Helper: construye una corrección in-memory (desconectada de Eloquent) con
     * los atributos mínimos que consume Correction::applyToText().
     */
    private function makeCorrection(string $wrong, string $correct): object
    {
        $c = new \stdClass();
        $c->wrong_normalized = Keyword::asciiLower($wrong);
        $c->correct_text = $correct;
        return $c;
    }

    public function testEmptyCorrectionsDoesNotMutate(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $result = $applyText->invoke($service, 'el presidente habla', collect([]));
        $this->assertSame('el presidente habla', $result);
    }

    public function testSingleCorrectionIsAppliedCaseInsensitive(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('presEdeNte', 'presidente'),
        ]);

        $result = $applyText->invoke($service, 'el PRESEDENTE habla', $corrections);
        $this->assertSame('el presidente habla', $result);
    }

    public function testEmptyWrongNormalizedIsSkipped(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('', 'should_not_appear'),
            $this->makeCorrection('foo', 'bar'),
        ]);

        $result = $applyText->invoke($service, 'foo baz', $corrections);
        $this->assertSame('bar baz', $result);
    }

    public function testMultipleCorrectionsCommaSeparatedApply(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        // El caller ordena por longitud DESC. Simulamos un caller responsable.
        $corrections = collect([
            $this->makeCorrection('valorar el tiempo', 'valorar el tiempo'),
            $this->makeCorrection('el tiempo', 'el tiempo'),
            $this->makeCorrection('el', 'XX'),
        ]);

        $result = $applyText->invoke($service, 'el presidente', $corrections);
        $this->assertSame('XX presidente', $result);
    }

    public function testAsciiTransliterationOfNormalizedKey(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        // wrong_normalized se construye con asciiLower; la búsqueda via str_ireplace
        // matchea con/sin acentos en el contenido.
        $corrections = collect([
            $this->makeCorrection('tecnico', 'técnico'),
        ]);

        $result = $applyText->invoke($service, 'el técnico habló', $corrections);
        $this->assertSame('el técnico habló', $result);
    }

    public function testReturnsSameStringWhenNoMatch(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('zzzz', 'OTHER'),
        ]);

        $result = $applyText->invoke($service, 'texto sin match', $corrections);
        $this->assertSame('texto sin match', $result);
    }

    public function testWordBoundaryDoesNotMatchInsideOtherWords(): void
    {
        // Bug fix: "Active to" no debe romper "attractive", "proactive",
        // "psychoactive", "reactive". Antes con str_ireplace rompia todas.
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('Active to', 'Activa tu'),
        ]);

        $cases = [
            'the attractive touristic destination',
            'the proactive psychoactive initiative',
            'a reactive to demand situation',
        ];

        foreach ($cases as $input) {
            $result = $applyText->invoke($service, $input, $corrections);
            $this->assertSame(
                $input,
                $result,
                "Word-boundary no debe romper substrings en: '{$input}'"
            );
        }
    }

    public function testWordBoundaryMatchesFullPhraseAtSentenceStart(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('in the world', 'en el mundo'),
        ]);

        $cases = [
            ['in the world today',            'en el mundo today'],
            ['peace in the world matters',    'peace en el mundo matters'],
            ['In The World of politics',      'en el mundo of politics'],
        ];

        foreach ($cases as [$input, $expected]) {
            $result = $applyText->invoke($service, $input, $corrections);
            $this->assertSame($expected, $result, "Frase completa al inicio/medio de oración: '{$input}'");
        }
    }

    public function testWordBoundaryWithPunctuation(): void
    {
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            $this->makeCorrection('in the world', 'en el mundo'),
        ]);

        $cases = [
            ['the situation, in the world, matters', 'the situation, en el mundo, matters'],
            ['"in the world" they said',             '"en el mundo" they said'],
            ['(in the world)',                       '(en el mundo)'],
        ];

        foreach ($cases as [$input, $expected]) {
            $result = $applyText->invoke($service, $input, $corrections);
            $this->assertSame($expected, $result, "Puntuación como borde: '{$input}'");
        }
    }

    public function testMultiWordPhraseNotDisarmedBySubphrase(): void
    {
        // Si existe una regla "valor the time" (larga) y una sub-regla
        // "the time" (corta), la larga debe aplicarse primero y consumir
        // la sub-regla en el resultado.
        $service = new CorrectionService();
        $serviceReflection = new \ReflectionClass($service);
        $applyText = $serviceReflection->getMethod('applyText');
        $applyText->setAccessible(true);

        // El caller ordena por LENGTH DESC. Simulamos eso.
        $corrections = collect([
            $this->makeCorrection('valor the time', 'valorar el tiempo'),
            $this->makeCorrection('the time',       'XX'),
        ]);

        $result = $applyText->invoke($service, 'we must valor the time together', $corrections);
        $this->assertSame(
            'we must valorar el tiempo together',
            $result,
            'Sub-frase no debe romper frase ya corregida'
        );
    }
}
