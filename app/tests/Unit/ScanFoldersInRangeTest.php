<?php

namespace Tests\Unit;

use App\Services\Ia\DiskScannerService;
use PHPUnit\Framework\TestCase;

/**
 * transcriptor-scan-scope-selector: constructor de la lista de carpetas
 * 'dmY' para un rango [from, to] explícito.
 */
class ScanFoldersInRangeTest extends TestCase
{
    public function testRangoDeTresDiasConsecutivos(): void
    {
        $this->assertSame(
            ['01082026', '02082026', '03082026'],
            DiskScannerService::foldersInRange('01082026', '03082026')
        );
    }

    public function testRangoDeUnSoloDia(): void
    {
        $this->assertSame(
            ['05092026'],
            DiskScannerService::foldersInRange('05092026', '05092026')
        );
    }

    public function testCruceDeMes(): void
    {
        $this->assertSame(
            ['31012026', '01022026', '02022026'],
            DiskScannerService::foldersInRange('31012026', '02022026')
        );
    }

    public function testFormatoInvertidoSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiskScannerService::foldersInRange('05092026', '01092026');
    }

    public function testFormatoIncorrectoSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiskScannerService::foldersInRange('2026-09-01', '2026-09-05');
    }

    public function testFechaInexistenteSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 31 de febrero: DateTime malnormaliza a 3-marzo; el parse estricto lo descarta.
        DiskScannerService::foldersInRange('31022026', '05032026');
    }

    public function testCotaDeSeguridadCubreUnAnio(): void
    {
        // 2026 no es bisiesto: 01ene→31dic = 365 carpetas.
        $names = DiskScannerService::foldersInRange('01012026', '31122026');
        $this->assertCount(365, $names);
        $this->assertSame('01012026', $names[0]);
        $this->assertSame('31122026', $names[364]);
    }

    public function testScopeHelpers(): void
    {
        $today = DiskScannerService::scopeToday();
        $this->assertSame('today', $today['mode']);

        $range = DiskScannerService::scopeRange('01082026', '02082026');
        $this->assertSame('range', $range['mode']);
        $this->assertSame(['01082026', '02082026'], $range['folders']);

        $all = DiskScannerService::scopeAll();
        $this->assertSame('all', $all['mode']);
    }
}