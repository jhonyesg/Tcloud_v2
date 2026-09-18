<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tirada por `WatermarkReconciler` cuando se intenta operar sobre un storage
 * que fue mergeado en otro (change `storage-physical-path-normalization`,
 * 2026-09-17). Los storages mergeados tienen `enabled=false, base_path=NULL,
 * duplicate_of_storage_id=<canonical>`. Crear/consultar watermarks contra ellos seria
 * escribir estado en un storage que el sistema considera muerto.
 */
class WatermarkReconcilerOnMergedStorageException extends RuntimeException
{
}

/**
 * Reconciliador de cobertura de watermarks (avisos-scan-coverage-reconciler).
 *
 * Centraliza TODA la lógica de creación / consulta / rewind de pares
 * (keyword_id, storage_provider_id) en keyword_scan_watermarks.
 *
 * Antes de este servicio, la lógica vivía inline en:
 *   - Keyword::created
 *   - UserAlertsInteligente::saved
 * Con hooks duplicados y SQL embebido. Este servicio expone:
 *
 *   - ensureForUser(int $userId): crea watermarks NULL faltantes para
 *     (todas las keywords del usuario) × (todos los storages del usuario
 *     con transcription_access=true). Útil cuando un usuario recién habilita
 *     el módulo de avisos.
 *
 *   - ensureForKeyword(int $keywordId): crea watermarks NULL faltantes para
 *     (la keyword) × (todos los storages donde cualquier usuario con esa
 *     keyword tiene transcription_access=true). Útil cuando el cliente recibe
 *     una keyword preexistente.
 *
 *   - ensureForStorage(int $storageId): crea watermarks NULL faltantes para
 *     (todas las keywords donde algún usuario con acceso al storage la tiene)
 *     × (el storage). Útil cuando un usuario recupera acceso al storage.
 *
 *   - rewindPair(int $keywordId, int $storageId, ?int $actorId = null):
 *     pone scanned_until=NULL sobre un par existente (o lo crea si no
 *     existía). Audita siempre.
 *
 *   - driftReport(?int $userId = null): retorna pares aplicables que NO
 *     existen en keyword_scan_watermarks (drift negativo) y pares existentes
 *     sin usuarios aplicables (drift positivo). NO muta.
 *
 * Toda mutación pasa por aquí; los modelos sólo llaman al servicio.
 *
 * Por convención del proyecto (AGENTS.md), nada de SQL inline en modelos.
 */
