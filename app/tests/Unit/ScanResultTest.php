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
            ScanResult::PARTIAL_UNREADABLE,
        ];

        $this->assertSame($reasons, array_unique($reasons));
    }

    // ------------------------------------------------- escaneo parcial
    //
    // Los NFS de difusor01 tienen archivos que devuelven EIO. Descartar el
    // directorio entero al primer fallo dejaba invisibles 64 archivos sanos de
    // una carpeta que solo tenia 4 rotos.

    public function testParcialConservaLasEntradasQueSiSePudieronLeer(): void
    {
        $r = ScanResult::partial(
            [['name' => 'a.mp3'], ['name' => 'b.mp3']],
            ['roto.mp3'],
            '/data/12062026'
        );

        $this->assertSame(2, $r->count());
        $this->assertFalse($r->isEmpty());
        $this->assertSame(['roto.mp3'], $r->unreadable);
        $this->assertSame(ScanResult::PARTIAL_UNREADABLE, $r->failureReason);
        $this->assertSame('/data/12062026', $r->path);
    }

    /**
     * El nucleo del diseño: parcial es utilizable para crear filas pero NO fiable
     * para borrarlas. `ok` en false hace que PruneGuard lo rechace sin logica
     * propia — lo que no se pudo leer no puede contarse como desaparecido.
     */
    public function testParcialEsUtilizablePeroNuncaAutorizaUnaPurga(): void
    {
        $r = ScanResult::partial([['name' => 'a.mp3']], ['roto.mp3']);

        $this->assertTrue($r->usable(), 'debe servir para crear/actualizar');
        $this->assertFalse($r->ok, 'no debe autorizar la purga');
        $this->assertTrue($r->isPartial());
        $this->assertFalse($r->isTrustworthyEmpty());
    }

    /** Caso limite: TODAS las entradas ilegibles. Utilizable, pero sin nada que crear. */
    public function testParcialSinNingunaEntradaLegibleSigueSinAutorizarPurga(): void
    {
        $r = ScanResult::partial([], ['a.mp3', 'b.mp3'], '/data/08062026');

        $this->assertTrue($r->isEmpty());
        $this->assertTrue($r->usable());
        $this->assertFalse($r->ok);
        $this->assertFalse(
            $r->isTrustworthyEmpty(),
            'vacio PORQUE no se pudo leer: es justo el caso que provoco el incidente'
        );
    }

    public function testOkYFailedRespondenAUsableComoCorresponde(): void
    {
        $this->assertTrue(ScanResult::ok([])->usable());
        $this->assertFalse(ScanResult::ok([])->isPartial());

        $this->assertFalse(ScanResult::failed(ScanResult::MOUNT_DETACHED)->usable());
        $this->assertFalse(ScanResult::failed(ScanResult::MOUNT_DETACHED)->isPartial());
    }

    public function testElContextoDeUnParcialCuentaLasEntradasIlegibles(): void
    {
        $ctx = ScanResult::partial([['name' => 'a']], ['x.mp3', 'y.mp3'], '/d')->context();

        $this->assertSame(1, $ctx['entries']);
        $this->assertSame(2, $ctx['unreadable']);
        $this->assertSame(['x.mp3', 'y.mp3'], $ctx['unreadable_sample']);
        $this->assertSame(ScanResult::PARTIAL_UNREADABLE, $ctx['reason']);
    }

    /** Una carpeta-dia rota puede tener decenas de entradas: el log no las vuelca todas. */
    public function testElContextoAcotaLaMuestraDeIlegibles(): void
    {
        $unreadable = array_map(fn ($i) => "f{$i}.mp3", range(1, 30));

        $ctx = ScanResult::partial([], $unreadable)->context();

        $this->assertSame(30, $ctx['unreadable']);
        $this->assertCount(10, $ctx['unreadable_sample']);
    }

    public function testUnEscaneoSinIncidenciasNoEnsuciaElContexto(): void
    {
        $ctx = ScanResult::ok([['name' => 'a']], '/d')->context();

        $this->assertArrayNotHasKey('unreadable', $ctx);
        $this->assertArrayNotHasKey('unreadable_sample', $ctx);
    }

    public function testLaRutaEsOpcional(): void
    {
        $this->assertNull(ScanResult::ok([])->path);
        $this->assertNull(ScanResult::failed(ScanResult::EXCEPTION)->path);
    }
}
