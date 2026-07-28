<?php

namespace Tests\Unit;

use App\Services\FileScannerService;
use App\Services\ScanResult;
use PHPUnit\Framework\TestCase;

/**
 * El test mas importante de este arreglo: fija que "vacio" y "no puedo leer"
 * son resultados DISTINTOS.
 *
 * Antes scanDirectory() devolvia [] para ambos, y StorageSyncService leia ese []
 * como "borraron todo el contenido en disco" y purgaba las filas. Cuando el
 * montaje NFS cayo el 2026-07-27, el punto de montaje quedo como un directorio
 * local vacio y legible — indistinguible de una carpeta vacia — y se borro el
 * arbol completo de la base de datos.
 *
 * Solo toca sistema de archivos: no necesita Laravel ni base de datos.
 */
class FileScannerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/scanner_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmp);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        @chmod($path, 0777);
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    $this->rmrf($path . '/' . $e);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    private function scanner(): FileScannerService
    {
        return new FileScannerService();
    }

    public function testDirectorioPobladoDevuelveSusEntradas(): void
    {
        file_put_contents($this->tmp . '/a.mp4', 'x');
        file_put_contents($this->tmp . '/b.mp3', 'yy');
        mkdir($this->tmp . '/sub');

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertTrue($r->ok);
        $this->assertSame(3, $r->count());
        $this->assertNull($r->failureReason);
    }

    /**
     * El caso central. Un directorio vacio de VERDAD es un resultado fiable con
     * cero entradas — y solo en este caso una purga estaria justificada.
     */
    public function testDirectorioVacioEsFiableYVacio(): void
    {
        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertTrue($r->ok, 'un directorio vacio real debe considerarse fiable');
        $this->assertSame(0, $r->count());
        $this->assertTrue($r->isTrustworthyEmpty());
    }

    /**
     * El caso que provoco el incidente. Sin permiso de lectura NO es fiable, por
     * mucho que devuelva cero entradas.
     */
    public function testDirectorioIlegibleNoEsFiable(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root ignora los permisos de archivo');
        }

        chmod($this->tmp, 0000);

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertFalse($r->ok);
        $this->assertSame(ScanResult::NOT_READABLE, $r->failureReason);
        $this->assertFalse($r->isTrustworthyEmpty(), 'un directorio ilegible NUNCA es un vacio fiable');
    }

    public function testRutaInexistenteNoEsFiable(): void
    {
        $r = $this->scanner()->scanDirectory($this->tmp . '/no_existe');

        $this->assertFalse($r->ok);
        $this->assertSame(ScanResult::NOT_A_DIRECTORY, $r->failureReason);
    }

    public function testUnArchivoNoEsUnDirectorio(): void
    {
        $file = $this->tmp . '/suelto.txt';
        file_put_contents($file, 'x');

        $r = $this->scanner()->scanDirectory($file);

        $this->assertFalse($r->ok);
        $this->assertSame(ScanResult::NOT_A_DIRECTORY, $r->failureReason);
    }

    public function testProfundidadExcedidaNoEsFiable(): void
    {
        $r = $this->scanner()->scanDirectory($this->tmp, 999);

        $this->assertFalse($r->ok);
        $this->assertSame(ScanResult::DEPTH_EXCEEDED, $r->failureReason);
    }

    /**
     * Un montaje NFS caido se comporta exactamente asi: el directorio existe, es
     * legible, y esta vacio. El escaner no puede distinguirlo por si solo — de
     * ahi PruneGuard y MountGuard. Este test documenta el limite.
     */
    public function testMontajeCaidoSeParecEaUnDirectorioVacio(): void
    {
        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertTrue($r->isTrustworthyEmpty(),
            'el escaner por si solo no puede detectar un montaje caido: por eso existen PruneGuard y MountGuard');
    }

    public function testLasEntradasLlevanLosMetadatosQueUsaElSync(): void
    {
        file_put_contents($this->tmp . '/video.mp4', str_repeat('x', 42));

        $entry = $this->scanner()->scanDirectory($this->tmp)->entries[0];

        $this->assertSame('video.mp4', $entry['name']);
        $this->assertFalse($entry['is_folder']);
        $this->assertSame(42, $entry['size']);
        $this->assertSame('video/mp4', $entry['mime_type']);
        $this->assertIsInt($entry['modified_at']);
    }
}
