<?php

namespace App\Services\Ia;

use App\Models\Keyword;
use App\Models\TranscriptionSegment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Helpers para vista de menciones + backfill (change admin-matches-and-backfill).
 * La idea es mantener la lógica de agrupación + backfill en un solo servicio
 * compartido por:
 *   - Admin AvisosInteligentesController (server-side grouping en Blade)
 *   - Job BackfillKeywordMatches (backfill retroactivo de una keyword)
 *   - Consola BackfillKeywordCommand (--all y --keyword=ID)
 *
 * El agrupamiento es server-side (Blade @foreach) porque la vista admin no usa
 * Alpine. La vista de cliente (mis-avisos) sigue agrupando client-side vía
 * `_groupRows` de Alpine (UX consistente entre las dos vistas).
 */
class MentionBackfillService
{
    /**
     * Agrupa filas planas de hits/keyword_matches por `(transcription_id, keyword_id)`.
     * Cada grupo expone: key, transcription_id, filename, storage_name,
     * keyword_id, keyword, total, hits (orden DESC matched_at), first_*.
     *
     * Acepta cualquier Collection/iterable que tenga los atributos esperados.
     * Se hace desde PHP solo (Blade server-side); la contraparte Alpine vive en
     * `mis-avisos/index.blade.php::_groupRows`.
     */
    public static function groupHits(iterable $rows): Collection
    {
        $byKey = [];
        foreach ($rows as $r) {
            // Normalizar entrada: aceptar tanto Eloquent (con $r->id) como array/stdClass.
            $row = is_array($r) ? (object) $r : (object) $r;
            if (isset($row->pivot)) $row = (object) array_merge((array) $row, (array) $row->pivot);

            $tid = (int) ($row->transcription_id ?? 0);
            $kid = (int) ($row->keyword_id ?? 0);
            if ($tid === 0 || $kid === 0) {
                continue;
            }

            $key = $tid . ':' . $kid;
            if (!isset($byKey[$key])) {
                $matchedAt = $row->matched_at ?? null;
                $startSeconds = (float) ($row->start_seconds ?? 0);
                $byKey[$key] = [
                    'key' => $key,
                    'transcription_id' => $tid,
                    'keyword_id' => $kid,
                    'filename' => (string) ($row->filename ?? ''),
                    'storage_name' => (string) ($row->storage_name ?? ''),
                    'storage_id' => isset($row->storage_provider_id) ? (int) $row->storage_provider_id : null,
                    'transcription_id_src' => (int) ($row->transcription_id_src ?? $tid),
                    'parent_id' => isset($row->parent_id) ? (int) $row->parent_id : null,
                    'keyword' => (string) ($row->keyword ?? ''),
                    'total' => (int) ($row->occurrences_in_media ?? 0),
                    'occurrences_in_media' => (int) ($row->occurrences_in_media ?? 0),
                    'first_id' => (int) ($row->id ?? 0),
                    'first_matched_at' => $matchedAt,
                    'first_segment_id' => isset($row->segment_id) ? (int) $row->segment_id : null,
                    'first_start_seconds' => $startSeconds,
                    'first_snippet' => (string) ($row->snippet ?? ''),
                    // change mis-avisos-media-kind-indicator: tipo de medio del
                    // filename que el grupo representa (todas las menciones
                    // de un mismo (transcripción, keyword) comparten mime_type).
                    'first_media_kind' => (string) ($row->media_kind ?? 'other'),
                    'can_view_file' => (bool) ($row->can_view_file ?? false),
                    'can_clip' => (bool) ($row->can_clip ?? false),
                    'hits' => [],
                ];
            }

            $matchedAt = $row->matched_at ?? null;
            $byKey[$key]['hits'][] = [
                'id' => (int) ($row->id ?? 0),
                'matched_at' => $matchedAt,
                'minute_label' => self::hms((float) ($row->start_seconds ?? 0)),
                'start_seconds' => (float) ($row->start_seconds ?? 0),
                'segment_id' => isset($row->segment_id) ? (int) $row->segment_id : null,
                'snippet' => (string) ($row->snippet ?? ''),
                'occurrences' => (int) ($row->occurrences ?? 1),
                'file_url' => (string) ($row->file_url ?? ''),
                'filename' => (string) ($row->filename ?? ''),
                'media_kind' => (string) ($row->media_kind ?? 'other'),
            ];
        }

        // Ordenar hits interno por matched_at DESC; ordenar grupos por first_matched_at DESC.
        $groups = [];
        foreach ($byKey as $key => $group) {
            usort($group['hits'], fn ($a, $b) => strcmp((string) ($b['matched_at'] ?? ''), (string) ($a['matched_at'] ?? '')));
            $group['total'] = count($group['hits']);
            $groups[$key] = $group;
        }
        uasort($groups, fn ($a, $b) => strcmp((string) ($b['first_matched_at'] ?? ''), (string) ($a['first_matched_at'] ?? '')));

        return new Collection($groups);
    }

