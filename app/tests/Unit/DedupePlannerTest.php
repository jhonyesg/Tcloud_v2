<?php

namespace Tests\Unit;

use App\Services\Dedupe\DedupePlanner;
use PHPUnit\Framework\TestCase;

/**
 * El algoritmo de de-duplicado, sin base de datos.
 *
 * Lo que se prueba aqui es lo que hace seguro borrar 70.762 filas en produccion:
 * sobre todo que NINGUN superviviente quede colgando de un padre condenado,
 * porque `files_parent_id_fkey` es ON DELETE CASCADE y arrastraria subarboles
 * legitimos.
 */
class DedupePlannerTest extends TestCase
{
    private function row(int $id, string $path, ?int $parentId = null, int $storage = 1, bool $tx = false): array
    {
        return [
            'id' => $id,
            'storage_provider_id' => $storage,
            'path' => $path,
            'parent_id' => $parentId,
            'has_transcription' => $tx,
        ];
    }

    private function planner(): DedupePlanner
    {
        return new DedupePlanner();
    }

    public function testSinDuplicadosElPlanEstaVacio(): void
    {
        $plan = $this->planner()->plan([
            $this->row(1, 'a'),
            $this->row(2, 'b'),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(0, $plan->doomedCount());
    }

    public function testSobreviveElIdMenor(): void
    {
        $plan = $this->planner()->plan([
            $this->row(7, 'Choco'),
            $this->row(3, 'Choco'),
            $this->row(9, 'Choco'),
        ]);

        $this->assertSame([3], $plan->survivors);
        $this->assertEqualsCanonicalizing([7, 9], $plan->doomed);
        $this->assertSame(3, $plan->survivorOf[7]);
        $this->assertSame(3, $plan->survivorOf[9]);
    }

    /** No tirar trabajo de GPU: la copia con transcripcion gana al id menor. */
    public function testLaCopiaConTranscripcionTienePreferencia(): void
    {
        $plan = $this->planner()->plan([
            $this->row(3, 'v.mp4', tx: false),
            $this->row(7, 'v.mp4', tx: true),
        ]);

        $this->assertSame([7], $plan->survivors);
        $this->assertSame([3], $plan->doomed);
    }

    public function testMismaRutaEnStoragesDistintosNoEsDuplicado(): void
    {
        // Los storages se solapan fisicamente: el mismo archivo existe
        // legitimamente bajo varios storage_provider_id.
        $plan = $this->planner()->plan([
            $this->row(1, 'Disco_A/x.mp4', storage: 5),
            $this->row(2, 'Disco_A/x.mp4', storage: 6),
        ]);

        $this->assertTrue($plan->isEmpty());
    }

    /** El caso del incidente: carpeta duplicada con su subarbol replicado. */
    public function testSubarbolDuplicadoSeFusionaEnUnaPasada(): void
    {
        $rows = [
            $this->row(10, 'Choco'),                  // superviviente
            $this->row(11, 'Choco'),                  // duplicado
            $this->row(20, 'Choco/dia', 10),
            $this->row(21, 'Choco/dia', 11),          // duplicado bajo el padre duplicado
            $this->row(30, 'Choco/dia/a.mp4', 20),
            $this->row(31, 'Choco/dia/a.mp4', 21),    // duplicado a dos niveles
        ];

        $plan = $this->planner()->plan($rows);

        $this->assertEqualsCanonicalizing([11, 21, 31], $plan->doomed);

        // El hijo del padre condenado se re-parenta al superviviente.
        $this->assertSame(10, $plan->reparent[21]);
        $this->assertSame(20, $plan->reparent[31]);

        $after = $plan->applyTo($rows);
        $this->assertCount(3, $after);
        $this->assertTrue(
            $plan->noSurvivorHangsFromDoomedParent($after),
            'ningun superviviente puede quedar colgando de un padre condenado'
        );
    }

    /**
     * La propiedad critica frente al CASCADE, con un superviviente que NO es
     * duplicado colgando de un padre que si lo es.
     */
    public function testSupervivienteBajoPadreCondenadoSeReparenta(): void
    {
        $rows = [
            $this->row(10, 'X'),
            $this->row(11, 'X'),                 // condenado
            $this->row(50, 'X/unico.mp4', 11),   // unico: NO es duplicado, colgaba del condenado
        ];

        $plan = $this->planner()->plan($rows);

        $this->assertSame([11], $plan->doomed);
        $this->assertSame(10, $plan->reparent[50], 'debe re-parentarse o el CASCADE lo borraria');

        $after = $plan->applyTo($rows);
        $this->assertCount(2, $after);
        $this->assertTrue($plan->noSurvivorHangsFromDoomedParent($after));
    }

    public function testReplanificarTrasAplicarDaPlanVacio(): void
    {
        $rows = [
            $this->row(10, 'Choco'),
            $this->row(11, 'Choco'),
            $this->row(12, 'Choco'),
            $this->row(20, 'Choco/dia', 11),
            $this->row(21, 'Choco/dia', 12),
        ];

        $plan = $this->planner()->plan($rows);
        $after = $plan->applyTo($rows);

        $this->assertTrue(
            $this->planner()->plan($after)->isEmpty(),
            'el comando debe poder re-ejecutarse sin efecto'
        );
    }

    /** 36 copias en un solo grupo — el peor caso real observado. */
    public function testTreintaYSeisCopiasColapsanAUna(): void
    {
        $rows = [];
        for ($i = 1; $i <= 36; $i++) {
            $rows[] = $this->row(1000 + $i, 'Disco_B/tv/09062026/x.mp4');
        }

        $plan = $this->planner()->plan($rows);

        $this->assertSame(35, $plan->doomedCount());
        $this->assertSame([1001], $plan->survivors);
        $this->assertCount(1, $plan->applyTo($rows));
    }

    public function testFilasRaizConPadreNuloNoRompenElReparentado(): void
    {
        $plan = $this->planner()->plan([
            $this->row(1, 'a', null),
            $this->row(2, 'a', null),
        ]);

        $this->assertSame([2], $plan->doomed);
        $this->assertSame([], $plan->reparent);
    }
}
