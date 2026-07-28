<?php

namespace Tests\Unit;

use App\Services\Ia\SrtParser;
use Tests\LaravelTestCase;

/**
 * El parser de SRT, con foco en el truncado.
 *
 * El limite estuvo fijado en 500 chars "para no inflar la BD con basura". La
 * premisa era falsa: la columna es `text` (ilimitada en Postgres) y lo que se
 * cortaba era habla real de ~30s sin pausas — tipico de emisoras de radio.
 * 9.023 segmentos perdieron texto que dejo de ser buscable, a cambio de ahorrar
 * 4 MB sobre una tabla de 3,2 GB.
 *
 * Necesita el contenedor porque el limite se lee de la configuracion.
 */
class SrtParserTest extends LaravelTestCase
{
    private function srt(string $text, int $index = 1): string
    {
        return "{$index}\n00:00:0{$index},000 --> 00:00:3{$index},000\n{$text}\n\n";
    }

    private function parser(): SrtParser
    {
        return new SrtParser();
    }

    public function testParseaTimestampsYTexto(): void
    {
        $s = $this->parser()->parse("1\n00:00:01,500 --> 00:00:06,250\nHola mundo\n\n");

        $this->assertCount(1, $s);
        $this->assertSame(1, $s[0]['index']);
        $this->assertSame(1.5, $s[0]['start_seconds']);
        $this->assertSame(6.25, $s[0]['end_seconds']);
        $this->assertSame('Hola mundo', $s[0]['text']);
    }

    public function testAceptaPuntoYComaComoSeparadorDeMilisegundos(): void
    {
        $s = $this->parser()->parse("1\n00:00:01.500 --> 00:00:06.250\nTexto\n\n");

        $this->assertSame(1.5, $s[0]['start_seconds']);
    }

    public function testUneLasLineasDeUnMismoSegmento(): void
    {
        $s = $this->parser()->parse("1\n00:00:01,000 --> 00:00:05,000\nprimera\nsegunda\n\n");

        $this->assertSame('primera segunda', $s[0]['text']);
    }

    public function testContenidoVacioDevuelveVacio(): void
    {
        $this->assertSame([], $this->parser()->parse(''));
        $this->assertSame([], $this->parser()->parse("   \n  "));
    }

    /**
     * El caso que motivo el cambio: 800 caracteres de habla continua. Con el
     * limite antiguo de 500 se perdian 300 y dejaban de ser buscables.
     */
    public function testNoTruncaPorDebajoDelLimiteConfigurado(): void
    {
        config(['transcriptor.srt_max_segment_chars' => 3000]);
        $largo = str_repeat('a', 800);

        $s = $this->parser()->parse($this->srt($largo));

        $this->assertSame(800, mb_strlen($s[0]['text']), 'con el limite en 3000, 800 chars deben conservarse enteros');
    }

    public function testTruncaPorEncimaDelLimiteConfigurado(): void
    {
        config(['transcriptor.srt_max_segment_chars' => 100]);

        $s = $this->parser()->parse($this->srt(str_repeat('b', 250)));

        $this->assertSame(100, mb_strlen($s[0]['text']));
    }

    public function testLimiteCeroDesactivaElTruncado(): void
    {
        config(['transcriptor.srt_max_segment_chars' => 0]);
        $largo = str_repeat('c', 9000);

        $s = $this->parser()->parse($this->srt($largo));

        $this->assertSame(9000, mb_strlen($s[0]['text']), '0 debe significar sin limite');
    }

    /** El recorte es por caracteres, no por bytes: no debe partir un UTF-8. */
    public function testElTruncadoRespetaLosCaracteresMultibyte(): void
    {
        config(['transcriptor.srt_max_segment_chars' => 10]);

        $s = $this->parser()->parse($this->srt(str_repeat('ñ', 25)));

        $this->assertSame(10, mb_strlen($s[0]['text']));
        $this->assertSame(str_repeat('ñ', 10), $s[0]['text']);
    }

    public function testElValorHistoricoQuedaDocumentado(): void
    {
        $this->assertSame(500, SrtParser::LEGACY_MAX_SEGMENT_CHARS);
    }

    public function testCalculaDuracionYPalabras(): void
    {
        $s = $this->parser()->parse(
            "1\n00:00:01,000 --> 00:00:05,000\nuno dos tres\n\n" .
            "2\n00:00:06,000 --> 00:00:12,400\ncuatro cinco\n\n"
        );

        $this->assertCount(2, $s);
        $this->assertSame(13, $this->parser()->calculateDuration($s));
        $this->assertSame(5, $this->parser()->calculateWordCount($s));
    }
}
