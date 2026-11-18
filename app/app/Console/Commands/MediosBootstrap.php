<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\MisArchivos\MedioCatalogService;
use Illuminate\Console\Command;

/**
 * Siembra el catálogo de medios con los canales planos.
 *
 * QUÉ HACE: por cada storage local cuyas carpetas-día cuelgan DIRECTAMENTE
 * del `base_path`, propone un medio con `subpath` vacío (el storage ES el
 * canal). El tipo (TV o radio) se deduce de la extensión de un archivo real
 * del canal, leyendo el DISCO.
 *
 * POR QUÉ NO SE USA `mime_type` DE `files`: agregar el mime sobre 1,2 M
 * filas tarda 18,9 s (medido el 2026-11-18). La prueba es estructural —
 * "tiene carpetas-día colgadas del base_path" — y cuesta un `scandir` por
 * storage.
 *
 * El catálogo NO incluye los sub-canales de los contenedores Emisoras
 * 01/02/03/04: esos se dan de alta con `medios:remapear`, porque son
 * carpetas internas de otro storage.
 *
 * Escribe con `--apply`. Por defecto solo reporta.
 */
class MediosBootstrap extends Command
{
    protected $signature = 'medios:bootstrap
                            {--apply : Escribe los medios declarados}
                            {--yes : No pedir confirmación}';

    protected $description = 'Siembra el catálogo de medios con los canales planos (TV y Radio).';

    public function handle(MedioCatalogService $catalog): int
    {
        $proposals = $catalog->bootstrapProposals();

        if ($proposals === []) {
            $this->error('No se detectaron canales planos.');

            return Command::FAILURE;
        }

        $rows = [];

        foreach ($proposals as $proposal) {
            $storage = StorageProvider::find($proposal['storage_id']);

            $rows[] = [
                $proposal['storage_id'],
                $proposal['name'],
                $proposal['media_kind'],
                $proposal['declared'] ? 'ya declarado' : 'nuevo',
                $storage?->base_path ?? '—',
            ];
        }

        $this->table(['storage', 'nombre', 'tipo', 'estado', 'base_path'], $rows);

        $pending = array_values(array_filter($proposals, fn (array $p) => ! $p['declared']));
        $already = count($proposals) - count($pending);

        $this->newLine();
        $this->line('Canales planos detectados: ' . count($proposals));
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

        foreach ($pending as $proposal) {
            $storage = StorageProvider::find($proposal['storage_id']);

            if ($storage === null) {
                continue;
            }

            $catalog->declare(
                $storage,
                $proposal['subpath'],
                $proposal['name'],
                $proposal['region'],
                $proposal['media_kind'],
            );

            $written++;
        }

        $this->info("Medios escritos: {$written}");

        return Command::SUCCESS;
    }
}
