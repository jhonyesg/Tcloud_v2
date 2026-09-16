<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranscriptionPurgeStalePendingCommand extends Command
{
    protected $signature = 'transcription:purge-stale-pending
                            {--days= : (Opcional) ventana movil de N dias en vez del corte "hoy". Si se omite, se purga todo lo ANTERIOR A HOY (America/Bogota).}
                            {--batch= : Tamano del chunk de procesamiento (default 500)}
                            {--max-ratio= : Ratio maxima candidatos/total (default 0.5)}
                            {--dry-run : Cuenta candidatos sin modificar BD}';

    protected $description = 'Promueve a dead los pending que NO son de hoy (o mas viejos que N dias si se pasa --days). Respeta guardarrail de ratio y libera el audio staged.';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $batch = max(1, (int) ($this->option('batch') ?? 500));
        $maxRatio = (float) ($this->option('max-ratio') ?? 0.5);
        $dryRun = (bool) $this->option('dry-run');

        // Corte por defecto: inicio del dia en America/Bogota. Es el MISMO eje
        // que usan el worker PG y el tick (`recorded_at >= today`), asi que lo
        // que se purga es exactamente lo que el worker nunca va a tomar.
        //
        // Antes esto era `created_at < now()->subDays(N)` con N=15: una ventana
        // MOVIL que no coincide con la regla de negocio ("solo lo de hoy") y que
        // ademas dejaba huecos por el desfase entre ejes. Medido: con el corte
        // movil de 24 h se purgaban 2.692 filas pero quedaban 3.991 pre-hoy sin
        // tocar (descubiertas hoy con fecha de programa anterior), y el comando
        // no estaba programado, asi que no purgaba nada nunca.
        $cutoff = $days !== null
            ? now()->subDays($days)
            : BogotaTime::todayStart();

        $this->info(sprintf(
            "transcription:purge-stale-pending starting (%s=%s, batch=%d, max_ratio=%s%s)",
            $days !== null ? 'days' : 'corte',
            $days !== null ? $days : $cutoff->toDateTimeString(),
            $batch,
            $maxRatio,
            $dryRun ? ', DRY-RUN' : ''
        ));

        $lock = Cache::lock('transcription:purge-stale-pending', 600);
        if (!$lock->get()) {
            $this->warn('Otra instancia en curso; abortando.');
            Log::info('transcription.purge.skipped_locked');

            return self::SUCCESS;
        }

        try {
            // `recorded_at IS NULL` entra tambien: el worker filtra
            // `recorded_at >= today`, que para NULL es falso, asi que esas filas
            // jamas serian reclamadas. Dejarlas seria basura permanente.
            $candidatesQuery = fn () => Transcription::where('state', Transcription::STATE_PENDING)
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('recorded_at')
                      ->orWhere('recorded_at', '<', $cutoff);
                });

            $candidates = $candidatesQuery()->count();
            $total = Transcription::count();

            if ($total === 0) {
                $this->info('Sin transcripciones registradas.');

                return self::SUCCESS;
            }

            $ratio = $candidates / $total;
            $this->line(sprintf(
                'Candidatos (pending fuera de alcance antes de %s): %d de %d (ratio=%.4f, max=%.2f)',
                $cutoff->toDateTimeString(), $candidates, $total, $ratio, $maxRatio
            ));

            if ($ratio > $maxRatio) {
                Log::warning('transcription.purge.aborted_mass_delete', [
                    'candidates' => $candidates,
                    'total' => $total,
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                    'cutoff' => $cutoff->toIso8601String(),
                ]);
                $this->error(sprintf(
                    'ABORTADO: ratio %.4f excede max_ratio %.2f. Probablemente estas viendo la BD completa de transcriptions, no solo el universo a purgar. Sube --max-ratio o filtra el universo con otra precondicion.',
                    $ratio, $maxRatio
                ));

                return self::SUCCESS;
            }

            if ($dryRun) {
                Log::info('transcription.purge.dry_run', [
                    'candidates' => $candidates,
                    'cutoff' => $cutoff->toIso8601String(),
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                ]);
                $this->info(sprintf('DRY-RUN: %d serian promovidos a dead.', $candidates));

                return self::SUCCESS;
            }

            $promoted = 0;
            $stagedFreed = 0;

            // chunkById + cursor: se procesa en lotes para no abrir una
            // transaccion gigante ni disparar un UPDATE masivo sobre 400k filas.
            $candidatesQuery()
                ->whereNotNull('id')
                ->orderBy('id')
                ->chunkById($batch, function ($rows) use (&$promoted, &$stagedFreed, $cutoff) {
                    foreach ($rows as $row) {
                        // Liberar el WAV staged antes de cerrar la fila: una fila
                        // dead no va a enviar su audio, y el sender solo mira
                        // `pending`. Sin esto el tmpfs se llenaria de basura.
                        $path = $row->getAttribute('staged_path');
                        if (is_string($path) && $path !== '' && is_file($path)) {
                            @unlink($path);
                            $stagedFreed++;
                        }

                        $row->forceFill([
                            'state' => Transcription::STATE_DEAD,
                            'error_message' => "Purgado por alcance: pending fuera del dia actual (registro anterior a {$cutoff->toDateTimeString()}). transcription:purge-stale-pending.",
                            'finished_at' => now(),
                            'staged_path' => null,
                            'staged_bytes' => null,
                            'staged_at' => null,
                        ])->saveQuietly();
                        $promoted++;
                    }
                });

            Log::info('transcription.purge.completed', [
                'promoted' => $promoted,
                'staged_freed' => $stagedFreed,
                'candidates' => $candidates,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            $this->info(sprintf('Promovidos a dead: %d (audios staged liberados: %d)', $promoted, $stagedFreed));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('transcription.purge.unhandled_exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
