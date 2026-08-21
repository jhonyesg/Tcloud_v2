<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Inspecciona y modifica la configuracion en caliente del transcriptor.
 *
 * Es la herramienta de verificacion de la capa de settings y la salida de
 * emergencia por CLI cuando la UI no esta disponible.
 *
 * Ejemplos:
 *   php artisan transcription:config
 *   php artisan transcription:config --json
 *   php artisan transcription:config --set=inflight_max=4 --set=dispatch_stagger_ms=200
 *   php artisan transcription:config --reset=inflight_max
 *   php artisan transcription:config --reset=all
 */
class TranscriptionConfigCommand extends Command
{
    protected $signature = 'transcription:config
                            {--json : Emitir el estado efectivo como JSON}
                            {--set=* : clave=valor (repetible)}
                            {--reset=* : clave a restaurar, o "all" (repetible)}';

    protected $description = 'Muestra y modifica en caliente la configuracion del transcriptor (override en BD sobre config/transcriptor.php).';

    public function handle(TranscriptorSettings $settings): int
    {
        if ($code = $this->applyResets($settings)) {
            return $code;
        }

        if ($code = $this->applySets($settings)) {
            return $code;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'effective' => $settings->effective(),
                'guards' => $this->guards(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $this->guardsFailed() ? Command::FAILURE : Command::SUCCESS;
        }

        $this->renderTable($settings);

        return $this->reportGuards() ? Command::SUCCESS : Command::FAILURE;
    }

    private function applyResets(TranscriptorSettings $settings): ?int
    {
        $reset = (array) $this->option('reset');
        if (!$reset) {
            return null;
        }

        $keys = in_array('all', $reset, true) ? [] : $reset;

        foreach ($keys as $key) {
            if (!$settings->has($key)) {
                $this->error("Clave desconocida: {$key}");

                return Command::FAILURE;
            }
        }

        $settings->reset($keys);
        $this->info($keys ? 'Restauradas: ' . implode(', ', $keys) : 'Restauradas todas las claves a su valor de config/env.');

        return null;
    }

    private function applySets(TranscriptorSettings $settings): ?int
    {
        $set = (array) $this->option('set');
        if (!$set) {
            return null;
        }

        $values = [];
        foreach ($set as $pair) {
            if (!str_contains($pair, '=')) {
                $this->error("Formato invalido: '{$pair}'. Use clave=valor.");

                return Command::FAILURE;
            }
            [$k, $v] = explode('=', $pair, 2);
            $values[trim($k)] = trim($v);
        }

        try {
            $settings->set($values);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->error("{$key}: " . implode(' ', $messages));
            }

            return Command::FAILURE;
        }

        $this->info('Aplicado: ' . implode(', ', array_keys($values)));

        return null;
    }

    private function renderTable(TranscriptorSettings $settings): void
    {
        $rows = [];
        $lastGroup = null;
        $clampedKeys = [];

        foreach ($settings->effective() as $key => $e) {
            if ($lastGroup !== null && $e['group'] !== $lastGroup) {
                $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
            }
            $lastGroup = $e['group'];

            $range = $e['options']
                ? implode('|', $e['options'])
                : (($e['min'] !== null || $e['max'] !== null) ? "{$e['min']}..{$e['max']}" : '-');

            // El valor efectivo puede diferir del guardado si el override esta
            // fuera del rango del schema: se recorta al leerlo. Sin señalarlo,
            // el operador cree estar corriendo con lo que escribio.
            $rows[] = [
                $e['group'],
                $key . ($e['clamped'] ? ' <fg=red>!</>' : ''),
                $this->fmt($e['value']),
                $this->fmt($e['default']),
                $e['source'],
                $range,
            ];

            if ($e['clamped']) {
                $clampedKeys[$key] = $e['stored'];
            }
        }

        $this->table(['Grupo', 'Clave', 'Efectivo', 'Default', 'Origen', 'Rango'], $rows);
        $this->line('');
        $this->line("Origen: <fg=yellow>bd</> = override activo (restaurable) · <fg=cyan>env</> = definido en .env · archivo = literal de config/transcriptor.php");

        foreach ($clampedKeys as $key => $stored) {
            $this->line('');
            $this->warn("'{$key}' guardado como {$stored}, fuera del rango del schema: se aplica el valor efectivo de la columna 'Efectivo', no el guardado.");
        }
    }

    private function fmt(mixed $v): string
    {
        return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
    }

    /**
     * Invariantes que no viven en el esquema porque cruzan capas (config de
     * colas vs propiedades del job).
     *
     * @return array<string,array{ok:bool,detail:string}>
     */
    private function guards(): array
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $jobTimeout = (new ConvertAndTranscribeJob(0))->timeout;

        return [
            'retry_after_gt_job_timeout' => [
                'ok' => $retryAfter > $jobTimeout,
                'detail' => "queue.connections.redis.retry_after={$retryAfter} debe superar ConvertAndTranscribeJob::\$timeout={$jobTimeout}. "
                    . 'Si no, Redis devuelve el job a la cola mientras el worker original sigue en ffmpeg y el mismo archivo se procesa dos veces.',
            ],
        ];
    }

    private function guardsFailed(): bool
    {
        foreach ($this->guards() as $g) {
            if (!$g['ok']) {
                return true;
            }
        }

        return false;
    }

    private function reportGuards(): bool
    {
        $allOk = true;

        foreach ($this->guards() as $name => $g) {
            if ($g['ok']) {
                $this->line("<fg=green>OK</>   {$name}");
            } else {
                $allOk = false;
                $this->error("FALLO {$name}");
                $this->line('      ' . $g['detail']);
            }
        }

        return $allOk;
    }
}
