<?php

namespace Tests\Unit;

use App\Services\Ia\CorrectionService;
use PHPUnit\Framework\TestCase;

/**
 * Regresión del bug de fronteras UTF-8 en CorrectionService::applyTextWithPairs().
 *
 * El matcher recorría el texto por BYTES y decidía la frontera de palabra con
 * `ctype_alnum($text[$i])`. Sobre el byte líder de 'ñ'/'á' (0xC3) eso devuelve
 * false, así que una letra acentuada contaba como separador y las reglas
 * disparaban dentro de palabras españolas correctas. Detectado en producción
 * el 2026-08-11 (transcripciones 90303 y 90305).
 */
class CorrectionUtf8BoundaryTest extends TestCase
{
    /**
     * @param array<int, array{0: string, 1: string}> $pairs
     */
    private function apply(string $text, array $pairs): string
    {
        $service = new CorrectionService();
        $method = new \ReflectionMethod(CorrectionService::class, 'applyTextWithPairs');
        $method->setAccessible(true);

        // Mismo orden que preparePairs(): wrong más largo primero.
        usort($pairs, fn ($a, $b) => strlen($b[0]) - strlen($a[0]));

        return $method->invoke($service, $text, $pairs);
    }

    public function testNoRompePalabraAntesDeEne(): void
    {
        // Caso real: transcripción 90303 guardó "al deño" en vez de "al diseño".
        $this->assertSame('al diseño', $this->apply('al diseño', [['dise', 'de']]));
    }

    public function testNoRompePalabraDespuesDeVocalAcentuada(): void
    {
        // Caso real: transcripción 90305 guardó "veintisées" por "veintiséis".
        $this->assertSame('veintiséis', $this->apply('veintiséis', [['is', 'es']]));
    }

    public function testNoTocaPalabraQueContieneElWrongComoPrefijo(): void
    {
        $this->assertSame('diseñador', $this->apply('diseñador', [['dise', 'de']]));
    }

    public function testLetraAcentuadaCuentaComoLetraEnAmbosBordes(): void
    {
        // 'mas' no debe tocar 'más': la tilde es parte de la palabra.
        $this->assertSame('un año más o menos', $this->apply('un año más o menos', [['mas', 'más']]));
    }

    public function testCorreccionDeTildeSigueFuncionando(): void
    {
        // El arreglo no puede cargarse el caso legítimo mayoritario.
        $this->assertSame('dame más agua', $this->apply('dame mas agua', [['mas', 'más']]));
        $this->assertSame('el ácido cítrico', $this->apply('el acido cítrico', [['acido', 'ácido']]));
    }

    public function testSignosDePuntuacionMultibyteSiguenSiendoFrontera(): void
    {
        // '¿' y '«' son multibyte pero NO son letras: deben permitir el match.
        // Por eso el test de frontera decodifica el carácter en vez de asumir
        // que todo byte >= 0x80 es letra.
        $this->assertSame('¿la qué?', $this->apply('¿the qué?', [['the', 'la']]));
        $this->assertSame('«la»', $this->apply('«the»', [['the', 'la']]));
    }

    public function testMatchSigueSiendoCaseInsensitive(): void
    {
        $this->assertSame('la cosa', $this->apply('THE cosa', [['the', 'la']]));
    }

    public function testContabilizaSoloReemplazosReales(): void
    {
        $service = new CorrectionService();
        $method = new \ReflectionMethod(CorrectionService::class, 'applyTextWithPairs');
        $method->setAccessible(true);

        // 'dise' aparece dentro de "diseño" pero NO debe contar como acierto:
        // el conteo viejo usaba substr_count() sin frontera y por eso
        // applies_count llegó a 84.011 para la regla 'the'.
        $hits = [];
        $pairs = [['dise', 'de'], ['the', 'la']];
        $method->invokeArgs($service, ['al diseño, the casa y the otra', $pairs, &$hits]);

        $this->assertSame([1 => 2], $hits);
    }
}
