<?php

namespace Tests\Unit;

use App\Services\PruneGuard;
use PHPUnit\Framework\TestCase;

/**
 * Regla 5 (orphan_linked): una candidata a purga que tiene FKs aguas abajo
 * (transcriptions.file_id, shares.file_id, media_edit_jobs.source_file_id)
 * NUNCA se borra automaticamente. Se marca 'missing' en su lugar, preservando
 * el trabajo terminado aguas abajo.
 *
 * La salvaguarda se evalua ANTES de forced() para que ni el boton Actualizar
 * la levante: desacoplar el vinculo es una decision humana que hara una
 * version futura.
 */
class PruneGuardOrphanLinkedTest extends TestCase
{
    private function guard(): PruneGuard
    {
        return new PruneGuard([
            'enabled' => true,
            'refuse_on_empty' => true,
            'min_rows_for_ratio' => 5,
            'max_delete_ratio' => 0.34,
        ]);
    }

    /**
     * Auto-sync con huérfanos vinculados: rechaza con orphan_linked, NO
     * autoriza el borrado que arrastraria el CASCADE.
     */
    public function testAutoSyncConVinculadosRechazaPorOrphanLinked(): void
    {
        $d = $this->guard()->decide(
            dbCount: 100,
            diskCount: 80,
            scanOk: true,
            linkedCount: 30,
            forced: false,
        );

        $this->assertTrue($d->refused());
        $this->assertSame('orphan_linked', $d->reason);
        $this->assertSame(30, $d->context['linked']);
    }

    /**
     * La regla 5 NO se levanta con forced=true (boton Actualizar):
     * sigue marcando 'missing' las vinculadas y borrando solo las no-vinculadas.
     */
    public function testForcePruneNoSalvaVinculados(): void
    {
        $d = $this->guard()->decide(
            dbCount: 100,
            diskCount: 80,
            scanOk: true,
            linkedCount: 5,
            forced: true,
        );

        $this->assertTrue($d->refused(), '--force-prune no puede borrar filas con trabajo aguas abajo');
        $this->assertSame('orphan_linked', $d->reason);
    }

    /**
     * Sin vinculados, el camino normal sigue intacto: ratio 20% permitido.
     */
    public function testSinVinculadosRatioNormalSePermite(): void
    {
        $d = $this->guard()->decide(
            dbCount: 100,
            diskCount: 80,
            scanOk: true,
            linkedCount: 0,
            forced: false,
        );

        $this->assertTrue($d->allowed);
    }

    /**
     * Sin vinculados y con force: borrado legitimo por orden explicita.
     */
    public function testSinVinculadosConForcePermite(): void
    {
        $d = $this->guard()->decide(
            dbCount: 100,
            diskCount: 80,
            scanOk: true,
            linkedCount: 0,
            forced: true,
        );

        $this->assertTrue($d->allowed);
        $this->assertSame('forced', $d->reason);
    }

    /**
     * Escaneo no fiable sigue bloqueando TODO, incluyendo la proteccion
     * de vinculados: si el scanner no se fia, ni siquiera la marca 'missing'
     * es segura porque podria afectar filas que en realidad si estan.
     */
    public function testScanUntrustedBloqueaAntesQueVinculados(): void
    {
        $d = $this->guard()->decide(
            dbCount: 100,
            diskCount: 80,
            scanOk: false,
            linkedCount: 30,
            forced: true,
        );

        $this->assertTrue($d->refused());
        $this->assertSame('scan_untrusted', $d->reason);
    }
}