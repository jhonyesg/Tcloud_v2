<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use Illuminate\Console\Command;

/**
 * Ajusta automaticamente el pool de workers de transcripcion segun el numero
 * de storages habilitados y su tipo (flat vs grouped_by_subfolder).
 *
 * Para storages grouped_by_subfolder, el sistema cuenta las subcarpetas
 * directas (cada una representa un medio consolidado) y los suma al pool.
 *
 * Formula (regresion 2026-07-22 17:30):
 *   31 storages habilitados = 29 flat (1 medio c/u) + 2 grouped (37 medios consolidados)
 *   -> 66 medios equivalentes -> workers_objetivo = clamp(ceil(66/6), 3, 12) = 11
 *
 * Comportamiento:
 *   - Sin --apply: solo muestra el plan (dr-run por defecto)
 *   - Con --apply: calcula, despues ajusta systemd
 *   - Con --json: emite el plan como una sola linea JSON al final (legible por log parsers)
 *
 * Idempotencia:
 *   - Si un servicio en target ya esta active: skip (no llama systemctl start)
 *   - Si un sobrante ya esta inactive: skip (no llama systemctl stop)
 *   Correrse dos veces seguidas con el mismo set de storages produce 0 churn.
 */
class TranscriptionTuneCommand extends Command
{
    protected $signature = 'transcription:tune
                            {--apply : Aplicar cambios (start/stop de services), sin esto solo muestra el plan}
                            {--json : Emitir linea JSON con resumen al final}';

    protected $description = 'Calcula el numero optimo de workers de transcripcion segun storages habilitados y ajusta el pool systemd.';

    private const MIN_WORKERS = 3;
    private const MAX_WORKERS = 12;
    private const RATIO_MEDIOS_POR_WORKER = 6;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $json = (bool) $this->option('json');

        $countStorages = StorageProvider::transcriptionEnabled()->count();

        if ($countStorages === 0) {
            $this->info('No hay storages con transcripcion habilitada. Workers objetivo: 0 (off).');
            if ($json) {
                $this->line(json_encode(['ts' => now()->toIso8601String(), 'storages_total' => 0, 'medios_total' => 0, 'workers_target' => 0, 'started' => [], 'stopped' => []]));
            }
            if ($apply) {
                $this->stopAllWorkers();
            }
            return Command::SUCCESS;
        }

        $mediosAgrupados = 0;
        $grouped = StorageProvider::transcriptionEnabled()
            ->where('folder_layout', 'grouped_by_subfolder')
            ->get();
        foreach ($grouped as $storage) {
            $abs = rtrim((string) $storage->base_path, '/');
            if (!is_dir($abs)) continue;
            foreach (scandir($abs) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (is_dir($abs . '/' . $entry)) $mediosAgrupados++;
            }
        }
        $countFlat = StorageProvider::transcriptionEnabled()
            ->where('folder_layout', 'flat')
            ->count();

        $totalMedios = $countFlat + $mediosAgrupados;
        $workersObjetivo = (int) max(
            self::MIN_WORKERS,
            min(self::MAX_WORKERS, (int) ceil($totalMedios / self::RATIO_MEDIOS_POR_WORKER))
        );

        $this->info("Storages habilitados: {$countStorages}");
        $this->info("  - planos (1 medio c/u): {$countFlat}");
        $this->info("  - agrupados (multi-medio): {$grouped->count()} storages -> {$mediosAgrupados} medios");
        $this->info("Medios total equivalente: {$totalMedios}");
        $this->info("Workers objetivo: {$workersObjetivo} (rango ".self::MIN_WORKERS."-".self::MAX_WORKERS.")");

        if (!$apply) {
            $this->warn('Modo dry-run. Use --apply para aplicar cambios al pool systemd.');
            if ($json) {
                $this->line(json_encode([
                    'ts' => now()->toIso8601String(),
                    'storages_total' => $countStorages,
                    'medios_total' => $totalMedios,
                    'workers_target' => $workersObjetivo,
                    'started' => [],
                    'stopped' => [],
                ]));
            }
            return Command::SUCCESS;
        }

        $started = [];
        $stopped = [];

        // Asegurar que existan y esten active los servicios 1..workersObjetivo
        for ($i = 1; $i <= $workersObjetivo; $i++) {
            $name = "tcloud-transcription-batch-{$i}";
            if (!$this->serviceExists($name)) {
                $this->warn("Servicio {$name}.service no existe (saltando).");
                continue;
            }
            $status = $this->getActiveState($name);
            if ($status === 'active') {
                // idempotente: ya esta como lo queremos
                continue;
            }
            $this->line("Iniciando {$name}.service (estado actual: {$status})...");
            @shell_exec("systemctl enable {$name}.service 2>/dev/null");
            @shell_exec("systemctl start {$name}.service 2>/dev/null");
            $started[] = "{$name}.service";
        }

        // Detener sobrantes
        for ($i = $workersObjetivo + 1; $i <= self::MAX_WORKERS; $i++) {
            $name = "tcloud-transcription-batch-{$i}";
            if (!$this->serviceExists($name)) continue;
            $status = $this->getActiveState($name);
            if ($status === 'inactive') {
                continue;
            }
            if ($status === 'active') {
                $this->line("Deteniendo sobrante {$name}.service...");
                @shell_exec("systemctl stop {$name}.service 2>/dev/null");
                $stopped[] = "{$name}.service";
            }
        }

        // Detener el legacy tcloud-transcription-batch.service (singular) si esta activo
        $legacy = 'tcloud-transcription-batch';
        if ($this->serviceExists($legacy)) {
            $status = $this->getActiveState($legacy);
            if ($status === 'active') {
                $this->line("Deteniendo legacy {$legacy}.service...");
                @shell_exec("systemctl stop {$legacy}.service 2>/dev/null");
                $stopped[] = "{$legacy}.service";
            }
        }

        $this->info("Pool ajustado a {$workersObjetivo} workers. (started=" . implode(',', $started) . " stopped=" . implode(',', $stopped) . ")");

        if ($json) {
            $this->line(json_encode([
                'ts' => now()->toIso8601String(),
                'storages_total' => $countStorages,
                'medios_total' => $totalMedios,
                'workers_target' => $workersObjetivo,
                'started' => $started,
                'stopped' => $stopped,
            ]));
        }

        return Command::SUCCESS;
    }

    private function serviceExists(string $name): bool
    {
        $unit = str_ends_with($name, '.service') ? $name : $name . '.service';
        return is_file("/etc/systemd/system/{$unit}");
    }

    private function getActiveState(string $name): string
    {
        $unit = str_ends_with($name, '.service') ? $name : $name . '.service';
        $out = @shell_exec("systemctl is-active {$unit} 2>/dev/null");
        return trim((string) $out);
    }

    private function stopAllWorkers(): void
    {
        for ($i = 1; $i <= self::MAX_WORKERS; $i++) {
            $name = "tcloud-transcription-batch-{$i}";
            if ($this->serviceExists($name)) {
                @shell_exec("systemctl stop {$name}.service 2>/dev/null");
            }
        }
    }
}
