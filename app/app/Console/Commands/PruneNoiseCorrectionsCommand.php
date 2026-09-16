<?php

namespace App\Console\Commands;

use App\Models\Correction;
use App\Services\Ia\EnEsRuleClassifier;
use Illuminate\Console\Command;

/**
 * Limpia el diccionario de las reglas que no deberían estar en él.
 *
 * Separa dos clases con riesgos muy distintos:
 *
 *  - INERTES (`wrong_text` === `correct_text`): no cambian ningún texto, así que
 *    borrarlas es seguro en cualquier estado y no obliga a reaplicar nada. Solo
 *    quitan trabajo del recorrido sobre 20,6M de segmentos.
 *  - INSEGURAS: traducciones EN->ES y reemplazos cortos que no son arreglos
 *    ortográficos (`presidenta`->`presidente` cambia el género;
 *    `of love`->`de love` produce espanglish). Estas SÍ cambian texto, así que
 *    solo se borran en `pending`, donde todavía no se ha aplicado nada. Sobre
 *    `approved` el comando informa pero no toca: borrarlas exigiría una corrida
 *    retroactiva completa, que es decisión aparte.
 *
 * Read-only por defecto, igual que `corrections:quarantine-en-es`.
 *
 * Uso:
 *   php artisan corrections:prune-suggestions                    # informe de pending
 *   php artisan corrections:prune-suggestions --apply            # borra en pending
 *   php artisan corrections:prune-suggestions --status=approved  # informe (nunca borra inseguras)
 *   php artisan corrections:prune-suggestions --status=approved --apply --noise-only
 */
class PruneNoiseCorrectionsCommand extends Command
{
    protected $signature = 'corrections:prune-suggestions
                            {--apply : Borra de verdad. Sin este flag solo informa}
                            {--status=pending : Estado a revisar (pending|approved|rejected|merged)}
                            {--noise-only : Limitar la acción a las reglas inertes}
                            {--loose-words : Borra TODA regla de una sola palabra, sea cual sea su estado}';

    protected $description = 'Informa y opcionalmente borra reglas inertes o inseguras del diccionario.';

    public function handle(EnEsRuleClassifier $classifier): int
    {
        $apply = (bool) $this->option('apply');
        $noiseOnly = (bool) $this->option('noise-only');
        $status = (string) $this->option('status');

        $allowed = [
            Correction::STATUS_PENDING,
            Correction::STATUS_APPROVED,
            Correction::STATUS_REJECTED,
            Correction::STATUS_MERGED,
        ];

        if (!in_array($status, $allowed, true)) {
            $this->error('--status debe ser uno de: ' . implode(', ', $allowed));

            return self::FAILURE;
        }

        $rules = Correction::query()
            ->where('status', $status)
            ->orderByDesc('applies_count')
            ->get(['id', 'wrong_text', 'correct_text', 'status', 'risk_level', 'applies_count', 'source']);

        if ($this->option('loose-words')) {
            return $this->pruneLooseWords($rules, $status, $apply);
        }

        $inert = [];
        $unsafe = [];

        foreach ($rules as $rule) {
            $wrong = (string) $rule->wrong_text;
            $correct = (string) $rule->correct_text;
            $bucket = $classifier->classify($wrong, $correct)['bucket'];

            if ($bucket === EnEsRuleClassifier::NOISE) {
                $inert[] = ['rule' => $rule, 'reason' => 'no cambia nada'];
                continue;
            }

            if ($noiseOnly) {
                continue;
            }

            if ($bucket === EnEsRuleClassifier::QUARANTINE) {
                $unsafe[] = ['rule' => $rule, 'reason' => 'traducción EN->ES'];
                continue;
            }

            // Reemplazos de una o dos palabras: disparan en todo el corpus sin
            // mirar el contexto, así que solo valen si son arreglos ortográficos.
            // Las frases largas se dejan estar: acotan su propio contexto.
            if ($this->wordCount($wrong) <= 2 && !$classifier->isOrthographicVariant($wrong, $correct)) {
                $unsafe[] = ['rule' => $rule, 'reason' => 'palabra suelta que no arregla ortografía'];
            }
        }

        $this->newLine();
        $this->info(sprintf('Diccionario en estado "%s": %d reglas revisadas', $status, $rules->count()));
        $this->line(sprintf('  INERTES   %4d (borrado seguro en cualquier estado)', count($inert)));

        if (!$noiseOnly) {
            $this->line(sprintf('  INSEGURAS %4d (borrado solo en pending)', count($unsafe)));
        }

        $this->renderGroup('INERTES (wrong === correct)', $inert);

        if (!$noiseOnly) {
            $this->renderGroup('INSEGURAS (traducción o palabra suelta sin arreglo ortográfico)', $unsafe);
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Informe únicamente. Repite con --apply para borrar.');

            return self::SUCCESS;
        }

        $toDelete = array_column($inert, 'rule');

        if (!$noiseOnly) {
            if ($status === Correction::STATUS_PENDING) {
                $toDelete = array_merge($toDelete, array_column($unsafe, 'rule'));
            } elseif (!empty($unsafe)) {
                $this->newLine();
                $this->warn(sprintf(
                    'Las %d reglas INSEGURAS de "%s" NO se borran: ya modificaron texto y quitarlas '
                    . 'exige una corrida retroactiva. Pon risk_level=high con corrections:quarantine-en-es '
                    . 'o revísalas en /ia/correcciones con "Ver ejemplos".',
                    count($unsafe),
                    $status
                ));
            }
        }

        if (empty($toDelete)) {
            $this->info('Nada que borrar.');

            return self::SUCCESS;
        }

        $ids = array_map(fn (Correction $r) => $r->id, $toDelete);
        $deleted = Correction::whereIn('id', $ids)->delete();

        $this->newLine();
        $this->info("Borradas {$deleted} reglas.");

        if ($status === Correction::STATUS_PENDING) {
            $this->comment('Eran propuestas sin aprobar: ninguna transcripción cambia.');
        } else {
            $this->comment('Solo se borraron reglas inertes: por definición no cambiaban ningún texto.');
        }

        return self::SUCCESS;
    }

