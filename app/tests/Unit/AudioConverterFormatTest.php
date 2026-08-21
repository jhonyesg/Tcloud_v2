<?php

namespace Tests\Unit;

use App\Services\Ia\AudioConverter;
use Tests\TestCase;

/**
 * La eleccion de codec y extension del archivo que se sube al transcriptor.
 *
 * Se testea aparte de convert() porque esa parte es pura: no necesita ffmpeg,
 * ni disco, ni contenedor. La extension importa mas de lo que parece — decide
 * el Content-Type del multipart.
 */
class AudioConverterFormatTest extends TestCase
{
    public function testWavUsaPcmSinBitrate(): void
    {
        $args = AudioConverter::codecArgs('wav');

        $this->assertSame(['-c:a', 'pcm_s16le'], $args);
        $this->assertNotContains('-b:a', $args, 'PCM no lleva bitrate objetivo.');
    }

    public function testOpusMantieneLibopusA64k(): void
    {
        $this->assertSame(['-c:a', 'libopus', '-b:a', '64k'], AudioConverter::codecArgs('opus'));
    }

    public function testUnFormatoDesconocidoFallaAntesDeLanzarFfmpeg(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AudioConverter::codecArgs('flac');
    }

    public function testLaExtensionCorrespondeAlFormato(): void
    {
        $this->assertSame('wav', AudioConverter::extensionFor('wav'));
        $this->assertSame('opus', AudioConverter::extensionFor('opus'));
    }
}
