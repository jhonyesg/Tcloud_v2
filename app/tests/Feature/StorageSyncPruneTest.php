<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Support\Facades\DB;
use Tests\LaravelTestCase;

/**
 * El ciclo completo del boton Actualizar: escanear, crear y borrar lo que ya no
 * esta en disco.
 *
 * Cubre dos cosas que estaban rotas:
 *
 * 1. PruneGuard recibia el conteo de HUERFANOS donde esperaba el TOTAL de la
 *    carpeta, asi que su regla de proporcion media algo sin sentido: dejaba
 *    pasar un borrado del 40% y rechazaba la rotacion diaria legitima de los
 *    storages de prensa (114 huerfanos contra 4 archivos en disco => rechazo
 *    permanente, y cuanto mas se desfasaba mas seguro era el rechazo).
 *
 * 2. El boton nunca pedia la purga forzada, asi que no habia forma desde la UI
 *    de reconciliar una carpeta con rotacion.
 *
 * Usa la base de datos de test (phpunit.xml fija tcloudstorage_test) y un
 * directorio temporal real: la interaccion entre escaneo, guarda y borrado en
 * cascada es justo lo que no se puede comprobar con dobles.
 */
class StorageSyncPruneTest extends LaravelTestCase
{
    private string $dir;
    private StorageProvider $storage;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/storage_sync_test_' . uniqid();
        mkdir($this->dir, 0777, true);

