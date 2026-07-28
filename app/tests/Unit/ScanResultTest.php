<?php

namespace Tests\Unit;

use App\Services\ScanResult;
use PHPUnit\Framework\TestCase;

/**
 * El objeto que distingue "directorio vacio" de "no pude leerlo".
 *
 * Antes `scanDirectory()` devolvia `[]` para cinco situaciones distintas, y
 * `StorageSyncService` interpretaba ese `[]` como "borraron todo en disco" y
 * purgaba las filas. Cuando el montaje NFS cayo el 2026-07-27, el punto de
 * montaje quedo como un directorio local vacio y legible — indistinguible de una
 * carpeta vacia real — y se borro el arbol entero de la base de datos.
 *
 * `isTrustworthyEmpty()` es el predicado que autoriza una purga: solo es cierto
 * cuando el escaneo fue fiable Y no habia nada.
 */
class ScanResultTest extends TestCase
{
    public function testOkLlevaSusEntradasYNoTieneMotivoDeFallo(): void
    {
        $r = ScanResult::ok([['name' => 'a.mp4'], ['name' => 'b.mp3']], '/data/x');

        $this->assertTrue($r->ok);
        $this->assertSame(2, $r->count());
        $this->assertNull($r->failureReason);
        $this->assertSame('/data/x', $r->path);
    }

    public function testFailedNoLlevaEntradasYSiMotivo(): void
    {
        $r = ScanResult::failed(ScanResult::NOT_READABLE, '/data/y');

        $this->assertFalse($r->ok);
        $this->assertSame([], $r->entries);
        $this->assertSame(0, $r->count());
        $this->assertSame(ScanResult::NOT_READABLE, $r->failureReason);
    }

    /** El unico caso que puede autorizar una purga. */
    public function testVacioYFiableEsElUnicoVacioDeConfianza(): void
    {
        $r = ScanResult::ok([], '/data/vacio');

        $this->assertTrue($r->isEmpty());
        $this->assertTrue($r->isTrustworthyEmpty());
    }

    /**
     * El escenario del incidente: cero entradas, pero por no poder leer. Jamas
     * debe autorizar una purga.
     */
    public function testVacioPorFalloNoEsDeConfianza(): void
    {
        foreach ([
            ScanResult::NOT_A_DIRECTORY,
            ScanResult::NOT_READABLE,
            ScanResult::SCANDIR_FAILED,
            ScanResult::EXCEPTION,
            ScanResult::DEPTH_EXCEEDED,
            ScanResult::MOUNT_DETACHED,
        ] as $reason) {
            $r = ScanResult::failed($reason);

            $this->assertTrue($r->isEmpty(), "{$reason}: no deberia traer entradas");
            $this->assertFalse($r->isTrustworthyEmpty(), "{$reason}: NUNCA debe autorizar una purga");
        }
    }

    public function testUnResultadoConEntradasNoEsVacio(): void
    {
        $r = ScanResult::ok([['name' => 'x']]);

        $this->assertFalse($r->isEmpty());
        $this->assertFalse($r->isTrustworthyEmpty());
    }

    public function testElContextoParaLogsLlevaLoNecesarioParaDiagnosticar(): void
    {
        $ctx = ScanResult::failed(ScanResult::MOUNT_DETACHED, '/mnt/nfs')->context();

        $this->assertSame(false, $ctx['ok']);
        $this->assertSame(0, $ctx['entries']);
        $this->assertSame(ScanResult::MOUNT_DETACHED, $ctx['reason']);
        $this->assertSame('/mnt/nfs', $ctx['path']);
    }

    public function testElContextoDeUnEscaneoCorrectoCuentaLasEntradas(): void
    {
        $ctx = ScanResult::ok([['name' => 'a'], ['name' => 'b'], ['name' => 'c']], '/d')->context();

        $this->assertTrue($ctx['ok']);
        $this->assertSame(3, $ctx['entries']);
        $this->assertNull($ctx['reason']);
    }

    /** Los motivos son constantes publicas porque los consumen las guardas y los logs. */
    public function testLosMotivosDeFalloSonDistintosEntreSi(): void
    {
        $reasons = [
            ScanResult::NOT_A_DIRECTORY,
            ScanResult::NOT_READABLE,
            ScanResult::SCANDIR_FAILED,
            ScanResult::EXCEPTION,
            ScanResult::DEPTH_EXCEEDED,
            ScanResult::MOUNT_DETACHED,
        ];

        $this->assertSame($reasons, array_unique($reasons));
    }

    public function testLaRutaEsOpcional(): void
    {
        $this->assertNull(ScanResult::ok([])->path);
        $this->assertNull(ScanResult::failed(ScanResult::EXCEPTION)->path);
    }
}
