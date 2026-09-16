<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\CorrectionBulkAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Triage en capas de correcciones pending.
 *
 * Cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage.
 *
 * El extractor `ai-coherence-learn` venía emitiendo 6.000+ reglas de
 * 4-6 palabras (segmentos enteros en lugar de frases cortas) y sin
 * `source_segment_id` poblado. Esto dejó la cola `/ia/correcciones` con
 * 6.099 pending inmanejables para moderación humana.
 *
 * Este servicio aplica 6 capas de descarte para dejar una cola chica y
 * audit-able, con opción de auto-aprobar solo las que `EnEsRuleClassifier`
 * marque como KEEP (variantes ortográficas seguras), con la misma red de
 * seguridad de undo de 5 min que el bulk-moderation existente.
 *
 * Capas:
 *  1. Longitud >4 palabras → descartar
 *  2. source_segment_id NULL → descartar
 *  3. Duplicado contra approved (mismo wrong_normalized) → descartar
 *  4. Marca / nombre propio (LlmCorrectionSuggester::looksLikeBrandOrProperNoun)
 *  5. EnEsRuleClassifier NOISE / QUARANTINE (lo que el 2026-08-11 desprogramó)
 *  6. WarmCorrectionContext (solo carga contexto para las supervivientes)
 *
 * Uso:
 *   $result = $service->run(
 *       dryRun: true,
 *       autoApproveKeep: false,
 *       max: 10000,
 *       daysBack: null,
 *       by: $admin,
 *   );
 *   // devuelve ['run_id' => ..., 'layers' => [...], ...]
 */
class CorrectionTriageService
{
    private const CACHE_TTL_HOURS = 4;

    private const REJECTED_REASON_TRIAGE = 'triage:short_or_no_segment';
    private const REJECTED_REASON_DUP = 'triage:duplicate_of_approved';
    private const REJECTED_REASON_BRAND = 'triage:brand_or_proper_noun';
    private const REJECTED_REASON_CLASSIFIER = 'triage:classifier_noise_quarantine';

    /**
     * Modos:
     *  - dry_run: solo lectura, no escribe nada.
     *  - apply: descarta las 5 capas sin auto-aprobar. Deja supervivientes en pending.
     *  - apply_with_auto_approve_keep: descarta + auto-aprueba las KEEP vía bulkApprove.
     */
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_APPLY = 'apply';
    public const MODE_APPLY_AUTO_APPROVE_KEEP = 'apply_with_auto_approve_keep';

