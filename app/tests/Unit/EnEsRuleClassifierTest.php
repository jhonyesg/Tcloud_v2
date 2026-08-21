<?php

namespace Tests\Unit;

use App\Services\Ia\EnEsRuleClassifier;
use PHPUnit\Framework\TestCase;

/**
 * El clasificador separa las correcciones legítimas de español de las
 * traducciones inglés->español que degradaban el texto. Los casos de aquí
 * salen del diccionario real de producción (2026-08-11).
 */
class EnEsRuleClassifierTest extends TestCase
{
    private EnEsRuleClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new EnEsRuleClassifier();
    }

    private function bucket(string $wrong, string $correct): string
    {
        return $this->classifier->classify($wrong, $correct)['bucket'];
    }

    /**
     * @dataProvider correccionesDeTilde
     */
    public function testCorreccionesDeTildeSeConservan(string $wrong, string $correct): void
    {
        $this->assertSame(EnEsRuleClassifier::KEEP, $this->bucket($wrong, $correct));
    }

    public static function correccionesDeTilde(): array
    {
        return [
            ['mas', 'más'],
            ['aca', 'acá'],
            ['politica', 'política'],
            ['region', 'región'],
            ['unica', 'única'],
            ['practicamente', 'prácticamente'],
        ];
    }

    /**
     * @dataProvider traduccionesEnEs
     */
    public function testTraduccionesVanACuarentena(string $wrong, string $correct): void
    {
        $this->assertSame(EnEsRuleClassifier::QUARANTINE, $this->bucket($wrong, $correct));
    }

    public static function traduccionesEnEs(): array
    {
        return [
            'palabra función'      => ['the', 'la'],
            'preposición'          => ['in', 'en'],
            'conjunción'           => ['and', 'y'],
            'cópula'               => ['are', 'están'],
            'interrogativo'        => ['where', 'donde'],
            'modismo multipalabra' => ['in the world', 'en el mundo'],
            'sustantivo acentuado' => ['football', 'fútbol'],
            'grafema no español'   => ['work', 'trabajo'],
        ];
    }

    /**
     * La frase inglesa con una tilde suelta seguía siendo traducción: mirar
     * solo si el wrong era ASCII dejaba 406 reglas de traducción marcadas
     * como "español" y activas en producción.
     */
    public function testFraseMixtaConTildeSueltaEsTraduccion(): void
    {
        $this->assertSame(
            EnEsRuleClassifier::QUARANTINE,
            $this->bucket('The canción alcanzó el número 7', 'La canción alcanzó el número 7')
        );
        $this->assertSame(
            EnEsRuleClassifier::QUARANTINE,
            $this->bucket('in Nápoles', 'en Nápoles')
        );
    }

    /**
     * 'he', 'me' y 'has' son español válido además de inglés. Meterlos en la
     * lista de palabras función marcaba correcciones legítimas como traducción.
     */
    public function testHomografosEspanolNoDisparanCuarentena(): void
    {
        $this->assertNotSame(
            EnEsRuleClassifier::QUARANTINE,
            $this->bucket('No me basta incontrar', 'No me basta encontrar')
        );
    }

    /**
     * Una frase realmente inglesa con 'me' sigue cayendo: trae otros marcadores.
     */
    public function testFraseInglesaConMeSigueDetectandose(): void
    {
        $this->assertSame(
            EnEsRuleClassifier::QUARANTINE,
            $this->bucket('Give it to me', 'Dámelo')
        );
    }

    /**
     * @dataProvider ambiguas
     */
    public function testAmbiguasQuedanParaRevisionManual(string $wrong, string $correct): void
    {
        $this->assertSame(EnEsRuleClassifier::REVIEW, $this->bucket($wrong, $correct));
    }

    public static function ambiguas(): array
    {
        return [
            'typo español real' => ['echa', 'hecha'],
            'typo con q'        => ['quando', 'cuando'],
            'basura del miner'  => ['dise', 'de'],
        ];
    }

    public function testEspanolCorrigiendoEspanolSeConserva(): void
    {
        $this->assertSame(
            EnEsRuleClassifier::KEEP,
            $this->bucket('una strategia de prevención', 'una estrategia de prevención')
        );
    }

    public function testParVacioNoRevienta(): void
    {
        $this->assertSame(EnEsRuleClassifier::REVIEW, $this->bucket('', 'algo'));
        $this->assertSame(EnEsRuleClassifier::REVIEW, $this->bucket('algo', ''));
    }

    // ============ Cubo NOISE: reglas que no cambian nada ============

    /**
     * @dataProvider inertes
     */
    public function testReglasQueNoCambianNadaSonRuido(string $texto): void
    {
        // Antes caían en KEEP como "corrección de tilde/mayúsculas", porque el
        // test de tilde pliega ambos lados al mismo ASCII. Así se colaron 48
        // reglas inertes en el diccionario de producción.
        $this->assertSame(EnEsRuleClassifier::NOISE, $this->bucket($texto, $texto));
    }

    public static function inertes(): array
    {
        return [
            'nombre propio'  => ['Powerball'],
            'palabra suelta' => ['ataque'],
            'con tilde'      => ['región'],
            'frase entera'   => ['el partido de ayer'],
        ];
    }

    public function testElRuidoNoSeTragaLasCorreccionesDeTilde(): void
    {
        // La igualdad es EXACTA a propósito: `mas`->`más` cambia el texto.
        $this->assertSame(EnEsRuleClassifier::KEEP, $this->bucket('mas', 'más'));
        $this->assertSame(EnEsRuleClassifier::KEEP, $this->bucket('region', 'región'));
    }

    // ============ isMisspelledSpanish: typo vs cambio de significado ============

    /**
     * @dataProvider erroresOrtograficos
     */
    public function testDetectaEspanolMalEscrito(string $wrong): void
    {
        // Consonante doble imposible en español sobre una palabra con forma
        // española: es un typo del ASR y arreglarlo no cambia el significado.
        $this->assertTrue($this->classifier->isMisspelledSpanish($wrong));
    }

    public static function erroresOrtograficos(): array
    {
        return [
            'pp' => ['opportunidades'],
            'ff' => ['Affectados'],
            'ss' => ['necessariamente'],
            'ff y ss' => ['professionales'],
        ];
    }

    /**
     * @dataProvider noSonErroresOrtograficos
     */
    public function testNoConfundeCambioDeSignificadoConTypo(string $wrong, string $motivo): void
    {
        $this->assertFalse($this->classifier->isMisspelledSpanish($wrong), $motivo);
    }

    public static function noSonErroresOrtograficos(): array
    {
        return [
            // Palabras españolas perfectamente válidas: sustituirlas cambia el
            // sentido, no arregla una falta. Son la clase que degradaba el texto.
            'género' => ['presidenta', 'es una palabra española válida'],
            'matiz'  => ['ahorita', 'es una palabra española válida'],
            'tilde'  => ['region', 'solo le falta la tilde'],
            // Palabras inglesas: tampoco son español mal escrito, son otro
            // idioma. Sustituirlas es traducir, y eso lo veta el cubo QUARANTINE.
            'inglés en -t'  => ['innocent', 'es inglés, no español mal escrito'],
            'inglés en -t2' => ['different', 'es inglés, no español mal escrito'],
            'inglés en -y'  => ['interdisciplinary', 'es inglés, no español mal escrito'],
            'inglés corto'  => ['top', 'es inglés, no español mal escrito'],
            'con w'         => ['Powerball', 'la w no existe en español'],
        ];
    }

    public function testSufijosQueSiExistenEnEspanolNoSeDescartan(): void
    {
        // -ible/-able y -sion son españoles (`posible`, `inversión` sin tilde);
        // meterlos en la lista de sufijos ingleses habría roto casos legítimos.
        $this->assertFalse($this->classifier->isMisspelledSpanish('inversion'));
        $this->assertTrue($this->classifier->isMisspelledSpanish('Possiblemente'));
    }

    public function testCadenaVaciaNoRevienta(): void
    {
        $this->assertFalse($this->classifier->isMisspelledSpanish(''));
    }

    // ============ looksSpanishWord: prueba positiva de morfología ============

    /**
     * @dataProvider palabrasEspanolas
     */
    public function testReconoceFormaEspanola(string $word): void
    {
        $this->assertTrue($this->classifier->looksSpanishWord($word), $word);
    }

    public static function palabrasEspanolas(): array
    {
        return [
            ['emergencia'], ['seguridad'], ['gobierno'], ['cali'],
            ['región'], ['información'], ['posible'], ['inversion'],
        ];
    }

    /**
     * @dataProvider palabrasNoEspanolas
     */
    public function testDescartaFormaNoEspanola(string $word): void
    {
        $this->assertFalse($this->classifier->looksSpanishWord($word), $word);
    }

    public static function palabrasNoEspanolas(): array
    {
        return [
            // El caso que originó la regla `of emergency` -> `de emergency`:
            // la denylist de CycleSuggestionsCommand no lo tenía y pasó.
            'termina en -y'  => ['emergency'],
            'termina en -y2' => ['security'],
            'termina en -t'  => ['august'],
            'con w'          => ['powerball'],
            'con th'         => ['thing'],
            'sufijo -tion'   => ['information'],
            'sufijo -ing'    => ['shopping'],
            'muy corta'      => ['a'],
        ];
    }
}
