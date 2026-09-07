<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gobernanza del escaneo de menciones (avisos-scan-configuration).
 *
 * Única entrada para corridas de escaneo — cron y manual comparten aquí.
 * Reutiliza KeywordMatcher::run() (idempotente: UNIQUE triple + insertOrIgnore
 * + guardia "ya tiene hits → return 0"), por lo que el solape con el pipeline
 * o entre corridas es inofensivo.
 *
 * Separación estricta scan→entrega: este servicio JAMÁS llama a
 * AlertDispatcher ni crea alert_deliveries/correos. La entrega es trabajo
 * exclusivo de avisos:deliver-alerts (cadencia, techo, rate limiter).
 *
 * Settings (SystemSetting): avisos_scan_enabled (bool, default false),
 * avisos_scan_interval_minutes (int, min 5, default 30),
 * avisos_scan_window_hours (int, min 1, default 72).
 */
class AvisosScanService
{
    public const DEFAULT_BATCH = 50;

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
                    ->map(fn ($t) => ['id' => $t->id, 'finished_at' => (string) $t->finished_at])
                    ->values()->all(),
            ];
        }

        try {
            $runId = DB::table('avisos_scan_runs')->insertGetId([
                'origin' => $origin,
                'status' => 'success', // provisional; se corrige al finalizar
                'params' => json_encode($this->paramsSummary($opts)),
                'started_at' => $startedAt,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);
        } catch (\Throwable $e) {
            // La corrida no debe depender de la tabla de corridas.
            Log::error('avisos.scan.run_register_failed', ['error' => $e->getMessage()]);
        }

        $scanStart = microtime(true);
        $scanned = 0;
        $hitsNew = 0;
        $failed = 0;
        $errors = [];

        try {
            $candidates = $this->selectCandidates($opts);

            foreach ($candidates as $candidate) {
                try {
                    // selectCandidates devuelve stdClass del query builder;
                    // el matcher necesita el modelo Eloquent (relaciones).
                    $transcription = Transcription::findOrFail($candidate->id);
                    $hits = $this->scanOne($transcription, (bool) ($opts['force'] ?? false));
                    $scanned++;
                    $hitsNew += max(0, $hits);
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = ['transcription_id' => $candidate->id, 'error' => mb_substr($e->getMessage(), 0, 200)];
                    Log::error('avisos.scan.transcription_error', [
                        'transcription_id' => $candidate->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $status = $failed > 0 && $scanned === 0 ? 'failed' : 'success';
            $durationMs = (int) ((microtime(true) - $scanStart) * 1000);

            // Avance del cursor de drenaje (solo sin rango explícito): la
            // próxima corrida retoma DESPUÉS del último candidato procesado —
            // las escaneadas sin hits no bloquean el avance.
            if (empty($opts['from']) && empty($opts['to']) && empty($opts['transcriptionId'])) {
                $maxFinished = $candidates->max('finished_at');
                if ($maxFinished) {
                    \App\Models\SystemSetting::set('avisos_scan_cursor', Carbon::parse($maxFinished)->addSecond()->toDateTimeString());
                }
            }

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
            ]);

            return [
                'mode' => 'run', 'runId' => $runId, 'candidates' => $candidates->count(),
                'scanned' => $scanned, 'hitsNew' => $hitsNew, 'failed' => $failed,
                'durationMs' => $durationMs, 'status' => $status,
                'error' => $errors ? array_slice($errors, 0, 5) : null,
                // IDs ya intentados: el drenaje secuencial los excluye de la
                // siguiente tanda para que un rango/preset termine y no re-visite
                // transcripciones sin hits (que siguen siendo candidatas).
                'attemptedIds' => $candidates->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
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
     * Escanea UNA transcripción. Con force borra sus hits previos primero
     * (re-escaneo explícito: el matcher luego los regenera idempotentemente).
     */
    public function scanOne(Transcription $transcription, bool $force = false): int
    {
        if ($force) {
            DB::table('segment_keyword_hits')
                ->where('transcription_id', $transcription->id)
                ->delete();
        }

        return app(KeywordMatcher::class)->run($transcription);
    }

    /**
     * Candidatos: transcripciones done con generate_alerts=true, SIN ningún
     * hit, dentro de la ventana de re-escaneo. Consulta acotada (LIMIT duro,
     * índice por transcription_id en segment_keyword_hits).
     *
     * Cursor de drenaje (SystemSetting avisos_scan_cursor): el modo drenaje
     * (sin from/to/transcriptionId explícitos) parte del cursor y lo avanza
     * al terminar la corrida — así las transcripciones escaneadas sin hits
     * no bloquean el avance (ya se intentaron).
     */
    public function selectCandidates(array $opts = []): \Illuminate\Support\Collection
    {
        $settings = $this->settings();
        $limit = max(1, (int) ($opts['limit'] ?? self::DEFAULT_BATCH));

        $q = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->leftJoin('segment_keyword_hits as h', 'h.transcription_id', '=', 't.id')
            ->where('t.state', 'done')
            ->where('t.generate_alerts', true)
            ->whereNull('h.id');

        // Ventana de re-escaneo: se omite con noWindow (catch-up manual) o
        // con from/to explícitos. El cron automático JAMÁS usa noWindow.
        $hasExplicitRange = !empty($opts['from']) || !empty($opts['to']) || !empty($opts['transcriptionId']);
        if (empty($opts['noWindow']) && empty($opts['from']) && empty($opts['to']) && empty($opts['transcriptionId'])) {
            $windowHours = isset($opts['windowHours']) ? max(1, (int) $opts['windowHours']) : $settings['windowHours'];
            $q->where('t.finished_at', '>=', now()->subHours($windowHours));
            // Cursor: retomar después de lo ya escaneado en drenaje previo.
            $cursor = \App\Models\SystemSetting::get('avisos_scan_cursor');
            if ($cursor) {
                $q->where('t.finished_at', '>=', $cursor);
            }
        } elseif (empty($opts['from']) && empty($opts['to']) && empty($opts['transcriptionId'])) {
            // noWindow sin rango explícito: catch-up; el cursor también aplica
            // para no re-visitar lo ya drenado.
            $cursor = \App\Models\SystemSetting::get('avisos_scan_cursor');
            if ($cursor) {
                $q->where('t.finished_at', '>=', $cursor);
            }
        }

        if (!empty($opts['storageId'])) {
            $q->where('f.storage_provider_id', (int) $opts['storageId']);
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
        // Drenaje secuencial: excluir transcripciones ya intentadas en tandas
        // previas de la misma secuencia manual (un rango sin hits termina).
        if (!empty($opts['excludeIds']) && is_array($opts['excludeIds'])) {
            $q->whereNotIn('t.id', array_map('intval', $opts['excludeIds']));
        }

        return $q->orderBy('t.finished_at')
            ->limit($limit)
            ->get(['t.id', 't.finished_at', 'f.storage_provider_id']);
    }

    /**
     * Estimación sin límite de lote: para la confirmación de corridas masivas.
     * Aplica el preset (8h/24h/...) si viene, igual que run().
     */
    public function estimate(array $opts = []): int
    {
        $settings = $this->settings();
        $this->applyPreset($opts);

        $q = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->leftJoin('segment_keyword_hits as h', 'h.transcription_id', '=', 't.id')
            ->where('t.state', 'done')
            ->where('t.generate_alerts', true)
            ->whereNull('h.id');

        // Misma regla de ventana que selectCandidates (noWindow omite).
        if (empty($opts['noWindow']) && empty($opts['from']) && empty($opts['to']) && empty($opts['transcriptionId'])) {
            $windowHours = isset($opts['windowHours']) ? max(1, (int) $opts['windowHours']) : $settings['windowHours'];
            $q->where('t.finished_at', '>=', now()->subHours($windowHours));
        }

        if (!empty($opts['storageId'])) {
            $q->where('f.storage_provider_id', (int) $opts['storageId']);
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

    /** Settings vigentes con defaults y mínimos aplicados. */
    public function settings(): array
    {
        return [
            'enabled' => (bool) \App\Models\SystemSetting::get('avisos_scan_enabled', false),
            'intervalMinutes' => max(5, (int) \App\Models\SystemSetting::get('avisos_scan_interval_minutes', 30)),
            'windowHours' => max(1, (int) \App\Models\SystemSetting::get('avisos_scan_window_hours', 72)),
        ];
    }

    /** Persiste settings validando mínimos. Retorna los valores finales. */
    public function saveSettings(array $input): array
    {
        $interval = max(5, (int) ($input['intervalMinutes'] ?? 30));
        $window = max(1, (int) ($input['windowHours'] ?? 72));
        $enabled = (bool) ($input['enabled'] ?? false);

        \App\Models\SystemSetting::set('avisos_scan_enabled', $enabled);
        \App\Models\SystemSetting::set('avisos_scan_interval_minutes', $interval);
        \App\Models\SystemSetting::set('avisos_scan_window_hours', $window);

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
            return true; // nunca ha corrido como cron
        }

        // diffInMinutes es SIGNED en Carbon 3: past → negativo. Usar valor
        // absoluto o la comparación siempre da false tras la 1ª corrida y el
        // cron automático muere en silencio (bug detectado 2026-09-07).
        return abs(now()->diffInMinutes($lastRun)) >= $settings['intervalMinutes'];
    }

    /**
     * Exclusividad noWindow ↔ rango explícito, visible para el controller:
     * si un disparo trae ambos, el rango gana. Retorna los opts efectivos.
     */
    public function enforceRangeExclusivity(array $opts): array
    {
        return $this->applyCatchupGuard($opts);
    }

    /**
     * Guardia de catch-up: el cron automático JAMÁS drena el histórico
     * (sin ventana). Solo el disparo manual puede usar noWindow. Además,
     * noWindow y rango explícito son excluyentes: si llegan ambos, el rango
     * gana (más acotado = más seguro) y se deja constancia.
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

    /**
     * Extrae opts de filtro de una request HTTP (query o body) para estimados:
     * preset explícito gana, luego from/to; sin nada de eso retorna null para
     * que el llamador decida (ventana global o noWindow).
     */
    public function filterOptsFromRequest(\Illuminate\Http\Request $request): ?array
    {
        $preset = $request->input('preset');
        $from = $request->input('from');
        $to = $request->input('to');
        $storageId = $request->input('storageId');
        $noWindow = $request->boolean('noWindow') || $request->boolean('no_window');

        if (!$preset && !$from && !$to && !$noWindow) {
            return null;
        }

        $opts = ['storageId' => $storageId ?: null];
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

    /**
     * Aplica el preset de ventana al opts: traduce preset → from/to concretos
     * (solo si no vienen rangos explícitos, que tienen prioridad). Retorna el
     * preset y el rango resuelto para auditoría en el log.
     */
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

    /** Última corrida registrada (cualquier origen) para la sub-ventana. */
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
                // Auditoría de la ventana efectiva (avisos-scan-time-presets)
                'params' => $r->params ? json_decode($r->params, true) : null,
            ])->all();
    }

    /** Resumen de params para la corrida (sin datos ruidosos). */
    private function paramsSummary(array $opts): array
    {
        $from = $opts['from'] ?? null;
        $to = $opts['to'] ?? null;

        return [
            'storageId' => isset($opts['storageId']) ? (int) $opts['storageId'] : null,
            'from' => $from instanceof Carbon ? $from->toDateTimeString() : $from,
            'to' => $to instanceof Carbon ? $to->toDateTimeString() : $to,
            'preset' => $opts['preset'] ?? null,
            'transcriptionId' => isset($opts['transcriptionId']) ? (int) $opts['transcriptionId'] : null,
            'limit' => (int) ($opts['limit'] ?? self::DEFAULT_BATCH),
            'force' => (bool) ($opts['force'] ?? false),
            'windowHours' => isset($opts['windowHours']) ? (int) $opts['windowHours'] : null,
        ];
    }
}