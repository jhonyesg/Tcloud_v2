<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use App\Services\Ia\MentionBackfillService;
use Illuminate\Console\Command;

/**
 * change admin-matches-and-backfill (Fase 3): comando artisan para ejecutar
 * el backfill retroactivo de una keyword contra todos los segmentos accesibles.
 *
 * Usage:
 *   php artisan mentions:backfill-keyword --keyword=42
 *   php artisan mentions:backfill-keyword --all
 *
 * Idempotente: el insertOrIgnore más el UNIQUE constraint de la tabla
 * segment_keyword_hits blinda contra duplicados.
 */
class BackfillKeywordCommand extends Command
{
    protected $signature = 'mentions:backfill-keyword
                            {--keyword= : ID de la keyword a backfillear (o texto)}
                            {--all : Backfillea todas las keywords que aún tienen 0 hits}
                            {--limit=100 : Límite cuando se usa --all}
                            {--silent : Suprime output verboso (default false)}';

    protected $description = 'Backfill retroactivo de hits en segment_keyword_hits para keywords sin escanear.';

    public function handle(MentionBackfillService $backfill): int
    {
        $silent = (bool) $this->option('silent');

        if (!$this->option('keyword') && !$this->option('all')) {
            $this->error('Especifica --keyword=ID o --all');
            return self::FAILURE;
        }

        if ($this->option('keyword')) {
            $kwSpec = (string) $this->option('keyword');

            // Aceptar ID numérico o el texto exacto de la keyword
            if (ctype_digit($kwSpec)) {
                $keyword = Keyword::find((int) $kwSpec);
            } else {
                $keyword = Keyword::where('text', $kwSpec)->first();
            }

            if (!$keyword) {
                $this->error("Keyword no encontrada: '$kwSpec'");
                return self::FAILURE;
            }

            return $this->processOne($backfill, $keyword);
        }

        // --all: process every keyword with 0 hits
        $limit = (int) $this->option('limit');
        $keywordsWithoutHits = $backfill->keywordsWithoutHits($limit);

        if (empty($keywordsWithoutHits)) {
            $this->info('No hay keywords sin hits. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Backfill para %d keyword(s) sin hits (límite %d).', count($keywordsWithoutHits), $limit));

        $totalHits = 0;
        $totalTrans = 0;
        $totalTime = 0.0;

        foreach ($keywordsWithoutHits as $kid => $text) {
            $keyword = Keyword::find($kid);
            if (!$keyword) continue;

            if (!$silent) $this->line("  - {$kid}: {$text}");
            $result = $backfill->backfillKeyword($keyword);
            $totalHits += $result['hits_inserted'];
            $totalTrans += $result['transcriptions_scanned'];
            $totalTime += $result['seconds'];

            if (!$silent) {
                $this->line(sprintf(
                    '    ↳ %d hits en %d transcripciones (%.2fs)',
                    $result['hits_inserted'],
                    $result['transcriptions_scanned'],
                    $result['seconds']
                ));
            }
        }

        $this->info(sprintf(
            'TOTAL: %d hits en %d transcripciones (%.2fs total)',
            $totalHits,
            $totalTrans,
            $totalTime
        ));

        return self::SUCCESS;
    }

    private function processOne(MentionBackfillService $backfill, Keyword $keyword): int
    {
        $this->info("Backfill para keyword #{$keyword->id} ('{$keyword->text}')");

        $result = $backfill->backfillKeyword($keyword);

        $this->info(sprintf(
            '%d hits insertados en %d transcripciones (%.2fs)',
            $result['hits_inserted'],
            $result['transcriptions_scanned'],
            $result['seconds']
        ));

        return self::SUCCESS;
    }
}
