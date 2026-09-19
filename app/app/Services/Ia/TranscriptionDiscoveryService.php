<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use App\Models\Transcription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Descubrimiento de candidatos a transcribir: SOLO LECTURA sobre `files`.
 *
 * Nace del change transcriptor-physical-file-identity (design.md D4/D5) para
 * reemplazar el escaneo de disco de DiskScannerService. La razon: Mis Archivos
 * ya puebla el inventario completo cada 15 minutos.
 *
 *   storage:sync --all (cron 15 min)  -> escanea TODOS los storages locales
 *                                        y escribe `files` (sin filtrar tx)
 *   TranscriptionTickCommand (2 min)  -> ANTES: escaneaba el mismo disco
 *                                        AHORA: consulta `files`
 *
 * Evidencia de la redundancia (2026-09-16): storage 47 tenia 3.452 filas
 * `files` de hoy para 2.144 archivos en disco — el inventario ya estaba.
 *
 * FRONTERA (capability module-independence-boundary):
 *  - NO usa scandir, stat, filemtime ni filesize.
 *  - NO escribe `files` (tabla soberana de Mis Archivos).
 *  - NO crea carpetas.
 *  - Si falta inventario, INVOCA a Mis Archivos (TranscriptionInventoryInvoker)
 *    en background, no lo imita.
 *
 * ELEGIBILIDAD POR CADENA (design.md D9): un archivo es target si ALGUN storage
 * de su cadena de cobertura tiene transcription_enabled. Filtrar por el storage
 * DE LA FILA perdia 9.074 archivos de hoy (28.530 por cadena vs 19.456 por
 * fila, casi todos de `00 Discos`, tx=false, bajo ancestros habilitados).
 *
 * IDENTIDAD FISICA (D3): la deduplicacion es por `source_absolute_path` con
 * indice UNIQUE, no por fila de `files`.
 */
class TranscriptionDiscoveryService
{
    /** Extensiones consideradas candidatas (mismas que el scanner historico). */
    private const MEDIA_EXTENSIONS = ['mp4', 'mkv', 'm4a', 'opus', 'flac', 'wav', 'mp3', 'aac'];

    public function __construct(
        private TranscriptorSettings $settings,
        private PhysicalFileIdentity $identity,
        private StorageHierarchyService $hierarchy,
        private TranscriptionInventoryInvoker $inventory,
    ) {}

