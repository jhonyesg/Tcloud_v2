<?php

namespace Tests\Unit;

use App\Support\Utf8;
use PHPUnit\Framework\TestCase;

/**
 * Reparación de la doble codificación UTF-8 (mojibake).
 *
 * Un texto UTF-8 leído como Latin-1 produce "Ã¡" donde debería decir "á".
 * El nombre de un storage de producción quedó así ("30 Emisora del
 * AtlÃ¡ntico") y el medio heredó el nombre con el error.
 */
class Utf8RepairTest extends TestCase
{
    public function test_repara_el_mojibake_de_utf8_leido_como_latin1(): void
    {
        $this->assertSame('Atlántico', Utf8::repair('AtlÃ¡ntico'));
        $this->assertSame('30 Emisora del Atlántico', Utf8::repair('30 Emisora del AtlÃ¡ntico'));
    }

    public function test_no_toca_el_texto_que_ya_esta_bien(): void
    {
        $this->assertSame('01 Caracol Tv', Utf8::repair('01 Caracol Tv'));
        $this->assertSame('Alcalá', Utf8::repair('Alcalá'));
        $this->assertSame('Café con Ñ', Utf8::repair('Café con Ñ'));
        $this->assertSame('', Utf8::repair(''));
    }

    public function test_la_reparacion_es_idempotente(): void
    {
        $una = Utf8::repair('AtlÃ¡ntico');

        $this->assertSame($una, Utf8::repair($una));
    }

    public function test_no_inventa_interrogantes_que_el_original_no_tenia(): void
    {
        // La "ñ" no tiene equivalente en Latin-1: la reparación no debe
        // empeorar el texto original.
        $this->assertSame('ÃaÃ±o', Utf8::repair('ÃaÃ±o'));
    }
}
