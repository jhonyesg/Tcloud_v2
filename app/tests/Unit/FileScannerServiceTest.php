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

    // ------------------------------------------------- entradas ilegibles
    //
    // En los NFS de difusor01 hay 67 archivos que devuelven EIO al hacer stat.
    // Como el try/catch envolvia el bucle entero, UNA de esas entradas descartaba
    // el directorio completo: en Atlantico/BARRANQUILLA_RADIO_YA/12062026 habia 4
    // rotos y 64 sanos, y los 64 quedaban invisibles para la app.
    //
    // Un symlink colgante reproduce fielmente la condicion sin necesidad de NFS:
    // scandir() lo lista, is_dir() da false y el stat falla.

    private function crearEntradaIlegible(string $name): void
    {
        symlink($this->tmp . '/destino_que_no_existe', $this->tmp . '/' . $name);
    }

    public function testUnaEntradaIlegibleNoDescartaElRestoDelDirectorio(): void
    {
        file_put_contents($this->tmp . '/a.mp3', 'x');
        file_put_contents($this->tmp . '/b.mp3', 'yy');
        file_put_contents($this->tmp . '/c.mp3', 'zzz');
        $this->crearEntradaIlegible('roto.mp3');

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertSame(3, $r->count(), 'los archivos sanos deben seguir llegando');
        $this->assertSame(['roto.mp3'], $r->unreadable);
        $this->assertSame(
            ['a.mp3', 'b.mp3', 'c.mp3'],
            array_column($r->entries, 'name')
        );
    }

    public function testUnaEntradaIlegibleMarcaElEscaneoComoParcial(): void
    {
        file_put_contents($this->tmp . '/a.mp3', 'x');
        $this->crearEntradaIlegible('roto.mp3');

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertTrue($r->isPartial());
        $this->assertTrue($r->usable(), 'sirve para crear/actualizar filas');
        $this->assertFalse($r->ok, 'pero nunca para purgar');
        $this->assertSame(ScanResult::PARTIAL_UNREADABLE, $r->failureReason);
    }

    /** Sin entradas rotas el resultado debe seguir siendo fiable, no parcial. */
    public function testUnDirectorioSanoNoSeMarcaComoParcial(): void
    {
        file_put_contents($this->tmp . '/a.mp3', 'x');

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertTrue($r->ok);
        $this->assertFalse($r->isPartial());
        $this->assertSame([], $r->unreadable);
    }

    /**
     * El caso de Valle_Cauca/Cali_FM/10062026: la carpeta-dia entera da EIO. Se
     * lista pero no se puede consultar, asi que cuenta como entrada ilegible.
     */
    public function testTodasLasEntradasIlegiblesDejaUnParcialVacio(): void
    {
        $this->crearEntradaIlegible('roto1.mp3');
        $this->crearEntradaIlegible('roto2.mp3');

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertSame(0, $r->count());
        $this->assertCount(2, $r->unreadable);
        $this->assertTrue($r->isPartial());
        $this->assertFalse(
            $r->isTrustworthyEmpty(),
            'vacio por ilegible NO es un vacio de confianza: purgar aqui borraria la carpeta en BD'
        );
    }

    /** Las carpetas legibles se siguen describiendo bien aunque haya rotas al lado. */
    public function testLasCarpetasSanasConvivenConEntradasRotas(): void
    {
        mkdir($this->tmp . '/sub');
        $this->crearEntradaIlegible('roto.mp3');

        $r = $this->scanner()->scanDirectory($this->tmp);

        $this->assertSame(1, $r->count());
        $this->assertTrue($r->entries[0]['is_folder']);
        $this->assertSame('sub', $r->entries[0]['name']);
        $this->assertSame(0, $r->entries[0]['size']);
    }
}
