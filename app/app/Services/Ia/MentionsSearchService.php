<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Seam única de acceso a menciones (mis-avisos-menciones).
 *
 * Encapsula la regla de intersección que gobierna TODO lo que el cliente
 * ve o recibe: feed en vivo, histórico, exportaciones, transcripciones y
 * capabilities por fila. Un solo lugar que respeta:
 *
 *   1. transcription_access (concesión admin por pivote user_storages)
 *   2. alcance keyword→store (user_keyword_storage, "sin filas = todos")
 *
 * Cualquier consulta de coincidencias o segmentos para un cliente DEBE
 * pasar por aquí — jamás filtrar por parámetros del request.
 */
class MentionsSearchService
{
    /** Mapa de niveles de permiso de user_storages (idéntico a FileController). */
    private const PERMISSION_LEVELS = ['read' => 1, 'write' => 2, 'upload' => 2, 'full' => 3];

    /**
     * Whitelist del parámetro `date_field` (cambio 2026-09-10-mis-avisos-program-date-filter).
     *   - 'program': filtra por `t.recorded_at` (fecha del programa — default).
     *   - 'detected': filtra por `h.matched_at` (fecha de detección, comportamiento legacy).
     */
    private const DATE_FIELDS = ['program', 'detected'];
    private const DEFAULT_DATE_FIELD = 'program';

    /**
     * IDs de storages con acceso del usuario.
     *
     * SIN cache: si el admin revoca transcription_access, el efecto debe ser
     * inmediato incluso dentro del mismo proceso (queue workers long-running
     * reutilizan memoria estática).
     */
    public function accessibleStorageIds(User $user): array
    {
        return DB::table('user_storages')
            ->where('user_id', $user->id)
            ->where('transcription_access', true)
            ->pluck('storage_provider_id')
            ->all();
    }

    /**
     * Query builder de segment_keyword_hits visibles para el usuario,
     * ya filtrado por la intersección completa de acceso y enriquecido con
     * los datos para capabilities por fila (permiso de archivo, tipo de
     * storage). Úsalo como base y añade tus filtros.
     */
    public function visibleHitsQuery(User $user): Builder
    {
        $storageIds = $this->accessibleStorageIds($user);

        // Base: hits cuya transcripción pertenece a un storage con acceso.
        $q = DB::table('segment_keyword_hits as h')
            ->join('keywords as k', 'k.id', '=', 'h.keyword_id')
            ->join('transcription_segments as seg', 'seg.id', '=', 'h.segment_id')
            ->join('transcriptions as t', 't.id', '=', 'h.transcription_id')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
            // Permiso del cliente sobre el archivo del hit (capabilities):
            // una sola fila por (user, storage) en el pivote.
            ->leftJoin('user_storages as us', function ($j) use ($user) {
                $j->on('us.storage_provider_id', '=', 'f.storage_provider_id')
                    ->where('us.user_id', $user->id);
            })
            ->where(function ($query) use ($storageIds) {
                if (!empty($storageIds)) {
                    $query->whereIn('f.storage_provider_id', $storageIds);
                } else {
                    $query->whereRaw('1 = 0'); // sin acceso: nada visible
                }
            })
            ->when(!empty($storageIds), function ($query) use ($user, $storageIds) {
                // Alcance keyword→store: la keyword del hit debe estar
                // registrada por este usuario Y (sin asignación O asignada
                // a un storage visible).
                $query->whereExists(function ($sub) use ($user, $storageIds) {
                    $sub->selectRaw(1)
                        ->from('user_keyword as uk')
                        ->whereColumn('uk.keyword_id', 'h.keyword_id')
                        ->where('uk.user_id', $user->id)
                        ->leftJoin('user_keyword_storage as uks', function ($j) {
                            $j->on('uks.user_id', '=', 'uk.user_id')
                                ->on('uks.keyword_id', '=', 'uk.keyword_id');
                        })
                        ->where(function ($scope) use ($storageIds) {
                            $scope->whereNull('uks.user_id')
                                ->orWhereIn('uks.storage_provider_id', $storageIds);
                        });
                });
            });

        return $q;
    }

