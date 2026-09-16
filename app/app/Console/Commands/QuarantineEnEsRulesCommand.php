<?php

namespace App\Console\Commands;

use App\Models\Correction;
use App\Services\Ia\EnEsRuleClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audita el diccionario approved y pone en cuarentena las reglas que traducen
 * inglés->español palabra por palabra.
 *
 * Read-only por defecto. `--apply` solo mueve el cubo QUARANTINE a
 * risk_level='high', que `Correction::scopeSafe()` ya excluye del apply
 * automático: no borra filas y se revierte con --revert.
 */
class QuarantineEnEsRulesCommand extends Command
{
    protected $signature = 'corrections:quarantine-en-es
                            {--apply : Aplica la cuarentena (sin este flag solo informa)}
                            {--revert : Devuelve a risk_level=low las reglas marcadas por este comando}
                            {--out= : Escribe el listado completo a un CSV}
                            {--limit=40 : Cuántas filas muestra por cubo en el informe}';

    protected $description = 'Clasifica el diccionario approved y pone en cuarentena (risk_level=high) las reglas de traducción EN->ES.';

    /**
     * Marca que se anexa a `source` para poder revertir exactamente las filas
     * que tocó este comando, sin pisar las que un admin marcó high a mano.
     * Corta a propósito: `source` es varchar(64) y los valores actuales rondan
     * los 21 caracteres ('ai-suggest-2026-08-01').
     */
    private const QUARANTINE_TAG = '|q-en-es';

    public function handle(EnEsRuleClassifier $classifier): int
    {
        if ($this->option('revert')) {
            return $this->revert();
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        // La tabla corrections tiene ~2.900 filas: cabe en memoria de sobra y
        // evita cualquier escaneo sobre transcription_segments (3,2 GB).
        $rules = Correction::approved()
            ->orderByDesc('applies_count')
            ->get(['id', 'wrong_text', 'correct_text', 'risk_level', 'source', 'applies_count']);

        $buckets = [
            EnEsRuleClassifier::NOISE => [],
            EnEsRuleClassifier::KEEP => [],
            EnEsRuleClassifier::QUARANTINE => [],
            EnEsRuleClassifier::REVIEW => [],
        ];

        foreach ($rules as $rule) {
            $result = $classifier->classify((string) $rule->wrong_text, (string) $rule->correct_text);
            $buckets[$result['bucket']][] = ['rule' => $rule, 'reason' => $result['reason']];
        }

        $this->newLine();
        $this->info(sprintf('Diccionario approved: %d reglas', $rules->count()));
        $this->line(sprintf('  CONSERVAR   %5d reglas (%s aplicaciones)',
            count($buckets[EnEsRuleClassifier::KEEP]),
            number_format($this->sumApplies($buckets[EnEsRuleClassifier::KEEP]))));
        $this->line(sprintf('  CUARENTENA  %5d reglas (%s aplicaciones)',
            count($buckets[EnEsRuleClassifier::QUARANTINE]),
            number_format($this->sumApplies($buckets[EnEsRuleClassifier::QUARANTINE]))));
        $this->line(sprintf('  REVISAR     %5d reglas (%s aplicaciones)',
            count($buckets[EnEsRuleClassifier::REVIEW]),
            number_format($this->sumApplies($buckets[EnEsRuleClassifier::REVIEW]))));
        $this->line(sprintf('  INERTES     %5d reglas (no cambian nada; purgar con corrections:prune-suggestions)',
            count($buckets[EnEsRuleClassifier::NOISE])));
        $this->newLine();

        foreach ([EnEsRuleClassifier::QUARANTINE, EnEsRuleClassifier::REVIEW, EnEsRuleClassifier::NOISE, EnEsRuleClassifier::KEEP] as $bucket) {
            $this->renderBucket($bucket, $buckets[$bucket], $limit);
        }

        if ($out = $this->option('out')) {
            $this->writeCsv($out, $buckets);
            $this->info("Listado completo escrito en {$out}");
        }

        $toQuarantine = array_values(array_filter(
            $buckets[EnEsRuleClassifier::QUARANTINE],
            fn ($row) => $row['rule']->risk_level !== Correction::RISK_HIGH
        ));

        if (!$apply) {
            $this->newLine();
            $this->warn(sprintf(
                'Modo informe. Con --apply se marcarían %d reglas como risk_level=high (reversible con --revert).',
                count($toQuarantine)
            ));
            $this->line('REVISAR no se toca nunca con --apply: esas las decide un humano.');

            return self::SUCCESS;
        }

        if (empty($toQuarantine)) {
            $this->info('Nada que hacer: no hay reglas de traducción activas fuera de cuarentena.');

            return self::SUCCESS;
        }

        $ids = array_map(fn ($row) => $row['rule']->id, $toQuarantine);

        $affected = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $affected += DB::table('corrections')
                ->whereIn('id', $chunk)
                ->update([
                    'risk_level' => Correction::RISK_HIGH,
                    // LEFT(...,64) porque `source` es varchar(64): sin el recorte
                    // un source ya largo reventaría el UPDATE.
                    'source' => DB::raw("LEFT(COALESCE(source, '') || '" . self::QUARANTINE_TAG . "', 64)"),
                    'updated_at' => now(),
                ]);
        }

        $this->newLine();
        $this->info("Cuarentena aplicada a {$affected} reglas (risk_level=high).");
        $this->line('Siguiente paso: reparar el histórico con');
        $this->line('  php artisan transcription:apply-corrections --dry-run --days=1');

        return self::SUCCESS;
    }