        $this->userId = (int) DB::table('users')->insertGetId([
            'email' => 'sync-test-' . uniqid() . '@test.local',
            'password_hash' => 'x',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storageId = (int) DB::table('storage_providers')->insertGetId([
            'name' => 'sync-test',
            'type' => 'local',
            'config' => json_encode([]),
            'base_path' => $this->dir,
            'enabled' => true,
            'is_accessible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // createFileFromScan() saca el owner_id de aqui y cae en 1 si no hay
        // asignacion; en la BD de test ese usuario no existe y la FK lo rechaza.
        DB::table('user_storages')->insert([
            'user_id' => $this->userId,
            'storage_provider_id' => $storageId,
            'permissions' => 'full',
            'can_create_shares' => false,
        ]);

        $this->storage = StorageProvider::findOrFail($storageId);
    }

    protected function tearDown(): void
    {
        DB::table('files')->where('storage_provider_id', $this->storage->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $this->storage->id)->delete();
        DB::table('storage_providers')->where('id', $this->storage->id)->delete();
        DB::table('users')->where('id', $this->userId)->delete();

        $this->rmrf($this->dir);

        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function sync(bool $force = false): array
    {
        return $this->app->make(StorageSyncService::class)
            ->syncFolderWithReport($this->storage, null, $this->userId, $force);
    }

    private function rootRows(): int
    {
        return File::where('storage_provider_id', $this->storage->id)
            ->whereNull('parent_id')
            ->count();
    }

    private function crearArchivos(int $desde, int $hasta): void
    {
        for ($i = $desde; $i <= $hasta; $i++) {
            file_put_contents($this->dir . "/archivo{$i}.txt", "contenido {$i}");
        }
    }

    public function testLosArchivosNuevosDelDiscoSeAgregan(): void
    {
        $this->crearArchivos(1, 3);

        $report = $this->sync();

        $this->assertSame('synced', $report['stats']['status']);
        $this->assertSame(3, $report['stats']['created']);
        $this->assertSame(3, $this->rootRows());
    }

    /**
     * El bug de semantica, en su forma exacta: 10 filas, 4 desaparecen del disco.
     *
     * Antes se le pasaba a la guarda dbCount=4 (los huerfanos) y diskCount=6, con
     * lo que max(0, 4-6) daba 0 y el ratio salia 0: PERMITIA borrar el 40%, por
     * encima del umbral del 34% que decia proteger. Ahora el denominador es el
     * total y la regla hace lo que su nombre dice.
     */
    public function testBorrarElCuarentaPorCientoYaNoPasaDesapercibido(): void
    {
        $this->crearArchivos(1, 10);
        $this->sync();
        $this->assertSame(10, $this->rootRows());

        for ($i = 1; $i <= 4; $i++) {
            unlink($this->dir . "/archivo{$i}.txt");
        }

        $report = $this->sync();

        $this->assertSame('mass_delete_ratio', $report['stats']['reason']);
        $this->assertFalse($report['stats']['pruned']);
        $this->assertSame(4, $report['stats']['orphans']);
        $this->assertSame(10, $this->rootRows(), 'la purga automatica no debe borrar el 40% de la carpeta');
    }

    /** El clic humano si reconcilia: es una orden explicita, no una heuristica. */
    public function testElRefrescoForzadoBorraLoQueYaNoEstaEnDisco(): void
    {
        $this->crearArchivos(1, 10);
        $this->sync();

        for ($i = 1; $i <= 4; $i++) {
            unlink($this->dir . "/archivo{$i}.txt");
        }

        $report = $this->sync(force: true);

        $this->assertTrue($report['stats']['pruned']);
        $this->assertSame(4, $report['stats']['deleted']);
        $this->assertSame(6, $this->rootRows());
    }

    /**
     * La rotacion diaria de los storages de prensa: el disco conserva dos dias y
     * la BD lleva meses. Sin forzar es un rechazo permanente que se aprieta solo.
     */
    public function testRotacionDiariaSoloSeReconciliaAlForzar(): void
    {
        $this->crearArchivos(1, 20);
        $this->sync();
        $this->assertSame(20, $this->rootRows());

        for ($i = 1; $i <= 18; $i++) {
            unlink($this->dir . "/archivo{$i}.txt");
        }

        $automatico = $this->sync();
        $this->assertSame('mass_delete_ratio', $automatico['stats']['reason']);
        $this->assertSame(20, $this->rootRows());

        $manual = $this->sync(force: true);
        $this->assertSame(18, $manual['stats']['deleted']);
        $this->assertSame(2, $this->rootRows());
    }

    /**
     * Borrar una carpeta arrastra su subarbol, y el contador lo refleja: decir
     * "1 eliminado" cuando se fueron 4 filas seria enganoso en la UI.
     */
    public function testBorrarUnaCarpetaCuentaElSubarbolCompleto(): void
    {
        mkdir($this->dir . '/20260819');
        file_put_contents($this->dir . '/20260819/a.txt', 'a');
        file_put_contents($this->dir . '/20260819/b.txt', 'b');
        $this->crearArchivos(1, 3);

        $service = $this->app->make(StorageSyncService::class);
        $service->syncFolderWithReport($this->storage, null, $this->userId);

        $carpeta = File::where('storage_provider_id', $this->storage->id)
            ->where('name', '20260819')
            ->firstOrFail();
        $service->syncFolderWithReport($this->storage, $carpeta->id, $this->userId);

        $this->assertSame(3, File::where('storage_provider_id', $this->storage->id)
            ->where('parent_id', $carpeta->id)->count() + 1);

        $this->rmrf($this->dir . '/20260819');

        $report = $this->sync(force: true);

        $this->assertSame(3, $report['stats']['deleted'], 'la carpeta y sus dos hijos');
        $this->assertSame(0, File::where('storage_provider_id', $this->storage->id)
            ->where('name', '20260819')->count());
    }

    /**
     * Un directorio vacio de verdad no autoriza a vaciar la BD por su cuenta:
     * es indistinguible de un montaje caido sin la declaracion de MountGuard.
     */
    public function testDirectorioVacioNoPurgaSinForzar(): void
    {
        $this->crearArchivos(1, 5);
        $this->sync();

        for ($i = 1; $i <= 5; $i++) {
            unlink($this->dir . "/archivo{$i}.txt");
        }

        $report = $this->sync();

        $this->assertSame('empty_scan', $report['stats']['reason']);
        $this->assertSame(5, $this->rootRows());
    }

    /** El listado y los contadores viajan juntos; syncFolder() sigue devolviendo solo el listado. */
    public function testLaApiAntiguaSigueDevolviendoElListado(): void
    {
        $this->crearArchivos(1, 2);

        $files = $this->app->make(StorageSyncService::class)
            ->syncFolder($this->storage, null, $this->userId);

        $this->assertCount(2, $files);
        $this->assertArrayHasKey('id', $files[0]);
    }
}