    /**
     * Convierte segundos a HH:MM:SS (label amigable para UI).
     */
    public static function hms(float $seconds): string
    {
        $total = (int) floor($seconds);
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }

    /**
     * Backfill retroactivo de UNA keyword contra los segmentos accesibles
     * para los usuarios habilitados con transcription_access.
     *
     * Estrategia: misma lógica del KeywordMatcher pero funcionando en bulk
     * via SQL con LIKE para matching por substring, procesando los segmentos
     * en chunks para evitar cargar 51M de filas en memoria.
     *
     * Idempotente: la UNIQUE constraint (transcription_id, segment_id, keyword_id)
     * en segment_keyword_hits blinda contra cualquier re-inserción.
     *
     * Retorna un array con: ['hits_inserted' => int, 'transcriptions_scanned' => int, 'seconds' => float]
     */
    public function backfillKeyword(Keyword $keyword): array
    {
        $start = microtime(true);
        $keywordNorm = \App\Models\Keyword::asciiLower($keyword->text);

        if ($keywordNorm === '') {
            return ['hits_inserted' => 0, 'transcriptions_scanned' => 0, 'seconds' => 0.0];
        }

        // Encontrar transcripciones accesibles para usuarios con esta keyword registrada.
        // El matcher considera (user_keyword + user_storages + user_alerts_inteligentes.enabled).
        //
        // MATERIALIZAMOS los IDs de transcripciones candidatas en PHP para evitar
        // el bug de Laravel's PostgreSQL grammar: cuando se hace `where('column', '=', $int)`
        // dentro de cláusulas JOIN/ON, el binder puede entrecomillar el literal como
        // "47" y Postgres lo interpreta como columna → "column 47 does not exist".
        // Pasamos binds explícitos con ? y whereRaw para mantener enteros puros.
        $candidateTranscriptionIds = DB::table('transcription_segments as seg')
            ->join('transcriptions as t', 't.id', '=', 'seg.transcription_id')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->join('user_keyword as uk', function ($j) use ($keyword) {
                $j->whereRaw('uk.keyword_id = ?', [$keyword->id]);
            })
            ->join('user_storages as us', function ($j) {
                $j->on('us.user_id', '=', 'uk.user_id')
                    ->on('us.storage_provider_id', '=', 'f.storage_provider_id')
                    ->where('us.transcription_access', '=', true);
            })
            ->join('user_alerts_inteligentes as uai', function ($j) {
                $j->on('uai.user_id', '=', 'uk.user_id')
                    ->where('uai.enabled', '=', true);
            })
            ->where('t.state', \App\Models\Transcription::STATE_DONE)
            // Excluir transcripciones que ya tienen hits para esta keyword
            ->whereNotExists(function ($q) use ($keyword) {
                $q->select(DB::raw(1))
                    ->from('segment_keyword_hits as existing')
                    ->whereColumn('existing.transcription_id', '=', 't.id')
                    ->whereRaw('existing.keyword_id = ?', [$keyword->id]);
            })
            // Acotar por scope keyword→store si existe
            ->whereNotExists(function ($q) use ($keyword) {
                $q->select(DB::raw(1))
                    ->from('user_keyword_storage as uks')
                    ->whereColumn('uks.user_id', '=', 'uk.user_id')
                    ->whereRaw('uks.keyword_id = ?', [$keyword->id])
                    ->whereColumn('uks.storage_provider_id', '!=', 'f.storage_provider_id');
            })
            // El match de LIKE sobre lower(seg.text)
            ->whereRaw('lower(seg.text) LIKE ?', ['%' . $this->escapeLike($keywordNorm) . '%'])
            ->pluck('seg.transcription_id')
            ->unique();

        if ($candidateTranscriptionIds->isEmpty()) {
            return ['hits_inserted' => 0, 'transcriptions_scanned' => 0, 'seconds' => round(microtime(true) - $start, 2)];
        }

        $transcriptionsScanned = [];
        $now = now();
        $inserted = 0;
        $chunk = [];

        // Iterar segmentos por chunks para evitar 51M-rows en memoria.
        // chunkById necesita una columna `id` para paginar — incluimos el id del
        // segmento como `id` y los demás via aliases.
        TranscriptionSegment::query()
            ->whereIn('transcription_id', $candidateTranscriptionIds)
            ->whereRaw('lower(text) LIKE ?', ['%' . $this->escapeLike($keywordNorm) . '%'])
            ->select(['id', 'transcription_id', 'text', 'start_seconds', 'end_seconds'])
            ->orderBy('transcription_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$chunk, &$inserted, &$transcriptionsScanned, $keyword, $keywordNorm, $now) {
                foreach ($rows as $row) {
                    $transcriptionsScanned[$row->transcription_id] = true;
                    $text = \App\Models\Keyword::asciiLower((string) $row->text);
                    if ($text === '') {
                        continue;
                    }

                    // avisos-keyword-word-boundary: aceptación final con
                    // frontera de palabra (el LIKE SQL previo es solo
                    // pre-filtro, subcadena es superset de la frontera).
                    $occurrences = KeywordBoundaryMatcher::countOccurrences($text, $keywordNorm);
                    if ($occurrences === 0) {
                        continue;
                    }

                    $snippet = $this->buildSnippet((string) $row->text, $keyword->text);

                    $chunk[] = [
                        'transcription_id' => $row->transcription_id,
                        'segment_id' => $row->id,
                        'keyword_id' => $keyword->id,
                        'snippet' => $snippet,
                        'occurrences' => $occurrences,
                        'matched_at' => $now,
                    ];

                    if (count($chunk) >= 500) {
                        $inserted += $this->insertChunk($chunk);
                        $chunk = [];
                    }
                }
            });

