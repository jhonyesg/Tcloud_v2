<?php

namespace Tests\Unit;

use App\Services\Ia\LlmCorrectionSuggester;
use PHPUnit\Framework\TestCase;

/**
 * Guardrail contra las reglas de 1-2 palabras sin contexto.
 *
 * Es la clase de regla que degradaba el corpus: un reemplazo de un solo token
 * dispara en todo el texto y, sobre segmentos que ya están en inglés, produce
 * mezcla ("two hours" -> "two horas", "the class" -> "the clase") en vez de
 * arreglar nada. Solo se aceptan si son arreglos ORTOGRÁFICOS, que conservan el
 * esqueleto de la palabra.
 */
class LooseWordGuardrailTest extends TestCase
{
    private function isSpellingFix(string $wrong, string $correct): bool
    {
        $method = new \ReflectionMethod(LlmCorrectionSuggester::class, 'isSpellingFix');
        $method->setAccessible(true);

        return $method->invoke(new LlmCorrectionSuggester(), $wrong, $correct);
    }

    /**
     * @dataProvider arreglosOrtograficos
     */
    public function testArreglosOrtograficosPasan(string $wrong, string $correct): void
    {
        $this->assertTrue($this->isSpellingFix($wrong, $correct), "{$wrong} -> {$correct}");
    }

    public static function arreglosOrtograficos(): array
    {
        return [
            'tilde'            => ['mas', 'más'],
            'doble consonante' => ['difficultades', 'dificultades'],
            'h inicial'        => ['echa', 'hecha'],
            'e protética'      => ['strategia', 'estrategia'],
        ];
    }

    /**
     * @dataProvider cambiosDePalabra
     */
    public function testCambiosDePalabraSeRechazan(string $wrong, string $correct): void
    {
        $this->assertFalse($this->isSpellingFix($wrong, $correct), "{$wrong} -> {$correct}");
    }

    public static function cambiosDePalabra(): array
    {
        return [
            'sin parecido'   => ['top', 'cima'],
            'traducción'     => ['Good', 'Bien'],
            'falso cognado'  => ['Happy', 'Feliz'],
            'cognado cercano'=> ['hours', 'horas'],
            'cognado corto'  => ['class', 'clase'],
            'cognado medio'  => ['sound', 'sonido'],
            // Una regla que no cambia nada NO es un arreglo ortográfico. Antes
            // este caso estaba en el grupo de arriba, dándose por bueno, y de ahí
            // salieron 48 reglas inertes en el diccionario de producción que el
            // corrector recorría en cada pasada sin hacer nada.
            'no-op'          => ['internacionales', 'internacionales'],
            // Dos palabras españolas válidas distintas: cambia el género, no la
            // ortografía. Se parecen un 90 %, que es justo por lo que el umbral
            // de similitud por sí solo no bastaba.
            'cambio género'  => ['presidenta', 'presidente'],
            'cambio matiz'   => ['ahorita', 'ahora'],
            // Inglés con forma casi española: sustituirlo es traducir.
            'cognado alto'   => ['innocent', 'inocente'],
        ];
    }

    public function testParVacioSeRechaza(): void
    {
        $this->assertFalse($this->isSpellingFix('', 'algo'));
        $this->assertFalse($this->isSpellingFix('algo', ''));
    }
}
