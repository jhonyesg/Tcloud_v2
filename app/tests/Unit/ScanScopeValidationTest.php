<?php

namespace Tests\Unit;

use App\Services\Ia\DiskScannerService;
use PHPUnit\Framework\TestCase;

/**
 * transcriptor-scan-scope-selector: verificación del scope shape y de la
 * validación de fechas que ambos lados (controller estimador + comando)
 * comparten vía DiskScannerService::foldersInRange().
 */
class ScanScopeValidationTest extends TestCase
{
    public function testRangoValidoProduceCarpetasDmY(): void
    {
        $folders = DiskScannerService::foldersInRange('01092026', '03092026');
        $this->assertSame(['01092026', '02092026', '03092026'], $folders);
    }

    public function testRangoInvertidoRechazado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiskScannerService::foldersInRange('03092026', '01092026');
    }

    public function testScopeRangeInyectaFoldersAlServiceShape(): void
    {
        $scope = DiskScannerService::scopeRange('01082026', '01082026');
        $this->assertSame(['mode' => 'range', 'folders' => ['01082026']], $scope);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $scope['folders'][0]);
    }
}