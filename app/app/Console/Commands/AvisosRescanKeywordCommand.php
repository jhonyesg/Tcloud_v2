<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use App\Models\Transcription;
use App\Services\Ia\WatermarkReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * avisos-keyword-word-boundary: re-indexación de una keyword bajo la regla
 * de matching vigente (frontera de palabra). Elimina sus hits existentes,
 * rebobina los watermarks de sus pares vía el servicio central (auditoría
 * automática) y lanza el re-scan por pares reutilizando el barrido del
 * AvisosScanService.
 *
 * Uso:
 *   php artisan avisos:rescan-keyword petro --dry-run   (solo reporta)
 *   php artisan avisos:rescan-keyword petro             (ejecuta)
 *   php artisan avisos:rescan-keyword 49                (por id)
 *   php artisan avisos:rescan-keyword petro --user=7    (acota al storage de 1 usuario)
 *
 * La operación de datos es idempotente: los hits borrados se regeneran por
 * el scan solo cuando la frontera vigente los acepta.
 */
class AvisosRescanKeywordCommand extends Command
{
    protected $signature = 'avisos:rescan-keyword
        {keyword : Texto o ID de la keyword}
        {--user= : Acotar el re-scan a los storages de un usuario}
        {--dry-run : Contar hits a eliminar sin mutar nada}';

    protected $description = 'Re-indexa una keyword con la regla de matching vigente (borra hits, rebobina watermarks y re-escanea)';

    private const CHUNK = 500;

    public function handle(): int
    {
        $keywordArg = (string) $this->argument('keyword');
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $keyword = is_numeric($keywordArg)
            ? Keyword::find((int) $keywordArg)
            : Keyword::matchingText(\App\Models\Keyword::normalize($keywordArg))->first();

        if (!$keyword) {
            $this->error("Keyword no encontrada: {$keywordArg}");
            return self::FAILURE;
        }

        $keywordId = (int) $keyword->id;
        $hitsCount = DB::table('segment_keyword_hits')->where('keyword_id', $keywordId)->count();

        // Pares (keyword, storage) con watermark — sobre ellos corre el re-scan.
        $pairs = DB::table('keyword_scan_watermarks as w')
            ->where('w.keyword_id', $keywordId)
            ->when($userId !== null, function ($q) use ($userId) {
                $q->whereIn('w.storage_provider_id', function ($sub) use ($userId) {
                    $sub->select('us.storage_provider_id')
                        ->from('user_storages as us')
                        ->where('us.user_id', $userId)
                        ->where('us.transcription_access', true);
                });
            })
            ->get(['w.storage_provider_id']);

        $this->line("Keyword: #{$keywordId} \"{$keyword->text}\"");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Hits existentes a eliminar', $hitsCount],
                ['Pares (keyword, storage) a re-escanear', $pairs->count()],
                ['Acotado a user_id', $userId ?? 'NO (todos)'],
            ],
        );

        if ($dryRun) {
            $this->info('Dry-run: nada modificado.');
            return self::SUCCESS;
        }

        if ($hitsCount === 0 && $pairs->isEmpty()) {
            $this->info('Nada que hacer (sin hits ni pares con watermark).');
            return self::SUCCESS;
        }

        // 1) Borrar hits de la keyword en chunks (evita lock largo en tablas grandes).
        $deleted = 0;
        while (true) {
            $ids = DB::table('segment_keyword_hits')
                ->where('keyword_id', $keywordId)
                ->limit(self::CHUNK)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('segment_keyword_hits')->whereIn('id', $ids->all())->delete();
        }
        $this->line("Hits eliminados: {$deleted}");

        // 2) Rebobinar watermarks por el servicio central (audita rewind_pair).
        $reconciler = app(WatermarkReconciler::class);
        foreach ($pairs as $pair) {
            $reconciler->rewindPair($keywordId, (int) $pair->storage_provider_id);
        }
        $this->line('Watermarks rebobinados: ' . $pairs->count());

        // 3) Re-scan por pares reutilizando el barrido existente. force=false:
        //    los hits ya se borraron y los watermarks están en NULL, el barrido
        //    toma TODAS las transcripciones del par de nuevo.
        $scan = app(\App\Services\Ia\AvisosScanService::class);
        $scanned = 0;
        $hitsNew = 0;
        $failed = 0;

        foreach ($pairs as $pair) {
            $storageId = (int) $pair->storage_provider_id;
            $candidates = $scan->selectCandidatesForPair($keywordId, $storageId, 100000);
            $this->line("  storage {$storageId}: {$candidates->count()} transcripciones...");

            foreach ($candidates as $candidate) {
                try {
                    $transcription = Transcription::findOrFail($candidate->transcription_id);
                    $hits = $scan->scanPair($transcription, $keywordId, false);
                    $scanned++;
                    $hitsNew += max(0, $hits);
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('avisos.rescan_keyword.pair_error', [
                        'keyword_id' => $keywordId,
                        'storage_provider_id' => $storageId,
                        'transcription_id' => $candidate->transcription_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 4) Actualizar contadores de los watermarks con el resultado real.
        $totals = DB::table('segment_keyword_hits as h')
            ->join('transcriptions as t', 't.id', '=', 'h.transcription_id')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->where('h.keyword_id', $keywordId)
            ->groupBy('f.storage_provider_id')
            ->get([
                'f.storage_provider_id',
                DB::raw('COUNT(*) as hits'),
                DB::raw('MAX(t.finished_at) as max_finished'),
            ]);

        foreach ($totals as $row) {
            DB::table('keyword_scan_watermarks')
                ->where('keyword_id', $keywordId)
                ->where('storage_provider_id', $row->storage_provider_id)
                ->update([
                    'scanned_until' => $row->max_finished,
                    'hits_total' => $row->hits,
                    'updated_at' => now(),
                ]);
        }
        \App\Services\Ia\CacheEpoch::bump();

        $this->info("Re-escaneo completado: {$scanned} transcripciones, {$hitsNew} hits nuevos, {$failed} fallos.");

        Log::info('avisos.rescan_keyword.completed', [
            'keyword_id' => $keywordId,
            'hits_deleted' => $deleted,
            'transcriptions_scanned' => $scanned,
            'hits_new' => $hitsNew,
            'failed' => $failed,
        ]);

        return self::SUCCESS;
    }
}