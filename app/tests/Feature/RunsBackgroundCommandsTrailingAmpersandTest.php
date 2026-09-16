<?php

namespace Tests\Feature;

use Tests\LaravelTestCase;

/**
 * Helper para exponer el trait como método público en los tests.
 */
class RunsBackgroundCommandsAmpProbe
{
    use \App\Http\Controllers\Concerns\RunsBackgroundCommands {
        execBackground as public;
    }
}

/**
 * Tests de la normalización de `$cmd` (defensa contra el patrón histórico
 * del controller de transcripción, que le agregaba `&` al `$cmd`).
 *
 * Cambio: openspec/changes/fix-transcriptor-batch-bg-launcher/.
 *
 * El bug: `ApiTranscriptorController::processBatch` armaba `$cmd` con
 * `$cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';`. Cuando el
 * trait envolvía eso en `setsid bash -c "echo ...; $cmd; echo ..."`,
 * bash veía `<cmd> &; echo ...` que es syntax error
 * (`&` ya terminó la lista, `;` después es inválido).
 *
 * El fix: el trait recorta el `&` residual silenciosamente y deja log
 * `runs_background.stripped_trailing_ampersand tag=X` para detectar
 * callers que aún violan el contrato.
 */
class RunsBackgroundCommandsTrailingAmpersandTest extends LaravelTestCase
{
    public function test_exec_background_strips_trailing_ampersand_with_spaces(): void
    {
        $probe = new RunsBackgroundCommandsAmpProbe();

        // `true` con ` &` al final; el strip debe permitir que el wrapper
        // arme `... ; true ; echo ...end...` que es bash válido.
        $cmdWithAmp = 'true   &';

        $result = $probe->execBackground($cmdWithAmp, 'unit:test:strip-amp-spaces');

        $this->assertTrue($result,
            'execBackground() debe recortar el `&` residual y retornar true con bash válido');
    }

    public function test_exec_background_strips_bare_trailing_ampersand(): void
    {
        $probe = new RunsBackgroundCommandsAmpProbe();
        $result = $probe->execBackground('true&', 'unit:test:strip-amp-bare');
        $this->assertTrue($result);
    }

    public function test_exec_background_preserves_ampersand_inside_command(): void
    {
        $probe = new RunsBackgroundCommandsAmpProbe();

        // `echo "a&b"` no debe alterarse; solo recortamos el `&` FINAL,
        // no los `&` que aparecen dentro de strings literales.
        $result = $probe->execBackground('echo "a&b"', 'unit:test:keep-inner-amp');

        $this->assertTrue($result);
    }
}