    private function revert(): int
    {
        $tag = self::QUARANTINE_TAG;

        $affected = DB::table('corrections')
            ->where('source', 'like', '%' . $tag)
            ->update([
                'risk_level' => Correction::RISK_LOW,
                'source' => DB::raw("REPLACE(source, '{$tag}', '')"),
                'updated_at' => now(),
            ]);

        $this->info("Revertidas {$affected} reglas a risk_level=low.");

        return self::SUCCESS;
    }

    /** @param array<int, array{rule: Correction, reason: string}> $rows */
    private function sumApplies(array $rows): int
    {
        return array_sum(array_map(fn ($row) => (int) $row['rule']->applies_count, $rows));
    }

    /** @param array<int, array{rule: Correction, reason: string}> $rows */
    private function renderBucket(string $bucket, array $rows, int $limit): void
    {
        if (empty($rows)) {
            return;
        }

        $label = match ($bucket) {
            EnEsRuleClassifier::QUARANTINE => 'CUARENTENA (traducción EN->ES)',
            EnEsRuleClassifier::REVIEW => 'REVISAR A MANO (ASCII->ASCII ambiguo)',
            EnEsRuleClassifier::NOISE => 'INERTES (wrong === correct, no cambian nada)',
            default => 'CONSERVAR (corrección de español)',
        };

        $this->line("<comment>{$label}</comment> — mostrando " . min($limit, count($rows)) . ' de ' . count($rows));

        $this->table(
            ['id', 'wrong', 'correct', 'risk', 'aplic.', 'motivo'],
            array_map(fn ($row) => [
                $row['rule']->id,
                $row['rule']->wrong_text,
                $row['rule']->correct_text,
                $row['rule']->risk_level,
                $row['rule']->applies_count,
                $row['reason'],
            ], array_slice($rows, 0, $limit))
        );
    }

    /** @param array<string, array<int, array{rule: Correction, reason: string}>> $buckets */
    private function writeCsv(string $path, array $buckets): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['bucket', 'id', 'wrong_text', 'correct_text', 'risk_level', 'source', 'applies_count', 'reason']);

        foreach ($buckets as $bucket => $rows) {
            foreach ($rows as $row) {
                fputcsv($fh, [
                    $bucket,
                    $row['rule']->id,
                    $row['rule']->wrong_text,
                    $row['rule']->correct_text,
                    $row['rule']->risk_level,
                    $row['rule']->source,
                    $row['rule']->applies_count,
                    $row['reason'],
                ]);
            }
        }

        fclose($fh);
    }
}
