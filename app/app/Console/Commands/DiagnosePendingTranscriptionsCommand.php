<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use Illuminate\Console\Command;

class DiagnosePendingTranscriptionsCommand extends Command
{
    protected $signature = 'transcriptor:diagnose-pending
                            {--json : Imprime salida en formato JSON}';

    protected $description = 'Lista las Transcription en state=pending para diagnóstico de jobs no enviados. Útil cuando la UI muestra Pendientes=0 pero hay jobs sin enviar en BD.';

    public function handle(): int
    {
        $rows = Transcription::with('file:id,name,storage_provider_id')
            ->where('state', Transcription::STATE_PENDING)
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info('✓ No hay filas pendientes');
            }
            return Command::SUCCESS;
        }

        $now = now();

        $data = $rows->map(function (Transcription $t) use ($now) {
            $file = $t->file;
            $storageId = $file?->storage_provider_id;
            $ageMin = (int) round($t->created_at?->diffInSeconds($now, false) / 60);
            $anomaly = !empty($t->job_id);

            return [
                'id' => $t->id,
                'file_id' => $t->file_id,
                'original_name' => $t->original_name ?: ($file?->name ?? null),
                'storage_provider_id' => $storageId,
                'created_at' => $t->created_at?->toIso8601String(),
                'started_at' => $t->started_at?->toIso8601String(),
                'age_minutes' => $ageMin,
                'job_id' => $t->job_id,
                'anomaly' => $anomaly,
            ];
        })->values();

        $data = $data->sortBy(function ($r) {
            return ($r['anomaly'] ? 1 : 0) . '_' . $r['created_at'];
        })->values();

        if ($this->option('json')) {
            $this->line(json_encode($data->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $this->warn("⚠ {$rows->count()} Transcription(s) en state=pending");

        $oldest = $data->min('created_at');
        $this->line("OLDEST: {$oldest}");

        $this->table(
            ['ID', 'File', 'Storage', 'Antigüedad (min)', 'Job ID'],
            $data->map(function ($r) {
                $jobCol = $r['job_id'] ?? '—';
                if ($r['anomaly']) {
                    $jobCol .= ' ⚠ ANOMALÍA';
                }
                return [
                    $r['id'],
                    $r['original_name'] ?? '—',
                    $r['storage_provider_id'] ?? '—',
                    $r['age_minutes'],
                    $jobCol,
                ];
            })->toArray()
        );

        $anomalyCount = $data->where('anomaly', true)->count();
        if ($anomalyCount > 0) {
            $this->error("Detectadas {$anomalyCount} fila(s) con state=pending PERO job_id poblado. Bug grave: deberían tener state distinto.");
        }

        return Command::SUCCESS;
    }
}
