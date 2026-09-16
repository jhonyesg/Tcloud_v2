<?php

namespace Tests\Unit;

use App\Services\PruneGuard;
use PHPUnit\Framework\TestCase;

/**
 * PruneGuard es la barrera que habria evitado el incidente del 2026-07-27:
 * un escaneo vacio sobre un NFS caido no puede autorizar el borrado de 1M filas.
 *
 * Funcion pura: se le inyecta la config, sin Laravel ni base de datos.
 */
class PruneGuardTest extends TestCase
{
    private function guard(array $overrides = []): PruneGuard
    {
        return new PruneGuard($overrides + [
            'enabled' => true,
            'refuse_on_empty' => true,
            'min_rows_for_ratio' => 5,
            'max_delete_ratio' => 0.34,
        ]);
    }

    /** Regla 1: sin escaneo fiable no se purga jamas — ni con --force. */
    public function testEscaneoNoFiableSiempreRechaza(): void
    {
        $d = $this->guard()->decide(dbCount: 100, diskCount: 100, scanOk: false);

        $this->assertTrue($d->refused());
        $this->assertSame('scan_untrusted', $d->reason);
    }

    public function testEscaneoNoFiableRechazaInclusoConForce(): void
    {
        $d = $this->guard()->decide(dbCount: 100, diskCount: 0, scanOk: false, forced: true);

        $this->assertTrue($d->refused(), '--force no puede saltarse la fiabilidad del escaneo');
        $this->assertSame('scan_untrusted', $d->reason);
    }

    /** Regla 2: el escenario exacto del incidente. */
    public function testDiscoVacioConFilasEnBdRechaza(): void
    {
        $d = $this->guard()->decide(dbCount: 1000, diskCount: 0, scanOk: true);

        $this->assertTrue($d->refused());
        $this->assertSame('empty_scan', $d->reason);
    }

    public function testDiscoVacioSinFilasEnBdPermite(): void
    {
        $d = $this->guard()->decide(dbCount: 0, diskCount: 0, scanOk: true);

        $this->assertTrue($d->allowed, 'no hay nada que borrar: no hay motivo para rechazar');
    }

    /** Regla 3: borrado masivo proporcional. */
    public function testBorradoMasivoProporcionalRechaza(): void
    {
        // 100 en BD, 10 en disco => se borraria el 90%
        $d = $this->guard()->decide(dbCount: 100, diskCount: 10, scanOk: true);

        $this->assertTrue($d->refused());
        $this->assertSame('mass_delete_ratio', $d->reason);
        $this->assertSame(0.9, $d->context['ratio']);
    }

    public function testBorradoModeradoSePermite(): void
    {
        // 100 en BD, 80 en disco => 20%, por debajo del 34%
        $d = $this->guard()->decide(dbCount: 100, diskCount: 80, scanOk: true);

        $this->assertTrue($d->allowed);
    }

    /**
     * En carpetas diminutas el ratio no aplica: borrar 1 de 2 archivos es
     * legitimo y superaria cualquier umbral.
     */
    public function testCarpetasPequenasNoAplicanElRatio(): void
    {
        $d = $this->guard()->decide(dbCount: 3, diskCount: 1, scanOk: true);

        $this->assertTrue($d->allowed, 'por debajo de min_rows_for_ratio no se aplica la regla de proporcion');
    }

    /**
     * Rotacion diaria: los storages de prensa conservan ~2 dias en disco y la BD
     * lleva meses. Automaticamente se rechaza — bien, porque es indistinguible de
     * un disco que dejo de responder. El boton Actualizar manda `forced` y por eso
     * es la unica via de reconciliarlos.
     *
     * `dbCount` es el TOTAL de la carpeta, no los huerfanos: el llamador pasaba lo
     * segundo y el ratio medida algo sin sentido.
     */
    public function testRotacionDiariaSoloSeReconciliaForzando(): void
    {
        $automatico = $this->guard()->decide(dbCount: 118, diskCount: 4, scanOk: true);
        $manual = $this->guard()->decide(dbCount: 118, diskCount: 4, scanOk: true, forced: true);

        $this->assertTrue($automatico->refused());
        $this->assertSame('mass_delete_ratio', $automatico->reason);
        $this->assertTrue($manual->allowed, 'una orden explicita del operador si reconcilia');
    }

    /**
     * El caso que el llamador roto dejaba pasar: 100 filas, 40 desaparecen.
     *
     * Con el conteo de huerfanos (40) como dbCount y 60 en disco, max(0, 40-60)
     * daba 0 y el ratio salia 0 — permitia borrar el 40% saltandose el umbral del
     * 34%. Con el total como denominador la regla hace lo que su nombre dice.
     */
    public function testBorradoDelCuarentaPorCientoSuperaElUmbral(): void
    {
        $d = $this->guard()->decide(dbCount: 100, diskCount: 60, scanOk: true);

        $this->assertTrue($d->refused());
        $this->assertSame(0.4, $d->context['ratio']);
    }

    /** Regla 4: --force salta las reglas 2 y 3. */
    public function testForceSaltaVacioYRatio(): void
    {
        $vacio = $this->guard()->decide(dbCount: 1000, diskCount: 0, scanOk: true, forced: true);
        $ratio = $this->guard()->decide(dbCount: 100, diskCount: 10, scanOk: true, forced: true);

        $this->assertTrue($vacio->allowed);
        $this->assertTrue($ratio->allowed);
        $this->assertSame('forced', $vacio->reason);
    }

    public function testPurgaDesactivadaRechazaTodo(): void
    {
        $d = $this->guard(['enabled' => false])->decide(dbCount: 10, diskCount: 10, scanOk: true);

        $this->assertTrue($d->refused());
        $this->assertSame('prune_disabled', $d->reason);
    }

    public function testRefuseOnEmptyEsDesactivable(): void
    {
        $d = $this->guard(['refuse_on_empty' => false, 'min_rows_for_ratio' => 99999])
            ->decide(dbCount: 10, diskCount: 0, scanOk: true);

        $this->assertTrue($d->allowed);
    }

    public function testUmbralDeRatioConfigurable(): void
    {
        // Con el umbral al 95%, borrar el 90% pasa
        $d = $this->guard(['max_delete_ratio' => 0.95])->decide(dbCount: 100, diskCount: 10, scanOk: true);

        $this->assertTrue($d->allowed);
    }

    /**
     * Reconstruccion del incidente: storage 134, 51 filas en BD, 0 en disco
     * porque el montaje se cayo.
     */
    public function testReconstruccionDelIncidenteDel27Julio(): void
    {
        $d = $this->guard()->decide(dbCount: 51, diskCount: 0, scanOk: true);

        $this->assertTrue($d->refused(), 'esta guarda por si sola habria evitado el borrado en cascada');
        $this->assertSame('empty_scan', $d->reason);
    }
}
