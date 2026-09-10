<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gobernanza del escaneo de menciones (avisos-keyword-storage-watermark).
 *
 * Única entrada para corridas de escaneo — cron y manual comparten aquí.
 * Reutiliza KeywordMatcher::run() (idempotente: UNIQUE triple + insertOrIgnore
 * + guardia "ya tiene hits → return 0"), por lo que el solape con el pipeline
 * o entre corridas es inofensivo.
 *
 * Cobertura per-par (avisos-keyword-storage-watermark): cada par
 * (keyword_id, storage_provider_id) rastrea su propio scanned_until en
 * keyword_scan_watermarks. selectCandidates() emite PARES (transcription,
 * keyword_id), no transcripciones sueltas, así una keyword nueva recibe
 * retroactivo sin re-procesar las ya cubiertas.
 *
 * Separación estricta scan→entrega: este servicio JAMÁS llama a
 * AlertDispatcher ni crea alert_deliveries/correos. La entrega es trabajo
 * exclusivo de avisos:deliver-alerts (cadencia, techo, rate limiter).
 *
 * Settings (SystemSetting): avisos_scan_enabled (bool, default false),
 * avisos_scan_interval_minutes (int, min 5, default 30),
 * avisos_scan_window_hours (int, min 1, default 72),
 * avisos_scan_full_batch (int, min 50, default 200),
 * avisos_scan_full_max_runtime_seconds (int, min 30, default 600).
 */
class AvisosScanService
{
    public const DEFAULT_BATCH = 50;
    public const DEFAULT_FULL_BATCH = 200;
    public const DEFAULT_FULL_MAX_RUNTIME = 600;

    /** Presets de ventana temporal del disparo manual → horas (today = null). */
    public const PRESETS = ['8h' => 8, '24h' => 24, '3d' => 72, '7d' => 168, 'today' => null];

    /**
     * Resuelve un preset de ventana a from/to concretos anclados a now.
     * Retorna null si el preset no existe o no viene.
     */
    public function resolvePreset(?string $preset): ?array
    {
        if (!$preset || !array_key_exists($preset, self::PRESETS)) {
            return null;
        }

        $now = now();
        $hours = self::PRESETS[$preset];

        return $hours !== null
            ? ['from' => $now->copy()->subHours($hours), 'to' => $now]
            : ['from' => $now->copy()->startOfDay(), 'to' => $now];
    }

    /**
     * Normaliza un límite de rango: si trae componente de hora se respeta
     * tal cual; si solo es fecha (Y-m-d) mantiene la semántica de día
     * completo previa (inicio/fin del día según $isStart).
     */
    public function normalizeRangeBound(?string $value, bool $isStart): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $carbon = Carbon::parse($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1
            ? ($isStart ? $carbon->startOfDay() : $carbon->endOfDay())
            : $carbon;
    }