        if (!empty($chunk)) {
            $inserted += $this->insertChunk($chunk);
        }

        return [
            'hits_inserted' => (int) $inserted,
            'transcriptions_scanned' => count($transcriptionsScanned),
            'seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * Devuelve keywords con 0 hits en segment_keyword_hits.
     * Útil para `mentions:backfill-keyword --all`.
     */
    public function keywordsWithoutHits(int $limit = 100): array
    {
        return DB::table('keywords as k')
            ->leftJoin('segment_keyword_hits as h', 'h.keyword_id', '=', 'k.id')
            ->whereNull('h.id')
            ->groupBy('k.id', 'k.text')
            ->orderBy('k.id')
            ->limit($limit)
            ->pluck('k.text', 'k.id')
            ->all();
    }

    private function insertChunk(array $chunk): int
    {
        return DB::table('segment_keyword_hits')->insertOrIgnore($chunk);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }

    private function buildSnippet(string $text, string $keyword): string
    {
        // Posición de la primera aparición CON frontera de palabra
        // (avisos-keyword-word-boundary). La posición del helper es en bytes
        // sobre el texto normalizado ASCII; coincide 1:1 con los bytes del
        // texto original.
        $posNorm = KeywordBoundaryMatcher::firstPosition(
            \App\Models\Keyword::asciiLower($text),
            \App\Models\Keyword::asciiLower($keyword),
        );
        $length = mb_strlen($text);
        $kwLength = mb_strlen($keyword);

        if ($posNorm === null) {
            return mb_substr($text, 0, min(120, $length));
        }
        $pos = (int) $posNorm;

        $from = max(0, $pos - 60);
        $to = min($length, $pos + $kwLength + 60);
        $snippet = mb_substr($text, $from, $to - $from);
        if ($from > 0) $snippet = '…' . $snippet;
        if ($to < $length) $snippet .= '…';

        return $snippet;
    }
}