    /**
     * Coincidencias del DÍA ACTUAL para el feed en vivo, con filtros
     * (q, storage_ids, keyword_id) y paginación server-side.
     *
     * `$filters['date_field']`:
     *   'program' (default) — filtra por `transcriptions.recorded_at`.
     *   'detected'           — filtra por `segment_keyword_hits.matched_at` (legacy).
     */
    public function todayHits(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $dateField = $this->resolveDateField($filters['date_field'] ?? null);

        $q = $this->visibleHitsQuery($user);
        // Filtro del día en forma sargable: usa el MISMO literal de fecha que
        // binda whereDate(..., today()) pero sin el cast ::date, que bloquea
        // transcriptions_recorded_at_index y hacia seq-scan de hits en la
        // primera carga y en cada poll. Medición 2026-09-12 (user 1, pivote real):
        // count 111ms -> 26ms, página 93ms -> 29ms.
        $from = today()->toDateString() . ' 00:00:00';
        $to = today()->addDay()->toDateString() . ' 00:00:00';
        if ($dateField === 'program') {
            $q->where('t.recorded_at', '>=', $from)->where('t.recorded_at', '<', $to);
        } else {
            $q->where('h.matched_at', '>=', $from)->where('h.matched_at', '<', $to);
        }

        $this->applyHitFilters($q, $user, $filters);

        // Paginación AGRUPADA: una página = $perPage grupos (archivo+keyword),
        // cada uno con TODAS sus menciones. Antes la página era de hits planos
        // y el navegador agrupaba después: 25 hits con keywords de alta
        // frecuencia colapsaban a 4-5 filas visibles (bug reportado 2026-09-12).
        return $this->groupedHitsPage(
            $q,
            $user,
            $perPage,
            $dateField === 'program' ? 't.recorded_at' : 'h.matched_at'
        );
    }