    /**
     * Descubre candidatos de un storage e inserta las transcripciones faltantes.
     *
     * @param  array|null  $scope  {mode: 'today'|'range'|'all', folders?: string[]}
     * @return array{candidates:int, files_created:int, transcriptions_created:int, scanned:int, skipped_existing:int, inventory_invoked:bool}
     */
    public function discover(
        StorageProvider $storage,
        ?array $scope = null,
        ?int $batchOverride = null,
        bool $generateAlerts = true,
    ): array {
        $stats = [
            'candidates' => 0,
            'files_created' => 0,
            'transcriptions_created' => 0,
            'scanned' => 0,
            'skipped_existing' => 0,
            'inventory_invoked' => false,
        ];

        $batch = $batchOverride ?? $this->settings->int('scan_batch');
        $minSize = $this->settings->int('min_file_size_bytes');
        $minAge = $this->settings->int('scan_min_age_seconds');
        $skipLatest = $this->settings->bool('scan_skip_latest_per_storage');

        [$from, $to] = $this->dateBounds($scope);

        $rows = $this->queryCandidates($storage, $from, $to, $minSize, $minAge, $skipLatest, $batch);

        // Inventario ausente: en vez de escanear el disco, delegar en Mis
        // Archivos (design.md D5, decision Q2: encolado, no sincrono).
        if ($rows === [] && $this->inventory->isEmptyForToday($storage)) {
            $stats['inventory_invoked'] = $this->inventory->requestSync($storage);

            return $stats;
        }

        $stats['scanned'] = count($rows);
        $stats['candidates'] = count($rows);

        // Aplicar el cap DESPUES de excluir lo ya registrado (misma razon que
        // el scanner historico: si se corta antes, los slots se gastan en
        // archivos conocidos porque el orden es mtime DESC).
        if (count($rows) > $batch) {
            $rows = array_slice($rows, 0, $batch);
        }

        $language = $this->settings->str('language');

        foreach ($rows as $row) {
            $absolutePath = rtrim((string) $row->base_path, '/') . '/' . ltrim((string) $row->path, '/');

            // Ya tiene transcripcion (por ruta fisica, cualquier estado)? El
            // `leftJoin` ya lo filtra, pero se revalida contra el indice para
            // no competir si otra corrida la creo en el intervalo.
            if ($this->identity->hasTranscription($absolutePath)) {
                $stats['skipped_existing']++;
                continue;
            }

            $canonical = $this->identity->resolve($absolutePath);

            try {
                $tx = $this->identity->firstOrCreateTranscription($absolutePath, $canonical, [
                    'original_name' => $row->name,
                    'state' => Transcription::STATE_PENDING,
                    'generate_alerts' => $generateAlerts,
                    'language' => $language,
                    'started_at' => now(),
                    'discovered_at' => now(),
                ]);

                if ($tx->wasRecentlyCreated) {
                    $stats['transcriptions_created']++;
                } else {
                    $stats['skipped_existing']++;
                }
            } catch (\Throwable $e) {
                Log::error('TranscriptionDiscovery: error creando transcripcion', [
                    'storage_id' => $storage->id,
                    'absolute_path' => $absolutePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Consulta de solo lectura: filas `files` elegibles sin transcripcion.
     *
     * `files_created` siempre sera 0: el descubrimiento NO escribe `files`.
     * Se mantiene en el shape por compatibilidad con los callers del scanner.
     *
     * @return list<object>
     */
    private function queryCandidates(
        StorageProvider $storage,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        int $minSize,
        int $minAge,
        bool $skipLatest,
        int $batch,
    ): array {
        $cutoff = BogotaTime::now()->subSeconds($minAge);

        $q = DB::table('files')
            ->join('storage_providers as sp', 'sp.id', '=', 'files.storage_provider_id')
            ->where('files.is_folder', false)
            ->where('files.is_trashed', false)
            ->whereNull('files.deleted_at')
            ->where('files.file_modified_at', '<', $cutoff)
            ->select([
                'files.id as file_id',
                'files.path',
                'files.name',
                'files.size',
                'files.file_modified_at',
                'sp.id as storage_id',
                'sp.base_path',
            ]);

        if ($minSize > 0) {
            $q->where('files.size', '>=', $minSize);
        }

        // Alcance por fecha: `file_modified_at` (columna), no carpetas en disco.
        if ($from !== null) {
            $q->where('files.file_modified_at', '>=', $from);
        }
        if ($to !== null) {
            $q->where('files.file_modified_at', '<', $to);
        }

        // Extension multimedia: `path` ya trae el nombre completo.
        $q->where(function ($inner) {
            foreach (self::MEDIA_EXTENSIONS as $ext) {
                $inner->orWhere('files.path', 'ilike', '%.' . $ext);
            }
        });

        // ELEGIBILIDAD POR CADENA (D9): algun storage que cubre la ruta
        // transcribe. La cobertura se expresa como prefijo de base_path sobre
        // la ruta absoluta de la fila.
        $q->whereExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('storage_providers as a')
                ->where('a.transcription_enabled', true)
                ->whereNotNull('a.base_path')
                ->whereRaw("rtrim(a.base_path, '/') <> ''")
                ->whereRaw(
                    "(rtrim(sp.base_path, '/') || '/' || files.path) LIKE (rtrim(a.base_path, '/') || '/%')"
                );
        });

        // Sin transcripcion por RUTA FISICA. El leftJoin cubre el caso normal;
        // el filtro por file_id cubre filas viejas sin source_absolute_path.
        $q->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('transcriptions as t')
                ->whereRaw('t.source_absolute_path = rtrim(sp.base_path, \'/\') || \'/\' || files.path');
        });

        $q->orderByDesc('files.file_modified_at')
            ->limit(max($batch * 4, 400));

        $rows = $q->get()->all();

        // El storage actual solo aporta sus filas; el resto ya las aporta su
        // dueño efectivo. Esto evita que un ancestro reclame lo del hijo.
        $rows = array_values(array_filter($rows, function ($row) use ($storage) {
            $abs = rtrim((string) $row->base_path, '/') . '/' . ltrim((string) $row->path, '/');
            $owner = $this->hierarchy->ownerOf($abs);

            return $owner !== null && (int) $owner->id === (int) $storage->id;
        }));

        if ($skipLatest && $rows !== []) {
            // Descartar el mas reciente por storage (el grabador lo sigue
            // escribiendo). Ya viene ordenado DESC, asi que el primero es el
            // mas nuevo de cada storage.
            $seen = [];
            $rows = array_values(array_filter($rows, function ($row) use (&$seen) {
                $sid = (int) $row->storage_id;
                if (isset($seen[$sid])) {
                    return true;
                }
                $seen[$sid] = true;

                return false;
            }));
        }

        return $rows;
    }

    /**
     * Convierte el `scope` (compatibilidad con DiskScannerService) a limites de
     * fecha sobre `files.file_modified_at`.
     *
     * scope shape: {mode: 'today'|'range'|'all', folders?: string[]}
     *  - today -> [inicio de hoy Bogota, null)
     *  - range -> [inicio del dia menor, inicio del dia siguiente al mayor)
     *  - all   -> [null, null)
     *
     * @return array{0:?CarbonImmutable,1:?CarbonImmutable}
     */
    public function dateBounds(?array $scope): array
    {
        $mode = (string) ($scope['mode'] ?? 'today');

        if ($mode === 'all') {
            return [null, null];
        }

        if ($mode === 'range') {
            $folders = array_values(array_filter(
                (array) ($scope['folders'] ?? []),
                static fn ($f) => is_string($f) && preg_match('/^\d{8}$/', $f)
            ));

            if ($folders === []) {
                return [BogotaTime::todayStart(), null];
            }

            sort($folders);
            $from = $this->dmYToDate($folders[0]);
            $last = $this->dmYToDate(end($folders));

            if ($from === null || $last === null) {
                return [BogotaTime::todayStart(), null];
            }

            return [$from, $last->addDay()];
        }

        return [BogotaTime::todayStart(), null];
    }

    private function dmYToDate(string $dmY): ?CarbonImmutable
    {
        $m = [];
        if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', $dmY, $m)) {
            return null;
        }

        $iso = $m[3] . '-' . $m[2] . '-' . $m[1];
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $iso, new \DateTimeZone(BogotaTime::TIMEZONE));

        if (!$d || $d->format('Y-m-d') !== $iso) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $iso, BogotaTime::TIMEZONE)->startOfDay();
    }
}
