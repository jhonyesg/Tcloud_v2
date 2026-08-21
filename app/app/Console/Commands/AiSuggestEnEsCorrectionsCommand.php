<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ia\CorrectionService;
use App\Services\Ia\LlmCorrectionSettings;
use App\Services\Ia\LlmCorrectionSuggester;
use Illuminate\Console\Command;

/**
 * Suggester LLM-powered para correcciones EN↔ES con contexto y
 * defensa-en-profundidad contra marcas / nombres propios.
 *
 * Complementa a `corrections:mine-en-es` (rule-based):
 *   - mine-en-es: detecta patrones estructurales via heurística.
 *   - ai-suggest: detecta long-tail contextual via LLM con brand exclusion.
 *
 * Uso:
 *   php artisan corrections:ai-suggest --days=1 --sample=200 --dry-run
 *   php artisan corrections:ai-suggest --days=2 --sample=300          # insert
 *
 * Configuración y defaults via LlmCorrectionSettings (DB-overridable
 * desde la UI). Solo api_key queda en .env.
 */
class AiSuggestEnEsCorrectionsCommand extends Command
{
    protected $signature = 'corrections:ai-suggest
                            {--days= : Ventana de días (default llm_correction.days_back desde UI/env)}
                            {--sample= : Tamaño de muestra (default llm_correction.sample_size desde UI/env)}
                            {--dry-run : Solo muestra candidatos, no inserta}
                            {--auto-approve : Inserta con status=approved en lugar de pending (default: lee llm_correction.auto_approve desde UI/env)}';

    protected $description = 'Suggester LLM-powered de correcciones EN↔ES con contexto y exclusión de marcas.';

    public function handle(CorrectionService $service, LlmCorrectionSettings $settings): int
    {
        if (!$settings->bool('enabled')) {
            $this->warn('LLM_CORRECTION_ENABLED=false (o override UI=false). Saliendo sin gastar tokens.');
            return self::SUCCESS;
        }

        $apiKey = $settings->apiKey();
        if ($apiKey === '') {
            $this->error('LLM_API_KEY no configurada en .env. Setea LLM_API_KEY=sk-... y reintentar.');
            return self::FAILURE;
        }

        $days = (int) ($this->option('days') ?? $settings->int('days_back'));
        $sample = (int) ($this->option('sample') ?? $settings->int('sample_size'));
        $dryRun = (bool) $this->option('dry-run');
        // CLI --auto-approve gana sobre el setting; si no se pasó flag,
        // leemos el toggle desde LlmCorrectionSettings (DB-overridable).
        // Truco: option('auto-approve') retorna null si no se pasó, pero el
        // CLI parser de Laravel marca boolean flags con presencia. Verificamos
        // explícitamente con $this->input para evitar el bug de "false string".
        $cliAutoApprove = $this->input->hasParameterOption('--auto-approve');
        $autoApprove = $cliAutoApprove || $settings->bool('auto_approve');

        $this->info(sprintf(
            'AI suggest EN↔ES: days=%d sample=%d model=%s auto_approve=%s%s',
            $days,
            $sample,
            $settings->str('model'),
            $autoApprove ? 'true' : 'false',
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        if ($dryRun) {
            $suggester = new LlmCorrectionSuggester();
            $result = $suggester->suggest($days, $sample);

            if (isset($result['error'])) {
                $this->error('LLM error: ' . $result['error']);
                return self::FAILURE;
            }

        if (empty($result['candidates'])) {
            $this->warn('Dry-run: 0 candidatos. Rejected=' . count($result['rejected_by_filter']));
            return self::SUCCESS;
        }

        $this->table(
            ['Wrong', 'Correct', 'Freq', 'Confidence', 'Reason'],
            array_map(
                fn($c) => [$c['wrong'], $c['correct'], $c['freq'], $c['confidence'], $c['reason']],
                $result['candidates']
            )
        );
        $this->info('Rechazados por filtro (marcas/siglas): ' . count($result['rejected_by_filter']));
        $this->info('Rechazados por longitud: ' . count($result['rejected_by_length'] ?? []));
        $this->info('Promoted to atomic (bigramas extraídos): ' . count($result['atomic_candidates'] ?? []));
        $this->info('Segments procesados: ' . $result['segments_processed']);
        $this->info('Cacheados hoy (skipped): ' . $result['cached_today']);
        $this->info('Source: ' . $result['source']);
        return self::SUCCESS;
    }

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        if (!$admin) {
            $this->error('No se encontró usuario admin.');
            return self::FAILURE;
        }

        $result = $service->aiSuggestEnEsMix($days, $sample, $admin, $autoApprove);

        if (isset($result['error'])) {
            $this->error('LLM error: ' . $result['error']);
            return self::FAILURE;
        }

        $this->info('Mined: ' . $result['mined']);
        $this->info('Inserted (pending): ' . $result['inserted']);
        $this->info('Skipped (pending duplicado): ' . $result['skipped_duplicate']);
        $this->info('Rechazados por traducción EN->ES: ' . ($result['rejected_en_es'] ?? 0));
        $this->info('Rechazados por filtro: ' . $result['rejected_by_filter']);
        $this->info('Source: ' . $result['source']);

        if ($autoApprove) {
            $this->warn('La auto-aprobación está desactivada desde 2026-08-11: los candidatos entran como pending y los aprueba un admin en /ia/correcciones.');
        }

        return self::SUCCESS;
    }
}