    public function run(
        bool $dryRun,
        bool $autoApproveKeep,
        int $max,
        ?int $daysBack,
        User $by,
    ): array {
        $runId = 'triage_' . time() . '_' . substr(md5((string) mt_rand()), 0, 8);
        $startedAt = now();
        $cacheKey = "corrections_triage:{$runId}";

        $mode = match (true) {
            $dryRun => self::MODE_DRY_RUN,
            $autoApproveKeep => self::MODE_APPLY_AUTO_APPROVE_KEEP,
            default => self::MODE_APPLY,
        };

        // Anti-duplicado: una sola corrida activa a la vez (mismo patrón
        // que CorreccionesController::applyRetroactive).
        $activePointer = Cache::get('corrections_triage:active');
        if (is_array($activePointer) && !empty($activePointer['runId'])) {
            $activeId = (string) $activePointer['runId'];
            $activeState = Cache::get("corrections_triage:{$activeId}");
            $orphan = !$activeState
                || in_array($activeState['status'] ?? null, ['done', 'error'], true)
                || (
                    ($activeState['status'] ?? null) === 'running'
                    && empty($activeState['finished_at'])
                    && CarbonImmutable::parse($activeState['started_at'] ?? $startedAt)->lt(now()->subMinutes(15))
                );
            if (!$orphan) {
                throw new \RuntimeException("Ya hay un triage activo (runId={$activeId}). Espera a que termine o usa el endpoint de status.");
            }
            Cache::forget('corrections_triage:active');
            if ($activeState && is_array($activeState)) {
                Cache::forget("corrections_triage:{$activeId}");
            }
        }
        Cache::put('corrections_triage:active', ['runId' => $runId, 'started_at' => $startedAt->toIso8601String()], now()->addHours(self::CACHE_TTL_HOURS));

        $state = [
            'run_id' => $runId,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => null,
            'layers' => [],
            'survivors_for_review' => 0,
            'auto_approve_candidates' => 0,
            'bulk_action_id' => null,
            'undo_expires_at' => null,
            'error_message' => null,
        ];
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        try {
            $this->executeTriage($state, $cacheKey, $max, $daysBack, $dryRun, $autoApproveKeep, $by);

            $final = Cache::get($cacheKey);
            return $final;
        } catch (\Throwable $e) {
            Log::error('CorrectionTriageService: fallo', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            Cache::put($cacheKey, array_merge($state, [
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]), now()->addHours(self::CACHE_TTL_HOURS));
            Cache::forget('corrections_triage:active');
            throw $e;
        }
    }

    private function executeTriage(
        array $state,
        string $cacheKey,
        int $max,
        ?int $daysBack,
        bool $dryRun,
        bool $autoApproveKeep,
        User $by,
    ): void {
        // Capa 1+2: longitud >4 palabras o source_segment_id NULL → descartar.
        // Combina las dos capas en una sola query SQL para no duplicar el recorrido.
        $survivorsIds = $this->layerLongOrOrphan($state, $cacheKey, $max, $daysBack, $dryRun);
        $this->checkpoint($cacheKey, $state);

        // Capa 3: duplicado contra approved (mismo wrong_normalized).
        $survivorsIds = $this->layerDuplicateOfApproved($survivorsIds, $state, $cacheKey, $dryRun);
        $this->checkpoint($cacheKey, $state);

        // Capa 4: brand / proper noun (PHP-side, requiere LlmCorrectionSuggester).
        $survivorsIds = $this->layerBrand($survivorsIds, $state, $cacheKey, $dryRun);
        $this->checkpoint($cacheKey, $state);

        // Capa 5: EnEsRuleClassifier NOISE / QUARANTINE; separa KEEP y REVIEW.
        [$survivorsIds, $keepIds, $reviewIds] = $this->layerClassifier($survivorsIds, $state, $cacheKey, $dryRun);
        $state['survivors_for_review'] = count($reviewIds);
        $state['auto_approve_candidates'] = count($keepIds);
        $this->checkpoint($cacheKey, $state);

        // Capa 6: WarmCorrectionContext solo para las que llegaron hasta aquí.
        if (!empty($survivorsIds)) {
            $this->layerWarmContext(array_merge($keepIds, $reviewIds), $state, $cacheKey);
            $this->checkpoint($cacheKey, $state);
        }

        // Auto-approve de KEEP via bulkApprove existente (con undo de 5 min).
        if (!$dryRun && $autoApproveKeep && !empty($keepIds)) {
            $bulkResult = app(CorrectionService::class)->bulkApprove($keepIds, $by);
            $state['bulk_action_id'] = $bulkResult['bulk_action_id'] ?? null;
            $state['undo_expires_at'] = $bulkResult['undo_expires_at'] ?? null;
            $this->checkpoint($cacheKey, $state);
        }

        $state['status'] = 'done';
        $state['finished_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
        Cache::forget('corrections_triage:active');
    }

    private function checkpoint(string $cacheKey, array $state): void
    {
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
    }

    /**
     * Lee el cache state de un run. Devuelve null si no existe (expirado o desconocido).
     */
    public function getStatus(string $runId): ?array
    {
        return Cache::get("corrections_triage:{$runId}");
    }

    /**
     * Capa 1+2: descarta pendientes con `wrong_text` ≤4 palabras o sin segmento origen.
     *
     * Política invertida por feedback admin 2026-08-18: las reglas de 1-4 palabras
     * son find/replace demasiado genérico que ignora contexto y produce espanglish
     * (lesson learned del 2026-08-15-en-es-mix-miner-prune-open-strategy:
     * 2.465 reglas palabra-por-palabra auto-aprobadas, 205.000 aplicaciones
     * dañinas). Solo sobreviven reglas de 5+ palabras, que tienen suficiente
     * contexto para preservar tono/intención/registro del segmento original.
     *
     * Si es dry-run, cuenta sin escribir; si no, marca como rejected con
     * motivo trazable.
     *
     * @return array<int, int>  IDs de las pendientes que sobreviven
     */
    private function layerLongOrOrphan(array &$state, string $cacheKey, int $max, ?int $daysBack, bool $dryRun): array
    {
        $q = Correction::query()->where('status', Correction::STATUS_PENDING);
        if ($daysBack !== null && $daysBack > 0) {
            $q->where('created_at', '>=', now()->subDays($daysBack));
        }

        $candidates = (clone $q)->orderByDesc('id')->limit($max)->get(['id', 'wrong_text', 'source_segment_id']);

        $discarded = 0;
        $keepIds = [];
        foreach ($candidates as $c) {
            $wc = self::wordCount((string) $c->wrong_text);
            // Política: solo reglas con 5+ palabras (wrong_text) sobreviven.
            // También se descartan las que no tienen segmento auditable.
            if ($c->source_segment_id === null || $wc <= 4) {
                $discarded++;
            } else {
                $keepIds[] = (int) $c->id;
            }
        }

        if (!$dryRun && $discarded > 0) {
            $idsToReject = [];
            foreach ($candidates as $c) {
                $wc = self::wordCount((string) $c->wrong_text);
                if ($c->source_segment_id === null || $wc <= 4) {
                    $idsToReject[] = (int) $c->id;
                }
            }
            if (!empty($idsToReject)) {
                foreach (array_chunk($idsToReject, 500) as $chunk) {
                    DB::table('corrections')
                        ->whereIn('id', $chunk)
                        ->where('status', Correction::STATUS_PENDING)
                        ->update([
                            'status' => Correction::STATUS_REJECTED,
                            'rejected_reason' => self::REJECTED_REASON_TRIAGE,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        $state['layers'][] = [
            'name' => 'short_or_no_segment',
            'reason' => 'wrong_text ≤4 palabras (demasiado genérico, riesgo de espanglish) o source_segment_id NULL',
            'discarded' => $discarded,
            'survivors' => count($keepIds),
        ];

        return $keepIds;
    }

    /**
     * Capa 3: descarta pendientes cuyo wrong_normalized ya tiene una regla approved.
     */
    private function layerDuplicateOfApproved(array $candidates, array &$state, string $cacheKey, bool $dryRun): array
    {
        if (empty($candidates)) {
            return [];
        }

        $corrections = Correction::whereIn('id', $candidates)->get(['id', 'wrong_normalized']);
        $normsWithApproved = Correction::approved()
            ->whereIn('wrong_normalized', $corrections->pluck('wrong_normalized')->unique()->filter()->values())
            ->pluck('wrong_normalized')
            ->flip();

        $discarded = 0;
        $keepIds = [];
        $idsToReject = [];
        foreach ($corrections as $c) {
            if (isset($normsWithApproved[$c->wrong_normalized])) {
                $discarded++;
                $idsToReject[] = (int) $c->id;
            } else {
                $keepIds[] = (int) $c->id;
            }
        }

        if (!$dryRun && !empty($idsToReject)) {
            foreach (array_chunk($idsToReject, 500) as $chunk) {
                DB::table('corrections')
                    ->whereIn('id', $chunk)
                    ->where('status', Correction::STATUS_PENDING)
                    ->update([
                        'status' => Correction::STATUS_REJECTED,
                        'rejected_reason' => self::REJECTED_REASON_DUP,
                        'updated_at' => now(),
                    ]);
            }
        }

        $state['layers'][] = [
            'name' => 'duplicate_of_approved',
            'reason' => 'mismo wrong_normalized ya en approved',
            'discarded' => $discarded,
            'survivors' => count($keepIds),
        ];

        return $keepIds;
    }

    /**
     * Capa 4: descarta si LlmCorrectionSuggester detecta brand/proper noun.
     */
    private function layerBrand(array $candidates, array &$state, string $cacheKey, bool $dryRun): array
    {
        if (empty($candidates)) {
            return [];
        }

        $corrections = Correction::whereIn('id', $candidates)->get(['id', 'wrong_text']);
        $classifier = app(\App\Services\Ia\LlmCorrectionSuggester::class);

        $discarded = 0;
        $keepIds = [];
        $idsToReject = [];
        foreach ($corrections as $c) {
            if ($classifier->looksLikeBrandOrProperNoun((string) $c->wrong_text)) {
                $discarded++;
                $idsToReject[] = (int) $c->id;
            } else {
                $keepIds[] = (int) $c->id;
            }
        }

        if (!$dryRun && !empty($idsToReject)) {
            foreach (array_chunk($idsToReject, 500) as $chunk) {
                DB::table('corrections')
                    ->whereIn('id', $chunk)
                    ->where('status', Correction::STATUS_PENDING)
                    ->update([
                        'status' => Correction::STATUS_REJECTED,
                        'rejected_reason' => self::REJECTED_REASON_BRAND,
                        'updated_at' => now(),
                    ]);
            }
        }

        $state['layers'][] = [
            'name' => 'brand_or_proper_noun',
            'reason' => 'marcas, empresas, personas detectadas',
            'discarded' => $discarded,
            'survivors' => count($keepIds),
        ];

        return $keepIds;
    }

    /**
     * Capa 5: EnEsRuleClassifier descarta NOISE y QUARANTINE; KEEP y REVIEW sobreviven.
     *
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}  [all, keep, review]
     */
    private function layerClassifier(array $candidates, array &$state, string $cacheKey, bool $dryRun): array
    {
        if (empty($candidates)) {
            return [[], [], []];
        }

        $corrections = Correction::whereIn('id', $candidates)->get(['id', 'wrong_text', 'correct_text']);
        $classifier = app(\App\Services\Ia\EnEsRuleClassifier::class);

        $discarded = 0;
        $keepIds = [];
        $reviewIds = [];
        $idsToReject = [];
        foreach ($corrections as $c) {
            $bucket = $classifier->classify((string) $c->wrong_text, (string) $c->correct_text)['bucket'];
            switch ($bucket) {
                case \App\Services\Ia\EnEsRuleClassifier::KEEP:
                    $keepIds[] = (int) $c->id;
                    break;
                case \App\Services\Ia\EnEsRuleClassifier::REVIEW:
                    $reviewIds[] = (int) $c->id;
                    break;
                default: // NOISE / QUARANTINE
                    $discarded++;
                    $idsToReject[] = (int) $c->id;
            }
        }

        if (!$dryRun && !empty($idsToReject)) {
            foreach (array_chunk($idsToReject, 500) as $chunk) {
                DB::table('corrections')
                    ->whereIn('id', $chunk)
                    ->where('status', Correction::STATUS_PENDING)
                    ->update([
                        'status' => Correction::STATUS_REJECTED,
                        'rejected_reason' => self::REJECTED_REASON_CLASSIFIER,
                        'updated_at' => now(),
                    ]);
            }
        }

        $state['layers'][] = [
            'name' => 'classifier_noise_quarantine',
            'reason' => 'EnEsRuleClassifier marca como ruido o traducción EN→ES',
            'discarded' => $discarded,
            'survivors_keep' => count($keepIds),
            'survivors_review' => count($reviewIds),
        ];

        return [array_merge($keepIds, $reviewIds), $keepIds, $reviewIds];
    }

    /**
     * Capa 6: recalienta caché de contexto para sobrevivientes.
     * Solo lectura — no modifica la BD. errors se loggean por el Finder.
     */
    private function layerWarmContext(array $survivors, array &$state, string $cacheKey): void
    {
        $finder = app(\App\Services\Ia\CorrectionContextFinder::class);
        $warmed = 0;
        $errored = 0;

        foreach ($survivors as $id) {
            try {
                $correction = Correction::find($id);
                if (!$correction) {
                    continue;
                }
                $finder->examples($correction);
                $warmed++;
            } catch (\Throwable $e) {
                $errored++;
            }
        }

        $state['layers'][] = [
            'name' => 'warm_context',
            'reason' => 'precalentamiento de caché de ejemplos',
            'warmed' => $warmed,
            'errored' => $errored,
        ];
    }

    /**
     * Helper consistente con el extractor de coherencia: cuenta palabras
     * sobre Unicode español con strip de tokens puramente puntuación.
     */
    public static function wordCount(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return 0;
        }
        $count = 0;
        foreach ($words as $w) {
            if (preg_match('/[\p{L}\p{N}]/u', $w)) {
                $count++;
            }
        }
        return $count;
    }
}
