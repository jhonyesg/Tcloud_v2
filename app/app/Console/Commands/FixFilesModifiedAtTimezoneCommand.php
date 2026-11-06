<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\MountGuard;
use App\Support\FilesystemTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repara el desfase +5 h de `files.file_modified_at` (2026-11-05).
 *
 * Contexto del bug: la regresion `b07e434` (2026-09-21) quito el segundo
 * argumento `config('app.timezone')` de tres llamadas a
 * `Carbon::createFromTimestamp()`. Carbon 3 devuelve el instante en UTC cuando
 * no se pasa zona, Eloquent lo formatea con la hora de pared UTC y PostgreSQL
 * lo interpreta en la sesion `America/Bogota`: la hora Bogota real se guardo
 * 5 h adelantada. Es el mismo patron de `transcription:fix-recorded-at-timezone`
 * pero aplicado a `files.file_modified_at`.
 *
 * El fix de raiz es `App\Support\FilesystemTime` (aplicado en todos los
 * call sites de escritura). Este comando corrige SOLO las filas ya escritas y
 * solo cuando el desfase es DEMOSTRABLE contra el disco: se compara el `mtime`
 * real del archivo con el valor guardado. Las filas cuyo archivo no es legible
 * se reportan como `unreadable` y NO se tocan.
 *
 * Flujo seguro: el comando SIEMPRE escanea primero (sin escribir), y solo si
 * `--apply` esta presente crea el snapshot y recien despues escribe. Nunca
 * escribe antes de tener el snapshot.
 *
 * Uso:
 *   php artisan files:fix-modified-at-timezone                      (dry-run)
 *   php artisan files:fix-modified-at-timezone --apply --yes
 *   php artisan files:fix-modified-at-timezone --storage=34 --apply --yes
 *   php artisan files:fix-modified-at-timezone --since=2026-09-21 --apply --yes
 *
 * Idempotente: una fila ya correcta no vuelve a matchear la condicion.
 */
class FixFilesModifiedAtTimezoneCommand extends Command
{
    protected $signature = 'files:fix-modified-at-timezone
                            {--apply : Ejecuta los UPDATE. Sin esta bandera solo reporta (dry-run)}
                            {--yes : Omite la confirmacion interactiva}
                            {--storage= : Limita a un storage_provider_id concreto}
                            {--since=2026-09-21 : Solo filas con updated_at >= esta fecha (YYYY-MM-DD)}
                            {--mode=shift : shift = solo la firma +5h del bug; all = cualquier drift contra el disco}
                            {--future-only : Solo filas con file_modified_at > now() (valores imposibles)}
                            {--chunk=500 : Tamano de lote}';

    protected $description = 'Corrige el desfase +5h de files.file_modified_at comparando contra el mtime real en disco.';

    /** Tolerancia entre el mtime del disco y el valor guardado. */
    private const TOLERANCE_SECONDS = 2;

    /**
     * Firma del bug: el binding se escribio con la hora de pared UTC y PG la
     * interpreto en Bogota, asi que el drift es exactamente +5 h. El rango
     * absorbe redondeos de segundos del mtime.
     */
    private const SHIFT_MIN_SECONDS = 4 * 3600 + 55 * 60;
    private const SHIFT_MAX_SECONDS = 5 * 3600 + 5 * 60;

