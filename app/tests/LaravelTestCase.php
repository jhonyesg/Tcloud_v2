<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base para tests que necesitan el contenedor de Laravel (config, cache, DI).
 *
 * Deliberadamente separada de Tests\TestCase: esa no arranca el framework y
 * varios tests existentes dependen de ese comportamiento. Añadir el bootstrap
 * alli cambiaria su entorno sin necesidad.
 *
 * NO requiere base de datos. phpunit.xml fija CACHE_DRIVER=array, y los tests
 * que necesitan simular overrides persistidos siembran la cache directamente.
 */
abstract class LaravelTestCase extends BaseTestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require __DIR__ . '/../bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app->flush();
        }

        parent::tearDown();
    }
}
