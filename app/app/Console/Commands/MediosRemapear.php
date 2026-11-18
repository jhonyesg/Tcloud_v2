<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\MisArchivos\MedioCatalogService;
use Illuminate\Console\Command;

/**
 * Da de alta los sub-canales de los contenedores Emisoras 01/02/03/04.
 *
 * QUÉ HACE: recorre un storage contenedor (`folder_layout =
 * 'grouped_by_subfolder'`: `01 Emisoras 01`, `02 Emisoras 01 Reg`,
 * `05 Emisoras 04`, `06 Emisoras 05`, `04 Emisoras 03`) y propone un medio
 * por cada canal que encuentre dentro.
 *
 * Un canal es una carpeta cuyas subcarpetas directas son días `DDMMYYYY`.
 * El recorrido baja hasta 3 niveles porque los layouts reales son de 1
 * (`Radios/Bogota/CANDELA`), 2 (`RD1/Antioquia/BLU`) y 3 niveles
 * (`Radios/Bogota/TROPICANA_REGIONAL/barranquilla`).
 *
 * DESTINO DEL MEDIO: si el canal YA tiene un storage propio, el medio se
 * declara sobre ese storage con `subpath` vacío — ese storage es el dueño
 * de la ruta física y de sus filas en `files`, y declararlo sobre el
 * contenedor crearía una tercera identidad del mismo árbol.
 *
 * COMPARTEN VALIDACIONES CON LA UI: ambos llaman a
 * `MedioCatalogService::detectSubchannels()`. No hay puerta trasera por
 * consola con reglas más débiles.
 *
 * Escribe con `--apply`. Por defecto solo reporta.
 */
class MediosRemapear extends Command
{
    protected $signature = 'medios:remapear
                            {--storage= : storage_provider_id del contenedor a remapear}
                            {--todos : Remapea todos los contenedores con layout agrupado}
                            {--apply : Escribe los medios declarados}
                            {--yes : No pedir confirmación}';

    protected $description = 'Declara los sub-canales de los contenedores Emisoras como medios del catálogo.';

    public function handle(MedioCatalogService $catalog): int
    {
        $containers = $this->resolveContainers();

        if ($containers === []) {
            $this->error('Indica --storage=<id> o --todos.');

            return Command::FAILURE;
        }

        $rows = [];
        $pending = [];
        $already = 0;

        foreach ($containers as $container) {
            $this->line("── {$container->name} (storage {$container->id})");
            $this->line("   " . $container->base_path);

            $proposals = $catalog->detectSubchannels($container);

            if ($proposals === []) {
                $this->warn('   No se detectaron canales (¿montaje caído o layout no reconocido?).');
                $this->newLine();

                continue;
            }

            foreach ($proposals as $proposal) {
                $target = StorageProvider::find($proposal['target_storage_id']);
                $estado = $proposal['declared']
                    ? 'ya declarado'
                    : ($proposal['is_storage'] ? 'nuevo (canal con storage propio)' : 'nuevo');

                $rows[] = [
                    $proposal['subpath'],
                    $proposal['name'],
                    $proposal['region'] ?? '—',
                    $proposal['media_kind'],
                    $target?->name ?? '—',
                    $estado,
                ];

                if ($proposal['declared']) {
                    $already++;

                    continue;
                }

                $pending[] = ['container' => $container, 'proposal' => $proposal];
            }

            $this->newLine();
        }

        $this->table(
            ['canal en disco', 'nombre', 'región', 'tipo', 'medio sobre', 'estado'],
            $rows
        );

        $this->newLine();
        $this->line('Canales detectados: ' . count($rows));
        $this->line('Ya declarados: ' . $already);
        $this->line('Pendientes: ' . count($pending));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Modo reporte (dry-run). Repetir con --apply para escribir los medios.');

            return Command::SUCCESS;
        }

        if ($pending === []) {
            $this->warn('No hay nada que escribir.');

            return Command::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Escribir ' . count($pending) . ' medios en el catálogo?', true)) {
            $this->warn('Cancelado.');

            return Command::SUCCESS;
        }

        $written = 0;

        foreach ($pending as $entry) {
            /** @var StorageProvider $container */
            $container = $entry['container'];
            $proposal = $entry['proposal'];

            $target = StorageProvider::find($proposal['target_storage_id']);

            if ($target === null) {
                continue;
            }

            $catalog->declare(
                $target,
                $proposal['target_subpath'],
                $proposal['name'],
                $proposal['region'],
                $proposal['media_kind'],
            );

            $written++;
        }

        $this->info("Medios escritos: {$written}");

        return Command::SUCCESS;
    }

    /**
     * @return list<StorageProvider>
     */
    private function resolveContainers(): array
    {
        $storageId = $this->option('storage');

        if ($storageId !== null) {
            $storage = StorageProvider::find((int) $storageId);

            return $storage === null ? [] : [$storage];
        }

        if (! $this->option('todos')) {
            return [];
        }

        return StorageProvider::query()
            ->where('kind', 'local')
            ->where('enabled', true)
            ->where('folder_layout', 'grouped_by_subfolder')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
