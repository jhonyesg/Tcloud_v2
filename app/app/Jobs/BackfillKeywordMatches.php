<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Services\Ia\MentionBackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * change admin-matches-and-backfill (Fase 3): job que ejecuta el backfill
 * retroactivo de una keyword recién creada contra todos los segmentos
 * accesibles del cliente.
 *
 * Disparado desde MisAvisosController::storeKeyword cuando el cliente crea
 * una keyword nueva. Corre en background (`default` queue) — el cliente no
 * espera a que termine para recibir el 201.
 *
 * Idempotente: el backfill hace insertOrIgnore sobre segment_keyword_hits
 * con UNIQUE constraint, así que re-dispatch no duplica.
 */
class BackfillKeywordMatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800; // 30 min worst-case (escaneo de 51M segmentos)

    public function __construct(public int $keywordId)
    {
    }

    public function handle(MentionBackfillService $backfill): void
    {
        $keyword = Keyword::find($this->keywordId);
        if (!$keyword) {
            Log::warning('mentions.backfill_keyword_missing', ['keyword_id' => $this->keywordId]);
            return;
        }

        $result = $backfill->backfillKeyword($keyword);

        Log::info('mentions.backfill_keyword_done', [
            'keyword_id' => $this->keywordId,
            'keyword_text' => $keyword->text,
            'hits_inserted' => $result['hits_inserted'],
            'transcriptions_scanned' => $result['transcriptions_scanned'],
            'seconds' => $result['seconds'],
        ]);
    }
}
