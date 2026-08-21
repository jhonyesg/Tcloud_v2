<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

    public function handle(TranscriptorSettings $settings): int
    {
        $apply = (bool) $this->option('apply');
        $json = (bool) $this->option('json');

        // Antes eran private const. Ahora salen de la capa de settings, con los
        // mismos valores como default, para poder bajarlos en caliente durante
        // una saturacion sin desplegar.
        $minWorkers = $settings->int('worker_min');
        $ratio = max(1, $settings->int('worker_ratio'));

        // El techo se acota a las units realmente instaladas: la UI no puede
        // pedir workers que no existen en systemd.
        $installed = $this->installedUnitCount();
        $maxWorkers = max(1, min($settings->int('worker_max'), $installed ?: $settings->int('worker_max')));
        $override = $settings->int('worker_override');

        $countStorages = StorageProvider::transcriptionEnabled()->count();

        if ($countStorages === 0) {
            $this->info('No hay storages con transcripcion habilitada. Workers objetivo: 0 (off).');

            // Este mensaje vivia solo en transcription-tune.log, que nadie mira.
            // El 2026-08-18 se repitio 517 veces mientras el pipeline entero
            // estaba parado por un pivote vacio. Apagar TODO el pool no es una
            // operacion rutinaria: va a laravel.log como WARNING, con cuantos
            // workers se estan apagando.
            $activos = $apply ? $this->countActiveWorkers() : 0;
            Log::warning('TranscriptionTune: 0 storages con transcripcion habilitada; el pool queda apagado', [
                'workers_activos' => $activos,
                'apply' => $apply,
                'pista' => 'revisar user_storages.transcription_enabled: si esta vacio, el pipeline no descubre ni envia nada',
            ]);

            $stoppedOrphans = $apply ? $this->reconcileForbiddenPools() : [];
            if ($json) {
                $this->line(json_encode(['ts' => now()->toIso8601String(), 'storages_total' => 0, 'medios_total' => 0, 'workers_target' => 0, 'started' => [], 'stopped' => [], 'stopped_orphans' => $stoppedOrphans]));
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

        if ($override > 0) {
            // En saturacion lo que se necesita es "ponlo en 4 ahora", no deducir
            // que ratio produce 4.
            $workersObjetivo = min($override, $maxWorkers);
        } else {
            $workersObjetivo = (int) max($minWorkers, min($maxWorkers, (int) ceil($totalMedios / $ratio)));
        }

        $this->info("Storages habilitados: {$countStorages}");
        $this->info("  - planos (1 medio c/u): {$countFlat}");
        $this->info("  - agrupados (multi-medio): {$grouped->count()} storages -> {$mediosAgrupados} medios");
        $this->info("Medios total equivalente: {$totalMedios}");
        if ($override > 0) {
            $this->warn("Workers objetivo: {$workersObjetivo} (FORZADO por worker_override={$override}; la formula se ignora)");
        } else {
            $this->info("Workers objetivo: {$workersObjetivo} (medios/{$ratio}, acotado a {$minWorkers}-{$maxWorkers})");
        }

        if (!$apply) {
            $this->warn('Modo dry-run. Use --apply para aplicar cambios al pool systemd.');
            $orphansDetected = $this->detectForbiddenPools();
            if ($orphansDetected) {
                $this->warn('Pools prohibidos activos (se desactivarian con --apply): ' . implode(', ', $orphansDetected));
            }
            if ($json) {
                $this->line(json_encode([
                    'ts' => now()->toIso8601String(),
                    'storages_total' => $countStorages,
                    'medios_total' => $totalMedios,
                    'workers_target' => $workersObjetivo,
                    'started' => [],
                    'stopped' => [],
                    'stopped_orphans' => [],
                    'orphans_detected' => $orphansDetected,
                ]));
            }
            return Command::SUCCESS;
        }

        $started = [];
        $stopped = [];

        // Reconciliar pools prohibidos ANTES de calcular nada mas: si hay
        // instancias worker@N vivas, la concurrencia real no es la que este
        // comando cree gestionar.
        $stoppedOrphans = $this->reconcileForbiddenPools();

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
        for ($i = $workersObjetivo + 1; $i <= max($maxWorkers, $installed); $i++) {
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
                'stopped_orphans' => $stoppedOrphans,
                'worker_min' => $minWorkers,
                'worker_max' => $maxWorkers,
                'worker_ratio' => $ratio,
                'worker_override' => $override,
                'units_installed' => $installed,
            ]));
        }

        return Command::SUCCESS;
    }

    /**
     * Desactiva pools de workers prohibidos por la spec
     * (transcription-orchestrator-runtime §7).
     *
     * El unico pool permitido es tcloud-transcription-batch-{1..N}. Las
     * instancias del template tcloud-transcription-worker@N son invisibles para
     * este comando: no las arranca ni las cuenta, asi que mientras esten vivas
     * la concurrencia real duplica a la calculada. Verificado el 2026-07-26:
     * 11 batch-N + 10 worker@N = 21 workers reales contra una API con 2 GPUs.
     *
     * La spec ya las prohibia, pero nada lo hacia cumplir. Esto convierte el
     * contrato en autoaplicado: si reaparecen, el siguiente tune las apaga y
     * deja rastro en el log.
     *
     * @return list<string> units detenidas en esta ejecucion
     */
    private function reconcileForbiddenPools(): array
    {
        $stopped = [];

        foreach ($this->detectForbiddenPools() as $unit) {
            $this->warn("Pool prohibido activo: {$unit} — desactivando.");
            @shell_exec('systemctl disable --now ' . escapeshellarg($unit) . ' 2>/dev/null');
            $stopped[] = $unit;
        }

        if ($stopped) {
            \Illuminate\Support\Facades\Log::warning(
                'TranscriptionTune: detenidas instancias de un pool prohibido (spec transcription-orchestrator-runtime §7)',
                ['stopped_orphans' => $stopped]
            );
        }

        return $stopped;
    }

    /**
     * Instancias activas del template prohibido tcloud-transcription-worker@.
     *
     * @return list<string>
     */
    private function detectForbiddenPools(): array
    {
        $out = @shell_exec("systemctl list-units 'tcloud-transcription-worker@*.service' --all --plain --no-legend 2>/dev/null");
        if (!$out) {
            return [];
        }

        $units = [];
        foreach (preg_split('/\R/', trim($out)) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Formato: UNIT LOAD ACTIVE SUB DESCRIPTION
            $cols = preg_split('/\s+/', $line);
            $unit = $cols[0] ?? '';
            $active = $cols[2] ?? '';

            if ($unit !== '' && $active === 'active') {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /** Numero de units tcloud-transcription-batch-N.service instaladas. */
    private function installedUnitCount(): int
    {
        return count(glob('/etc/systemd/system/tcloud-transcription-batch-*.service') ?: []);
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

    /** Cuantos workers del pool estan corriendo ahora mismo. */
    private function countActiveWorkers(): int
    {
        $activos = 0;
        for ($i = 1; $i <= max(1, $this->installedUnitCount()); $i++) {
            $name = "tcloud-transcription-batch-{$i}";
            if ($this->serviceExists($name) && $this->getActiveState($name) === 'active') {
                $activos++;
            }
        }

        return $activos;
    }

    private function stopAllWorkers(): void
    {
        // Recorre las units instaladas, no una constante: si mañana hay 16
        // instaladas, un tope fijo de 12 dejaria 4 corriendo.
        for ($i = 1; $i <= max(1, $this->installedUnitCount()); $i++) {
            $name = "tcloud-transcription-batch-{$i}";
            if ($this->serviceExists($name)) {
                @shell_exec("systemctl stop {$name}.service 2>/dev/null");
            }
        }
    }
}
