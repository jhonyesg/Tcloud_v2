<?php

namespace Tests\Unit;

use App\Models\Keyword;
use App\Services\Ia\KeywordBoundaryMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Regresión de fronteras de palabra (avisos-keyword-word-boundary).
 *
 * El matching por substring crudo producía falsos positivos del tipo
 * "petro" ∈ "petróleo"/"Petromil" (2,929 de 8,648 hits en producción).
 * Estos casos replican los escenarios del spec avisos-keyword-word-boundary.
 */
class KeywordBoundaryMatcherTest extends TestCase
{
    private function norm(string $t): string
    {
        return Keyword::asciiLower($t);
    }

    public function testPalabraCompletaAisladaMatchea(): void
    {
        $this->assertTrue(
            KeywordBoundaryMatcher::matchesWord($this->norm('el gobierno de Petro'), 'petro')
        );
    }

    public function testNombreCompuestoMatcheaSinKeywordAdicional(): void
    {
        $this->assertTrue(
            KeywordBoundaryMatcher::matchesWord($this->norm('Gustavo Petro'), 'petro')
        );
    }

    public function testPrefijoDePalabraDerivadaSeDescarta(): void
    {
        $this->assertFalse(
            KeywordBoundaryMatcher::matchesWord($this->norm('como en Cali puede ser Petróleo'), 'petro')
        );
    }

    public function testMarcaConPrefijoSeDescarta(): void
    {
        $this->assertFalse(
            KeywordBoundaryMatcher::matchesWord($this->norm('Bienvenidos a Petromil'), 'petro')
        );
    }

    public function testDerivacionSeDescarta(): void
    {
        $this->assertFalse(
            KeywordBoundaryMatcher::matchesWord($this->norm('el petrismo no es'), 'petro')
        );
    }

    public function testSubcadenaInternaSeDescarta(): void
    {
        $this->assertFalse(
            KeywordBoundaryMatcher::matchesWord($this->norm('un caso subpetrolero'), 'petro')
        );
    }

    public function testPuntuacionEsFronteraValida(): void
    {
        $this->assertTrue(
            KeywordBoundaryMatcher::matchesWord($this->norm('Petro, dijo'), 'petro')
        );
    }

    public function testCaracterUtf8AdyacenteSeEvaluaCompleto(): void
    {
        // El byte líder de 'á' (0xC3) NO debe contar como frontera: "áPetro"
        // tiene letra pegada a la izquierda → frontera izquierda falla.
        $this->assertFalse(
            KeywordBoundaryMatcher::matchesWord($this->norm('áPetro'), 'petro')
        );
        // Pero 'á Petro á' sí: los espacios son fronteras válidas aunque
        // rodeen un carácter multibyte.
        $this->assertTrue(
            KeywordBoundaryMatcher::matchesWord($this->norm('á Petro á'), 'petro')
        );
    }

    public function testConteoMixtoCuentaSoloFronteras(): void
    {
        $this->assertSame(
            1,
            KeywordBoundaryMatcher::countOccurrences($this->norm('petróleo, Petro y Petromil'), 'petro')
        );
    }

    public function testConteoMultiplePalabraRepetida(): void
    {
        $this->assertSame(
            3,
            KeywordBoundaryMatcher::countOccurrences($this->norm('Petro habló con Petro y más Petro'), 'petro')
        );
    }

    public function testConteoCeroSinMatch(): void
    {
        $this->assertSame(
            0,
            KeywordBoundaryMatcher::countOccurrences($this->norm('petróleo Petromil petrolero'), 'petro')
        );
    }

    public function testPosicionParaSnippet(): void
    {
        $hay = $this->norm('Bienvenidos a Petromil y luego Petro');
        $pos = KeywordBoundaryMatcher::firstPosition($hay, 'petro');
        $this->assertNotNull($pos);
        // Debe apuntar a la segunda aparición ("Petro" final), no a "Petromil".
        $this->assertSame('petro', substr($hay, $pos, 5));
        $this->assertSame(' ', substr($hay, $pos - 1, 1));
    }

    public function testPosicionNullSinMatch(): void
    {
        $this->assertNull(
            KeywordBoundaryMatcher::firstPosition($this->norm('puede ser Petróleo'), 'petro')
        );
    }

    public function testAgujaVacia(): void
    {
        $this->assertFalse(KeywordBoundaryMatcher::matchesWord('algo', ''));
        $this->assertSame(0, KeywordBoundaryMatcher::countOccurrences('algo', ''));
        $this->assertNull(KeywordBoundaryMatcher::firstPosition('algo', ''));
    }

    public function testSegmentoRealDeProduccionSeDescarta(): void
    {
        // El segmento que disparó el reporte del operador.
        $seg = 'Sanpacho es como nuestra industria cultural y creativa más grande, '
            . 'como en Cali puede ser Petróleo, Felia de Cali, para nosotros era San Pacho.';
        $this->assertFalse(KeywordBoundaryMatcher::matchesWord(Keyword::asciiLower($seg), 'petro'));
    }
}