    public function handle(MountGuard $mountGuard): int
    {
        $apply = (bool) $this->option('apply');
        $since = (string) $this->option('since');
        $chunk = max(100, (int) $this->option('chunk'));
        $storageFilter = $this->option('storage') !== null ? (int) $this->option('storage') : null;
        $mode = (string) $this->option('mode');
        $futureOnly = (bool) $this->option('future-only');

        if (!in_array($mode, ['shift', 'all'], true)) {
            $this->error("--mode invalido: '{$mode}'. Usa shift o all.");
            return Command::FAILURE;
        }

        if (!$this->validDate($since)) {
            $this->error("--since invalido: '{$since}'. Usa YYYY-MM-DD.");
            return Command::FAILURE;
        }

        $this->line($apply
            ? '<fg=red>MODO APPLY: se escribira en la BD (con snapshot previo).</>'
            : '<fg=yellow>DRY-RUN: solo reporte, no se escribe nada.</>');
        $this->line("Filtro: updated_at >= {$since}" . ($storageFilter ? " · storage={$storageFilter}" : '') . " · modo={$mode}" . ($futureOnly ? ' · solo futuros' : ''));
        if ($mode === 'shift') {
            $this->line('Modo shift: solo filas con drift +5h (firma exacta de la regresion b07e434).');
        }

        $storages = StorageProvider::query()
            ->when($storageFilter, fn ($q) => $q->where('id', $storageFilter))
            ->get(['id', 'name', 'kind', 'base_path'])
            ->keyBy('id');

        if ($storages->isEmpty()) {
            $this->error('No hay storages para el filtro indicado.');
            return Command::FAILURE;
        }

        $unreachable = $this->unreachableStorages($storages, $mountGuard);
        if (!empty($unreachable)) {
            $this->warn('Storages omitidos (no se puede leer su filesystem):');
            foreach ($unreachable as $sid => $reason) {
                $this->line("  storage {$sid} ({$storages[$sid]->name}): {$reason}");
            }
            $this->newLine();
        }

        $usableIds = $storages->keys()->diff(array_keys($unreachable))->values()->all();
        if (empty($usableIds)) {
            $this->error('Ningun storage es legible; nada que hacer.');
            return Command::FAILURE;
        }

        $scan = $this->scan($usableIds, $storages, $since, $chunk, $mode, $futureOnly);

        if (!empty($scan['examples'])) {
            $this->newLine();
            $this->line('Ejemplos (hasta 12):');
            foreach ($scan['examples'] as $e) {
                $this->line('  ' . $e);
            }
        }

        $this->newLine();
        $this->table(
            ['metrica', 'valor'],
            [
                ['storages procesados', count($usableIds)],
                ['storages omitidos', count($unreachable)],
                ['filas escaneadas', $scan['scanned']],
                ['filas correctas', $scan['ok']],
                [$futureOnly ? 'filas con fecha futura' : ($mode === 'all' ? 'filas desfasadas (cualquier drift)' : 'filas desfasadas (+5h)'), $scan['candidates']],
                [$futureOnly ? 'filas no futuras (no tocadas)' : 'filas con otro drift (no tocadas)', $scan['other_drift']],
                ['filas ilegibles (no tocadas)', $scan['unreadable']],
                ['filas sin storage', $scan['missing_storage']],
            ],
        );

        if ($scan['candidates'] === 0) {
            $this->info('Nada que corregir.');
            return Command::SUCCESS;
        }

        if (!$apply) {
            $this->warn('DRY-RUN: ninguna modificacion realizada. Use --apply para ejecutar.');
            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm(
            "Se creara un snapshot y se corregiran {$scan['candidates']} filas de files.file_modified_at. ¿Continuar?",
            false
        )) {
            $this->warn('Cancelado por el operador.');
            return Command::SUCCESS;
        }

        // Snapshot PRIMERO: sin snapshot persistido no se escribe nada.
        $snapshot = $this->snapshotRows($scan['candidate_ids']);
        if ($snapshot === null) {
            return Command::FAILURE;
        }

        $fixed = $this->applyFixes($scan['fixes'], $chunk);

        $this->invalidateListingCaches($scan['affected_storages'], $scan['affected_parents']);

        Log::info('files.fix_modified_at_timezone.completed', [
            'snapshot' => $snapshot,
            'fixed' => $fixed,
            'candidates' => $scan['candidates'],
            'storages' => array_keys($scan['affected_storages']),
        ]);

        $this->newLine();
        $this->info("Filas corregidas: {$fixed}");
        $this->info("Snapshot persistido: {$snapshot}");
        $this->info('Caches de listado invalidadas: ' . count($scan['affected_storages']) . ' storages.');

        return Command::SUCCESS;
    }

