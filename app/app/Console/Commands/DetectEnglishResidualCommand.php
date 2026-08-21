<?php

namespace App\Console\Commands;

use App\Services\Ia\EnglishResidualSegmentDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detecta segmentos con inglés residual en transcripciones done y (opcionalmente)
 * los marca como needs_review en transcription_reviews.
 *
 * Cambios/2026-08-11-english-residual-segment-detector.
 *
 * Uso:
 *   php artisan corrections:detect-english-residual --days=1 --threshold=0.4
 *   php artisan corrections:detect-english-residual --days=1 --apply
 *   php artisan corrections:detect-english-residual --id=165445 --id=165436 --apply
 *   php artisan corrections:detect-english-residual --days=1 --json
 */
class DetectEnglishResidualCommand extends Command
{
    protected $signature = 'corrections:detect-english-residual
                            {--days=1 : Ventana de análisis en días (ignorado si se pasa --id)}
                            {--hours= : Alternativa a --days, ventana en horas}
                            {--threshold= : Score mínimo para flag (default desde config)}
                            {--id=* : Solo estas transcripciones (omite ventana)}
                            {--apply : Persiste en transcription_reviews (default: dry-run)}
                            {--json : Output JSON}';

    protected $description = 'Detecta segmentos con inglés residual y los marca como needs_review.';

    public function handle(EnglishResidualSegmentDetector $detector): int
    {
        $threshold = $this->option('threshold') !== null
            ? (float) $this->option('threshold')
            : (float) config('corrections.english_residual.threshold', 0.4);

        $ids = (array) $this->option('id');
        $apply = (bool) $this->option('apply');
        $json = (bool) $this->option('json');

        $this->info("English residual detector: threshold={$threshold} "
            . ($apply ? '[APPLY]' : '[DRY-RUN]')
            . (empty($ids) ? '' : ' ids=' . implode(',', $ids)));

        // 1) Recolectar candidatos
        if (!empty($ids)) {
            $flagged = [];
            foreach ($ids as $id) {
                $score = $detector->scoreTranscription((int) $id, $threshold);
                if (!empty($score['flagged_segments'])) {
                    $flagged[] = [
                        'transcription_id' => $score['transcription_id'],
                        'finished_at' => null,
                        'flagged_count' => count($score['flagged_segments']),
                        'max_score' => $score['max_score'],
                        'flagged_segment_indexes' => array_column($score['flagged_segments'], 'segment_index'),
                    ];
                }
            }
        } else {
            $hoursOpt = $this->option('hours');
            if ($hoursOpt !== null) {
                $days = max(1, (int) ceil(((int) $hoursOpt) / 24));
            } else {
                $days = max(1, (int) $this->option('days'));
            }
            $flagged = $detector->findFlaggedTranscriptions($threshold, $days);
        }

        if (empty($flagged)) {
            $this->warn('0 transcripciones con segmentos flagged.');
            if ($json) {
                $this->line(json_encode(['flagged' => [], 'stats' => ['created' => 0, 'updated' => 0, 'skipped_manual' => 0]]));
            }
            return self::SUCCESS;
        }

        // 2) Stats acumulados
        $created = 0;
        $updated = 0;
        $skipped = 0;

        if ($apply) {
            $reviewer = (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1);
            foreach ($flagged as $f) {
                $result = $detector->flagForReview($f['transcription_id'], $reviewer, $f);
                $action = $result['action'];
                if ($action === 'created') $created++;
                elseif ($action === 'updated') $updated++;
                elseif ($action === 'skipped_manual') $skipped++;
            }
        }

        // 3) Output
        $stats = [
            'flagged_total' => count($flagged),
            'created' => $created,
            'updated' => $updated,
            'skipped_manual' => $skipped,
            'threshold' => $threshold,
            'applied' => $apply,
        ];

        if ($json) {
            $this->line(json_encode(['flagged' => $flagged, 'stats' => $stats], JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Transcription', 'Finished', 'Flagged', 'Max score', 'Indexes'],
                array_map(
                    fn($f) => [
                        $f['transcription_id'],
                        $f['finished_at'] ?? '-',
                        $f['flagged_count'],
                        round($f['max_score'], 2),
                        implode(',', array_slice($f['flagged_segment_indexes'], 0, 8))
                            . (count($f['flagged_segment_indexes']) > 8 ? '…' : ''),
                    ],
                    $flagged
                )
            );
            $this->info(sprintf(
                "Resumen: %d flagged, %d created, %d updated, %d skipped_manual%s",
                $stats['flagged_total'],
                $stats['created'],
                $stats['updated'],
                $stats['skipped_manual'],
                $apply ? '' : ' (dry-run, no se persistió)'
            ));
        }

        return self::SUCCESS;
    }
}