    /**
     * Borra toda regla cuyo `wrong_text` sea una sola palabra.
     *
     * Decisión del admin (2026-08-13) tras leer transcripciones reales: una regla
     * de un solo token no puede saber en qué idioma está la frase que la rodea, y
     * el corpus viene lleno de frases enteras en inglés. Incluso un arreglo de
     * tilde impecable produce espanglish al dispararse dentro de una:
     *
     *   "in the region of Antioquia"  ->  "in the región of Antioquia"
     *   "it's possible that"          ->  "it's posible that"
     *
     * Por eso el criterio es el número de palabras y no la clase de arreglo: no
     * hay reemplazo de un token que sea seguro sobre un corpus bilingüe. Las
     * frases sí se anclan a su contexto y se conservan.
     *
     * Ojo: borrar la regla no deshace lo que ya escribió en `transcription_segments.text`.
     * Eso lo repara `transcription:apply-corrections`, que recalcula desde `text_raw`.
     *
     * @param  \Illuminate\Support\Collection<int, Correction>  $rules
     */
    private function pruneLooseWords(\Illuminate\Support\Collection $rules, string $status, bool $apply): int
    {
        $loose = $rules->filter(fn (Correction $r) => $this->wordCount((string) $r->wrong_text) === 1)->values();

        if ($loose->isEmpty()) {
            $this->info("No hay reglas de una sola palabra en estado \"{$status}\".");

            return self::SUCCESS;
        }

        $applied = $loose->sum('applies_count');

        $this->newLine();
        $this->info(sprintf(
            'Reglas de una sola palabra en "%s": %d de %d (%s aplicaciones históricas)',
            $status,
            $loose->count(),
            $rules->count(),
            number_format($applied)
        ));

        $this->renderGroup(
            'UNA SOLA PALABRA (dispara sin saber el idioma de la frase)',
            $loose->map(fn (Correction $r) => ['rule' => $r, 'reason' => $r->risk_level === Correction::RISK_HIGH ? 'ya en cuarentena' : 'activa'])->all()
        );

        if (!$apply) {
            $this->newLine();
            $this->comment('Informe únicamente. Repite con --apply para borrarlas.');

            return self::SUCCESS;
        }

        $deleted = Correction::whereIn('id', $loose->pluck('id')->all())->delete();

        $this->newLine();
        $this->info("Borradas {$deleted} reglas de una sola palabra.");
        $this->warn(
            'El texto ya reescrito por estas reglas NO se revierte al borrarlas. Para repararlo: '
            . 'php artisan transcription:apply-corrections --days=3'
        );

        return self::SUCCESS;
    }

    /** @param array<int, array{rule: Correction, reason: string}> $rows */
    private function renderGroup(string $label, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $this->newLine();
        $this->line("<comment>{$label}</comment> — " . count($rows));
        $this->table(
            ['id', 'wrong', 'correct', 'aplic.', 'motivo'],
            array_map(fn ($row) => [
                $row['rule']->id,
                mb_strimwidth((string) $row['rule']->wrong_text, 0, 34, '…'),
                mb_strimwidth((string) $row['rule']->correct_text, 0, 34, '…'),
                $row['rule']->applies_count,
                $row['reason'],
            ], array_slice($rows, 0, 40))
        );

        if (count($rows) > 40) {
            $this->line('  … y ' . (count($rows) - 40) . ' más.');
        }
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
