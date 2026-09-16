<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Busca ejemplos reales de dónde dispara una corrección del diccionario.
 *
 * El admin modera reglas que se aplican globalmente a ~20M de segmentos. Sin ver
 * dónde aparecen, aprueba a ciegas: "of → de" parece razonable aislado y rompe
 * "top of the morning". Este servicio devuelve 3-5 apariciones de transcripciones
 * distintas para que la decisión se tome con evidencia.
 *
 * ---------------------------------------------------------------------------
 * Por qué la consulta se ve así (NO cambiar sin releer esto)
 * ---------------------------------------------------------------------------
 * transcription_segments son 8,3 GB y el ÚNICO índice de texto es
 *
 *     idx_transcription_segments_text_gin ON transcription_segments
 *         USING gin (text gin_trgm_ops)
 *
 * es decir, sobre `text` — NO sobre `text_raw`, que no tiene ninguno. Filtrar por
 * `text_raw ILIKE ...` como condición principal produce un Parallel Seq Scan de
 * toda la tabla (cost ~754k). Ese patrón ya saturó producción una vez.
 *
 * Por eso la condición indexable va siempre sobre `text`, y `text_raw` queda como
 * filtro secundario: Postgres lo evalúa sobre las filas que el bitmap del índice
 * ya trajo, lo cual es barato. Ese segundo filtro no es decorativo — garantiza que
 * el ejemplo es donde la regla realmente disparó, y no cualquier aparición
 * casual de la palabra corregida.
 *
 * Tampoco hay ORDER BY: `created_at` no está indexado y ordenar obligaría a
 * materializar todas las coincidencias.
 */
class CorrectionContextFinder
{
    public const STATUS_OK = 'ok';
    public const STATUS_TOO_SHORT = 'too_short';
    public const STATUS_NO_MATCHES = 'no_matches';
    public const STATUS_TIMEOUT = 'timeout';

    /** SQLSTATE de Postgres para "query canceled" (statement_timeout agotado). */
    private const SQLSTATE_QUERY_CANCELED = '57014';

    public function __construct(private CorrectionService $corrections)
    {
    }

    /**
     * Ejemplos cacheados de dónde aparece la corrección.
     *
     * La clave incluye updated_at, así que editar wrong_text/correct_text
     * invalida la entrada sin necesidad de purgarla a mano.
     *
     * @return array{status: string, examples: array<int, array<string, mixed>>, truncated: bool, probe: string}
     */
    public function examples(Correction $correction): array
    {
        $key = sprintf(
            'correction_ctx:%d:%d',
            $correction->id,
            $correction->updated_at?->getTimestamp() ?? 0
        );

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->lookup($correction);

        // Un timeout es una condición transitoria de la BD, no una respuesta
        // sobre la corrección. Cachearlo dejaría el botón de reintento inútil
        // durante toda la ventana del TTL.
        if ($result['status'] !== self::STATUS_TIMEOUT) {
            Cache::put($key, $result, (int) config('corrections.context.cache_ttl', 604800));
        }

        return $result;
    }

