<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\MountGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Declara el vínculo entre cada storage de origen y su carpeta de backup.
 *
 * POR QUÉ EXISTE: los nombres de los dos lados no se corresponden. En el
 * árbol de "Producción" (`Tcloud/Disco_D/backup`) las carpetas se llaman
 * `05_Redmas`, `22_Institucional_BK`, `08_Tro_BK`… y en la plataforma los
 * storages se llaman `09 Redmas`, `05 Institucional`, `19 Canal Tro`… La
 * numeración del backup no corresponde a la de la plataforma y hay dos
 * carpetas que comparten prefijo (`13_Telecafe_BK` y `13_Telepacifico_Bk`).
 * El vínculo NO se puede deducir: se declara.
 *
 * QUÉ HACE:
 *  - Lista las carpetas de canal del árbol de backup LEYÉNDOLAS DEL DISCO
 *    (el catálogo está obsoleto: 536 carpetas-día catalogadas frente a 368
 *    en disco).
 *  - Propone un candidato por storage de origen comparando las palabras
 *    normalizadas del nombre, ignorando el prefijo numérico (que no
 *    corresponde) y el sufijo `BK`.
 *  - Solo escribe los pares CONFIABLES: un token compartido que aparece en
 *    una sola carpeta y en un solo storage. El resto queda reportado como
 *    dudoso y se mapea a mano con `--map=ID:CARPETA`.
 *
 * Escribe con `--apply`. Por defecto solo reporta.
 */
class MapBackupStorages extends Command
{
    protected $signature = 'backups:map-storages
                            {--root= : storage_provider_id del árbol de backup (default: config backups.root_storage_id)}
                            {--map=* : Mapeo explícito storage_id:carpeta (repetible)}
                            {--apply : Escribe los mapeos encontrados}
                            {--yes : No pedir confirmación}';

    protected $description = 'Declara el vínculo storage de origen → carpeta de backup del árbol de producción.';

    public function handle(MountGuard $mountGuard): int
    {
        $rootId = $this->option('root') !== null
            ? (int) $this->option('root')
            : (int) config('backups.root_storage_id');

        if (! $rootId) {
            $this->error('Falta el storage raíz del árbol de backup. Usa --root=<id> o define BACKUP_ROOT_STORAGE_ID.');

            return Command::FAILURE;
        }

        $root = StorageProvider::find($rootId);
        if ($root === null) {
            $this->error("El storage {$rootId} no existe.");

            return Command::FAILURE;
        }

        $basePath = rtrim((string) $root->base_path, '/');
        if ($basePath === '') {
            $this->error("El storage {$rootId} no tiene base_path.");

            return Command::FAILURE;
        }

        if ($mountGuard->isNetworkMountDown($basePath)) {
            $this->error("El montaje de red de {$basePath} está caído. No se toca el disco.");

            return Command::FAILURE;
        }

        $folders = $this->channelFolders($basePath);
        if ($folders === []) {
            $this->error("No se encontraron carpetas de canal en {$basePath}.");

            return Command::FAILURE;
        }

        $this->line("Árbol de backup: {$basePath} (storage {$root->id} — {$root->name})");
        $this->line("Carpetas de canal en disco: " . count($folders));
        $this->newLine();

        $sources = StorageProvider::query()
            ->where('kind', 'local')
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        $assignment = $this->propose($sources, $folders);
        $explicit = $this->parseExplicitMaps();

        $rows = [];
        $confident = 0;
        $doubtful = 0;

        foreach ($sources as $source) {
            $folder = $explicit[$source->id] ?? ($assignment[$source->id] ?? null);

            if ($folder === null) {
                $rows[] = [$source->id, $source->name, '—', 'sin candidato', ''];
                continue;
            }

            $exists = is_dir($basePath . '/' . $folder);
            $origin = array_key_exists($source->id, $explicit) ? 'explícito' : 'automático';

            if (! $exists) {
                $rows[] = [$source->id, $source->name, $folder, 'NO EXISTE EN DISCO', $origin];
                continue;
            }

            if ($origin === 'explícito') {
                $rows[] = [$source->id, $source->name, $folder, 'listo', $origin];
            } else {
                $rows[] = [$source->id, $source->name, $folder, 'listo', $origin];
                $confident++;
                continue;
            }
        }

        foreach ($sources as $source) {
            if (isset($assignment[$source->id]) && ! isset($explicit[$source->id])) {
                continue;
            }
            if (isset($explicit[$source->id])) {
                continue;
            }
            if (! isset($assignment[$source->id])) {
                $doubtful++;
            }
        }

        $this->table(
            ['storage', 'nombre', 'carpeta backup', 'estado', 'origen'],
            $rows
        );

        $this->newLine();
        $this->line("Mapeos automáticos confiables: {$confident}");
        $this->line("Storages sin candidato confiable: {$doubtful}");

        $pending = array_filter(
            array_keys($assignment),
            fn (int $id) => ! isset($explicit[$id])
        );

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Modo reporte (dry-run). Repetir con --apply para escribir los mapeos.');
            $this->line('Pares dudosos: se declaran a mano con --map=<storage_id>:<carpeta>.');

            return Command::SUCCESS;
        }

