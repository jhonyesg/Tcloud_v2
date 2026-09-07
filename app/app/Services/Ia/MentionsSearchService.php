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
     */
    public function todayHits(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = $this->visibleHitsQuery($user)
            ->whereDate('h.matched_at', today());

        $this->applyHitFilters($q, $user, $filters);

        return $q->orderByDesc('h.matched_at')
            ->select($this->hitSelect())
            ->paginate($perPage)
            ->through(fn ($r) => $this->hitRow($r, $user));
    }

    /**
     * Búsqueda histórica (≤60 días) con filtros. Respeta la misma base de
     * acceso. Aplica mínimo de caracteres y rango máximo.
     */
    public function searchHistory(User $user, array $filters = []): LengthAwarePaginator
    {
        $maxDays = (int) config('avisos.exports.history_days', 60);

        $q = $this->visibleHitsQuery($user);

        // Rango de fechas acotado a la retención del negocio.
        $from = isset($filters['from']) && $filters['from'] !== ''
            ? \Carbon\Carbon::parse($filters['from'])->startOfDay() : now()->subDays($maxDays)->startOfDay();
        $to = isset($filters['to']) && $filters['to'] !== ''
            ? \Carbon\Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        if ($from->diffInDays($to, true) > $maxDays) {
            $from = $to->copy()->subDays($maxDays)->startOfDay();
        }
        $q->whereBetween('h.matched_at', [$from, $to]);

        $this->applyHitFilters($q, $user, $filters);

        return $q->orderByDesc('h.matched_at')
            ->select($this->hitSelect())
            ->paginate(25)
            ->through(fn ($r) => $this->hitRow($r, $user));
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

        if ($anchorSegmentId !== null) {
            $anchorIndex = (int) (clone $q)
                ->where('id', $anchorSegmentId)
                ->value('segment_index');
            $half = (int) floor($window / 2);
            $rows = $q
                ->where('segment_index', '>=', max(0, $anchorIndex - $half))
                ->where('segment_index', '<=', $anchorIndex + $half)
                ->orderBy('segment_index')
                ->limit($window)
                ->get($this->segmentSelect())->all();
        } elseif ($afterIndex !== null) {
            $rows = $q
                ->where('segment_index', '>', $afterIndex)
                ->orderBy('segment_index')
                ->limit($pageLimit)
                ->get($this->segmentSelect())->all();
        } elseif ($beforeIndex !== null) {
            $rows = array_reverse($q
                ->where('segment_index', '<', $beforeIndex)
                ->orderByDesc('segment_index')
                ->limit($pageLimit)
                ->get($this->segmentSelect())->all());
        } else {
            $rows = $q
                ->orderBy('segment_index')
                ->limit($pageLimit)
                ->get($this->segmentSelect())->all();
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

    private function hitSelect(): array
    {
        return [
            'h.id',
            'h.snippet',
            'h.matched_at',
            'h.transcription_id',
            'h.segment_id',
            'h.keyword_id',
            'k.text as keyword',
            'f.id as file_id',
            'f.name as filename',
            'f.owner_id',
            'f.storage_provider_id',
            'f.parent_id',
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
        return (int) DB::table('transcription_segments')
            ->where('transcription_id', $transcriptionId)
            ->count();
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
    private function hitRow($r, User $user): array
    {
        $startFloat = (float) $r->start_seconds;
        $start = (int) floor($startFloat);
        $fileId = $r->file_id ? (int) $r->file_id : null;

        return [
            'id' => (int) $r->id,
            'keyword' => (string) $r->keyword,
            'snippet' => (string) $r->snippet,
            'matched_at' => $r->matched_at,
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
            'start_seconds' => $startFloat,
            'end_seconds' => (float) $r->end_seconds,
            'can_view_file' => $this->canViewFile($r->owner_id, $r->file_permissions, $user),
            'can_clip' => $this->canClip($r->owner_id, $r->file_permissions, $r->storage_type, $user),
        ];
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