    /** Corrida de escaneo (cron y manual). Retorna el resumen de la corrida. */
    public function run(array $opts = []): array
    {
        $origin = ($opts['origin'] ?? 'manual') === 'cron' ? 'cron' : 'manual';
        $dryRun = (bool) ($opts['dryRun'] ?? false);
        $opts = $this->applyCatchupGuard($opts);
        $presetRange = $this->applyPreset($opts);

        $startedAt = now();
        $runId = null;

        if ($dryRun) {
            $candidates = $this->selectCandidates($opts);
            return [
                'mode' => 'dry-run',
                'candidates' => $candidates->count(),
                'scanned' => 0, 'hitsNew' => 0, 'failed' => 0,
                'durationMs' => 0, 'status' => 'dry-run', 'error' => null,
                'sample' => $candidates->take(5)
                    ->map(fn ($t) => [
                        'id' => (int) $t->transcription_id,
                        'keyword_id' => (int) $t->keyword_id,
                        'finished_at' => (string) $t->finished_at,
                    ])->values()->all(),
            ];
        }

        try {
            $runId = DB::table('avisos_scan_runs')->insertGetId([
                'origin' => $origin,
                'status' => 'success',
                'params' => json_encode($this->paramsSummary($opts)),
                'started_at' => $startedAt,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);
        } catch (\Throwable $e) {
            Log::error('avisos.scan.run_register_failed', ['error' => $e->getMessage()]);
        }

        $scanStart = microtime(true);
        $scanned = 0;
        $hitsNew = 0;
        $failed = 0;
        $errors = [];
        $bumpSet = [];
        $attemptedIds = [];

        try {
            $candidates = $this->selectCandidates($opts);

            foreach ($candidates as $candidate) {
                $attemptedIds[] = (int) $candidate->transcription_id;
                try {
                    $transcription = Transcription::findOrFail($candidate->transcription_id);
                    $hits = $this->scanPair($transcription, (int) $candidate->keyword_id, (bool) ($opts['force'] ?? false));
                    $scanned++;
                    $hitsNew += max(0, $hits);
                    $bumpSet[] = [
                        'keyword_id' => (int) $candidate->keyword_id,
                        'storage_provider_id' => (int) $candidate->storage_provider_id,
                        'finished_at' => (string) $candidate->finished_at,
                        'hits' => max(0, $hits),
                        'candidates' => 1,
                    ];
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = ['transcription_id' => $candidate->transcription_id, 'error' => mb_substr($e->getMessage(), 0, 200)];
                    Log::error('avisos.scan.transcription_error', [
                        'transcription_id' => $candidate->transcription_id,
                        'keyword_id' => $candidate->keyword_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($runId && !empty($bumpSet)) {
                $this->bumpWatermarks($bumpSet, $runId);
            }

            $status = $failed > 0 && $scanned === 0 ? 'failed' : 'success';
            $durationMs = (int) ((microtime(true) - $scanStart) * 1000);

            if ($runId) {
                DB::table('avisos_scan_runs')->where('id', $runId)->update([
                    'status' => $status,
                    'transcriptions_scanned' => $scanned,
                    'hits_new' => $hitsNew,
                    'failed_count' => $failed,
                    'duration_ms' => $durationMs,
                    'error' => $errors ? json_encode(array_slice($errors, 0, 5)) : null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::info('avisos.scan.run', [
                'origin' => $origin, 'scanned' => $scanned, 'hits_new' => $hitsNew,
                'failed' => $failed, 'duration_ms' => $durationMs,
                'preset' => $presetRange['preset'] ?? null,
                'range' => $presetRange['range'] ?? null,
                'pairs_bumped' => count($bumpSet),
            ]);

            return [
                'mode' => 'run', 'runId' => $runId, 'candidates' => $candidates->count(),
                'scanned' => $scanned, 'hitsNew' => $hitsNew, 'failed' => $failed,
                'durationMs' => $durationMs, 'status' => $status,
                'error' => $errors ? array_slice($errors, 0, 5) : null,
                'attemptedIds' => array_values(array_unique($attemptedIds)),
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $scanStart) * 1000);
            if ($runId) {
                DB::table('avisos_scan_runs')->where('id', $runId)->update([
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Log::error('avisos.scan.error', ['error' => $e->getMessage()]);

            return [
                'mode' => 'run', 'runId' => $runId, 'candidates' => null,
                'scanned' => $scanned, 'hitsNew' => $hitsNew, 'failed' => $failed,
                'durationMs' => $durationMs, 'status' => 'failed', 'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Escanea UN par (transcription, keyword). Aplica KeywordMatcher con scope
     * acotado a esa keyword. Idempotente por UNIQUE triple.
     */
    public function scanPair(Transcription $transcription, int $keywordId, bool $force = false): int
    {
        if ($force) {
            DB::table('segment_keyword_hits')
                ->where('transcription_id', $transcription->id)
                ->where('keyword_id', $keywordId)
                ->delete();
        }

        return app(KeywordMatcher::class)->run($transcription, [$keywordId]);
    }

    /**
     * Deduplica un $bumpSet agrupando por (keyword_id, storage_provider_id).
     *
     * fix-avisos-watermarks-cardinality-violation: cuando varias
     * transcripciones del mismo storage comparten las mismas keywords
     * activas dentro de la misma tanda, el array $bumpSet contiene
     * múltiples filas con el mismo par. PostgreSQL rechaza el
     * `ON CONFLICT DO UPDATE` con filas duplicadas dentro del mismo
     * INSERT (Cardinality violation). Esta función consolida las filas:
     *
     *   - `scanned_until` (vía `finished_at`) = MAX (la más reciente)
     *   - `candidates` = SUM (acumulado de cobertura)
     *   - `hits` = SUM (acumulado de hits; el matching idempotente por
     *     UNIQUE triple en `segment_keyword_hits` previene duplicados
     *     a nivel de transcripción; este contador es solo un agregado)
     *   - otras claves se conservan de la primera ocurrencia del par
     *
     * Sin cambios visibles en el path feliz (cuando no hay duplicados,
     * retorna un array equivalente con cero overhead).
     *
     * @return array<int, array> Array con una fila por par único
     */
    public static function dedupeBumpSet(array $bumpSet): array
    {
        if (empty($bumpSet)) {
            return [];
        }
        $grouped = [];
        foreach ($bumpSet as $b) {
            $kid = (int) ($b['keyword_id'] ?? 0);
            $sid = (int) ($b['storage_provider_id'] ?? 0);
            if ($kid === 0 || $sid === 0) {
                // Filas inválidas: conservamos pero no agrupamos (no
                // podrían agruparse de todas formas).
                $grouped['__invalid_' . md5(json_encode($b))] = $b;
                continue;
            }
            $key = $kid . ':' . $sid;
            if (!isset($grouped[$key])) {
                $grouped[$key] = $b;
                // Inicializa contadores agregados si no vienen.
                $grouped[$key]['candidates'] = (int) ($b['candidates'] ?? 1);
                $grouped[$key]['hits'] = (int) ($b['hits'] ?? 0);
                continue;
            }
            // MAX(scanned_until) vía finished_at.
            $existingAt = (string) $grouped[$key]['finished_at'];
            $newAt = (string) ($b['finished_at'] ?? '');
            if ($newAt !== '' && strcmp($newAt, $existingAt) > 0) {
                $grouped[$key]['finished_at'] = $newAt;
            }
            // SUM(candidates) y SUM(hits).
            $grouped[$key]['candidates'] = (int) ($grouped[$key]['candidates'] ?? 0) + (int) ($b['candidates'] ?? 0);
            $grouped[$key]['hits'] = (int) ($grouped[$key]['hits'] ?? 0) + (int) ($b['hits'] ?? 0);
        }
        return array_values($grouped);
    }

    /**
     * UPSERT atómico de watermarks por par (keyword_id, storage_provider_id).
     * Monotónico vía GREATEST: scanned_until nunca retrocede. Race-safe sin lock.
     *
     * $bumpSet: lista de ['keyword_id', 'storage_provider_id', 'finished_at',
     *                     'hits', 'candidates']
     */
    public function bumpWatermarks(array $bumpSet, int $runId): void
    {
        if (empty($bumpSet)) {
            return;
        }

        // fix-avisos-watermarks-cardinality-violation: deduplicar antes de
        // el bulk INSERT. El mismo (keyword_id, storage_provider_id) puede
        // aparecer varias veces en la misma tanda cuando varias
        // transcripciones del mismo storage comparten las mismas keywords
        // activas. PostgreSQL rechaza el `ON CONFLICT DO UPDATE` con filas
        // duplicadas dentro del mismo INSERT (Cardinality violation).
        $bumpSet = self::dedupeBumpSet($bumpSet);

        $now = now();
        $rows = [];
        foreach ($bumpSet as $b) {
            $rows[] = [
                'keyword_id' => (int) $b['keyword_id'],
                'storage_provider_id' => (int) $b['storage_provider_id'],
                'scanned_until' => (string) $b['finished_at'],
                'last_scan_run_id' => $runId,
                'last_scanned_at' => $now->toDateTimeString(),
                'candidates_total' => (int) ($b['candidates'] ?? 1),
                'hits_total' => (int) ($b['hits'] ?? 0),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?)'));
            $sql = "INSERT INTO keyword_scan_watermarks
                    (keyword_id, storage_provider_id, scanned_until, last_scan_run_id,
                     last_scanned_at, candidates_total, hits_total, created_at, updated_at)
                    VALUES {$placeholders}
                    ON CONFLICT (keyword_id, storage_provider_id) DO UPDATE SET
                        scanned_until = GREATEST(
                            keyword_scan_watermarks.scanned_until,
                            EXCLUDED.scanned_until
                        ),
                        last_scan_run_id = EXCLUDED.last_scan_run_id,
                        last_scanned_at = EXCLUDED.last_scanned_at,
                        last_hit_at = CASE
                            WHEN EXCLUDED.hits_total > 0 THEN EXCLUDED.last_scanned_at
                            ELSE keyword_scan_watermarks.last_hit_at
                        END,
                        candidates_total = keyword_scan_watermarks.candidates_total + EXCLUDED.candidates_total,
                        hits_total = keyword_scan_watermarks.hits_total + EXCLUDED.hits_total,
                        updated_at = EXCLUDED.last_scanned_at";

            $flatParams = [];
            foreach ($chunk as $r) {
                $flatParams[] = $r['keyword_id'];
                $flatParams[] = $r['storage_provider_id'];
                $flatParams[] = $r['scanned_until'];
                $flatParams[] = $r['last_scan_run_id'];
                $flatParams[] = $r['last_scanned_at'];
                $flatParams[] = $r['candidates_total'];
                $flatParams[] = $r['hits_total'];
                $flatParams[] = $now->toDateTimeString();
                $flatParams[] = $now->toDateTimeString();
            }

            DB::statement($sql, $flatParams);
        }
    }

    /**
     * Candidatos: PARES (transcription, keyword) cuyo:
     *  - transcription: state=done, generate_alerts=true
     *  - keyword: existe, está normalizada, tiene al menos un usuario habilitado
     *    con transcription_access sobre el storage de la transcripción
     *    (intersectando user_keyword_storage si existe)
     *  - par (t.id, k.id) SIN hit previo
     *  - par (k.id, storage_id) tiene watermark con scanned_until NULL o
     *    t.finished_at >= scanned_until
     *
     * Si viene from/to o transcriptionId, son filtros adicionales (no se usan
     * para el watermark). El cursor global legacy fue retirado.
     */
    public function selectCandidates(array $opts = []): \Illuminate\Support\Collection
    {
        $settings = $this->settings();
        $limit = max(1, (int) ($opts['limit'] ?? self::DEFAULT_BATCH));

        $q = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->join('keywords as k', function ($join) {
                $join->whereNotNull('k.normalized')->where('k.normalized', '!=', '');
            })
            ->join('user_keyword as uk', 'uk.keyword_id', '=', 'k.id')
            ->join('users as u', 'u.id', '=', 'uk.user_id')
            ->join('user_alerts_inteligentes as uai', 'uai.user_id', '=', 'u.id')
            ->join('user_storages as us', function ($join) {
                $join->on('us.user_id', '=', 'u.id')
                    ->on('us.storage_provider_id', '=', 'f.storage_provider_id')
                    ->where('us.transcription_access', true);
            })
            ->leftJoin('user_keyword_storage as uks', function ($join) {
                $join->on('uks.user_id', '=', 'uk.user_id')
                    ->on('uks.keyword_id', '=', 'uk.keyword_id');
            })
            ->leftJoin('keyword_scan_watermarks as w', function ($join) {
                $join->on('w.keyword_id', '=', 'k.id')
                    ->on('w.storage_provider_id', '=', 'f.storage_provider_id');
            })
            ->leftJoin('segment_keyword_hits as h', function ($join) {
                $join->on('h.transcription_id', '=', 't.id')
                    ->on('h.keyword_id', '=', 'k.id');
            })
            ->where('t.state', 'done')
            ->where('t.generate_alerts', true)
            ->where('uai.enabled', true)
            // Alcance keyword→store: sin uks → todos los storages con acceso; con uks → solo ese.
            ->where(function ($q2) {
                $q2->whereNull('uks.user_id')
                    ->orWhere('uks.storage_provider_id', '=', DB::raw('f.storage_provider_id'));
            })
            // Cobertura watermark: scanned_until NULL → catch-up completo;
            // scanned_until no NULL → t.finished_at debe ser >= scanned_until.
            ->where(function ($q2) {
                $q2->whereNull('w.scanned_until')
                    ->orWhereColumn('t.finished_at', '>=', 'w.scanned_until');
            })
            // Sin hit previo para este par (transc, keyword).
            ->whereNull('h.id');

        // Ventana de re-escaneo: el cron automático la respeta; el catch-up
        // manual la omite con noWindow; rango explícito manda sobre la ventana.
        $hasExplicitRange = !empty($opts['from']) || !empty($opts['to']) || !empty($opts['transcriptionId']);
        if (empty($opts['noWindow']) && !$hasExplicitRange) {
            $windowHours = isset($opts['windowHours']) ? max(1, (int) $opts['windowHours']) : $settings['windowHours'];
            $q->where('t.finished_at', '>=', now()->subHours($windowHours));
        }

        if (!empty($opts['storageId'])) {
            $q->where('f.storage_provider_id', (int) $opts['storageId']);
        }
        if (!empty($opts['keywordId'])) {
            $q->where('k.id', (int) $opts['keywordId']);
        }
        if (!empty($opts['from'])) {
            $from = $this->normalizeRangeBound($opts['from'], true);
            if ($from) {
                $q->where('t.finished_at', '>=', $from);
            }
        }
        if (!empty($opts['to'])) {
            $to = $this->normalizeRangeBound($opts['to'], false);
            if ($to) {
                $q->where('t.finished_at', '<=', $to);
            }
        }
        if (!empty($opts['transcriptionId'])) {
            $q->where('t.id', (int) $opts['transcriptionId']);
        }
        if (!empty($opts['excludeIds']) && is_array($opts['excludeIds'])) {
            $q->whereNotIn('t.id', array_map('intval', $opts['excludeIds']));
        }

        return $q->orderBy('t.finished_at')
            ->orderBy('k.id')
            ->limit($limit)
            ->get([
                't.id as transcription_id',
                't.finished_at',
                'f.storage_provider_id',
                'k.id as keyword_id',
            ]);
    }

    /**
     * Plan mensual para catch-up histórico (fix-avisos-scan-by-months-no-saturation).
     *
     * Descubre el rango temporal de los datos con `min/max(finished_at)` y
     * deriva la lista de meses `YYYY-MM` que el worker debe procesar de
     * forma secuencial. El plan es un SNAPSHOT (no se actualiza con
     * transcripciones nuevas durante la corrida).
     *
     * Si no hay transcripciones con state='done' retorna []. Si el rango
     * cubre N meses, retorna N entradas 'pending'.
     *
     * Los rangos mensuales son semi-abiertos: [inicio_del_mes, inicio_del_mes_siguiente).
     * Para el mes actual, `to` es `now()` (no fin de mes).
     *
     * @return array<int, array{0:string, 1:string}> Lista de [YYYY-MM, status]
     *         donde status siempre es 'pending' al retornar del planning.
     */
    public function planMonths(array $opts = []): array
    {
        $row = DB::table('transcriptions')
            ->where('state', 'done')
            ->whereNotNull('finished_at')
            ->selectRaw('min(finished_at) as min_at, max(finished_at) as max_at')
            ->first();

        if (!$row || !$row->min_at || !$row->max_at) {
            return [];
        }

        try {
            $minAt = \Illuminate\Support\Carbon::parse($row->min_at);
            $maxAt = \Illuminate\Support\Carbon::parse($row->max_at);
        } catch (\Throwable $e) {
            Log::warning('avisos.scan.plan_months.parse_error', ['min' => $row->min_at, 'max' => $row->max_at, 'error' => $e->getMessage()]);
            return [];
        }

        $start = $minAt->copy()->startOfMonth();
        $end = $maxAt->copy()->startOfMonth();
        $months = [];

        while ($start->lessThanOrEqualTo($end)) {
            $months[] = [$start->format('Y-m'), 'pending'];
            $start->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Límites de un mes en formato semi-abierto [from, to) — usado por
     * el worker mensual para acotar `selectCandidates` con from/to.
     *
     * Para el mes actual retorna `now()` como `to`. Para meses pasados
     * retorna inicio del mes siguiente.
     *
     * @return array{from:string, to:string}
     */
    public function monthBounds(string $yearMonth): array
    {
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $yearMonth . '-01')->startOfMonth();
        $now = now();
        if ($start->copy()->endOfMonth()->lessThan($now)) {
            $end = $start->copy()->addMonthNoOverflow();
        } else {
            $end = $now->copy();
        }
        return [
            'from' => $start->format('Y-m-d H:i:s'),
            'to' => $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Corrida de un solo mes del plan mensual.
     *
     * Acota `from`/`to` al rango del mes y delega a `run()`. El resultado
     * es el mismo shape que retorna `run()`: {scanned, hitsNew, failed, attemptedIds, error}.
     *
     * El caller (worker) acumula los contadores en el cache state.
     */
    public function runMonth(string $yearMonth, array $opts = []): array
    {
        $bounds = $this->monthBounds($yearMonth);
        $opts['from'] = $bounds['from'];
        $opts['to'] = $bounds['to'];
        // Sobrescribe noWindow: el mes YA tiene ventana explícita.
        unset($opts['noWindow']);
        return $this->run($opts);
    }

    /**
     * Indicador barato de "este caso amerita el modo mensual" usado por
     * `runScanBackground` antes de lanzar el worker. Cheap query (índices
     * cubren min/max con state filter).
     */
    public function hasHistoricalCatchup(int $monthThreshold = 1): bool
    {
        $row = DB::table('transcriptions')
            ->where('state', 'done')
            ->whereNotNull('finished_at')
            ->selectRaw('min(finished_at) as min_at, max(finished_at) as max_at')
            ->first();
        if (!$row || !$row->min_at || !$row->max_at) {
            return false;
        }
        try {
            $minAt = \Illuminate\Support\Carbon::parse($row->min_at);
            $maxAt = \Illuminate\Support\Carbon::parse($row->max_at);
        } catch (\Throwable $e) {
            return false;
        }
        $months = ($maxAt->year - $minAt->year) * 12 + ($maxAt->month - $minAt->month) + 1;
        return $months >= $monthThreshold;
    }

    /**
     * Candidatos para UN par específico (keyword_id, storage_id): todas las
     * transcripciones del storage posteriores al watermark (o todas si NULL).
     * Usado por la UI "Activar histórico" o rewind.
     */
    public function selectCandidatesForPair(int $keywordId, int $storageId, int $limit = 200): \Illuminate\Support\Collection
    {
        $q = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->leftJoin('keyword_scan_watermarks as w', function ($join) use ($keywordId, $storageId) {
                $join->on('w.keyword_id', '=', DB::raw((string) $keywordId))
                    ->on('w.storage_provider_id', '=', DB::raw((string) $storageId));
            })
            ->leftJoin('segment_keyword_hits as h', function ($join) use ($keywordId) {
                $join->on('h.transcription_id', '=', 't.id')
                    ->on('h.keyword_id', '=', DB::raw((string) $keywordId));
            })
            ->where('t.state', 'done')
            ->where('t.generate_alerts', true)
            ->where('f.storage_provider_id', $storageId)
            ->where(function ($q2) {
                $q2->whereNull('w.scanned_until')
                    ->orWhereColumn('t.finished_at', '>=', 'w.scanned_until');
            })
            ->whereNull('h.id')
            ->orderBy('t.finished_at')
            ->limit($limit)
            ->get(['t.id as transcription_id', 't.finished_at']);

        return $q;
    }

    /**
     * Estimación sin límite de lote: para la confirmación de corridas masivas.
     */
    public function estimate(array $opts = []): int
    {
        $settings = $this->settings();
        $this->applyPreset($opts);

        $q = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->join('keywords as k', function ($join) {
                $join->whereNotNull('k.normalized')->where('k.normalized', '!=', '');
            })
            ->join('user_keyword as uk', 'uk.keyword_id', '=', 'k.id')
            ->join('users as u', 'u.id', '=', 'uk.user_id')
            ->join('user_alerts_inteligentes as uai', 'uai.user_id', '=', 'u.id')
            ->join('user_storages as us', function ($join) {
                $join->on('us.user_id', '=', 'u.id')
                    ->on('us.storage_provider_id', '=', 'f.storage_provider_id')
                    ->where('us.transcription_access', true);
            })
            ->leftJoin('user_keyword_storage as uks', function ($join) {
                $join->on('uks.user_id', '=', 'uk.user_id')
                    ->on('uks.keyword_id', '=', 'uk.keyword_id');
            })
            ->leftJoin('keyword_scan_watermarks as w', function ($join) {
                $join->on('w.keyword_id', '=', 'k.id')
                    ->on('w.storage_provider_id', '=', 'f.storage_provider_id');
            })
            ->leftJoin('segment_keyword_hits as h', function ($join) {
                $join->on('h.transcription_id', '=', 't.id')
                    ->on('h.keyword_id', '=', 'k.id');
            })
            ->where('t.state', 'done')
            ->where('t.generate_alerts', true)
            ->where('uai.enabled', true)
            ->where(function ($q2) {
                $q2->whereNull('uks.user_id')
                    ->orWhere('uks.storage_provider_id', '=', DB::raw('f.storage_provider_id'));
            })
            ->where(function ($q2) {
                $q2->whereNull('w.scanned_until')
                    ->orWhereColumn('t.finished_at', '>=', 'w.scanned_until');
            })
            ->whereNull('h.id');

        $hasExplicitRange = !empty($opts['from']) || !empty($opts['to']) || !empty($opts['transcriptionId']);
        if (empty($opts['noWindow']) && !$hasExplicitRange) {
            $windowHours = isset($opts['windowHours']) ? max(1, (int) $opts['windowHours']) : $settings['windowHours'];
            $q->where('t.finished_at', '>=', now()->subHours($windowHours));
        }
        if (!empty($opts['storageId'])) {
            $q->where('f.storage_provider_id', (int) $opts['storageId']);
        }
        if (!empty($opts['keywordId'])) {
            $q->where('k.id', (int) $opts['keywordId']);
        }
        if (!empty($opts['from'])) {
            $from = $this->normalizeRangeBound($opts['from'], true);
            if ($from) {
                $q->where('t.finished_at', '>=', $from);
            }
        }
        if (!empty($opts['to'])) {
            $to = $this->normalizeRangeBound($opts['to'], false);
            if ($to) {
                $q->where('t.finished_at', '<=', $to);
            }
        }
        if (!empty($opts['transcriptionId'])) {
            $q->where('t.id', (int) $opts['transcriptionId']);
        }

        return (int) $q->count();
    }

    /**
     * Cobertura paginada + filtros + búsqueda LIKE por texto de keyword.
     * Diseñada para alimentar la UI sin cargar miles de filas a memoria.
     */
    public function coveragePaginated(?int $storageId, string $q, int $perPage, int $page): array
    {
        $epoch = \App\Services\Ia\CacheEpoch::get();
        $builder = DB::table('keyword_scan_watermarks as w')
            ->join('keywords as k', 'k.id', '=', 'w.keyword_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'w.storage_provider_id')
            ->where('sp.transcription_enabled', true)
            ->orderBy('k.text')
            ->orderBy('sp.name');

        if ($storageId !== null) {
            $builder->where('w.storage_provider_id', $storageId);
        }
        if ($q !== '') {
            $builder->where('k.text', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
        }

        $paginator = $builder->paginate(perPage: $perPage, page: $page, columns: [
            'w.keyword_id',
            'w.storage_provider_id',
            'k.text as keyword_text',
            'sp.name as storage_name',
            'w.scanned_until',
            'w.last_scanned_at',
            'w.last_hit_at',
            'w.candidates_total',
            'w.hits_total',
            'w.last_scan_run_id',
        ]);

        $items = collect($paginator->items())->map(fn ($r) => [
            'keyword_id' => (int) $r->keyword_id,
            'storage_provider_id' => (int) $r->storage_provider_id,
            'keyword_text' => $r->keyword_text,
            'storage_name' => $r->storage_name,
            'scanned_until' => $r->scanned_until ? (string) $r->scanned_until : null,
            'last_scanned_at' => $r->last_scanned_at ? (string) $r->last_scanned_at : null,
            'last_hit_at' => $r->last_hit_at ? (string) $r->last_hit_at : null,
            'candidates_total' => (int) $r->candidates_total,
            'hits_total' => (int) $r->hits_total,
            'last_scan_run_id' => $r->last_scan_run_id ? (int) $r->last_scan_run_id : null,
            'pending_catchup' => $r->scanned_until === null,
        ])->all();

        return [
            'items' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'query' => ['storageId' => $storageId, 'q' => $q, 'per_page' => $perPage],
        ];
    }

    /**
     * Modo "full scan": barre TODAS las (keyword, storage) con watermark
     * atrasado, en lotes, con tope de tiempo. Reporta progreso. Reanudable.
     */
    public function runFullScan(array $opts = []): array
    {
        $settings = $this->settings();
        $batch = max(50, (int) ($opts['batch'] ?? self::DEFAULT_FULL_BATCH));
        $maxRuntime = max(30, (int) ($opts['maxRuntime'] ?? self::DEFAULT_FULL_MAX_RUNTIME));
        $dryRun = (bool) ($opts['dryRun'] ?? false);

        $startedAt = microtime(true);
        $totalScanned = 0;
        $totalHits = 0;
        $iterations = 0;
        $lastResult = null;

        while ((microtime(true) - $startedAt) < $maxRuntime) {
            $iterations++;
            $runOpts = [
                'origin' => 'manual',
                'limit' => $batch,
                'noWindow' => true,
                'dryRun' => $dryRun,
            ];
            if (!empty($opts['storageId'])) {
                $runOpts['storageId'] = (int) $opts['storageId'];
            }
            if (!empty($opts['keywordId'])) {
                $runOpts['keywordId'] = (int) $opts['keywordId'];
            }

            $result = $this->run($runOpts);
            $lastResult = $result;

            if ($dryRun) {
                return [
                    'mode' => 'full-dry-run',
                    'candidates' => $result['candidates'] ?? 0,
                    'iterations' => 1,
                    'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                ];
            }

            $totalScanned += (int) ($result['scanned'] ?? 0);
            $totalHits += (int) ($result['hitsNew'] ?? 0);

            if (($result['candidates'] ?? 0) < $batch) {
                break;
            }
            if ($result['status'] === 'failed') {
                break;
            }
        }

        return [
            'mode' => 'full',
            'iterations' => $iterations,
            'scanned' => $totalScanned,
            'hitsNew' => $totalHits,
            'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            'status' => $lastResult['status'] ?? 'unknown',
            'lastRunId' => $lastResult['runId'] ?? null,
        ];
    }

    /**
     * Estado de cobertura por keyword para la UI.
     * @deprecated since 2026-09-22 use coveragePaginated()
     */
    public function coverage(?int $storageId = null): array
    {
        $q = DB::table('keyword_scan_watermarks as w')
            ->join('keywords as k', 'k.id', '=', 'w.keyword_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'w.storage_provider_id')
            ->where('sp.transcription_enabled', true)
            ->orderBy('k.text')
            ->orderBy('sp.name');

        if ($storageId !== null) {
            $q->where('w.storage_provider_id', $storageId);
        }

        $rows = $q->get([
            'w.keyword_id',
            'w.storage_provider_id',
            'k.text as keyword_text',
            'sp.name as storage_name',
            'w.scanned_until',
            'w.last_scanned_at',
            'w.last_hit_at',
            'w.candidates_total',
            'w.hits_total',
            'w.last_scan_run_id',
        ])->map(fn ($r) => [
            'keyword_id' => (int) $r->keyword_id,
            'storage_provider_id' => (int) $r->storage_provider_id,
            'keyword_text' => $r->keyword_text,
            'storage_name' => $r->storage_name,
            'scanned_until' => $r->scanned_until ? (string) $r->scanned_until : null,
            'last_scanned_at' => $r->last_scanned_at ? (string) $r->last_scanned_at : null,
            'last_hit_at' => $r->last_hit_at ? (string) $r->last_hit_at : null,
            'candidates_total' => (int) $r->candidates_total,
            'hits_total' => (int) $r->hits_total,
            'last_scan_run_id' => $r->last_scan_run_id ? (int) $r->last_scan_run_id : null,
            'pending_catchup' => $r->scanned_until === null,
        ])->all();

        if (config('app.debug')) {
            @trigger_error(
                'AvisosScanService::coverage() is deprecated since 2026-09-22, use coveragePaginated()',
                E_USER_DEPRECATED,
            );
        }

        return $rows;
    }

    /**
     * Audit log paginado y filtrable. JOIN con keywords y storage_providers
     * para evitar N+1 en el cliente.
     */
    public function auditLog(array $filters, int $perPage, int $page): array
    {
        $q = DB::table('watermark_audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->leftJoin('keywords as k', 'k.id', '=', 'a.keyword_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'a.storage_id')
            ->orderByDesc('a.created_at');

        if (!empty($filters['actor'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['actor']) . '%';
            $q->where('u.username', 'ilike', $like);
        }
        if (!empty($filters['action'])) {
            $q->where('a.action', (string) $filters['action']);
        }
        if (!empty($filters['keyword'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['keyword']) . '%';
            $q->where('k.text', 'ilike', $like);
        }
        if (!empty($filters['storage'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['storage']) . '%';
            $q->where('sp.name', 'ilike', $like);
        }
        if (!empty($filters['since'])) {
            $q->where('a.created_at', '>=', (string) $filters['since']);
        }
        if (!empty($filters['until'])) {
            $q->where('a.created_at', '<=', (string) $filters['until']);
        }

        $paginator = $q->paginate(perPage: $perPage, page: $page, columns: [
            'a.id',
            'a.actor_user_id',
            'u.username as actor_username',
            'a.action',
            'a.keyword_id',
            'k.text as keyword_text',
            'a.storage_id',
            'sp.name as storage_name',
            'a.before_value',
            'a.after_value',
            'a.metadata',
            'a.created_at',
        ]);

        $items = collect($paginator->items())->map(fn ($r) => [
            'id' => (int) $r->id,
            'created_at' => (string) $r->created_at,
            'actor' => $r->actor_username ?: 'sistema',
            'action' => $r->action,
            'keyword_id' => $r->keyword_id ? (int) $r->keyword_id : null,
            'keyword_text' => $r->keyword_text,
            'storage_id' => $r->storage_id ? (int) $r->storage_id : null,
            'storage_name' => $r->storage_name,
            'before_value' => $r->before_value ? (string) $r->before_value : null,
            'after_value' => $r->after_value ? (string) $r->after_value : null,
            'metadata' => $r->metadata,
        ])->all();

        return [
            'items' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Audit log completo (sin paginar) para exportar CSV.
     */
    public function auditLogAll(array $filters): array
    {
        $q = DB::table('watermark_audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->leftJoin('keywords as k', 'k.id', '=', 'a.keyword_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'a.storage_id')
            ->orderByDesc('a.created_at');

        if (!empty($filters['actor'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['actor']) . '%';
            $q->where('u.username', 'ilike', $like);
        }
        if (!empty($filters['action'])) {
            $q->where('a.action', (string) $filters['action']);
        }
        if (!empty($filters['keyword'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['keyword']) . '%';
            $q->where('k.text', 'ilike', $like);
        }
        if (!empty($filters['storage'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['storage']) . '%';
            $q->where('sp.name', 'ilike', $like);
        }
        if (!empty($filters['since'])) {
            $q->where('a.created_at', '>=', (string) $filters['since']);
        }
        if (!empty($filters['until'])) {
            $q->where('a.created_at', '<=', (string) $filters['until']);
        }

        return $q->get([
            'a.id',
            'a.actor_user_id',
            'u.username as actor_username',
            'a.action',
            'a.keyword_id',
            'k.text as keyword_text',
            'a.storage_id',
            'sp.name as storage_name',
            'a.before_value',
            'a.after_value',
            'a.created_at',
        ])->map(fn ($r) => [
            'id' => (int) $r->id,
            'created_at' => (string) $r->created_at,
            'actor' => $r->actor_username ?: 'sistema',
            'action' => $r->action,
            'keyword_text' => $r->keyword_text,
            'storage_name' => $r->storage_name,
            'before' => $r->before_value ? (string) $r->before_value : '',
            'after' => $r->after_value ? (string) $r->after_value : '',
        ])->all();
    }

    /**
     * Rewind del watermark para "Activar histórico". $to=null → scanned_until=NULL.
     */
    public function rewindWatermark(int $keywordId, int $storageId, ?Carbon $to = null): void
    {
        DB::table('keyword_scan_watermarks')
            ->where('keyword_id', $keywordId)
            ->where('storage_provider_id', $storageId)
            ->update([
                'scanned_until' => $to?->toDateTimeString(),
                'updated_at' => now(),
            ]);

        Log::info('avisos.scan.watermark_rewind', [
            'keyword_id' => $keywordId,
            'storage_provider_id' => $storageId,
            'scanned_until' => $to?->toIso8601String(),
        ]);
    }

    /** Settings vigentes con defaults y mínimos aplicados. */
    public function settings(): array
    {
        return [
            'enabled' => (bool) \App\Models\SystemSetting::get('avisos_scan_enabled', false),
            'intervalMinutes' => max(5, (int) \App\Models\SystemSetting::get('avisos_scan_interval_minutes', 30)),
            'windowHours' => max(1, (int) \App\Models\SystemSetting::get('avisos_scan_window_hours', 72)),
            'fullBatch' => max(50, (int) \App\Models\SystemSetting::get('avisos_scan_full_batch', self::DEFAULT_FULL_BATCH)),
            'fullMaxRuntimeSeconds' => max(30, (int) \App\Models\SystemSetting::get('avisos_scan_full_max_runtime_seconds', self::DEFAULT_FULL_MAX_RUNTIME)),
        ];
    }

    /** Persiste settings validando mínimos. Retorna los valores finales. */
    public function saveSettings(array $input): array
    {
        $interval = max(5, (int) ($input['intervalMinutes'] ?? 30));
        $window = max(1, (int) ($input['windowHours'] ?? 72));
        $enabled = (bool) ($input['enabled'] ?? false);
        $fullBatch = max(50, (int) ($input['fullBatch'] ?? self::DEFAULT_FULL_BATCH));
        $fullMaxRuntime = max(30, (int) ($input['fullMaxRuntimeSeconds'] ?? self::DEFAULT_FULL_MAX_RUNTIME));

        \App\Models\SystemSetting::set('avisos_scan_enabled', $enabled);
        \App\Models\SystemSetting::set('avisos_scan_interval_minutes', $interval);
        \App\Models\SystemSetting::set('avisos_scan_window_hours', $window);
        \App\Models\SystemSetting::set('avisos_scan_full_batch', $fullBatch);
        \App\Models\SystemSetting::set('avisos_scan_full_max_runtime_seconds', $fullMaxRuntime);

        return $this->settings();
    }

    /** ¿Toca correr el escaneo automático en este tick? (cron) */
    public function shouldRunCron(): bool
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            return false;
        }

        $last = DB::table('avisos_scan_runs')
            ->where('origin', 'cron')
            ->where('status', 'success')
            ->orderByDesc('id')
            ->value('finished_at');

        if (!$lastRun = $last ? Carbon::parse($last) : null) {
            return true;
        }

        return abs(now()->diffInMinutes($lastRun)) >= $settings['intervalMinutes'];
    }

    public function enforceRangeExclusivity(array $opts): array
    {
        return $this->applyCatchupGuard($opts);
    }

    /**
     * Guardia de catch-up: el cron automático JAMÁS drena el histórico
     * (sin ventana). Solo el disparo manual puede usar noWindow.
     */
    private function applyCatchupGuard(array $opts): array
    {
        if (!empty($opts['noWindow']) && ($opts['origin'] ?? 'manual') === 'cron') {
            unset($opts['noWindow']);
            Log::warning('avisos.scan.catchup_rejected', [
                'reason' => 'El cron automático nunca ejecuta catch-up (sin ventana)',
            ]);
        }

        $hasExplicitRange = !empty($opts['from']) || !empty($opts['to']) || !empty($opts['transcriptionId']);
        if (!empty($opts['noWindow']) && $hasExplicitRange) {
            Log::warning('avisos.scan.catchup_dropped_for_range', [
                'from' => $opts['from'] ?? null,
                'to' => $opts['to'] ?? null,
            ]);
            $opts['noWindow'] = false;
        }

        return $opts;
    }

    public function filterOptsFromRequest(\Illuminate\Http\Request $request): ?array
    {
        $preset = $request->input('preset');
        $from = $request->input('from');
        $to = $request->input('to');
        $storageId = $request->input('storageId');
        $keywordId = $request->input('keywordId');
        $noWindow = $request->boolean('noWindow') || $request->boolean('no_window');
        $full = $request->boolean('full');

        if (!$preset && !$from && !$to && !$noWindow && !$full && !$keywordId) {
            return null;
        }

        $opts = [
            'storageId' => $storageId ?: null,
            'keywordId' => $keywordId ?: null,
        ];
        if ($noWindow) {
            $opts['noWindow'] = true;
            return $opts;
        }
        if ($preset) {
            $opts['preset'] = $preset;
            return $opts;
        }
        if ($from) {
            $opts['from'] = $from;
        }
        if ($to) {
            $opts['to'] = $to;
        }
        return $opts;
    }

    private function applyPreset(array &$opts): array
    {
        $preset = $opts['preset'] ?? null;
        if (!$preset || !empty($opts['from']) || !empty($opts['to'])) {
            return ['preset' => null, 'range' => null];
        }

        $range = $this->resolvePreset($preset);
        if (!$range) {
            return ['preset' => null, 'range' => null];
        }

        $opts['from'] = $range['from'];
        $opts['to'] = $range['to'];

        return [
            'preset' => $preset,
            'range' => [
                'from' => $range['from']->toIso8601String(),
                'to' => $range['to']->toIso8601String(),
            ],
        ];
    }

    public function lastRun(): ?object
    {
        return DB::table('avisos_scan_runs')->orderByDesc('id')->first();
    }

    public function recentRuns(int $limit = 10): array
    {
        return DB::table('avisos_scan_runs')
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'origin' => $r->origin,
                'status' => $r->status,
                'scanned' => (int) $r->transcriptions_scanned,
                'hits_new' => (int) $r->hits_new,
                'failed' => (int) $r->failed_count,
                'duration_ms' => (int) $r->duration_ms,
                'error' => $r->error ? json_decode($r->error, true) : null,
                'started_at' => (string) $r->started_at,
                'finished_at' => (string) $r->finished_at,
                'params' => $r->params ? json_decode($r->params, true) : null,
            ])->all();
    }

    private function paramsSummary(array $opts): array
    {
        $from = $opts['from'] ?? null;
        $to = $opts['to'] ?? null;

        return [
            'storageId' => isset($opts['storageId']) ? (int) $opts['storageId'] : null,
            'keywordId' => isset($opts['keywordId']) ? (int) $opts['keywordId'] : null,
            'from' => $from instanceof Carbon ? $from->toDateTimeString() : $from,
            'to' => $to instanceof Carbon ? $to->toDateTimeString() : $to,
            'preset' => $opts['preset'] ?? null,
            'transcriptionId' => isset($opts['transcriptionId']) ? (int) $opts['transcriptionId'] : null,
            'limit' => (int) ($opts['limit'] ?? self::DEFAULT_BATCH),
            'force' => (bool) ($opts['force'] ?? false),
            'windowHours' => isset($opts['windowHours']) ? (int) $opts['windowHours'] : null,
            'noWindow' => (bool) ($opts['noWindow'] ?? false),
        ];
    }
}