        if ($pending === [] && $explicit === []) {
            $this->warn('No hay nada que escribir.');

            return Command::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Escribir los mapeos en storage_providers?', true)) {
            $this->warn('Cancelado.');

            return Command::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($assignment, $explicit, $rootId, &$written) {
            foreach ($assignment as $storageId => $folder) {
                if (isset($explicit[$storageId])) {
                    continue;
                }
                StorageProvider::where('id', $storageId)->update([
                    'backup_storage_id' => $rootId,
                    'backup_folder_name' => $folder,
                ]);
                $written++;
            }

            foreach ($explicit as $storageId => $folder) {
                StorageProvider::where('id', $storageId)->update([
                    'backup_storage_id' => $rootId,
                    'backup_folder_name' => $folder,
                ]);
                $written++;
            }
        });

        $this->info("Mapeos escritos: {$written}");

        return Command::SUCCESS;
    }

    /**
     * Carpetas de canal del árbol de backup, leídas DEL DISCO.
     *
     * @return list<string>
     */
    private function channelFolders(string $basePath): array
    {
        $entries = @scandir($basePath);
        if ($entries === false) {
            return [];
        }

        $folders = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! is_dir($basePath . '/' . $entry)) {
                continue;
            }
            $folders[] = $entry;
        }

        sort($folders);

        return $folders;
    }

    /**
     * Propone un par origen → carpeta para cada storage con una señal
     * inequívoca.
     *
     * La señal es un TOKEN compartido que aparece en una sola carpeta y en un
     * solo storage. Se resuelve en dos pasadas: cada vez que un par queda
     * asignado, su carpeta y su storage salen del conjunto y un token que era
     * ambiguo puede volverse único (por ejemplo "rcn" solo después de
     * resolver "internacional").
     *
     * @param  \Illuminate\Support\Collection<int,StorageProvider>  $sources
     * @param  list<string>  $folders
     * @return array<int,string>  storage_id => carpeta de backup
     */
    private function propose($sources, array $folders): array
    {
        $sourceTokens = [];
        foreach ($sources as $source) {
            $sourceTokens[$source->id] = $this->tokens($source->name);
        }

        $folderTokens = [];
        foreach ($folders as $folder) {
            $folderTokens[$folder] = $this->tokens($folder);
        }

        $assignment = [];
        $usedFolders = [];
        $usedSources = [];

        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($sources as $source) {
                if (isset($usedSources[$source->id])) {
                    continue;
                }

                $candidates = [];
                foreach ($folders as $folder) {
                    if (isset($usedFolders[$folder])) {
                        continue;
                    }

                    $shared = array_intersect($sourceTokens[$source->id], $folderTokens[$folder]);
                    if ($shared === []) {
                        continue;
                    }

                    foreach ($shared as $token) {
                        $folderCount = $this->countToken($token, $folderTokens, $usedFolders);
                        $sourceCount = $this->countToken($token, $sourceTokens, $usedSources);
                        if ($folderCount === 1 && $sourceCount === 1) {
                            $candidates[$folder] = true;
                            break;
                        }
                    }
                }

                if (count($candidates) === 1) {
                    $folder = array_key_first($candidates);
                    $assignment[$source->id] = $folder;
                    $usedFolders[$folder] = true;
                    $usedSources[$source->id] = true;
                }
            }
        }

        return $assignment;
    }

    /**
     * @param  array<string,list<string>>  $tokens
     * @param  array<string,bool>  $used
     */
    private function countToken(string $token, array $tokens, array $used): int
    {
        $count = 0;
        foreach ($tokens as $key => $set) {
            if (isset($used[$key])) {
                continue;
            }
            if (in_array($token, $set, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Palabras normalizadas de un nombre: minúsculas, solo alfanuméricos,
     * sin el prefijo numérico ni el sufijo `bk`.
     *
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '';

        $out = [];
        foreach (preg_split('/\s+/', trim($normalized)) ?: [] as $token) {
            if ($token === '' || $token === 'bk') {
                continue;
            }
            if (ctype_digit($token)) {
                continue; // prefijo numérico: no corresponde entre los dos lados
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<int,string>  storage_id => carpeta
     */
    private function parseExplicitMaps(): array
    {
        $out = [];
        foreach ((array) $this->option('map') as $pair) {
            if (! str_contains((string) $pair, ':')) {
                $this->warn("Ignorado --map={$pair} (formato esperado <storage_id>:<carpeta>).");
                continue;
            }
            [$id, $folder] = explode(':', (string) $pair, 2);
            $out[(int) $id] = trim($folder, '/');
        }

        return $out;
    }
}
