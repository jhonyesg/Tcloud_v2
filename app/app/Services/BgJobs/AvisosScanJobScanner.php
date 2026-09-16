<?php

namespace App\Services\BgJobs;

use Illuminate\Support\Facades\Cache;

/**
 * Scanner de jobs de "Avisos Inteligentes" en background.
 *
 * El módulo de avisos mantiene:
 *   - cache `avisos_scan_bg:active` con `{runId}` mientras hay un scan corriendo
 *   - cache `avisos_scan_bg:{runId}` con el estado/progreso del run
 *
 * (No `avisos_scan_run:` como erróneamente asumí en mi primera versión —
 * la convención real del módulo es `avisos_scan_bg:` para el state, mismo
 * prefijo que el pointer, solo que el pointer tiene sufijo `:active`.)
 *
 * Este scanner lee ambas y devuelve el shape normalizado que consume el
 * widget global.
 */
class AvisosScanJobScanner
{
    public static function scan(): array
    {
        $active = Cache::get('avisos_scan_bg:active');
        if (!is_array($active) || empty($active['runId'])) {
            return [];
        }
        $runId = (string) $active['runId'];
        $state = Cache::get("avisos_scan_bg:{$runId}");
        if (!is_array($state)) {
            // La clave `active` quedó huérfana (TTL expiró en active pero el
            // state sigue vivo, o viceversa). Limpiar active para no reportar
            // un fantasma.
            Cache::forget('avisos_scan_bg:active');
            return [];
        }

        $scanned = (int) ($state['scanned'] ?? 0);
        $estimate = isset($state['attempted_count']) ? (int) $state['attempted_count'] : null;
        $hitsNew = (int) ($state['hits_new'] ?? 0);
        $failed = (int) ($state['failed'] ?? 0);
        $batches = (int) ($state['batches'] ?? 0);
        $startedAt = $state['started_at'] ?? null;

        // Etiqueta humana: indica tanda actual si hay batches, o "iniciando"
        // si todavía no hay progreso registrado.
        $label = 'Escaneo de avisos';
        if ($batches > 0) {
            $label .= " (tanda {$batches})";
        }

        return [[
            'kind' => 'avisos-scan',
            'runId' => $runId,
            'module' => 'Avisos Inteligentes',
            'label' => $label,
            'startedAt' => $startedAt,
            'progress' => [
                'scanned' => $scanned,
                'estimate' => $estimate,
                'hits_new' => $hitsNew,
                'failed' => $failed,
                'batches' => $batches,
            ],
            'url' => '/ia/avisos-inteligentes?focus=bg-avisos-scan-' . $runId,
        ]];
    }
}