class WatermarkReconciler
{
    /**
     * Crea watermarks NULL faltantes para todas las (keyword, storage) del
     * usuario donde storage tiene transcription_access=true y el usuario
     * tiene la keyword asignada.
     *
     * Retorna el número de filas creadas (0 = nada nuevo).
     */
    public function ensureForUser(int $userId, ?int $actorId = null): int
    {
        $now = now();
        $inserted = DB::affectingStatement("
            INSERT INTO keyword_scan_watermarks
                (keyword_id, storage_provider_id, scanned_until,
                 candidates_total, hits_total, created_at, updated_at)
            SELECT uk.keyword_id, us.storage_provider_id, NULL, 0, 0, ?, ?
            FROM user_keyword uk
            JOIN user_storages us
                 ON us.user_id = uk.user_id
                AND us.transcription_access = true
            WHERE uk.user_id = ?
            ON CONFLICT (keyword_id, storage_provider_id) DO NOTHING
        ", [$now, $now, $userId]);

        if ($inserted > 0) {
            CacheEpoch::bump();
            // Change 2026-09-13-perf-audit-and-improve: invalidar cache de /scan.
            \Illuminate\Support\Facades\Cache::forget("avisos:scan_status");
            $this->audit(null, 'hook_auto', null, null, null, null, [
                'trigger' => 'WatermarkReconciler::ensureForUser',
                'user_id' => $userId,
                'created' => $inserted,
            ]);
        }

        return $inserted;
    }

    /**
     * Crea watermarks NULL faltantes para (keyword, storage) donde cualquier
     * usuario con esa keyword tiene transcription_access=true al storage.
     */
    public function ensureForKeyword(int $keywordId, ?int $actorId = null): int
    {
        $now = now();
        $inserted = DB::affectingStatement("
            INSERT INTO keyword_scan_watermarks
                (keyword_id, storage_provider_id, scanned_until,
                 candidates_total, hits_total, created_at, updated_at)
            SELECT ?, us.storage_provider_id, NULL, 0, 0, ?, ?
            FROM user_storages us
            JOIN user_keyword uk
                 ON uk.user_id = us.user_id
                AND uk.keyword_id = ?
            JOIN user_alerts_inteligentes uai
                 ON uai.user_id = us.user_id
                AND uai.enabled = true
            WHERE us.transcription_access = true
            ON CONFLICT (keyword_id, storage_provider_id) DO NOTHING
        ", [$keywordId, $now, $now, $keywordId]);

        if ($inserted > 0) {
            CacheEpoch::bump();
            // Change 2026-09-13-perf-audit-and-improve: invalidar cache de /scan.
            \Illuminate\Support\Facades\Cache::forget("avisos:scan_status");
            $this->audit(null, 'hook_auto', $keywordId, null, null, null, [
                'trigger' => 'WatermarkReconciler::ensureForKeyword',
                'created' => $inserted,
            ]);
        }

        return $inserted;
    }

    /**
     * Crea watermarks NULL faltantes para (todas las keywords aplicables
     * del storage, el storage). Útil cuando un usuario recupera acceso
     * (transcription_access false→true).
     */
    public function ensureForStorage(int $storageId, ?int $actorId = null): int
    {
        // Change `storage-physical-path-normalization` (2026-09-17):
        // rechazamos storages mergeados. Operar contra uno de ellos dejaria
        // estado en una fila que el sistema ya considera "soft-deleted".
        // El operador debe reconciliar contra el canonical (ver
        // `StorageProvider::canonicalFor()` si hay ambigüedad).
        $mergedInto = DB::table('storage_providers')
            ->where('id', $storageId)
            ->whereNotNull('duplicate_of_storage_id')
            ->value('duplicate_of_storage_id');
        if ($mergedInto !== null) {
            throw new WatermarkReconcilerOnMergedStorageException(
                "Storage {$storageId} fue mergeado en {$mergedInto}; reconcilie contra {$mergedInto} en su lugar."
            );
        }

        $now = now();
        $inserted = DB::affectingStatement("
            INSERT INTO keyword_scan_watermarks
                (keyword_id, storage_provider_id, scanned_until,
                 candidates_total, hits_total, created_at, updated_at)
            SELECT uk.keyword_id, ?, NULL, 0, 0, ?, ?
            FROM user_keyword uk
            JOIN user_storages us
                 ON us.user_id = uk.user_id
                AND us.storage_provider_id = ?
                AND us.transcription_access = true
            JOIN user_alerts_inteligentes uai
                 ON uai.user_id = us.user_id
                AND uai.enabled = true
            ON CONFLICT (keyword_id, storage_provider_id) DO NOTHING
        ", [$storageId, $now, $now, $storageId]);

        if ($inserted > 0) {
            CacheEpoch::bump();
            // Change 2026-09-13-perf-audit-and-improve: invalidar cache de /scan.
            \Illuminate\Support\Facades\Cache::forget("avisos:scan_status");
            $this->audit(null, 'hook_auto', null, $storageId, null, null, [
                'trigger' => 'WatermarkReconciler::ensureForStorage',
                'storage_id' => $storageId,
                'created' => $inserted,
            ]);
        }

        return $inserted;
    }

    /**
     * Rewind explícito de un par: scanned_until = NULL.
     * Crea el watermark si no existía.
     * Audita siempre con el actor (admin) o NULL si es sistema.
     */
    public function rewindPair(int $keywordId, int $storageId, ?int $actorId = null): void
    {
        $before = DB::table('keyword_scan_watermarks')
            ->where('keyword_id', $keywordId)
            ->where('storage_provider_id', $storageId)
            ->value('scanned_until');

        $now = now();
        DB::table('keyword_scan_watermarks')->upsert(
            [[
                'keyword_id' => $keywordId,
                'storage_provider_id' => $storageId,
                'scanned_until' => null,
                'candidates_total' => 0,
                'hits_total' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['keyword_id', 'storage_provider_id'],
            ['scanned_until', 'updated_at'],
        );

        $this->audit(
            $actorId,
            'rewind_pair',
            $keywordId,
            $storageId,
            $before ? (string) $before : null,
            null,
            ['reason' => 'rewind_pair_explicit'],
        );

        CacheEpoch::bump();
            // Change 2026-09-13-perf-audit-and-improve: invalidar cache de /scan.
            \Illuminate\Support\Facades\Cache::forget("avisos:scan_status");
    }

    /**
     * Reporte de drift sin mutar.
     *
     * Estructura del retorno:
     *   summary: { applicable_pairs, existing_pairs, missing, orphan, scope_user_id }
     *   missing: [{ keyword_id, storage_provider_id }]   (drift negativo)
     *   orphan:  [{ keyword_id, storage_provider_id }]   (drift positivo)
     *
     * Si $userId viene, el reporte se acota a ese usuario.
     */
    public function driftReport(?int $userId = null): array
    {
        $applicable = DB::table('user_keyword as uk')
            ->join('user_storages as us', 'us.user_id', '=', 'uk.user_id')
            ->join('user_alerts_inteligentes as uai', function ($j) {
                $j->on('uai.user_id', '=', 'us.user_id')->where('uai.enabled', true);
            })
            ->where('us.transcription_access', true);
        if ($userId !== null) {
            $applicable->where('uk.user_id', $userId);
        }
        $applicablePairs = $applicable
            ->distinct()
            ->get(['uk.keyword_id', 'us.storage_provider_id']);

        $existing = DB::table('keyword_scan_watermarks');
        if ($userId !== null) {
            $existing->whereIn('keyword_id', function ($q) use ($userId) {
                $q->select('keyword_id')->from('user_keyword')->where('user_id', $userId);
            });
        }
        $existingPairs = $existing->get(['keyword_id', 'storage_provider_id']);

        // Set-difference por hash O(n+m) en vez de contains() anidados O(n*m).
        // Con ~1.2k aplicables × ~0.9k existentes el cálculo baja de ~1.2 s a
        // pocos ms, sin cambiar el shape ni los conteos. Ver change
        // `optimize-watermark-drift-report`.
        $pairKey = fn ($r) => ((int) $r->keyword_id) . ':' . ((int) $r->storage_provider_id);

        $applicableByKey = [];
        foreach ($applicablePairs as $r) {
            $applicableByKey[$pairKey($r)] = $r;
        }
        $existingByKey = [];
        foreach ($existingPairs as $r) {
            $existingByKey[$pairKey($r)] = $r;
        }

        $missing = [];
        foreach ($applicableByKey as $key => $r) {
            if (!isset($existingByKey[$key])) {
                $missing[] = $r;
            }
        }

        $orphan = [];
        foreach ($existingByKey as $key => $r) {
            if (!isset($applicableByKey[$key])) {
                $orphan[] = $r;
            }
        }

        return [
            'summary' => [
                'applicable_pairs' => count($applicableByKey),
                'existing_pairs' => count($existingByKey),
                'missing' => count($missing),
                'orphan' => count($orphan),
                'scope_user_id' => $userId,
            ],
            'missing' => $missing,
            'orphan' => $orphan,
        ];
    }

    /**
     * Auditoría append-only en watermark_audit_log.
     * Si la tabla no existe aún (entregas incrementales), falla silenciosa.
     */
    private function audit(
        ?int $actorUserId,
        string $action,
        ?int $keywordId,
        ?int $storageId,
        ?string $beforeValue,
        ?string $afterValue,
        array $metadata = [],
    ): void {
        try {
            DB::table('watermark_audit_log')->insert([
                'actor_user_id' => $actorUserId,
                'action' => $action,
                'keyword_id' => $keywordId,
                'storage_id' => $storageId,
                'before_value' => $beforeValue,
                'after_value' => $afterValue,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Si el audit_log aún no existe (entrega incremental pre-migración),
            // no romper la operación principal. Log silencioso.
            Log::warning('watermark_audit.insert_failed', [
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
        }
    }
}
