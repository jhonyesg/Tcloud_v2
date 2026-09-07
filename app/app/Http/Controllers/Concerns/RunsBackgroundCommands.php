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
     * Cada corrida queda enmarcada en `/tmp/kilo_artisan_bg.log` con
     * `[<logTag>] start <iso>` y `[<logTag>] end <iso>` para que el log
     * compartido sea diagnosticable por caller cuando varios módulos
     * lanzan background jobs al mismo tiempo.
     *
     * En Windows, start /B (best-effort).
     */
    protected function execBackground(string $cmd, string $logTag = 'unknown'): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd, 'r'));
            return;
        }

        $prefix = sprintf('[%s] ', $logTag);
        $shellCmd = 'setsid bash -c ' . escapeshellarg(
            sprintf('echo "%sstart $(date -Is)"; %s; echo "%send $(date -Is)"', $prefix, $cmd, $prefix)
            . ' >> /tmp/kilo_artisan_bg.log 2>&1'
        ) . ' &';
        exec($shellCmd);
    }

    /**
     * Resuelve un binario PHP CLI ejecutable.
     *
     * Crítico: cuando el request llega bajo PHP-FPM, `PHP_BINARY` apunta
     * al SAPI fpm (`/www/server/php/84/sbin/php-fpm`), NO al CLI. Pasar
     * ese binario a `php artisan` hace que el comando imprima su Usage y
     * muera al instante — bug documentado en
     * openspec/changes/corrections-apply-retroactive-bg-launcher/.
     *
     * Si ya estamos bajo SAPI `cli` (cron, `php artisan` desde shell),
     * usamos el mismo binario que originó la llamada para preservar
     * comportamiento. Si no, probamos rutas documentadas; si ninguna
     * existe, lanzamos excepción para que el caller pueda surfacear
     * el problema en vez de fallar silenciosamente.
     */
    protected function resolvePhpCli(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        foreach (['/usr/bin/php', '/usr/bin/php84'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException(
            'No se encontró un binario CLI de PHP para lanzar comandos en background. '.
            'Probá instalar php-cli o exponer el binario en /usr/bin/php.'
        );
    }

    /**
     * Genera un runId único para tracking de un proceso asíncrono.
     */
    protected function generateRunId(string $prefix): string
    {
        return $prefix . '_' . time() . '_' . md5((string) mt_rand());
    }
}
