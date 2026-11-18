<?php

namespace Tests\Unit;

use App\Models\File;
use App\Services\MisArchivos\MedioCatalogService;
use App\Services\MisArchivos\PuntualResolver;
use App\Services\MountGuard;
use App\Services\StorageSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Contrato de las primitivas del catálogo de medios y del resolver
 * (change `solicitud-puntual-tv-radio`).
 *
 * Aquí se fija lo que NO depende del disco: el nombre y la región de un
 * medio, la resolución del destino (el sub-canal que ya tiene storage) y la
 * cadencia derivada de los datos.
 *
 * La resolución contra el disco real (cobertura, materialización del
 * catálogo, verificación de existencia) se verifica en
 * `tests/harness_solicitud_puntual.php`, que corre contra PostgreSQL y el
 * disco de verdad.
 */
class PuntualResolverTest extends TestCase
{
    private MedioCatalogService $catalog;

    private PuntualResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = new MedioCatalogService(
            $this->createMock(MountGuard::class),
        );

        $this->resolver = new PuntualResolver(
            $this->createMock(MountGuard::class),
            $this->createMock(StorageSyncService::class),
        );
    }

    // ------------------------------------------------------------------
    // Nombre y región
    // ------------------------------------------------------------------

    public function test_nombre_de_un_subcanal_agrupado_incluye_la_region(): void
    {
        $propuesta = $this->catalog->proposeName('Antioquia/BLU');

        $this->assertSame('BLU', $propuesta['name']);
        $this->assertSame('Antioquia', $propuesta['region']);
    }

    public function test_nombre_de_un_subcanal_plano_no_inventa_region(): void
    {
        $propuesta = $this->catalog->proposeName('CANDELA');

        $this->assertSame('CANDELA', $propuesta['name']);
        $this->assertNull($propuesta['region']);
    }

    public function test_dos_canales_homonimos_de_regiones_distintas_se_diferencian(): void
    {
        $antioquia = $this->catalog->proposeName('Antioquia/Caracol');
        $bogota = $this->catalog->proposeName('Radios/Bogota/CARACOL');

        // Los dos se llaman Caracol: lo que los distingue es la región.
        $this->assertSame('Antioquia', $antioquia['region']);
        $this->assertSame('Bogota', $bogota['region']);
        $this->assertNotSame($antioquia['region'], $bogota['region']);
    }

    public function test_el_nombre_humaniza_guiones_bajos_pero_conserva_los_guiones(): void
    {
        // El guion se conserva: forma parte del nombre del canal y conviene
        // que el cliente lo reconozca tal como está en el disco.
        $propuesta = $this->catalog->proposeName('Minuto_de-Dios');

        $this->assertSame('Minuto de-Dios', $propuesta['name']);
    }

    public function test_la_region_es_la_ultima_carpeta_del_prefijo(): void
    {
        $propuesta = $this->catalog->proposeName('Valle del Cauca/Besame_Cali');

        $this->assertSame('Besame Cali', $propuesta['name']);
        $this->assertSame('Valle del Cauca', $propuesta['region']);
    }

    // ------------------------------------------------------------------
    // Cadencia derivada
    // ------------------------------------------------------------------

    public function test_la_cadencia_de_tv_se_deriva_en_15_minutos(): void
    {
        $files = $this->filesWithStep('caracol_14112026_', 900, 8);

        $this->assertSame(900, $this->resolver->deriveStep($files));
    }

    public function test_la_cadencia_de_radio_nacional_se_deriva_en_15_minutos(): void
    {
        // La radio nacional graba cada 15 min con saltos de hasta 17 min
        // (pausas de programación): la mediana lo sostiene.
        $files = $this->filesWithStep('caracolradio_14112026_', 900, 8);

        $this->assertSame(900, $this->resolver->deriveStep($files));
    }

    public function test_la_cadencia_de_radio_regional_se_deriva_en_14_minutos(): void
    {
        $files = $this->filesWithStep('01_blu_antioquia_14112026_', 840, 8);

        $this->assertSame(840, $this->resolver->deriveStep($files));
    }

    public function test_un_salto_aislado_no_arrastra_la_cadencia(): void
    {
        // 8 intervalos de 900 s y uno de 1500 s (una pausa larga): la
        // mediana sigue en 900.
        $files = $this->filesWithStep('x_14112026_', 900, 9);
        $archivoLargo = $this->file('x_14112026_160000.m4a');
        $files->push($archivoLargo);

        $this->assertSame(900, $this->resolver->deriveStep($files));
    }

    public function test_con_menos_de_tres_archivos_no_hay_cadencia(): void
    {
        $files = $this->filesWithStep('x_14112026_', 900, 2);

        $this->assertNull($this->resolver->deriveStep($files));
    }

    public function test_un_nombre_sin_fecha_no_produce_cadencia(): void
    {
        $files = collect([
            $this->file('sin_fecha.m4a'),
            $this->file('tampoco.m4a'),
        ]);

        $this->assertNull($this->resolver->deriveStep($files));
    }

    /**
     * Archivos sintéticos con una cadencia dada, empezando a las 00:00:00.
     *
     * @return \Illuminate\Support\Collection<int,File>
     */
    private function filesWithStep(string $prefix, int $step, int $count)
    {
        $files = collect();

        for ($i = 0; $i < $count; $i++) {
            $seconds = $i * $step;
            $files->push($this->file($prefix . sprintf('%02d%02d%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) . '.m4a'));
        }

        return $files;
    }

    /**
     * Un `File` en memoria: el resolver solo lee el nombre.
     */
    private function file(string $name): File
    {
        $file = new File();
        $file->name = $name;

        return $file;
    }
}
