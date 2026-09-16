<?php

namespace App\Console\Commands;

use App\Services\Ia\DictionaryAudit;
use Illuminate\Console\Command;

/**
 * Reporte read-only del diccionario de correcciones.
 * Cambios/2026-08-02-corrections-dictionary-atomicity.
 *
 * Imprime totales, distribución de effectiveness, risk_level, top n-gramas,
 * duplicados, conflictos y clusters. Útil para auditoría antes/después
 * de cambios y para diagnóstico sin pedirle a Kilo.
 *
 * Uso:
 *   php artisan corrections:dictionary-audit
 *   php artisan corrections:dictionary-audit --no-cache  # saltar cache 5min
 */
class DictionaryAuditCommand extends Command
{
    protected $signature = 'corrections:dictionary-audit
                            {--no-cache : Saltar el cache de 5 minutos}';

    protected $description = 'Reporte read-only del diccionario: totales, effectiveness, top n-gramas, duplicados, risk levels.';

    public function handle(DictionaryAudit $audit): int
    {
        $useCache = !$this->option('no-cache');
        $r = $audit->run($useCache);

        $this->info('=== Dictionary Audit Report ===');
        $this->line(sprintf('Generado: %s', now()->toIso8601String()));
        $this->line('');

        // Totales
        $t = $r['totals'];
        $this->info(sprintf(
            'Totales: %d total · %d approved · %d pending · %d rejected',
            $t['total'],
            $t['approved'],
            $t['pending'],
            $t['rejected']
        ));

        // Effectiveness
        $e = $r['effectiveness_distribution'];
        $total = array_sum($e);
        $this->line('');
        $this->info('Distribución de effectiveness (reglas aprobadas):');
        $rows = [];
        foreach ($e as $bucket => $cnt) {
            $pct = $total > 0 ? round($cnt * 100 / $total, 1) : 0;
            $rows[] = [$bucket, $cnt, "{$pct}%"];
        }
        $this->table(['Bucket', 'Count', '%'], $rows);

        // Risk
        $rd = $r['risk_distribution'];
        $this->line('');
        $this->info('Distribución de risk_level:');
        $this->table(
            ['low', 'medium', 'high'],
            [[$rd['low'], $rd['medium'], $rd['high']]]
        );

        // Top unigramas
        $this->line('');
        $this->info('Top 10 unigramas dentro de wrong_text:');
        $rows = [];
        foreach (array_slice($r['top_unigrams'], 0, 10) as $u) {
            $rows[] = [$u['ngram'], $u['count']];
        }
        $this->table(['Unigrama', 'Count'], $rows);

        // Top bigramas
        $this->line('');
        $this->info('Top 10 bigramas:');
        $rows = [];
        foreach (array_slice($r['top_bigrams'], 0, 10) as $u) {
            $rows[] = [$u['ngram'], $u['count']];
        }
        $this->table(['Bigrama', 'Count'], $rows);

        // Top trigramas
        $this->line('');
        $this->info('Top 10 trigramas:');
        $rows = [];
        foreach (array_slice($r['top_trigrams'], 0, 10) as $u) {
            $rows[] = [$u['ngram'], $u['count']];
        }
        $this->table(['Trigrama', 'Count'], $rows);

        // Duplicados y conflictos
        $dc = $r['duplicates_and_conflicts'];
        $this->line('');
        $this->info(sprintf(
            'Duplicados exactos: %d · Conflictos (mismo wrong → distinto correct): %d',
            $dc['exact_duplicates'],
            $dc['conflicts']
        ));

        // Clusters
        $cl = $r['clusters'];
        $this->line('');
        $this->info(sprintf(
            'Clusters (Jaccard ≥%s con ≥%d overlaps): %d de %d correcciones con tokens≥3',
            $cl['threshold'],
            $cl['min_overlaps'],
            $cl['total_clusters'],
            $cl['total_with_tokens_ge_3']
        ));
        if (!empty($cl['samples'])) {
            $rows = [];
            foreach ($cl['samples'] as $s) {
                $rows[] = [$s['id'], $s['shared_with'], $s['tokens'], mb_substr($s['wrong_text'], 0, 80)];
            }
            $this->table(['ID', 'Shared con', 'Tokens', 'Wrong'], $rows);
        }

        $this->line('');
        $this->info('Para detalle adicional: GET /ia/correcciones/dictionary-audit (JSON)');

        return self::SUCCESS;
    }
}