    /**
     * Paginación AGRUPADA por (transcription_id, keyword_id): una página de
     * $perPage GRUPOS (archivo+keyword), cada uno con TODAS sus menciones.
     *
     * Dos queries acotadas (sin agregación masiva):
     *  1. Identidades de grupo con MAX(fecha) + SUM(occurrences) para
     *     orden/paginación (una fila por grupo).
     *  2. TODOS los hits de los pares (t,k) de la página en una sola query,
     *     re-hidratados con hitRow() para capabilities por fila.
     *
     * $sort: ['column' => ..., 'direction' => 'asc|desc'] con whitelist de
     * columnas agregadas; el resto ordena por group_date DESC (lo más
     * reciente del grupo primero).
     */
    private function groupedHitsPage(Builder $q, User $user, int $perPage, string $dateColumn, ?array $sort = null): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);

        // Paso 1: identidades de grupo + agregados para orden y paginación.
        $agg = (clone $q)
            ->selectRaw("h.transcription_id, h.keyword_id, MAX({$dateColumn}) AS group_date, COUNT(*) AS hits_count, COALESCE(SUM(h.occurrences), 0) AS occ_total")
            ->groupBy('h.transcription_id', 'h.keyword_id');

        // Sort del cliente sobre agregados (whitelist); desempate determinista.
        $sortCol = is_array($sort) ? ($sort['column'] ?? null) : null;
        $sortDir = (is_array($sort) ? ($sort['direction'] ?? 'desc') : 'desc') === 'asc' ? 'asc' : 'desc';
        $sortMap = [
            'recorded_at' => 'group_date',
            'matched_at'  => 'group_date',
            'occurrences' => 'occ_total',
            'hits'        => 'hits_count',
        ];
        if ($sortCol && isset($sortMap[$sortCol])) {
            $agg->orderBy($sortMap[$sortCol], $sortDir);
        }
        $agg->orderByDesc('group_date')->orderByDesc('h.transcription_id')->orderByDesc('h.keyword_id');

        // Total real de GRUPOS + página: la agregación completa se calcula UNA
        // vez (12k grupos típicos = filas livianas de ints) y la página se
        // corta en PHP. Evita una segunda pasada COUNT(DISTINCT) sobre la
        // misma base pesada de joins (medido: ~430ms extra por request).
        $allGroups = $agg->get()->all();
        $total = count($allGroups);

        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();
        $offset = ($page - 1) * $perPage;
        $groupRows = array_slice($allGroups, $offset, $perPage);
        if (empty($groupRows)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], $total, $perPage, $page, [
                'path' => '/mis-avisos',
            ]);
        }

        // Paso 2: TODOS los hits de los grupos de esta página, en UNA query.
        $tIds = array_map(fn ($g) => (int) $g->transcription_id, $groupRows);
        $kIds = array_values(array_unique(array_map(fn ($g) => (int) $g->keyword_id, $groupRows)));
        $pairsKey = fn ($t, $k) => ((int) $t) . ':' . ((int) $k);

        $pagePairs = [];
        foreach ($groupRows as $g) {
            $pagePairs[$pairsKey($g->transcription_id, $g->keyword_id)] = true;
        }

        $flat = (clone $q)
            ->whereIn('h.transcription_id', $tIds)
            ->whereIn('h.keyword_id', $kIds)
            ->select($this->hitSelect())
            // Intra-grupo: menciones por fecha DESC (la primera es la más
            // reciente, compatible con los campos first_* de la UI).
            ->orderByDesc($dateColumn)
            ->orderByDesc('h.id')
            ->get()
            ->all();

        $hydrated = [];
        foreach ($flat as $r) {
            $key = $pairsKey($r->transcription_id, $r->keyword_id);
            // Filtrar pares cruzados: la query por (t IN, k IN) puede traer
            // hits de un par (t,k) que no está en esta página.
            if (!isset($pagePairs[$key])) {
                continue;
            }
            $hydrated[$key][] = $this->hitRow($r, $user, []);
        }

        // Armar grupos en el orden EXACTO del paso 1.
        $groups = [];
        foreach ($groupRows as $g) {
            $key = $pairsKey($g->transcription_id, $g->keyword_id);
            $rows = $hydrated[$key] ?? [];
            if (empty($rows)) {
                continue;
            }
            $first = $rows[0];
            $groups[] = [
                'key' => 'g:' . $key,
                'file_id' => $first['file_id'],
                'filename' => $first['filename'],
                'file_url' => $first['file_url'],
                'storage' => $first['storage'],
                'storage_id' => $first['storage_id'],
                'parent_id' => $first['parent_id'],
                'transcription_id' => (int) $g->transcription_id,
                'keyword' => $first['keyword'],
                'keyword_id' => (int) $g->keyword_id,
                'occurrences_in_media' => (int) $g->occ_total,
                'hits_count' => (int) $g->hits_count,
                'can_view_file' => $first['can_view_file'],
                'can_clip' => $first['can_clip'],
                'first_id' => $first['id'],
                'first_matched_at' => $first['matched_at'],
                'first_recorded_at' => $first['recorded_at'],
                'first_minute_label' => $first['minute_label'],
                'first_start_seconds' => $first['start_seconds'],
                'first_segment_id' => $first['segment_id'],
                'first_snippet' => $first['snippet'],
                'first_media_kind' => $first['media_kind'],
                'hits' => $rows,
            ];
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($groups, $total, $perPage, $page, [
            'path' => '/mis-avisos',
        ]);
    }

    /**
     * Búsqueda histórica (≤60 días) con filtros. Respeta la misma base de
     * acceso. Aplica mínimo de caracteres y rango máximo.
     *
     * `$filters['date_field']`:
     *   'program' (default) — filtra por `transcriptions.recorded_at`.
     *   'detected'           — filtra por `segment_keyword_hits.matched_at` (legacy).
     */
    public function searchHistory(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $maxDays = (int) config('avisos.exports.history_days', 60);
        $dateField = $this->resolveDateField($filters['date_field'] ?? null);

        $q = $this->visibleHitsQuery($user);

        // Rango de fechas acotado a la retención del negocio.
        $from = isset($filters['from']) && $filters['from'] !== ''
            ? \Carbon\Carbon::parse($filters['from'])->startOfDay() : now()->subDays($maxDays)->startOfDay();
        $to = isset($filters['to']) && $filters['to'] !== ''
            ? \Carbon\Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        if ($from->diffInDays($to, true) > $maxDays) {
            $from = $to->copy()->subDays($maxDays)->startOfDay();
        }

        // Selección del campo de fecha según `date_field`.
        $dateColumn = $dateField === 'program' ? 't.recorded_at' : 'h.matched_at';
        $q->whereBetween($dateColumn, [$from, $to]);

        $this->applyHitFilters($q, $user, $filters);

        // Paginación AGRUPADA (misma semántica que todayHits): una página =
        // $perPage grupos (archivo+keyword) con todas sus menciones.
        return $this->groupedHitsPage($q, $user, max(1, $perPage), $dateColumn);
    }

    /**
     * Resuelve el date_field contra la whitelist; default a 'program'.
     * Centralizado para que cualquier consumidor del service tenga el mismo
     * contrato (controller, jobs, callers de consola).
     */
    public function resolveDateField(?string $input): string
    {
        if ($input !== null && in_array($input, self::DATE_FIELDS, true)) {
            return $input;
        }
        return self::DEFAULT_DATE_FIELD;
    }

    /**
     * Metadatos de una transcripción visible para el cliente + capabilities.
     * Retorna null si no existe, no está 'done', o el storage no tiene
     * transcription_access para el usuario (404 en el controlador: no
     * revela existencia).
     */
    public function visibleTranscription(User $user, int $transcriptionId): ?array
    {
        $storageIds = $this->accessibleStorageIds($user);
        if (empty($storageIds)) {
            return null;
        }

        $row = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
            ->leftJoin('user_storages as us', function ($j) use ($user) {
                $j->on('us.storage_provider_id', '=', 'f.storage_provider_id')
                    ->where('us.user_id', $user->id);
            })
            ->where('t.id', $transcriptionId)
            ->where('t.state', Transcription::STATE_DONE)
            ->whereIn('f.storage_provider_id', $storageIds)
            ->first([
                't.id',
                't.duration_seconds',
                'f.id as file_id',
                'f.name as file_name',
                'f.mime_type',
                'f.owner_id',
                'f.storage_provider_id as storage_id',
                'f.parent_id',
                'sp.name as storage_name',
                'sp.type as storage_type',
                'us.permissions as file_permissions',
            ]);

        if (!$row) {
            return null;
        }

        $totalSegments = DB::table('transcription_segments')
            ->where('transcription_id', $transcriptionId)
            ->count();

        return [
            'id' => (int) $row->id,
            'file_id' => (int) $row->file_id,
            'file_name' => (string) $row->file_name,
            'mime_type' => (string) $row->mime_type,
            'storage' => (string) ($row->storage_name ?? ''),
            'storage_id' => isset($row->storage_id) ? (int) $row->storage_id : null,
            'parent_id' => isset($row->parent_id) ? (int) $row->parent_id : null,
            'duration_seconds' => $row->duration_seconds !== null ? (float) $row->duration_seconds : null,
            'total_segments' => (int) $totalSegments,
            'can_view_file' => $this->canViewFile($row->owner_id, $row->file_permissions, $user),
            'can_clip' => $this->canClip($row->owner_id, $row->file_permissions, $row->storage_type, $user),
        ];
    }

    /**
     * Ventana de segmentos de una transcripción VISIBLE.
     *
     * Tres modos (uno por llamada):
     *   - anchorSegmentId: ventana centrada alrededor del segmento de la
     *     mención (config avisos.transcript.window).
     *   - afterIndex: siguiente página hacia adelante (cursor).
     *   - beforeIndex: página hacia atrás (cursor, se re-ordena asc).
     *   - sin parámetros: primera página desde el inicio.
     *
     * Nunca carga la transcripción completa: range-scan por
     * (transcription_id, segment_index). Retorna null si la transcripción
     * no es visible para el usuario.
     */
    public function pageVisibleSegments(
        User $user,
        int $transcriptionId,
        ?int $anchorSegmentId = null,
        ?int $afterIndex = null,
        ?int $beforeIndex = null,
        ?int $limit = null,
    ): ?array {
        if ($this->visibleTranscription($user, $transcriptionId) === null) {
            return null;
        }

        $window = max(10, (int) config('avisos.transcript.window', 120));
        $pageLimit = min(
            max(10, $limit ?? (int) config('avisos.transcript.page', 60)),
            max(10, (int) config('avisos.transcript.max_page', 200))
        );

        $q = DB::table('transcription_segments')
            ->where('transcription_id', $transcriptionId);

        // hotfix mis-avisos-transcript-duplicate-segments: si la transcripción
        // se reprocesó, la tabla `transcription_segments` puede tener varias
        // filas por (transcription_id, segment_index) con IDs distintos. Sin
        // este DISTINCT ON, el visor muestra cada segmento dos veces. PG exige
        // que la columna de DISTINCT ON sea la primera en ORDER BY para que
        // la selección sea determinista; como el resto del bloque ya ordena
        // por segment_index, agregamos `id` como tie-breaker estable.
        $select = 'DISTINCT ON (segment_index) ' . implode(', ', $this->segmentSelect());

        if ($anchorSegmentId !== null) {
            $anchorIndex = (int) (clone $q)
                ->where('id', $anchorSegmentId)
                ->value('segment_index');
            $half = (int) floor($window / 2);
            $rows = $q
                ->selectRaw($select)
                ->where('segment_index', '>=', max(0, $anchorIndex - $half))
                ->where('segment_index', '<=', $anchorIndex + $half)
                ->orderBy('segment_index')
                ->orderBy('id')
                ->limit($window)
                ->get()->all();
        } elseif ($afterIndex !== null) {
            $rows = $q
                ->selectRaw($select)
                ->where('segment_index', '>', $afterIndex)
                ->orderBy('segment_index')
                ->orderBy('id')
                ->limit($pageLimit)
                ->get()->all();
        } elseif ($beforeIndex !== null) {
            $rows = array_reverse($q
                ->selectRaw($select)
                ->where('segment_index', '<', $beforeIndex)
                ->orderByDesc('segment_index')
                ->orderByDesc('id')
                ->limit($pageLimit)
                ->get()->all());
        } else {
            $rows = $q
                ->selectRaw($select)
                ->orderBy('segment_index')
                ->orderBy('id')
                ->limit($pageLimit)
                ->get()->all();
        }

        $segments = array_map(fn ($s) => [
            'id' => (int) $s->id,
            'segment_index' => (int) $s->segment_index,
            'start_seconds' => (float) $s->start_seconds,
            'end_seconds' => (float) $s->end_seconds,
            'text' => (string) $s->text,
        ], $rows);

        if (empty($segments)) {
            return [
                'segments' => [],
                'first_index' => null,
                'last_index' => null,
                'total_segments' => 0,
            ];
        }

        return [
            'segments' => $segments,
            'first_index' => (int) $segments[0]['segment_index'],
            'last_index' => (int) $segments[count($segments) - 1]['segment_index'],
            'total_segments' => $this->segmentCount($transcriptionId),
        ];
    }

    // ── Internos: columnas, mapeo y capabilities ────────────────────────

    public function hitSelect(): array
    {
        return [
            'h.id',
            'h.snippet',
            'h.matched_at',
            't.recorded_at as recorded_at',   // change 2026-09-10-mis-avisos-program-date-filter
            'h.transcription_id',
            'h.segment_id',
            'h.keyword_id',
            'h.occurrences',
            'k.text as keyword',
            'f.id as file_id',
            'f.name as filename',
            'f.owner_id',
            'f.storage_provider_id',
            'f.parent_id',
            'f.mime_type',
            'sp.name as storage_name',
            'sp.type as storage_type',
            'us.permissions as file_permissions',
            'seg.start_seconds',
            'seg.end_seconds',
        ];
    }

    private function segmentSelect(): array
    {
        return ['id', 'segment_index', 'start_seconds', 'end_seconds', 'text'];
    }

    private function segmentCount(int $transcriptionId): int
    {
        // hotfix mis-avisos-transcript-duplicate-segments: el backend puede
        // contener filas duplicadas de (transcription_id, segment_index) si
        // la transcripción se reprocesó sin limpiar la previa. Devolvemos
        // el conteo ÚNICO de segmentos para no inflar `total_segments` en
        // el header del visor. `COUNT(DISTINCT segment_index)` evita un
        // GROUP BY completo y usa el índice transcription_id.
        return (int) DB::table('transcription_segments')
            ->where('transcription_id', $transcriptionId)
            ->selectRaw('COUNT(DISTINCT segment_index) AS unique_count')
            ->value('unique_count');
    }

    /**
     * Filtros compartidos por feed e histórico. El término se ignora si no
     * alcanza el mínimo configurado (protección de sobrecarga); los
     * storage_ids SIEMPRE se intersectan con los accesibles.
     */
    private function applyHitFilters(Builder $q, User $user, array $filters): void
    {
        $minLen = (int) config('avisos.exports.min_query_length', 3);

        // Filtro por storages concretos (siempre subconjunto de los accesibles).
        if (!empty($filters['storage_ids']) && !empty($this->accessibleStorageIds($user))) {
            $allowed = array_intersect(
                array_map('intval', (array) $filters['storage_ids']),
                $this->accessibleStorageIds($user)
            );
            if (empty($allowed)) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('f.storage_provider_id', $allowed);
            }
        }

        // Término libre: sobre el snippet del hit Y el texto del segmento.
        $term = isset($filters['q']) ? mb_strtolower(trim((string) $filters['q'])) : '';
        if ($term !== '' && mb_strlen($term) >= $minLen) {
            $q->where(function ($tq) use ($term) {
                $tq->whereRaw('lower(h.snippet) like ?', ["%{$term}%"])
                    ->orWhereRaw('lower(seg.text) like ?', ["%{$term}%"]);
            });
        }

        // change mis-avisos-media-kind-indicator (G2): filtro por tipo de medio.
        // Si el cliente seleccionó "TV" o "Radio" en el filtro rápido de Mis Avisos,
        // el query agrega `WHERE t.transcription_id IN (...with file mime_type like ...)`.
        // "all" o valor ausente → sin restricción.
        $mediaType = isset($filters['media_type']) ? (string) $filters['media_type'] : 'all';
        if ($mediaType === 'tv' || $mediaType === 'radio') {
            $mimePrefix = $mediaType === 'tv' ? 'video/%' : 'audio/%';
            // Sub-select sobre el join existente — usa el índice si existe.
            $q->whereExists(function ($sub) use ($mimePrefix, $q) {
                $sub->select(DB::raw(1))
                    ->from('files as f2')
                    ->join('transcriptions as t2', 't2.file_id', '=', 'f2.id')
                    ->whereColumn('t2.id', '=', 't.id')
                    ->where('f2.mime_type', 'like', $mimePrefix);
            });
        }

        // Filtro por keyword registrada (respeta su alcance natural).
        if (!empty($filters['keyword_id'])) {
            $q->where('h.keyword_id', (int) $filters['keyword_id']);
        }
    }

    /**
     * Mapeo ÚNICO de fila de hit (feed, histórico y cualquier consumidor
     * futuro). Incluye deep-link al reproductor con el segundo de la
     * mención y capabilities calculadas en el servidor.
     */
    private function hitRow($r, User $user, array $occurrenceTotals = []): array
    {
        $startFloat = (float) $r->start_seconds;
        $start = (int) floor($startFloat);
        $fileId = $r->file_id ? (int) $r->file_id : null;
        $occ = isset($r->occurrences) ? max(1, (int) $r->occurrences) : 1;
        $totalInMedia = $occurrenceTotals["{$r->transcription_id}:{$r->keyword_id}"] ?? $occ;

        return [
            'id' => (int) $r->id,
            'keyword' => (string) $r->keyword,
            'snippet' => (string) $r->snippet,
            'matched_at' => $r->matched_at,
            // change 2026-09-10-mis-avisos-program-date-filter: fecha real del programa.
            'recorded_at' => $r->recorded_at ?? null,
            'filename' => (string) $r->filename,
            'file_id' => $fileId,
            // Reproductor real (página view) posicionado en el minuto exacto.
            // (El endpoint /preview solo sirve imágenes inline.)
            'file_url' => $fileId ? "/files/{$fileId}/view?t={$start}" : '#',
            'storage' => (string) ($r->storage_name ?? ''),
            'storage_id' => isset($r->storage_provider_id) ? (int) $r->storage_provider_id : null,
            'parent_id' => isset($r->parent_id) ? (int) $r->parent_id : null,
            'minute_label' => $this->hms($startFloat),
            'transcription_id' => (int) $r->transcription_id,
            'segment_id' => $r->segment_id ? (int) $r->segment_id : null,
            'occurrences' => $occ,
            'occurrences_in_media' => $totalInMedia,
            'start_seconds' => $startFloat,
            'end_seconds' => (float) $r->end_seconds,
            'can_view_file' => $this->canViewFile($r->owner_id, $r->file_permissions, $user),
            'can_clip' => $this->canClip($r->owner_id, $r->file_permissions, $r->storage_type, $user),
            // change mis-avisos-media-kind-indicator: tipo de medio derivado
            // para que la UI muestre el ícono TV/Radio al inicio del filename.
            'mime_type' => (string) ($r->mime_type ?? ''),
            'media_kind' => self::classifyMediaKind($r->mime_type ?? null),
        ];
    }

    /**
     * Clasifica un mime_type en TV / Radio / Other para el UI de Mis Avisos.
     * change mis-avisos-media-kind-indicator (G1): regla simple, basada en el
     * prefijo del mime — el cliente quiere distinguir mp4 (TV) de mp3/m4a (Radio)
     * sin adivinar por la extensión del filename.
     */
    public static function classifyMediaKind(?string $mimeType): string
    {
        if (!$mimeType) {
            return 'other';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'tv';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'radio';
        }
        return 'other';
    }

    private function canViewFile($ownerId, $permissions, User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($ownerId !== null && (int) $ownerId === $user->id) {
            return true;
        }

        return $this->permissionLevel($permissions) >= self::PERMISSION_LEVELS['read'];
    }

    private function canClip($ownerId, $permissions, $storageType, User $user): bool
    {
        if (!$this->canViewFile($ownerId, $permissions, $user)) {
            return false;
        }
        // El endpoint de clip además respeta el cupo mensual; la capability
        // solo anticipa storage local + editor (admin lo tiene siempre).
        return $storageType === 'local' && $user->canUseMediaEditor();
    }

    private function permissionLevel($permissions): int
    {
        return self::PERMISSION_LEVELS[(string) $permissions] ?? 0;
    }

    private function hms(float $seconds): string
    {
        $total = (int) floor($seconds);
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}