    /**
     * Recorre las filas y calcula el valor correcto de cada una SIN escribir.
     *
     * @param  array<int,int>  $usableIds
     * @param  \Illuminate\Support\Collection<int,StorageProvider>  $storages
     * @return array{scanned:int,ok:int,candidates:int,unreadable:int,missing_storage:int,other_drift:int,
     *               candidate_ids:array<int,int>,fixes:array<int,string>,
     *               affected_storages:array<int,bool>,affected_parents:array<string,bool>,examples:array<int,string>}
     */
    private function scan(array $usableIds, $storages, string $since, int $chunk, string $mode, bool $futureOnly = false): array
    {
        $out = [
            'scanned' => 0,
            'ok' => 0,
            'candidates' => 0,
            'unreadable' => 0,
            'missing_storage' => 0,
            'other_drift' => 0,
            'candidate_ids' => [],
            'fixes' => [],
            'affected_storages' => [],
            'affected_parents' => [],
            'examples' => [],
        ];

        DB::table('files')
            ->select(['id', 'path', 'storage_provider_id', 'parent_id', 'file_modified_at'])
            ->whereNotNull('file_modified_at')
            ->where('updated_at', '>=', $since . ' 00:00:00')
            ->whereIn('storage_provider_id', $usableIds)
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$out, $storages, $mode, $futureOnly) {
                foreach ($rows as $row) {
                    $out['scanned']++;

                    $storage = $storages[$row->storage_provider_id] ?? null;
                    if ($storage === null) {
                        $out['missing_storage']++;
                        continue;
                    }

                    $absolute = rtrim((string) $storage->base_path, '/') . '/' . ltrim((string) $row->path, '/');
                    $expected = FilesystemTime::fromPath($absolute);
                    $stored = self::parseTimestamptz((string) $row->file_modified_at);

                    if ($expected === null || $stored === null) {
                        $out['unreadable']++;
                        continue;
                    }

                    $drift = $stored - $expected->timestamp;
                    if (abs($drift) <= self::TOLERANCE_SECONDS) {
                        $out['ok']++;
                        continue;
                    }

                    // `--future-only`: un `file_modified_at` en el futuro es
                    // imposible por definicion. Es el sintoma visible del bug
                    // cuando el mtime de la carpeta cambio despues del sync
                    // (el drift ya no es +5h exacto pero el valor sigue mal).
                    if ($futureOnly && $stored <= time()) {
                        $out['other_drift']++;
                        continue;
                    }

                    // Modo `shift`: solo la firma exacta del bug (+5h). Cualquier
                    // otro drift (p.ej. el mtime del directorio cambio despues del
                    // sync) NO es evidencia de la regresion y se deja intacto.
                    if ($mode === 'shift'
                        && ($drift < self::SHIFT_MIN_SECONDS || $drift > self::SHIFT_MAX_SECONDS)) {
                        $out['other_drift']++;
                        continue;
                    }

                    $out['candidates']++;
                    $out['candidate_ids'][] = (int) $row->id;
                    $out['fixes'][(int) $row->id] = $expected->format('Y-m-d H:i:sP');
                    $out['affected_storages'][(int) $row->storage_provider_id] = true;
                    // La cache de listado es por (storage, parent). Guardamos la
                    // pareja para invalidar exactamente las carpetas afectadas.
                    $out['affected_parents'][(int) $row->storage_provider_id . ':' . ($row->parent_id ?? 'null')] = true;

                    if (count($out['examples']) < 12) {
                        $out['examples'][] = sprintf(
                            'id=%d %s  guardado=%s  mtime=%s  drift=%+ds',
                            $row->id,
                            mb_substr((string) $row->path, 0, 48),
                            gmdate('Y-m-d H:i:s', $stored) . 'Z',
                            $expected->utc()->format('Y-m-d H:i:s\Z'),
                            $drift,
                        );
                    }
                }
            }, 'id');

        return $out;
    }

    /**
     * Aplica los UPDATE por lote. No toca `updated_at`: el registro no cambio,
     * solo se corrigio un valor derivado del filesystem.
     *
     * Usa un `UPDATE ... FROM (VALUES ...)` por lote en vez de N UPDATE sueltos:
     * con ~140k filas candidatas, fila-por-fila son horas de round-trips. Cada
     * lote va en su propia transaccion corta.
     *
     * @param  array<int,string>  $fixes  id => timestamp con offset
     */
    private function applyFixes(array $fixes, int $chunk): int
    {
        $fixed = 0;

        foreach (array_chunk($fixes, $chunk, true) as $batch) {
            $values = [];
            $params = [];

            foreach ($batch as $id => $value) {
                // Cast explicito a timestamptz para que PG NO interprete el naive
                // en la zona de la sesion: el string ya trae el offset correcto.
                $values[] = '(?::bigint, ?::timestamptz)';
                $params[] = $id;
                $params[] = $value;
            }

            $sql = 'UPDATE files AS f
                       SET file_modified_at = v.ts
                      FROM (VALUES ' . implode(',', $values) . ') AS v(id, ts)
                     WHERE f.id = v.id';

            $fixed += DB::affectingStatement($sql, $params);
        }

        return $fixed;
    }

    /**
     * Invalida las caches de listado de las carpetas afectadas y el root de
     * cada storage tocado. `folder_gen:{storage}:{parent}` es el contador que
     * forma parte de la cache key (ver FileController::index).
     *
     * @param  array<int,bool>     $storages
     * @param  array<string,bool>  $parents  llaves "storageId:parentId|null"
     */
    private function invalidateListingCaches(array $storages, array $parents): void
    {
        foreach (array_keys($storages) as $sid) {
            Cache::increment("folder_gen:{$sid}:null");
        }

        foreach (array_keys($parents) as $pair) {
            [$sid, $pid] = explode(':', $pair, 2);
            Cache::increment("folder_gen:{$sid}:{$pid}");
        }
    }

    /**
     * Crea la tabla snapshot con las filas candidatas. Devuelve el nombre o
     * null si fallo (en ese caso NO se permite escribir).
     *
     * Se llena por lotes: con ~140k candidatas, un solo `WHERE id IN (...)`
     * arma una sentencia de mas de un megabyte y una transaccion enorme. La
     * tabla se crea vacia con la estructura de `files` y se inserta por tandas.
     *
     * @param  array<int,int>  $ids
     */
    private function snapshotRows(array $ids): ?string
    {
        if (empty($ids)) {
            return null;
        }

        $table = 'files_mtime_pre_fix_' . date('Ymd_His');
        $this->info("Creando snapshot {$table} con " . count($ids) . ' filas...');

        try {
            DB::statement("CREATE TABLE {$table} AS SELECT * FROM files WHERE false");

            foreach (array_chunk($ids, 5000) as $batch) {
                DB::statement(
                    "INSERT INTO {$table} SELECT * FROM files WHERE id IN (" .
                    implode(',', array_map('intval', $batch)) . ')'
                );
            }
        } catch (\Throwable $e) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
            $this->error('No se pudo crear el snapshot: ' . $e->getMessage());
            Log::error('files.fix_modified_at_timezone.snapshot_failed', ['error' => $e->getMessage()]);
            return null;
        }

        return $table;
    }

    /**
     * Storages cuyo filesystem no se puede leer con seguridad.
     *
     * NUNCA `stat()` sobre un mount de red caido: en modo hard la syscall
     * bloquea indefinidamente (ver MountGuard).
     *
     * @param  \Illuminate\Support\Collection<int,StorageProvider>  $storages
     * @return array<int,string>  storage id => motivo
     */
    private function unreachableStorages($storages, MountGuard $mountGuard): array
    {
        $out = [];

        foreach ($storages as $storage) {
            $base = rtrim((string) $storage->base_path, '/');

            if ($base === '') {
                $out[$storage->id] = 'base_path_vacio';
                continue;
            }

            // Los `base_path` de Mis Archivos suelen ser SUBDIRECTORIOS de un
            // mount NFS (ej. .../Disco_D/backup), asi que comparar solo el path
            // exacto contra /proc/self/mounts no alcanza: hay que buscar el
            // mount de red que lo CONTIENE. Sin esto se ejecutaria `is_dir()`
            // sobre un NFS colgado en modo hard y el comando quedaria bloqueado.
            $networkMount = $this->enclosingNetworkMount($base, $mountGuard);
            if ($networkMount !== null && !$mountGuard->isMounted($networkMount)) {
                $out[$storage->id] = "network_mount_down ({$networkMount})";
                continue;
            }

            if (!@is_dir($base)) {
                $out[$storage->id] = 'base_path_no_existe';
            }
        }

        return $out;
    }

    /**
     * Mount de red EFECTIVO para el path dado, o null si el filesystem que lo
     * contiene es local.
     *
     * La lógica vive ahora en `MountGuard::enclosingNetworkMount()`: es la
     * misma regla (el kernel resuelve por el punto de montaje más específico)
     * y la comparten el comando de zona horaria y el resolver de backups.
     * No volver a duplicarla aquí.
     */
    private function enclosingNetworkMount(string $path, MountGuard $mountGuard): ?string
    {
        return $mountGuard->enclosingNetworkMount($path);
    }

    /**
     * Convierte el texto que devuelve PostgreSQL ("2026-11-06 01:42:24-05")
     * a epoch. `strtotime` respeta el offset explicito del string.
     */
    private static function parseTimestamptz(string $value): ?int
    {
        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }

    private function validDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
