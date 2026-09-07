<?php

namespace App\Services\Ia;

use App\Models\Keyword;
use App\Models\Transcription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Motor de matching de menciones (mis-avisos-menciones Fase 1).
 *
 * Scan universal: escanea los segmentos de UNA transcripción UNA sola vez
 * contra el conjunto de keywords DISTINCT de todos los usuarios habilitados
 * con transcription_access sobre el storage de la transcripción. Los hits se
 * persisten compartidos en segment_keyword_hits (UNIQUE por transcripción +
 * segmento + keyword) y el reparto por usuario se deriva relacionalmente en
 * alert_deliveries (con cadencia, techo y rate limiter del lado de entrega).
 *
 * Diferencias clave con el motor anterior:
 *  - Nada de queries dentro del loop de matches (mapa normalized→id precargado).
 *  - Nada de una fila por usuario: el hit es compartido.
 *  - Nada de correo durante el scan (la entrega la gestiona el scheduler).
 *
 * Firma pública run(Transcription): int sin cambios — TranscriptionProcessor
 * no se toca. Idempotente por diseño (UNIQUE triple + insertOrIgnore).
 */
class KeywordMatcher
{
    /**
     * Ejecuta el scan para una Transcription. Devuelve el número de hits
     * nuevos persistidos.
     */
    public function run(Transcription $transcription): int
    {
        // Idempotencia: si ya hay hits para esta transcripción, no reprocesar.
        $already = DB::table('segment_keyword_hits')
            ->where('transcription_id', $transcription->id)
            ->exists();
        if ($already) {
            return 0;
        }

        // Fail-safe: sin file/storage no hay de quién inferir acceso.
        $storageId = $transcription->file?->storage_provider_id;
        if (!$storageId) {
            return 0;
        }

        $segments = $transcription->segments()
            ->orderBy('segment_index')
            ->get(['id', 'segment_index', 'text', 'start_seconds']);
        if ($segments->isEmpty()) {
            return 0;
        }

        // Conjunto de keywords DISTINCT de usuarios habilitados con
        // transcription_access a ESTE storage, acotado por el alcance
        // keyword→store (user_keyword_storage: sin filas = todos).
        $keywords = $this->candidateKeywords((int) $storageId);
        if ($keywords->isEmpty()) {
            return 0;
        }

        $keywordIdByNorm = $keywords
            ->mapWithKeys(fn ($k) => [$k->normalized => $k->id]);

        $now = now();
        $hits = [];

        foreach ($segments as $segment) {
            $segmentText = Keyword::asciiLower((string) $segment->text);
            if ($segmentText === '') {
                continue;
            }

            foreach ($keywordIdByNorm as $keywordNorm => $keywordId) {
                if ($keywordNorm !== '' && str_contains($segmentText, $keywordNorm)) {
                    $hits[] = [
                        'transcription_id' => $transcription->id,
                        'segment_id' => $segment->id,
                        'keyword_id' => $keywordId,
                        'snippet' => $this->buildSnippet((string) $segment->text, $keywordNorm),
                        'matched_at' => $now,
                    ];
                }
            }
        }

        if (empty($hits)) {
            return 0;
        }

        // Insert masivo idempotente (UNIQUE triple). Nada de N inserts.
        $inserted = 0;
        foreach (array_chunk($hits, 500) as $chunk) {
            $inserted += DB::table('segment_keyword_hits')->insertOrIgnore($chunk);
        }

        // Reparto relacional: una fila de alert_deliveries por (usuario que
        // califica, hit), respetando intersección de acceso + scope, con
        // due_at según la cadencia del usuario. Todo en SQL de conjunto.
        $delivered = $this->fanOut($transcription->id, (int) $storageId);

        Log::info('mentions.scan_completed', [
            'transcription_id' => $transcription->id,
            'storage_id' => $storageId,
            'keywords_scanned' => $keywordIdByNorm->count(),
            'hits_persisted' => $inserted,
            'deliveries_queued' => $delivered,
        ]);

        return $inserted;
    }

    /**
     * Keywords distintas (id + normalized) que deben escanearse para un
     * storage: de usuarios enabled con transcription_access=true sobre él,
     * intersectando el alcance keyword→store.
     */
    private function candidateKeywords(int $storageId)
    {
        return DB::table('keywords as k')
            ->distinct()
            ->select('k.id', 'k.normalized')
            ->join('user_keyword as uk', 'uk.keyword_id', '=', 'k.id')
            ->join('users as u', 'u.id', '=', 'uk.user_id')
            ->join('user_alerts_inteligentes as uai', 'uai.user_id', '=', 'u.id')
            ->join('user_storages as us', function ($join) use ($storageId) {
                $join->on('us.user_id', '=', 'u.id')
                    ->where('us.storage_provider_id', $storageId)
                    ->where('us.transcription_access', true);
            })
            ->leftJoin('user_keyword_storage as uks', function ($join) {
                $join->on('uks.user_id', '=', 'uk.user_id')
                    ->on('uks.keyword_id', '=', 'uk.keyword_id');
            })
            // Sin filas de scope → rastrea en todos sus storages con acceso.
            // Con filas → solo si este storage está entre ellas.
            ->where(function ($q) use ($storageId) {
                $q->whereNull('uks.user_id')
                    ->orWhere('uks.storage_provider_id', $storageId);
            })
            ->whereNotNull('k.normalized')
            ->where('k.normalized', '!=', '')
            ->where('uai.enabled', true)
            ->get(['k.id', 'k.normalized']);
    }

    /**
     * Deriva alert_deliveries para los hits de una transcripción: por cada
     * hit, todos los usuarios calificados (módulo activo + acceso al storage
     * + keyword suya + scope de la keyword incluye este storage), con due_at
     * según la cadencia del usuario. Un solo INSERT...SELECT de conjunto.
     */
    private function fanOut(int $transcriptionId, int $storageId): int
    {
        return DB::affectingStatement("
            INSERT INTO alert_deliveries (user_id, hit_id, due_at, created_at, updated_at)
            SELECT uk.user_id,
                   h.id,
                   NOW() + (uai.alert_frequency_minutes || ' minutes')::interval,
                   NOW(),
                   NOW()
            FROM segment_keyword_hits h
            JOIN keywords k ON k.id = h.keyword_id
            JOIN user_keyword uk ON uk.keyword_id = k.id
            JOIN user_alerts_inteligentes uai
                 ON uai.user_id = uk.user_id AND uai.enabled = true
            JOIN user_storages us
                 ON us.user_id = uk.user_id
                AND us.storage_provider_id = ?
                AND us.transcription_access = true
            LEFT JOIN user_keyword_storage uks
                 ON uks.user_id = uk.user_id AND uks.keyword_id = k.id
            WHERE h.transcription_id = ?
              AND (uks.user_id IS NULL OR uks.storage_provider_id = ?)
            ON CONFLICT DO NOTHING
        ", [$storageId, $transcriptionId, $storageId]);
    }

    /**
     * Construye un snippet de ~200 chars alrededor del match.
     */
    private function buildSnippet(string $text, string $keyword): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $len = mb_strlen($text);
        if ($len <= 200) {
            return $text;
        }

        $pos = mb_stripos($text, $keyword);
        if ($pos === false) {
            return mb_substr($text, 0, 200);
        }

        $start = max(0, $pos - 80);
        $snippet = mb_substr($text, $start, 200);
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if ($start + 200 < $len) {
            $snippet = $snippet . '...';
        }
        return $snippet;
    }

    private function secondsToHms(float $seconds): string
    {
        $total = (int) $seconds;
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}