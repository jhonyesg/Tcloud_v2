<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait RunsBackgroundCommands
{
    /**
     * Ejecuta un comando artisan en background de forma portable.
     *
     * Estrategia: lanzar vía shell con `setsid bash -c '...' &` que lo
     * separa completamente del proceso PHP (nueva sesión + ignores HUP).
     * Esto elimina el clásico problema de proc_open donde parent queda
     * esperando por descriptores heredados (especialmente cuando Laravel
     * tiene conexiones Redis / Postgres abiertas en el container).
     *
     * El shell lanza el comando en segunda línea y muere inmediatamente,
     * devolviendo el control a PHP. El artisan queda corriendo bajo PID
     * init (no bajo nuestro PHP-FPM), así no se muere con la request.
     *
     * Contrato con los callers (centralizado en este trait):
     *   - El trait es el UNICO responsable de agregar el `&` final y la
     *     redirección de salida. Los callers pasan comandos artisan
     *     puros (sin `&` ni `>> log 2>&1`).
     *   - Si por un caller histórico el `$cmd` ya trae `&` al final, el
     *     trait lo recorta silenciosamente y deja log de auditoría.
     *   - Antes de ejecutar, el trait valida la sintaxis bash con
     *     `bash -n` para que errores del tipo `bash: -c: syntax error`
     *     se reporten al caller en lugar de perderse en el subshell
     *     reparentado.
     *
     * Cada corrida queda enmarcada en `/tmp/kilo_artisan_bg.log` con
     * `[<logTag>] start <iso>` y `[<logTag>] end <iso>` para que el log
     * compartido sea diagnosticable por caller cuando varios módulos
     * lanzan background jobs al mismo tiempo.
     *
     * Parametros:
     *   - $cmd       comando artisan puro (sin `&` ni redirección)
     *   - $logTag    identificador corto para los marcadores del log
     *   $logFile    ruta a un log por-corrida; si no es null, la salida
     *               del artisan también va a este archivo
     *   $cacheKey   clave de cache opcional; si se valida falla, se
     *               escribe `status: error` ahí para que el modal
     *               frontend pueda mostrar el error
     *
     * Retorna true si la validación pasó y el wrapper se disparó;
     * false si la validación de sintaxis detectó un problema.
     *
     * En Windows, start /B (best-effort).
     */
    protected function execBackground(
        string $cmd,
        string $logTag = 'unknown',
        ?string $logFile = null,
        ?string $cacheKey = null
    ): bool {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd, 'r'));
            return true;
        }

        // 1. Normalizar: recortar `&` residual que callers históricos
        //    pudieran haber dejado. Defensa contra el bug
        //    `bash: -c: syntax error near unexpected token ';'` que
        //    ocurría cuando un caller agregaba su propio `&` y el
        //    trait también lo hacía.
        $normalized = preg_replace('/\s*&\s*$/', '', $cmd);
        if ($normalized !== $cmd) {
            Log::info("runs_background.stripped_trailing_ampersand tag={$logTag}");
            $cmd = $normalized;
        }

        $prefix = sprintf('[%s] ', $logTag);

        // 2. Construir el wrapper completo (lo mismo que vamos a
        //    ejecutar después del probe) para validarlo con `bash -n`.
        $logTail = 'echo "' . $prefix . 'end $(date -Is)" >> /tmp/kilo_artisan_bg.log 2>&1';
        $innerCmd = sprintf(
            'echo "%sstart $(date -Is)"; %s%s; %s',
            $prefix,
            $cmd,
            $logFile !== null ? ' >> ' . escapeshellarg($logFile) . ' 2>&1' : '',
            $logTail
        );

        // 3. Validación barata con `bash -n` (parsea pero no ejecuta).
        //    OJO: `bash -n <script>` trata el argumento como un ARCHIVO,
        //    no como un script inline. Para validar un script inline
        //    hay que usar `bash -n -c "<script>"`. Sin `-c` veríamos
        //    "No such file or directory" y nunca detectaríamos errores
        //    reales del tipo `bash: -c: syntax error`.
        //    Usamos `setsid` para que el probe muera limpio aunque
        //    herede descriptores raros de PHP-FPM, y `< /dev/null`
        //    para que bash no se quede esperando stdin.
        $probe = 'setsid bash -n -c ' . escapeshellarg($innerCmd) . ' < /dev/null 2>&1';
        $validation = shell_exec($probe);
        $validation = trim((string) $validation);
        if ($validation !== '') {
            Log::error("runs_background.invalid_syntax tag={$logTag}: {$validation}");
            if ($cacheKey !== null) {
                Cache::put($cacheKey, [
                    'status' => 'error',
                    'message' => "Lanzador produjo bash inválido (revisá /tmp/kilo_artisan_bg.log, filtro [{$logTag}]): {$validation}",
                    'batch' => 0,
                    'processed' => 0,
                    'errors' => 1,
                    'total_to_process' => 0,
                    'total_candidates' => 0,
                    'current_index' => 0,
                    'current_file' => null,
                    'current_storage' => null,
                    'storages' => [],
                    'files' => [],
                    'started_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ], now()->addHours(2));
            }
            return false;
        }

        // 4. Sintaxis OK: disparar el wrapper real.
        $shellCmd = 'setsid bash -c ' . escapeshellarg($innerCmd) . ' &';
        exec($shellCmd);

        return true;
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
