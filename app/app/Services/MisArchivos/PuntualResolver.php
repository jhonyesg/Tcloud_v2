<?php

namespace App\Services\MisArchivos;

use App\Models\File;
use App\Models\Medio;
use App\Models\StorageProvider;
use App\Services\Ia\RecordedAt;
use App\Services\MountGuard;
use App\Services\StorageSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve el material de un medio para una fecha y un rango horario.
 *
 * QUÉ RESUELVE:
 *  - Los archivos de un medio en un día (`filesForDay`).
 *  - La cadencia del medio ese día (`deriveStep`).
 *  - La cobertura del rango pedido y el conjunto MÍNIMO de archivos que la
 *    cubren (`coverage`).
 *  - La carpeta del día, para la entrega del día completo (`dayFolder`).
 *
 * POR QUÉ NO UNA REJILLA RÍGIDA: la cadencia de emisión NO es uniforme ni
 * estable. Medido en producción el 2026-11-18:
 *
 *    01 Caracol Tv            96 archivos/día, paso ~15 min, desfase +13 s
 *    Caracol Radio Bogotá     96 archivos/día, paso ~15 min con saltos de
 *                             hasta 17 min (pausas de programación)
 *    Antioquia/BLU (regional) paso ~14 min IRREGULAR (13:59 / 14:01 / 14:00)
 *
 * Una rejilla de 15 min fija daría cobertura falsa en los tres casos. Por
 * eso la cobertura se calcula como el conjunto MÍNIMO de intervalos que
 * cubren el rango pedido: es la pregunta física ("¿hay material que cubra
 * cada instante?") y no necesita supuestos sobre la rejilla.
 *
 * ANCLA DEL MATERIAL: el NOMBRE del archivo, no su `path`. El nombre lleva
 * la fecha y la hora (`caracol_14112026_120013.mp4`) y funciona igual en los
 * tres layouts físicos (plano, agrupado por subcarpeta, agrupado por slot) y
 * en los dos formatos de `path` que conviven en `files` (el relativo al
 * `base_path` y el legacy relativo a la raíz Tcloud).
 *
 * DISPONIBILIDAD: se comprueba EN DISCO, nunca solo contra el catálogo. El
 * catálogo arrastra filas fantasma (el restore de agosto 2026 dejó filas de
 * carpetas que ya no existen) y el árbol rota a ~48 días.
 */
class PuntualResolver
{
    /**
     * Segundos de hueco que se toleran para declarar la cobertura completa.
     *
     * El grabador arranca el archivo siguiente unos segundos después de que
     * termina el anterior: el desfase de 13 s en TV es real, no un hueco.
     */
    public const TOLERANCIA_HUECO = 60;

    /** Un mtime más lejano que esto respecto del inicio se considera corrupto. */
    private const FACTOR_MTIME_SOSPECHOSO = 4;

    public function __construct(
        private readonly MountGuard $mountGuard,
        private readonly StorageSyncService $syncService,
    ) {
    }

    // ------------------------------------------------------------------
    // Localización del material
    // ------------------------------------------------------------------

    /**
     * Archivos de un medio en un día.
     *
     * @param bool $materialize ¿Se puede materializar el catálogo si falta?
     *
     * @return Collection<int,File>
     */
    public function filesForDay(Medio $medio, Carbon $date, bool $materialize = false): Collection
    {
        $files = $this->catalogFilesForDay($medio, $date);

        if ($files->isEmpty() && $materialize) {
            $this->materializeDay($medio, $date);

            $files = $this->catalogFilesForDay($medio, $date);
        }

        return $files;
    }

    /**
     * Archivos de un medio en un día, solo desde el catálogo.
     *
     * @return Collection<int,File>
     */
    private function catalogFilesForDay(Medio $medio, Carbon $date): Collection
    {
        $day = $date->format('dmY');
        $prefixes = $this->relativePrefixes($medio, $day);

        if ($prefixes === []) {
            return collect();
        }

        return File::query()
            ->where('storage_provider_id', $medio->storage_id)
            ->where('is_folder', false)
            ->where(function ($query) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('path', 'like', $prefix . '%');
                }
            })
            ->get()
            ->filter(fn (File $file) => $this->hasAnyPrefix((string) $file->path, $prefixes))
            ->values();
    }

    /**
     * Materializa en el catálogo el material de un día que existe en disco.
     *
     * POR QUÉ: el catálogo no está al día para todos los medios. Medido el
     * 2026-11-18, el storage 49 (RD1) tiene indexados los días hasta
     * septiembre para `Antioquia/BLU`, mientras el disco tiene los 48 días
     * hasta el 18/11. El disco es la verdad; el catálogo es un índice que
     * hay que ponerse al día.
     *
     * Se usa la vía canónica (`StorageSyncService::syncFolder`), nunca SQL
     * inline: Mis Archivos es el único escritor de `files`.
     *
     * El sync es de UN nivel por llamada, así que la cadena es: carpeta del
     * medio → carpeta-día → archivos del día.
     */
    private function materializeDay(Medio $medio, Carbon $date): void
    {
        $storage = $medio->storage;

        if ($storage === null) {
            return;
        }

        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            return;
        }

        // Los exports son `hard`: la guarda va ANTES de tocar el disco.
        if ($this->mountGuard->isNetworkMountDown($base)) {
            return;
        }

        $day = $date->format('dmY');
        $subpath = trim((string) $medio->subpath, '/');

        try {
            if ($subpath === '') {
                // El medio ES el storage: sus carpetas-día cuelgan de la raíz.
                $this->syncService->syncFolder($storage, null);
            } else {
                $folder = $this->findFolderRow($medio, $subpath);

                if ($folder === null) {
                    // La carpeta del canal todavía no está en el catálogo.
                    $this->syncService->syncFolder($storage, null);
                    $folder = $this->findFolderRow($medio, $subpath);
                }

                if ($folder === null) {
                    return;
                }

                $this->syncService->syncFolder($storage, (int) $folder->id);
            }

            $dayFolder = $this->findDayFolder($medio, $subpath === '' ? '' : $subpath . '/', $day);

            if ($dayFolder === null) {
                return;
            }

            $this->syncService->syncFolder($storage, (int) $dayFolder->id);
        } catch (\Throwable $e) {
            Log::warning('puntual.materialize_failed', [
                'medio_id' => $medio->id,
                'storage_id' => $medio->storage_id,
                'day' => $day,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fila de catálogo de la carpeta de un medio dentro de su storage.
     */
    private function findFolderRow(Medio $medio, string $relative): ?File
    {
        return File::query()
            ->where('storage_provider_id', $medio->storage_id)
            ->where('is_folder', true)
            ->get()
            ->first(fn (File $folder) => $this->pathIsFolder((string) $folder->path, $relative));
    }

    /**
     * Prefijos posibles del `path` para un medio y un día.
     *
     * Son DOS porque conviven dos formatos de `path` en `files`:
     *  - el vigente, relativo al `base_path` del storage (`14112026/…`);
     *  - el legacy, relativo a la raíz Tcloud (`Disco_B/…/14112026/…`).
     *
     * @return list<string> prefijos terminados en `/`
     */
    private function relativePrefixes(Medio $medio, string $day): array
    {
        $storage = $medio->storage;

        if ($storage === null) {
            return [];
        }

        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            return [];
        }

        $subpath = trim((string) $medio->subpath, '/');
        $relative = $subpath === '' ? $day : $subpath . '/' . $day;

        $prefixes = [$relative . '/'];

        $root = rtrim((string) config('filesystems.cloud_root'), '/');

        if ($root !== '' && str_starts_with($base . '/', $root . '/')) {
            $legacy = ltrim(substr($base, strlen($root)), '/');
            $prefixes[] = ($legacy === '' ? $relative : $legacy . '/' . $relative) . '/';
        }

        return $prefixes;
    }

    /**
     * @param list<string> $prefixes
     */
    private function hasAnyPrefix(string $path, array $prefixes): bool
    {
        $path = trim($path, '/');

        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '/');

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cadencia del medio ese día, en segundos.
     *
     * Es la MEDIANA de los deltas entre inicios de emisión consecutivos: la
     * moda se rompe con los huecos de programación y el promedio con los
     * solapamientos. Con al menos 3 archivos la mediana es estable.
     *
     * @param Collection<int,File> $files
     */
    public function deriveStep(Collection $files): ?int
    {
        $starts = $files
            ->map(fn (File $file) => $this->emissionOf($file))
            ->filter()
            ->map(fn (Carbon $moment) => $moment->getTimestamp())
            ->sort()
            ->values();

        if ($starts->count() < 3) {
            return null;
        }

        $deltas = [];

        for ($i = 1; $i < $starts->count(); $i++) {
            $delta = $starts[$i] - $starts[$i - 1];

            if ($delta > 0) {
                $deltas[] = $delta;
            }
        }

        if ($deltas === []) {
            return null;
        }

        sort($deltas);
        $median = $deltas[intdiv(count($deltas), 2)];

        return $median > 0 ? $median : null;
    }

    // ------------------------------------------------------------------
    // Cobertura
    // ------------------------------------------------------------------

    /**
     * Cobertura de un rango horario y conjunto mínimo de archivos que la cubren.
     *
     * @return array{
     *     estado:string,
     *     archivos:int,
     *     bytes:int,
     *     step:?int,
     *     completos:list<array{file_id:int,name:string,path:string,size:int,inicio:string,fin:string,fin_real:?string}>,
     *     huecos:list<array{desde:string,hasta:string,segundos:int}>,
     *     segundos_cubiertos:int,
     *     segundos_pedidos:int,
     *     cobertura_pct:float,
     *     mensajes:list<string>
     * }
     */
    public function coverage(Medio $medio, Carbon $date, string $desde, string $hasta, bool $materialize = true): array
    {
        $requested = $this->range($date, $desde, $hasta);

        if ($requested === null) {
            return $this->emptyCoverage('unavailable', ['El rango horario no es válido.']);
        }

        [$start, $end] = $requested;
        $files = $this->filesForDay($medio, $date, $materialize);

        if ($files->isEmpty()) {
            return $this->emptyCoverage('unavailable', [
                'No hay material de ese medio para el ' . $date->format('d/m/Y') . '.',
            ]);
        }

        $step = $this->deriveStep($files);
        $slots = $this->slots($files, $step);
        [$selected, $gaps] = $this->minimalCover($slots, $start, $end);

        $requestedSeconds = $end->getTimestamp() - $start->getTimestamp();
        $gapSeconds = array_sum(array_column($gaps, 'segundos'));
        $coveredSeconds = max(0, $requestedSeconds - $gapSeconds);
        $pct = $requestedSeconds > 0 ? round($coveredSeconds * 100 / $requestedSeconds, 1) : 0.0;

        $completos = array_map(function (array $slot) {
            return [
                'file_id' => (int) $slot['file']->id,
                'name' => (string) $slot['file']->name,
                'path' => (string) $slot['file']->path,
                'size' => (int) ($slot['file']->size ?? 0),
                'inicio' => $slot['inicio']->format('H:i:s'),
                'fin' => $slot['fin']->format('H:i:s'),
                'fin_real' => $slot['fin_real']?->format('H:i:s'),
            ];
        }, $selected);

        $messages = [];

        if ($gapSeconds > self::TOLERANCIA_HUECO) {
            foreach ($gaps as $gap) {
                $messages[] = sprintf(
                    'Sin material entre %s y %s (%d s).',
                    $gap['desde'],
                    $gap['hasta'],
                    $gap['segundos']
                );
            }
        }

        $estado = $gapSeconds <= self::TOLERANCIA_HUECO ? 'completa' : 'parcial';

        // La disponibilidad se comprueba EN DISCO: el catálogo puede tener
        // filas de material que ya no existe.
        $enDisco = 0;
        $bytes = 0;
        $entregables = [];

        foreach ($selected as $slot) {
            if (! $this->existsOnDisk($medio, $slot['file'])) {
                continue;
            }

            $enDisco++;
            $bytes += (int) ($slot['file']->size ?? 0);
            $entregables[] = $slot;
        }

        if ($enDisco === 0) {
            return $this->emptyCoverage('unavailable', [
                'El material de ese rango ya no está en disco.',
            ]);
        }

        if ($enDisco < count($selected)) {
            $messages[] = sprintf(
                '%d de %d archivos ya no están en disco.',
                count($selected) - $enDisco,
                count($selected)
            );
        }

        $completos = array_map(function (array $slot) {
            return [
                'file_id' => (int) $slot['file']->id,
                'name' => (string) $slot['file']->name,
                'path' => (string) $slot['file']->path,
                'size' => (int) ($slot['file']->size ?? 0),
                'inicio' => $slot['inicio']->format('H:i:s'),
                'fin' => $slot['fin']->format('H:i:s'),
                'fin_real' => $slot['fin_real']?->format('H:i:s'),
            ];
        }, $entregables);

        return [
            'estado' => $estado,
            'archivos' => count($entregables),
            'bytes' => $bytes,
            'step' => $step,
            'completos' => $completos,
            'huecos' => $gaps,
            'segundos_cubiertos' => $coveredSeconds,
            'segundos_pedidos' => $requestedSeconds,
            'cobertura_pct' => $pct,
            'mensajes' => $messages,
        ];
    }

    // ------------------------------------------------------------------
    // Entrega del día completo
    // ------------------------------------------------------------------

    /**
     * Carpeta del día de un medio.
     *
     * Si no está catalogada, se materializa por la vía canónica del módulo
     * (el sync), nunca con SQL inline.
     */
    public function dayFolder(Medio $medio, Carbon $date): ?File
    {
        $day = $date->format('dmY');
        $prefix = $this->relativePrefix($medio);

        $folder = $this->findDayFolder($medio, $prefix, $day);

        if ($folder !== null) {
            return $folder;
        }

        // Materializar: el sync de la carpeta padre deja la fila de la
        // carpeta-día.
        $storage = $medio->storage;

        if ($storage === null) {
            return null;
        }

        $parentId = null;

        if ($prefix !== '') {
            $parent = File::query()
                ->where('storage_provider_id', $medio->storage_id)
                ->where('is_folder', true)
                ->get()
                ->first(fn (File $candidate) => $this->pathIsFolder((string) $candidate->path, rtrim($prefix, '/')));

            if ($parent === null) {
                return null;
            }

            $parentId = (int) $parent->id;
        }

        try {
            $this->syncService->syncFolder($storage, $parentId);
        } catch (\Throwable $e) {
            Log::warning('puntual.day_folder_sync_failed', [
                'medio_id' => $medio->id,
                'storage_id' => $medio->storage_id,
                'day' => $day,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->findDayFolder($medio, $prefix, $day);
    }

    /**
     * Cuántos archivos de grabación tiene el día en DISCO.
     *
     * Se usa en la previsualización cuando el catálogo todavía no indexó el
     * día: así el operador ve una cifra real en vez de cero.
     */
    public function countDayFilesOnDisk(Medio $medio, Carbon $date): int
    {
        $base = rtrim((string) $medio->storage?->base_path, '/');

        if ($base === '') {
            return 0;
        }

        $path = $base . '/' . $this->relativePrefix($medio) . $date->format('dmY');

        if ($this->mountGuard->isNetworkMountDown($path) || ! is_dir($path)) {
            return 0;
        }

        $entries = @scandir($path);

        if ($entries === false) {
            return 0;
        }

        return count(array_filter($entries, fn (string $entry) => $entry !== '.' && $entry !== '..'
            && is_file($path . '/' . $entry)
            && preg_match('/_[0-9]{8}_[0-9]{6}\.[a-z0-9]+$/i', $entry) === 1));
    }

    /**
     * ¿Existe la carpeta del día en DISCO?
     *
     * Distingue "no hay material" de "el material existe pero el catálogo
     * va detrás". Es una lectura de disco, no escribe nada.
     */
    public function dayExistsOnDisk(Medio $medio, Carbon $date): bool
    {
        $storage = $medio->storage;

        if ($storage === null) {
            return false;
        }

        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            return false;
        }

        if ($this->mountGuard->isNetworkMountDown($base)) {
            return false;
        }

        $relative = trim((string) $medio->subpath, '/');
        $relative = $relative === '' ? $date->format('dmY') : $relative . '/' . $date->format('dmY');

        return is_dir($base . '/' . $relative);
    }

    /**
     * Días disponibles de un medio, del más reciente al más antiguo.
     *
     * El orden es por FECHA, no por texto: `DDMMYYYY` ordena mal
     * ('31082026' > '14112026' como cadena, pero el 31/08 es anterior al
     * 14/11).
     *
     * @return list<string> `DDMMYYYY`
     */
    public function availableDays(Medio $medio): array
    {
        $prefix = $this->relativePrefix($medio);

        $dias = File::query()
            ->where('storage_provider_id', $medio->storage_id)
            ->where('is_folder', true)
            ->get()
            ->filter(fn (File $folder) => (bool) preg_match('/^\d{8}$/', (string) $folder->name))
            ->filter(fn (File $folder) => $this->pathIsFolder((string) $folder->path, $prefix . $folder->name))
            ->pluck('name')
            ->unique()
            ->sortByDesc(fn (string $day) => $this->sortableDay($day))
            ->values()
            ->all();

        // La carpeta-día puede estar catalogada sin sus archivos: el índice
        // va un nivel por detrás del sync. Un día así NO tiene material para
        // pedir, y ofrecerlo llevaría al operador a un "sin material"
        // inexplicable. Se contrasta contra el disco, que es la verdad.
        $verificados = array_values(array_filter($dias, function (string $day) use ($medio) {
            $base = rtrim((string) $medio->storage?->base_path, '/');

            if ($base === '') {
                return false;
            }

            $physical = $base . '/' . $this->relativePrefix($medio) . $day;

            if ($this->mountGuard->isNetworkMountDown($physical)) {
                return false;
            }

            return is_dir($physical) && $this->tieneArchivos($physical);
        }));

        if ($verificados !== []) {
            return $verificados;
        }

        // El catálogo no tiene NINGÚN día vigente (va muy por detrás del
        // disco: medido el 2026-11-18, CANDELA tenía días catalogados hasta
        // mayo y grabaciones hasta octubre). En vez de devolver una lista
        // vacía que deja al operador sin poder pedir nada, se lee el DISCO:
        // es barato (un nivel de carpetas) y es la única fuente que dice la
        // verdad sobre qué días existen.
        return $this->diasDesdeDisco($medio);
    }

    /**
     * Días con material leídos DIRECTAMENTE del disco.
     *
     * Solo se usa como respaldo cuando el catálogo está tan atrasado que no
     * tiene ningún día vigente. Devuelve `DDMMYYYY`, del más reciente al más
     * antiguo.
     *
     * @return list<string>
     */
    private function diasDesdeDisco(Medio $medio): array
    {
        $base = rtrim((string) $medio->storage?->base_path, '/');

        if ($base === '') {
            return [];
        }

        $carpeta = $base . '/' . $this->relativePrefix($medio);
        $carpeta = rtrim($carpeta, '/');

        if (! is_dir($carpeta) || $this->mountGuard->isNetworkMountDown($carpeta)) {
            return [];
        }

        $dias = [];

        $entries = @scandir($carpeta);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $hijo) {
            if ($hijo === '.' || $hijo === '..' || ! preg_match('/^\d{8}$/', $hijo)) {
                continue;
            }

            if (is_dir($carpeta . '/' . $hijo) && $this->tieneArchivos($carpeta . '/' . $hijo)) {
                $dias[] = $hijo;
            }
        }

        usort($dias, fn (string $a, string $b) => $this->sortableDay($b) <=> $this->sortableDay($a));

        return $dias;
    }

    /**
     * ¿La carpeta tiene al menos un archivo de grabación dentro?
     *
     * Se busca la forma `<nombre>_DDMMYYYY_HHMMSS.<ext>`: el `scandir` de
     * una carpeta vacía o con basura no debe contar como material.
     */
    private function tieneArchivos(string $path): bool
    {
        $entries = @scandir($path);

        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_file($path . '/' . $entry) && preg_match('/_[0-9]{8}_[0-9]{6}\.[a-z0-9]+$/i', $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `DDMMYYYY` → `YYYYMMDD`, para ordenar por fecha.
     */
    private function sortableDay(string $day): string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{4})$/', $day, $m)) {
            return $day;
        }

        return $m[3] . $m[2] . $m[1];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Prefijo relativo del medio dentro del storage: '' o `Antioquia/BLU/`.
     */
    private function relativePrefix(Medio $medio): string
    {
        $subpath = trim((string) $medio->subpath, '/');

        return $subpath === '' ? '' : $subpath . '/';
    }

    /**
     * ¿La ruta ES la carpeta dada?
     *
     * El `path` de `files` es relativo al `base_path` del storage en el
     * formato vigente, y relativo a la raíz Tcloud en el legacy. Por eso la
     * comparación es por FINAL de ruta, en un límite de segmento.
     */
    private function pathIsFolder(string $path, string $folder): bool
    {
        $path = trim($path, '/');
        $folder = trim($folder, '/');

        if ($folder === '') {
            return true;
        }

        return $path === $folder || str_ends_with($path, '/' . $folder);
    }

    /**
     * ¿La ruta está dentro de la carpeta dada (o es la propia carpeta)?
     */
    private function pathIsInside(string $path, string $folder): bool
    {
        $path = trim($path, '/');
        $folder = trim($folder, '/');

        if ($folder === '') {
            return true;
        }

        return $path === $folder
            || str_starts_with($path, $folder . '/')
            || str_contains($path, '/' . $folder . '/');
    }

    /**
     * Instante de emisión de un archivo: el que lleva su nombre.
     *
     * Es el mismo criterio que usa el transcriptor para `recorded_at`: el
     * nombre es la hora en que se emitió el programa.
     */
    private function emissionOf(File $file): ?Carbon
    {
        $moment = RecordedAt::fromFilename((string) $file->name);

        if ($moment === null) {
            return null;
        }

        return Carbon::create(
            $moment->year,
            $moment->month,
            $moment->day,
            $moment->hour,
            $moment->minute,
            $moment->second
        );
    }

    /**
     * Fin real de escritura de un archivo.
     *
     * El `file_modified_at` es cuando el grabador terminó de escribir. Si
     * queda demasiado lejos del inicio, se desconfía (deriva de zona horaria
     * o fila corrupta) y se devuelve `null`.
     */
    private function realEnd(File $file, ?int $step): ?Carbon
    {
        $modified = $file->file_modified_at;

        if (! $modified instanceof \DateTimeInterface) {
            return null;
        }

        $end = Carbon::instance($modified);
        $start = $this->emissionOf($file);

        if ($start === null) {
            return null;
        }

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            return null;
        }

        if ($step !== null && ($end->getTimestamp() - $start->getTimestamp()) > $step * self::FACTOR_MTIME_SOSPECHOSO) {
            return null;
        }

        return $end;
    }

    /**
     * Convierte los archivos en slots con inicio, fin nominal y fin real.
     *
     * @param Collection<int,File> $files
     * @return list<array{file:File,inicio:Carbon,fin:Carbon,fin_real:?Carbon}>
     */
    private function slots(Collection $files, ?int $step): array
    {
        $slots = [];

        foreach ($files as $file) {
            $start = $this->emissionOf($file);

            if ($start === null) {
                continue;
            }

            $realEnd = $this->realEnd($file, $step);

            $end = $realEnd ?? $start->copy()->addSeconds($step ?? 900);

            $slots[] = [
                'file' => $file,
                'inicio' => $start,
                'fin' => $end,
                'fin_real' => $realEnd,
            ];
        }

        usort($slots, fn (array $a, array $b) => $a['inicio']->getTimestamp() <=> $b['inicio']->getTimestamp());

        return $slots;
    }

    /**
     * Conjunto MÍNIMO de archivos que cubren el rango, y los huecos.
     *
     * Algoritmo voraz: se avanza el frente de cobertura con el archivo que
     * llega más lejos. Es la solución estándar de "cobertura mínima de
     * intervalos" y no necesita suponer nada sobre la rejilla.
     *
     * La TOLERANCIA se aplica en los bordes: el grabador arranca el archivo
     * siguiente unos segundos después de que termina el anterior, así que el
     * primer y el último intervalo del rango están siempre un poco
     * descubiertos. Un borde con menos de `TOLERANCIA_HUECO` segundos de
     * material faltante NO es un hueco: es el desfase del grabador.
     *
     * @param list<array{file:File,inicio:Carbon,fin:Carbon,fin_real:?Carbon}> $slots
     * @return array{0:list<array{file:File,inicio:Carbon,fin:Carbon,fin_real:?Carbon}>,1:list<array{desde:string,hasta:string,segundos:int}>}
     */
    private function minimalCover(array $slots, Carbon $start, Carbon $end): array
    {
        $selected = [];
        $gaps = [];

        $frontier = $start->getTimestamp();
        $endTs = $end->getTimestamp();
        $total = count($slots);
        $index = 0;

        while ($frontier < $endTs - self::TOLERANCIA_HUECO) {
            while ($index < $total && $slots[$index]['fin']->getTimestamp() <= $frontier) {
                $index++;
            }

            $bestIndex = null;
            $bestEnd = $frontier;
            $cursor = $index;

            while ($cursor < $total && $slots[$cursor]['inicio']->getTimestamp() <= $frontier) {
                if ($slots[$cursor]['fin']->getTimestamp() > $bestEnd) {
                    $bestEnd = $slots[$cursor]['fin']->getTimestamp();
                    $bestIndex = $cursor;
                }

                $cursor++;
            }

            if ($bestIndex === null) {
                // Hueco real: el siguiente archivo empieza después del frente.
                $nextStart = $index < $total ? $slots[$index]['inicio']->getTimestamp() : $endTs;
                $gapEnd = min($nextStart, $endTs);

                if ($gapEnd > $frontier) {
                    $gaps[] = [
                        'desde' => Carbon::createFromTimestamp($frontier)->format('H:i:s'),
                        'hasta' => Carbon::createFromTimestamp($gapEnd)->format('H:i:s'),
                        'segundos' => $gapEnd - $frontier,
                    ];
                }

                $frontier = $gapEnd;

                continue;
            }

            // El archivo sólo cubre un borde del rango: es el desfase del
            // grabador, no material que falte. Se salta sin seleccionarlo.
            if ($frontier < $start->getTimestamp() + self::TOLERANCIA_HUECO
                && ($bestEnd - $frontier) <= self::TOLERANCIA_HUECO) {
                $frontier = $start->getTimestamp() + self::TOLERANCIA_HUECO;

                continue;
            }

            $selected[] = $slots[$bestIndex];
            $frontier = $bestEnd;
        }

        return [$selected, $gaps];
    }

    /**
     * ¿El archivo sigue en disco?
     *
     * El catálogo arrastra filas fantasma; la disponibilidad se comprueba
     * contra el sistema de archivos, con la guarda de montaje antes (los
     * exports son `hard` y un `stat()` sobre un NFS caído deja el proceso
     * esperando).
     */
    private function existsOnDisk(Medio $medio, File $file): bool
    {
        $storage = $medio->storage;

        if ($storage === null) {
            return false;
        }

        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            return false;
        }

        $relative = ltrim((string) $file->path, '/');

        if ($relative === '') {
            return false;
        }

        // Dos candidatos: el `path` vigente es relativo al `base_path` del
        // storage; el legacy es relativo a la raíz Tcloud.
        $candidates = [$base . '/' . $relative];

        $root = rtrim((string) config('filesystems.cloud_root'), '/');

        if ($root !== '') {
            $candidates[] = $root . '/' . $relative;
        }

        // La guarda de montaje va ANTES de cualquier `stat()`: los exports
        // son `hard` y un NFS caído deja el proceso esperando.
        foreach ($candidates as $candidate) {
            if ($this->mountGuard->isNetworkMountDown($candidate)) {
                return false;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:Carbon,1:Carbon}|null
     */
    private function range(Carbon $date, string $desde, string $hasta): ?array
    {
        try {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $desde);
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $hasta);
        } catch (\Throwable) {
            return null;
        }

        if (! $start || ! $end) {
            return null;
        }

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            return null;
        }

        return [$start, $end];
    }

    private function findDayFolder(Medio $medio, string $prefix, string $day): ?File
    {
        return File::query()
            ->where('storage_provider_id', $medio->storage_id)
            ->where('is_folder', true)
            ->where('name', $day)
            ->get()
            ->first(fn (File $folder) => $this->pathIsFolder((string) $folder->path, $prefix . $day));
    }

    /**
     * @param list<string> $messages
     * @return array<string,mixed>
     */
    private function emptyCoverage(string $estado, array $messages): array
    {
        return [
            'estado' => $estado,
            'archivos' => 0,
            'bytes' => 0,
            'step' => null,
            'completos' => [],
            'huecos' => [],
            'segundos_cubiertos' => 0,
            'segundos_pedidos' => 0,
            'cobertura_pct' => 0.0,
            'mensajes' => $messages,
        ];
    }
}
