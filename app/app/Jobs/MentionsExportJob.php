<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ia\MentionsSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Export CSV del histórico de menciones (mis-avisos-menciones Fase 3).
 *
 * Corre en la cola Redis. Filtra SIEMPRE por MentionsSearchService::visibleHitsQuery
 * (la seam de acceso del usuario) — jamás por parámetros del request. Stream
 * con fputcsv + BOM UTF-8 para acentos correctos en Excel. El resultado se
 * entrega como link firmado con expiración.
 */
class MentionsExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        public int $userId,
        public int $exportId,
        public array $filters,
    ) {}

    public function handle(MentionsSearchService $search): void
    {
        $user = User::find($this->userId);
        $export = DB::table('mentions_exports')->find($this->exportId);
        if (!$user || !$export || $export->status === 'cancelled') {
            return;
        }

        try {
            DB::table('mentions_exports')
                ->where('id', $this->exportId)
                ->update(['status' => 'processing', 'started_at' => now()]);

            $filename = "mis-avisos-historico-{$this->exportId}-" . now()->format('YmdHis') . '.csv';
            $relativePath = "mentions-exports/{$filename}";
            $fullPath = storage_path("app/{$relativePath}");
            @mkdir(dirname($fullPath), 0775, true);

            $fh = fopen($fullPath, 'w');
            // BOM UTF-8: Excel respeta acentos/ñ al abrir el CSV directo.
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['fecha', 'medio', 'canal', 'minuto', 'keyword', 'fragmento'], ';');

            $count = 0;
            // Chunk por ID descendente: no paginar offset sobre sets grandes.
            $lastId = PHP_INT_MAX;
            while (true) {
                $rows = $search->visibleHitsQuery($user)
                    ->when(!empty($this->filters['from']), fn ($q) => $q->where('h.matched_at', '>=', \Carbon\Carbon::parse($this->filters['from'])->startOfDay()))
                    ->when(!empty($this->filters['to']), fn ($q) => $q->where('h.matched_at', '<=', \Carbon\Carbon::parse($this->filters['to'])->endOfDay()))
                    ->when(!empty($this->filters['storage_ids']), function ($q) use ($user, $search) {
                        $allowed = array_intersect((array) $this->filters['storage_ids'], $search->accessibleStorageIds($user));
                        $allowed ? $q->whereIn('f.storage_provider_id', $allowed) : $q->whereRaw('1=0');
                    })
                    ->when(!empty($this->filters['q']) && mb_strlen(trim($this->filters['q'])) >= 3, function ($q) {
                        $term = mb_strtolower(trim($this->filters['q']));
                        $q->where(function ($tq) use ($term) {
                            $tq->whereRaw('lower(h.snippet) like ?', ["%{$term}%"])
                                ->orWhereRaw('lower(seg.text) like ?', ["%{$term}%"]);
                        });
                    })
                    ->when(!empty($this->filters['keyword_id']), fn ($q) => $q->where('h.keyword_id', (int) $this->filters['keyword_id']))
                    ->where('h.id', '<', $lastId)
                    ->orderByDesc('h.id')
                    ->limit(1000)
                    ->get([
                        'h.id', 'h.snippet', 'h.matched_at',
                        'k.text as keyword',
                        'f.name as filename',
                        'sp.name as storage_name',
                        'seg.start_seconds',
                    ]);

                if ($rows->isEmpty()) {
                    break;
                }

                foreach ($rows as $r) {
                    $minute = (function (float $s): string {
                        $t = (int) floor($s);
                        return sprintf('%02d:%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60);
                    })((float) $r->start_seconds);
                    fputcsv($fh, [
                        $r->matched_at,
                        (string) $r->filename,
                        (string) ($r->storage_name ?? ''),
                        $minute,
                        (string) $r->keyword,
                        (string) $r->snippet,
                    ], ';');
                    $count++;
                    $lastId = (int) $r->id;
                }
            }
            fclose($fh);

            $url = URL::temporarySignedRoute(
                'mis-avisos.exports.download',
                now()->addMinutes((int) config('avisos.exports.signed_url_ttl_minutes', 120)),
                ['export' => $this->exportId]
            );

            DB::table('mentions_exports')
                ->where('id', $this->exportId)
                ->update([
                    'status' => 'ready',
                    'rows_count' => $count,
                    'file_path' => $relativePath,
                    'download_url' => $url,
                    'finished_at' => now(),
                ]);

            Log::info('mentions.export_ready', [
                'export_id' => $this->exportId,
                'user_id' => $this->userId,
                'rows' => $count,
            ]);
        } catch (\Throwable $e) {
            DB::table('mentions_exports')
                ->where('id', $this->exportId)
                ->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 500),
                    'finished_at' => now(),
                ]);
            Log::error('mentions.export_failed', [
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}