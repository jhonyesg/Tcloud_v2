<?php

namespace App\Console\Commands;

use App\Services\FileScannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * fix-disk-scanner-mime-type-from-extension: reconcilia `files.mime_type`
 * para filas heredadas del bug del disk scanner (que escribía literal
 * 'video/mp4' para todos los archivos descubiertos, contaminando los
 * archivos de audio de emisoras de radio en Mis Avisos).
 *
 * Reglas operativas:
 *   - Solo se candidatean filas con `mime_type='video/mp4'` cuyo nombre
 *     termina en una extensión de audio (.mp3 .m4a .opus .flac .wav .aac).
 *   - Filas con extensión genuinamente de video (.mp4 .mkv) NO se tocan.
 *   - Sin --apply corre en modo dry-run: cuenta y reporta, no muta.
 *   - Procesa en chunks de 500 IDs (--chunk) para no mantener locks largos.
 *   - Loggea con prefijo `files.mime_reconcile.*` para auditoría.
 *
 * Uso:
 *   php artisan avisos:reconcile-file-mime-types                 # dry-run
 *   php artisan avisos:reconcile-file-mime-types --apply        # mutar
 *   php artisan avisos:reconcile-file-mime-types --chunk=200     # stress test
 */
class ReconcileFileMimeTypesCommand extends Command
{
    protected $signature = 'avisos:reconcile-file-mime-types
        {--apply : Aplicar los cambios (sin este flag solo reporta)}
        {--chunk=500 : Tamaño del chunk en IDs por iteración}';

    protected $description = 'Reescribe files.mime_type desde la extensión real del nombre (repara bug del disk scanner con audio mal etiquetado como video/mp4)';

    private const AUDIO_EXTENSION_REGEX = '\.(mp3|m4a|opus|flac|wav|aac)$';

    public function handle(FileScannerService $fileScanner): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));

        $dryRun = !$apply;
        $logBase = [
            'apply' => $apply,
            'chunk' => $chunk,
            'dry_run' => $dryRun,
        ];

        Log::warning('files.mime_reconcile.started', $logBase);

        $startedAt = microtime(true);

        $totalCandidates = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $lastId = 0;

        // Recorremos por IDs ascendentes con paginación cursor-like
        // (id > lastId) — más rápido que OFFSET y consistente con un
        // WHERE estrictamente acotado (constraint no_consultas_pesadas_masivas_servidor).
        while (true) {
            $rows = DB::table('files')
                ->select('id', 'name')
                ->where('mime_type', 'video/mp4')
                ->where('name', '~*', self::AUDIO_EXTENSION_REGEX)
                ->where('id', '>', $lastId)
                ->orderBy('id', 'asc')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $chunkUpdated = 0;
            $chunkSkipped = 0;

            if ($apply) {
                $perMime = [];
                foreach ($rows as $row) {
                    $newMime = $fileScanner->getMimeType($row->name);
                    if ($newMime === 'application/octet-stream') {
                        $chunkSkipped++;
                        continue;
                    }
                    if ($newMime === 'video/mp4') {
                        // Map no devolvió un mime de audio (raro; p.ej. si
                        // alguien nombró "x.mp3" pero la extensión está en
                        // otra posición). No tocamos para no romper nada.
                        $chunkSkipped++;
                        continue;
                    }
                    $perMime[$newMime][] = $row->id;
                }

                foreach ($perMime as $mime => $ids) {
                    DB::table('files')
                        ->whereIn('id', $ids)
                        ->update(['mime_type' => $mime]);
                    $chunkUpdated += count($ids);
                }
            } else {
                $chunkUpdated = $rows->count();
            }

            $totalCandidates += $rows->count();
            $totalUpdated += $chunkUpdated;
            $totalSkipped += $chunkSkipped;

            Log::warning('files.mime_reconcile.chunk', [
                'candidates' => $rows->count(),
                'updated' => $chunkUpdated,
                'skipped_unknown_extension' => $chunkSkipped,
                'last_id' => $rows->last()->id,
            ]);

            $lastId = $rows->last()->id;

            // Liberar la conexión tras cada chunk para evitar acumular
            // cursors sobre PG (constraint no_consultas_pesadas_masivas_servidor).
            DB::disconnect();
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        Log::warning('files.mime_reconcile.finished', [
            'total_candidates' => $totalCandidates,
            'total_updated' => $totalUpdated,
            'total_skipped_unknown_extension' => $totalSkipped,
            'duration_ms' => $durationMs,
        ]);

        $this->line('=== Reconciliación files.mime_type ===');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Modo', $apply ? 'APPLY (mutado)' : 'DRY-RUN (sin mutar)'],
                ['Chunk size', $chunk],
                ['Filas candidatas (mime=video/mp4 + ext audio)', $totalCandidates],
                ['Filas actualizadas', $totalUpdated],
                ['Saltadas (ext fuera de mapa)', $totalSkipped],
                ['Duración', "{$durationMs} ms"],
            ],
        );

        if ($totalUpdated > 0) {
            $this->info("Estimado: {$totalUpdated} filas se reclasificarán de TV → Radio en Mis Avisos tras la próxima carga.");
        } elseif ($apply) {
            $this->info('Sin filas para reconciliar — datos ya correctos.');
        }

        if ($dryRun && $totalCandidates > 0) {
            $this->line('');
            $this->line('Para aplicar los cambios: php artisan avisos:reconcile-file-mime-types --apply');
        }

        return self::SUCCESS;
    }
}
