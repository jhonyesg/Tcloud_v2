<?php

namespace Tests\Unit;

use App\Models\Canal;
use App\Models\Grabador;
use App\Models\User;
use App\Services\GrabacionesPuntuales\CupoCanalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\LaravelTestCase;

/**
 * Regresión del cupo por par (grabador × usuario).
 *
 * Bug original (2026-10-22): `CanalController::store` leía
 * `$acceso->limite_canales` sobre un modelo Grabador, donde la columna no
 * existe → null → `usados >= null` siempre true → ningún no-admin podía
 * crear canales con cualquier límite.
 *
 * Los fixtures usan tag `hcupo_<hex>` y se limpian en tearDown.
 *
 * Nota de entorno: la BD de tests (`tcloudstorage_test`) puede estar detrás en
 * migraciones y no tener `users.status`. Los usuarios de fixture se insertan
 * con las columnas que existan, para que el test valide la lógica del cupo y
 * no el estado del esquema de tests. La cobertura contra la BD real la hace
 * `tests/harness_canales_cupo.php`.
 */
class CupoCanalesServiceTest extends LaravelTestCase
{
    private string $tag;

    private ?int $userId = null;

    private ?Grabador $grabador = null;

    private bool $tieneStatus = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'hcupo_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->tieneStatus = Schema::hasColumn('users', 'status');

        $this->skipIfSchemaIsStale();

        $this->userId = $this->crearUsuario($this->tag);

        $this->grabador = Grabador::create([
            'nombre' => $this->tag,
            'tipo' => 'radio',
            'ip' => '127.0.0.1',
            'puerto' => 5002,
            'base_url' => 'http://127.0.0.1:5002/api',
            'activo' => true,
        ]);
    }

    /**
     * La BD `tcloudstorage_test` de este host está atrás en migraciones (85
     * pendientes, schema drift ajeno a este change). En lugar de repararla
     * desde un test, se salta con nota y se deja la cobertura de esquema real
     * al harness `tests/harness_canales_cupo.php`, que corre contra la BD de
     * producción en modo read/write acotado por tag.
     */
    private function skipIfSchemaIsStale(): void
    {
        $requeridas = [
            ['grabador_usuario', 'limite_canales'],
            ['grabador_usuario', 'ruta_base'],
            ['canales', 'slot_nombre'],
            ['canales', 'api_canal_id'],
            ['canales', 'formato_salida'],
        ];

        foreach ($requeridas as [$tabla, $columna]) {
            if (!Schema::hasColumn($tabla, $columna)) {
                $this->markTestSkipped(
                    "BD de tests desactualizada: falta {$tabla}.{$columna}. " .
                    'Correr `php artisan migrate --env=testing` o validar con ' .
                    'tests/harness_canales_cupo.php contra la BD real.'
                );
                return;
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->grabador !== null) {
            Canal::where('grabador_id', $this->grabador->id)->delete();
            DB::table('grabador_usuario')->where('grabador_id', $this->grabador->id)->delete();
            Grabador::where('id', $this->grabador->id)->delete();
        }

        if ($this->userId !== null) {
            User::where('id', $this->userId)->delete();
        }

        parent::tearDown();
    }

    /**
     * Inserta un usuario tolerando esquemas de tests incompletos.
     */
    private function crearUsuario(string $sufijo): int
    {
        $fila = [
            'email' => $sufijo . '@test.local',
            'username' => $sufijo,
            'password_hash' => 'harness_dummy',
            'role' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($this->tieneStatus) {
            $fila['status'] = 'active';
        }

        return (int) DB::table('users')->insertGetId($fila);
    }

    private function asignar(int $limite, ?string $rutaBase = null): void
    {
        DB::table('grabador_usuario')->insert([
            'grabador_id' => $this->grabador->id,
            'user_id' => $this->userId,
            'limite_canales' => $limite,
            'ruta_base' => $rutaBase,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearCanales(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Canal::create([
                'grabador_id' => $this->grabador->id,
                'usuario_id' => $this->userId,
                'slot_nombre' => $this->tag . '_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'activo' => true,
            ]);
        }
    }

    private function service(): CupoCanalesService
    {
        return app(CupoCanalesService::class);
    }

    public function test_limite_lee_el_pivote(): void
    {
        $this->asignar(5);

        $this->assertSame(5, $this->service()->limite($this->grabador->id, $this->userId));
    }

    public function test_limite_sin_pivote_es_null(): void
    {
        $this->assertNull($this->service()->limite($this->grabador->id, $this->userId));
    }

    public function test_limite_cero_se_trata_como_sin_asignacion(): void
    {
        $this->asignar(0);

        $this->assertNull(
            $this->service()->limite($this->grabador->id, $this->userId),
            'Un limite 0 no debe convertirse en ilimitado por comparacion/resta'
        );
    }

    public function test_resumen_calcula_disponibles(): void
    {
        $this->asignar(5, '/disco/pruebas');
        $this->crearCanales(4);

        $resumen = $this->service()->resumen($this->grabador->id, $this->userId);

        $this->assertNotNull($resumen);
        $this->assertSame(5, $resumen['limite']);
        $this->assertSame(4, $resumen['usados']);
        $this->assertSame(1, $resumen['disponibles']);
        $this->assertSame('/disco/pruebas', $resumen['ruta_base']);
    }

    public function test_resumen_sin_pivote_es_null(): void
    {
        $this->assertNull($this->service()->resumen($this->grabador->id, $this->userId));
    }

    public function test_puede_crear_con_espacio(): void
    {
        $this->asignar(5);
        $this->crearCanales(4);

        $this->assertTrue($this->service()->puedeCrear($this->grabador->id, $this->userId, false));
    }

    public function test_no_puede_crear_al_limite(): void
    {
        $this->asignar(5);
        $this->crearCanales(5);

        $this->assertFalse($this->service()->puedeCrear($this->grabador->id, $this->userId, false));
    }

    public function test_no_puede_crear_sin_asignacion(): void
    {
        $this->assertFalse(
            $this->service()->puedeCrear($this->grabador->id, $this->userId, false),
            'Sin pivot debe denegar, no comparar contra null'
        );
    }

    public function test_admin_bypassa_el_cupo(): void
    {
        $this->asignar(1);
        $this->crearCanales(3);

        $this->assertTrue($this->service()->puedeCrear($this->grabador->id, $this->userId, true));
    }

    public function test_uso_cuenta_solo_el_par(): void
    {
        $this->asignar(5);
        $this->crearCanales(2);

        $otroId = $this->crearUsuario($this->tag . '_otro');

        Canal::create([
            'grabador_id' => $this->grabador->id,
            'usuario_id' => $otroId,
            'slot_nombre' => $this->tag . '_otro_01',
            'activo' => true,
        ]);

        $this->assertSame(2, $this->service()->uso($this->grabador->id, $this->userId));
        $this->assertSame(1, $this->service()->uso($this->grabador->id, $otroId));

        Canal::where('usuario_id', $otroId)->delete();
        User::where('id', $otroId)->delete();
    }
}
