<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\LaravelTestCase;

/**
 * Helper que permite testear el trait `RunsBackgroundCommands` (que es
 * `protected`) sin tener que heredar de un controller real.
 */
class RunsBackgroundCommandsProbe
{
    use \App\Http\Controllers\Concerns\RunsBackgroundCommands {
        execBackground as public;
    }
}

/**
 * Tests del validador `bash -n` que RunsBackgroundCommands::execBackground
 * corre antes de hacer `exec()` real.
 *
 * Cambio: openspec/changes/fix-transcriptor-batch-bg-launcher/.
 *
 * Por qué: el bug original era bash inválido silencioso (`<cmd> &; echo ...`
 * tras el envoltorio). El validador `bash -n` captura esa clase de error
 * sin ejecutar nada, permite que el caller reaccione (devolviendo 500
 * con mensaje accionable) y que el modal frontend no se quede mudo.
 *
 * El validador también escribe `status: error` en el cache si se le
 * pasa `$cacheKey`, para que el modal pueda mostrar el error en vez de
 * quedarse colgado en "Iniciando proceso en background...".
 */
class RunsBackgroundCommandsValidationTest extends LaravelTestCase
{
    public function test_exec_background_returns_false_on_invalid_bash_syntax(): void
    {
        $probe = new RunsBackgroundCommandsProbe();
        $cacheKey = 'test:runsbg:invalid';

        // Comando con `&;` mal combinado (el patrón exacto del bug original:
        // `$cmd` traía `&` y el trait envolvía en `; echo ...`).
        $badCmd = 'echo "x" & ; echo y';

        $result = $probe->execBackground($badCmd, 'unit:test:invalid', null, $cacheKey);

        $this->assertFalse($result, 'execBackground() debe retornar false ante bash inválido');

        $cached = Cache::get($cacheKey);
        $this->assertIsArray($cached, 'El trait debe escribir cache con status=error cuando se le pasa cacheKey');
        $this->assertSame('error', $cached['status'] ?? null);
        $this->assertStringContainsString('bash inválido', $cached['message'] ?? '');
        $this->assertStringContainsString('[unit:test:invalid]', $cached['message'] ?? '',
            'El mensaje debe apuntar al filtro del log compartido para diagnóstico');
    }

    public function test_exec_background_returns_true_and_launches_on_valid_syntax(): void
    {
        $probe = new RunsBackgroundCommandsProbe();

        // `true` es bash válido; el comando existe y sale 0.
        $validCmd = 'true';

        $result = $probe->execBackground($validCmd, 'unit:test:valid');

        $this->assertTrue($result, 'execBackground() debe retornar true con bash válido');

        // Cleanup: el wrapper ya escribió [unit:test:valid] end en
        // /tmp/kilo_artisan_bg.log. No necesitamos aserción sobre el log
        // porque es compartido entre procesos y podría tener ruido de
        // otros tests; el retorno true ya valida el camino feliz.
    }

    public function test_exec_background_skips_cache_write_when_cache_key_is_null(): void
    {
        $probe = new RunsBackgroundCommandsProbe();
        $cacheKey = 'test:runsbg:no-cache-write-on-validation-error';

        $badCmd = 'echo "x" & ; echo y';

        $result = $probe->execBackground($badCmd, 'unit:test:invalid-no-cache', null, null);

        $this->assertFalse($result);
        $this->assertNull(Cache::get($cacheKey),
            'Sin cacheKey no debe escribirse nada en cache (el caller no pidió feedback)');
    }
}