    /**
     * @return array{status: string, examples: array<int, array<string, mixed>>, truncated: bool, probe: string}
     */
    private function lookup(Correction $correction): array
    {
        $probe = $this->probeFor($correction);
        $minLength = (int) config('corrections.context.min_probe_length', 3);

        // pg_trgm no puede servir patrones de menos de 3 caracteres: el planner
        // degrada a Seq Scan sobre los 8,3 GB. Preferimos no responder a tumbar la BD.
        if (mb_strlen($probe) < $minLength) {
            return $this->emptyResult(self::STATUS_TOO_SHORT, $probe);
        }

        try {
            $rows = $this->search($probe, (string) $correction->wrong_text);

            // Una corrección aprobada cuyo apply retroactivo nunca corrió sigue
            // teniendo el wrong_text en `text`. Reintentamos con esa sonda.
            if ($rows->isEmpty() && $probe !== (string) $correction->wrong_text) {
                $fallback = trim((string) $correction->wrong_text);

                if (mb_strlen($fallback) >= $minLength) {
                    $probe = $fallback;
                    $rows = $this->search($probe, (string) $correction->wrong_text);
                }
            }
        } catch (QueryException $e) {
            if ($this->isTimeout($e)) {
                return $this->emptyResult(self::STATUS_TIMEOUT, $probe);
            }

            throw $e;
        }

        $scanLimit = (int) config('corrections.context.scan_limit', 30);
        $truncated = $rows->count() >= $scanLimit;

        // El ILIKE es un pre-filtro por substring; la aplicación real exige
        // fronteras de palabra. Sin este descarte se colaban ejemplos donde la
        // regla nunca disparó y el moderador los leía como evidencia a favor.
        $previews = [];
        $rows = $rows->filter(function (TranscriptionSegment $segment) use ($correction, &$previews) {
            $applied = $this->corrections->applyRule($correction, (string) $segment->text_raw);

            if ($applied === (string) $segment->text_raw) {
                return false;
            }

            $previews[$segment->id] = $applied;

            return true;
        });

        if ($rows->isEmpty()) {
            return $this->emptyResult(self::STATUS_NO_MATCHES, $probe);
        }

        // Un ejemplo por transcripción: cinco segmentos del mismo archivo no
        // aportan más criterio que uno. Se deduplica en PHP porque un
        // DISTINCT ON obligaría a ordenar todas las coincidencias en la BD.
        $picked = $rows->unique('transcription_id')
            ->take((int) config('corrections.context.examples', 5))
            ->values();

        return [
            'status' => self::STATUS_OK,
            'examples' => $this->present($picked, $previews),
            'truncated' => $truncated,
            'probe' => $probe,
            'rule_state' => $this->ruleState($correction),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, TranscriptionSegment>
     */
    private function search(string $probe, string $wrongText): \Illuminate\Support\Collection
    {
        $timeoutMs = (int) config('corrections.context.timeout_ms', 10000);
        $scanLimit = (int) config('corrections.context.scan_limit', 30);

        // SET LOCAL solo aplica dentro de una transacción, y se revierte al salir.
        return DB::transaction(function () use ($probe, $wrongText, $timeoutMs, $scanLimit) {
            DB::statement("SET LOCAL statement_timeout = '{$timeoutMs}ms'");

            return TranscriptionSegment::query()
                ->whereRaw('text ILIKE ?', ['%' . $this->escapeLike($probe) . '%'])
                ->whereRaw('text_raw ILIKE ?', ['%' . $this->escapeLike($wrongText) . '%'])
                ->limit($scanLimit)
                ->get([
                    'id', 'transcription_id', 'segment_index',
                    'start_seconds', 'end_seconds', 'text_raw', 'text',
                ]);
        });
    }

    /**
     * ¿Esta regla se está aplicando de verdad hoy?
     *
     * Sin esto el modal miente: la vista previa muestra el reemplazo tal cual,
     * pero loadCorrections() filtra por scopeSafe(), que excluye risk_level=high.
     * Como 87 % del diccionario aprobado está en cuarentena, la mayoría de las
     * previsualizaciones son hipotéticas y hay que decirlo.
     */
    private function ruleState(Correction $correction): string
    {
        if ($correction->status !== Correction::STATUS_APPROVED) {
            return 'not_approved';
        }

        return $correction->risk_level === Correction::RISK_HIGH ? 'quarantined' : 'active';
    }

    /**
     * Sonda que sí va a estar presente en `text` (la columna indexada).
     *
     * En una corrección aprobada el diccionario ya reescribió `text`, así que el
     * wrong_text ya no está ahí: hay que buscar el correct_text. En pendientes y
     * rechazadas la regla nunca se aplicó y el wrong_text sigue intacto.
     */
    private function probeFor(Correction $correction): string
    {
        $probe = $correction->status === Correction::STATUS_APPROVED
            ? $correction->correct_text
            : $correction->wrong_text;

        return trim((string) $probe);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TranscriptionSegment>  $segments
     * @param  array<int, string>  $previews  texto resultante de aplicar solo esta regla, por segment id
     * @return array<int, array<string, mixed>>
     */
    private function present(\Illuminate\Support\Collection $segments, array $previews): array
    {
        $names = Transcription::query()
            ->whereIn('id', $segments->pluck('transcription_id')->all())
            ->with('file:id,name')
            ->get(['id', 'file_id', 'original_name'])
            ->keyBy('id');

        return $segments->map(function (TranscriptionSegment $segment) use ($names, $previews) {
            $transcription = $names->get($segment->transcription_id);

            return [
                'segment_id' => $segment->id,
                'transcription_id' => $segment->transcription_id,
                'segment_index' => $segment->segment_index,
                'start_label' => $segment->getStartLabel(),
                'end_label' => $segment->getEndLabel(),
                'text_raw' => $segment->text_raw,
                'text' => $segment->text,
                // Cómo quedaría el segmento con SOLO esta regla: es lo que el
                // moderador está decidiendo, y no coincide con `text`, que
                // refleja el diccionario entero ya aplicado.
                'preview' => $previews[$segment->id] ?? $segment->text,
                'file_name' => $transcription?->file?->name
                    ?? $transcription?->original_name
                    ?? ('Transcripción #' . $segment->transcription_id),
            ];
        })->all();
    }

    /**
     * @return array{status: string, examples: array<int, array<string, mixed>>, truncated: bool, probe: string}
     */
    private function emptyResult(string $status, string $probe): array
    {
        return [
            'status' => $status,
            'examples' => [],
            'truncated' => false,
            'probe' => $probe,
        ];
    }

    private function isTimeout(QueryException $e): bool
    {
        return ($e->getCode() === self::SQLSTATE_QUERY_CANCELED)
            || str_contains($e->getMessage(), 'statement timeout');
    }

    /**
     * Neutraliza los comodines de LIKE. Postgres usa \ como escape por defecto,
     * así que no hace falta cláusula ESCAPE.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
