<?php

namespace Tests\Unit;

use App\Services\Ia\ChannelSlug;
use PHPUnit\Framework\TestCase;

/**
 * El slug del canal decide si al material se le aplica el diccionario, así que
 * una extracción mal hecha implicaría corregir Teleislas (criollo raizal) o
 * dejar sin corregir un noticiero.
 *
 * Los nombres salen del corpus real: conviven dos convenciones y 34.497
 * transcripciones usan la segunda.
 */
class ChannelSlugTest extends TestCase
{
    /**
     * @dataProvider nombres
     */
    public function testExtraeElSlug(?string $archivo, ?string $esperado): void
    {
        $this->assertSame($esperado, ChannelSlug::fromFilename($archivo));
    }

    public static function nombres(): array
    {
        return [
            'convención simple' => ['teleisla_13082026_073002.mp4', 'teleisla'],
            'con prefijo de orden' => ['15_abc_atlantico_19072026_154003.mp3', 'abc_atlantico'],
            'nombre compuesto' => ['20_tolima_estereo_tolima_26072026_175601.mp3', 'tolima_estereo_tolima'],
            'con tilde' => ['alertabogotá_13082026_1.mp4', 'alertabogotá'],
            'mayúsculas' => ['LaFMPlus_01082026_120000.mp3', 'lafmplus'],
            'sin fecha' => ['sinfecha.mp3', 'sinfecha'],
            'solo dígitos' => ['12345678.mp3', null],
            'vacío' => ['', null],
            'nulo' => [null, null],
        ];
    }

    public function testLaFechaCortaElNombre(): void
    {
        // Sin este corte el slug arrastraría la fecha y cada emisión sería un
        // canal distinto.
        $this->assertSame('caracol', ChannelSlug::fromFilename('caracol_12072026_153001.mp4'));
        $this->assertSame('caracol', ChannelSlug::fromFilename('caracol_29082026_010203.mp4'));
    }

    public function testIgnoraNumerosSueltosQueNoSonLaFecha(): void
    {
        $this->assertSame('canal_uno', ChannelSlug::fromFilename('canal_3_uno_13082026_0.mp3'));
    }
}
