<?php

namespace App\Http\Controllers\Concerns;

trait RunsBackgroundCommands
{
    /**
     * Ejecuta un comando artisan en background de forma portable.
     *
     * Estrategia: lanzar vía shell con `nohup … &` que lo separa
     * completamente del proceso PHP (nueva sesión + ignores HUP). Esto
     * elimina el clásico problema de proc_open donde parent queda esperando
     * por descriptores heredados (especialmente cuando Laravel tiene
     * conexiones Redis / Postgres abiertas en el container).
     *
     * El shell lanza el comando en segunda línea y muere inmediatamente,
     * devolviendo el control a PHP. El artisan queda corriendo bajo
     * PID init (no bajo nuestro PHP-FPM), así no se muere con la request.
     *
     * En Windows, start /B (best-effort).
     */
    protected function execBackground(string $cmd): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd, 'r'));
            return;
        }

        // Escapeamos comillas del cmd ya pasado. Lo envolvemos en setsid +
        // redirigimos salida a un log para diagnóstico + disown para
        // asegurar que sobrevive al PHP-FPM.
        $shellCmd = 'setsid bash -c ' . escapeshellarg($cmd . ' > /tmp/kilo_artisan_bg.log 2>&1') . ' &';
        exec($shellCmd);
    }

    /**
     * Genera un runId único para tracking de un proceso asíncrono.
     */
    protected function generateRunId(string $prefix): string
    {
        return $prefix . '_' . time() . '_' . md5((string) mt_rand());
    }
}
