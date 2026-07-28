<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use App\Services\Ia\CorrectionService;
use App\Services\Ia\SrtParser;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recupera el texto de los segmentos que se truncaron a 500 caracteres.
 *
 * `SrtParser` cortaba todo segmento de mas de 500 chars "para no inflar la BD con
 * basura". La premisa era falsa: la columna es `text` (ilimitada) y lo cortado
 * era habla real de ~30s sin pausas, tipica de emisoras de radio. Ese texto
 * dejaba de aparecer en las busquedas.
 *
 * Este comando NO reprocesa audio ni gasta GPU: vuelve a descargar el SRT que el
 * transcriptor ya genero (via job_id + node_url) y re-parsea con el limite nuevo.
 * El transcriptor conserva los SRT unos 7 dias, asi que lo anterior a esa ventana
 * es irrecuperable y el comando lo reporta como tal.
 *
 * Reaplica las correcciones aprobadas para que `text` quede coherente con
 * `text_raw`, igual que hace TranscriptionProcessor al procesar un SRT nuevo.
 */
class RepairTruncatedSegments extends Command
{
    protected $signature = 'transcription:repair-truncated
                            {--dry-run : Analiza y reporta sin modificar nada}
                            {--limit=0 : Maximo de transcripciones a procesar (0 = todas)}
                            {--since= : Solo transcripciones creadas desde esta fecha (Y-m-d)}';

    protected $description = 'Recupera el texto de los segmentos truncados a 500 chars, re-descargando el SRT original (sin coste de GPU).';

    /** Longitud exacta que delata un segmento truncado por el limite antiguo. */
    private const LEGACY_LIMIT = SrtParser::LEGACY_MAX_SEGMENT_CHARS;

    public function handle(TranscriptorApiClient $client, SrtParser $parser, CorrectionService $corrections): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $since = $this->option('since');

        $current = (int) config('transcriptor.srt_max_segment_chars', 3000);
        if ($current > 0 && $current <= self::LEGACY_LIMIT) {
            $this->error("srt_max_segment_chars={$current}: reparar no sirve de nada si el limite sigue en " . self::LEGACY_LIMIT . " o menos.");

            return Command::FAILURE;
        }

        DB::statement('set max_parallel_workers_per_gather = 0');

        $ids = $this->affectedTranscriptionIds($since, $limit);

        if ($ids === []) {
            $this->info('No hay transcripciones con segmentos truncados.');

            return Command::SUCCESS;
        }

        $this->info(count($ids) . ' transcripciones con segmentos truncados.');
        if ($dryRun) {
            $this->warn('DRY-RUN: no se modificara nada.');
        }

        $stats = ['reparadas' => 0, 'segmentos' => 0, 'chars' => 0, 'purgadas' => 0, 'sin_cambio' => 0, 'error' => 0];
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        foreach ($ids as $id) {
            $this->repairOne($id, $client, $parser, $corrections, $dryRun, $stats);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Resultado', 'Valor'], [
            ['Transcripciones reparadas', number_format($stats['reparadas'])],
            ['Segmentos recuperados', number_format($stats['segmentos'])],
            ['Caracteres recuperados', number_format($stats['chars'])],
            ['SRT ya purgado del transcriptor', number_format($stats['purgadas'])],
            ['Sin cambios', number_format($stats['sin_cambio'])],
            ['Errores', number_format($stats['error'])],
        ]);

        if ($stats['purgadas'] > 0) {
            $this->warn("{$stats['purgadas']} transcripciones son irrecuperables: el transcriptor ya no conserva su SRT (retencion ~7 dias).");
        }

        if (!$dryRun) {
            Log::info('repair_truncated.completed', $stats);
        }

        return Command::SUCCESS;
    }

    /** @return list<int> */
    private function affectedTranscriptionIds(?string $since, int $limit): array
    {
        $q = Transcription::query()
            ->whereIn('id', function ($sub) {
                $sub->from('transcription_segments')
                    ->select('transcription_id')
                    ->whereRaw('length(text_raw) = ?', [self::LEGACY_LIMIT])
                    ->distinct();
            })
            ->whereNotNull('job_id')
            ->orderByDesc('created_at');   // los mas recientes primero: son los recuperables

        if ($since) {
            $q->whereDate('created_at', '>=', $since);
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->pluck('id')->all();
    }

    private function repairOne(
        int $id,
        TranscriptorApiClient $client,
        SrtParser $parser,
        CorrectionService $corrections,
        bool $dryRun,
        array &$stats
    ): void {
        $tx = Transcription::find($id);
        if (!$tx) {
            $stats['error']++;

            return;
        }

        try {
            $srt = $client->getSrt((string) $tx->job_id, (string) $tx->node_url);
        } catch (\Throwable $e) {
            // El transcriptor ya no lo tiene: irrecuperable, no es un fallo.
            $stats['purgadas']++;

            return;
        }

        $parsed = $parser->parse($srt);
        if ($parsed === []) {
            $stats['error']++;

            return;
        }

        // Reaplicar correcciones aprobadas, igual que TranscriptionProcessor.
        $withRaw = array_map(fn ($s) => array_merge($s, ['text_raw' => $s['text']]), $parsed);
        $withRaw = $corrections->applyToSegments($withRaw);

        $byIndex = [];
        foreach ($withRaw as $s) {
            $byIndex[(int) $s['index']] = $s;
        }

        $truncated = TranscriptionSegment::where('transcription_id', $id)
            ->whereRaw('length(text_raw) = ?', [self::LEGACY_LIMIT])
            ->get();

        $recovered = 0;
        $chars = 0;

        foreach ($truncated as $seg) {
            $fresh = $byIndex[(int) $seg->segment_index] ?? null;
            if ($fresh === null) {
                continue;
            }

            // Solo se escribe si el SRT trae MAS texto del que hay guardado.
            // Asi el comando es idempotente y nunca acorta nada.
            if (mb_strlen($fresh['text_raw']) <= mb_strlen((string) $seg->text_raw)) {
                continue;
            }

            $chars += mb_strlen($fresh['text_raw']) - mb_strlen((string) $seg->text_raw);
            $recovered++;

            if (!$dryRun) {
                $seg->update([
                    'text_raw' => $fresh['text_raw'],
                    'text' => $fresh['text'],
                ]);
            }
        }

        if ($recovered > 0) {
            $stats['reparadas']++;
            $stats['segmentos'] += $recovered;
            $stats['chars'] += $chars;
        } else {
            $stats['sin_cambio']++;
        }
    }
}
