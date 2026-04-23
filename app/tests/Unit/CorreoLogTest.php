<?php

namespace Tests\Unit;

use App\Modules\Correo\Models\CorreoLog;
use PHPUnit\Framework\TestCase;

class CorreoLogTest extends TestCase
{
    public function testWasSuccessfulReturnsTrueForExito(): void
    {
        $log = new CorreoLog();
        $log->estado = CorreoLog::ESTADO_EXITO;

        $this->assertTrue($log->wasSuccessful());
    }

    public function testWasSuccessfulReturnsFalseForError(): void
    {
        $log = new CorreoLog();
        $log->estado = CorreoLog::ESTADO_ERROR;

        $this->assertFalse($log->wasSuccessful());
    }

    public function testEstadoConstants(): void
    {
        $this->assertEquals('exito', CorreoLog::ESTADO_EXITO);
        $this->assertEquals('error', CorreoLog::ESTADO_ERROR);
    }
}
