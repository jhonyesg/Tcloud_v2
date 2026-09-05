<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptionProcessor;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-procesa transcripciones done de los últimos N días con el pase de
 * coherencia IA, en lotes pequeños para no saturar la BD ni el LLM.
 *
 * (2026-08-16) El pase de coherencia IA se desplegó el 15-08. Las
 * transcripciones anteriores quedaron con spanglish residual sin corregir.
 * Este comando las re-procesa reutilizando el srt_content guardado y
 * aplicando diccionario + pase IA, en lotes de `--batch` con pausa
 * `--sleep` para no recargar el sistema.
 *
 * Uso:
 *   php artisan transcription:backfill-coherence --days=7 --batch=5 --sleep=2
 *   php artisan transcription:backfill-coherence --days=7 --dry-run
 */
class TranscriptionBackfillCoherenceCommand extends Command
{
    protected $signature = 'transcription:backfill-coherence
                            {--days=7 : Días hacia atrás a re-procesar}
                            {--batch=5 : Transcripciones por lote}
                            {--sleep=2 : Pausa en segundos entre lotes}
                            {--limit= : Tope total de transcripciones a procesar}
                            {--from-id= : Id mínimo de transcripción (para paralelizar)}
                            {--to-id= : Id máximo de transcripción (para paralelizar)}
                            {--dry-run : Solo muestra cuántas procesaría, no toca BD}';

    protected $description = 'Re-procesa transcripciones done con el pase de coherencia IA, en lotes pequeños.';

    public function handle(TranscriptionProcessor $processor, TranscriptorSettings $settings): int
    {
        // (changes/2026-08-25 llm-coherence-manual-only-defaults-off) El pase
        // de coherencia IA es manual-only por defecto. Si el toggle maestro
        // está apagado, salimos sin gastar tokens ni tocar BD: el admin tiene
        // que activarlo explícitamente desde AI Settings o vía .env.
        if (!$settings->bool('ai_coherence_enabled')) {
            $this->warn('[WARNING] El pase de coherencia IA está deshabilitado. Activalo desde AI Settings antes de correr este comando.');
            Log::warning('transcription:backfill-coherence abortado: ai_coherence_enabled=0');
            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $batch = max(1, (int) $this->option('batch'));
        $sleep = max(0, (int) $this->option('sleep'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $since = now()->subDays($days);
        $fromId = $this->option('from-id') !== null ? (int) $this->option('from-id') : null;
        $toId = $this->option('to-id') !== null ? (int) $this->option('to-id') : null;

        // Seleccionar transcripciones done con spanglish residual (texto con
        // función EN + acentos ES) en la ventana. Solo las que el pase IA
        // corregiría, para no re-procesar las que ya están limpias.
        $query = Transcription::query()
            ->where('state', Transcription::STATE_DONE)
            ->where('created_at', '>=', $since)
            ->whereNotNull('srt_content')
            ->whereHas('segments', function ($q) {
                $q->whereRaw("text ~* '\\m(the|and|of|is|are|was|in|for|to|with)\\M'")
                  ->whereRaw("text ~* '[áéíóúñ]'")
                  ->whereRaw('length(text) > 40');
            })
            ->orderBy('id', 'desc');

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }
        if ($toId !== null) {
            $query->where('id', '<=', $toId);
        }

        $total = (clone $query)->count();
        $this->info("Transcripciones con spanglish residual en {$days} días: {$total}");

        if ($dryRun) {
            $this->info("[DRY-RUN] Procesaría hasta " . ($limit ?? $total) . " transcripciones en lotes de {$batch}.");
            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('Nada que re-procesar.');
            return self::SUCCESS;
        }

        $processed = 0;
        $corrected = 0;
        $errors = 0;

        $query->chunkById($batch, function ($transcriptions) use (&$processed, &$corrected, &$errors, $sleep, $limit, $processor) {
            foreach ($transcriptions as $tx) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }

                try {
                    $before = $tx->segments()->whereRaw("text ~* '\\m(the|and|of|is|are|was|in|for|to|with)\\M'")->count();
                    $processor->reprocessCorrected($tx, $tx->srt_content);
                    $after = $tx->segments()->whereRaw("text ~* '\\m(the|and|of|is|are|was|in|for|to|with)\\M'")->count();
                    $processed++;
                    if ($after < $before) {
                        $corrected++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning("backfill-coherence: error tx {$tx->id}: {$e->getMessage()}");
                }
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        });

        $this->info("Backfill coherencia: procesadas={$processed}, corregidas={$corrected}, errores={$errors}.");
        return self::SUCCESS;
    }
}
