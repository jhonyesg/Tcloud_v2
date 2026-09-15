<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Registry de jobs en background activos en cualquier módulo del sistema.
 *
 * Responsabilidad: agregar scanners de cada módulo y devolver una lista
 * normalizada de jobs en curso. NO conoce las claves internas de cada módulo:
 * cada scanner encapsula cómo consultar el cache de su módulo.
 *
 * Agregar un nuevo módulo:
 *   1. Crear App\Services\BgJobs\XxxJobScanner con método estático scan(): array
 *   2. Agregar la entrada al array $scanners abajo
 *   3. Listo: el endpoint /bg-jobs/active y el widget del layout lo recogen
 */
class BgJobRegistry
{
    /**
     * Mapa de `kind => [ClaseScanner::class, 'métodoEstático']`.
     *
     * El kind debe ser kebab-case y único en el sistema. Es el prefijo del
     * deep-link `?focus=bg-{kind}-{runId}`.
     *
     * bg-job-indicator-hide-completed: 'transcriptor-batch' ya NO está acá.
     * El progreso del escaneo del API Transcriptor se muestra inline en el
     * propio módulo (/ia/api-transcriptor: header pill línea 911 + modal
     * automático) en lugar de una tarjeta flotante global. Esto evita el
     * ruido visual de tarjetas que reaparecen tras recargar y elimina la
     * necesidad de un descarte manual por parte del operador.
     *
     * El scanner `TranscriptorBatchJobScanner` sigue existiendo por si en el
     * futuro se quiere re-registrar; solo hay que volver a agregarlo acá.
     */
    private array $scanners = [
        'avisos-scan' => [\App\Services\BgJobs\AvisosScanJobScanner::class, 'scan'],
    ];

    /**
     * Descubre todos los jobs en background activos en el momento de la llamada.
     *
     * @return array Lista de jobs normalizados al shape estándar
     *               {kind, runId, module, label, startedAt, progress, url}
     */
    public function discover(): array
    {
        $jobs = [];
        foreach ($this->scanners as $kind => [$scannerClass, $method]) {
            try {
                $found = $scannerClass::$method();
                if (!is_array($found)) {
                    Log::warning("bg_job_registry.scanner_returned_non_array kind={$kind}");
                    continue;
                }
                foreach ($found as $job) {
                    // Normalización defensiva: cada scanner puede tener campos extra
                    // pero el shape base debe estar presente.
                    if (empty($job['kind']) || empty($job['runId']) || empty($job['url'])) {
                        Log::warning("bg_job_registry.scanner_returned_invalid_job kind={$kind}", ['job' => $job]);
                        continue;
                    }
                    $jobs[] = $job;
                }
            } catch (\Throwable $e) {
                // Un scanner que falla NO debe tumbar el polling del widget.
                // Logueamos y seguimos con los demás.
                Log::warning("bg_job_registry.scanner_failed kind={$kind}: {$e->getMessage()}");
            }
        }

        // Orden determinístico por startedAt (los más viejos primero), luego por
        // kind para estabilidad cuando varios arrancan al mismo tiempo.
        usort($jobs, function ($a, $b) {
            $as = $a['startedAt'] ?? '';
            $bs = $b['startedAt'] ?? '';
            if ($as === $bs) {
                return strcmp($a['kind'] ?? '', $b['kind'] ?? '');
            }
            return strcmp($as, $bs);
        });

        return $jobs;
    }

    /**
     * Expone los kinds conocidos (útil para debug o para que el widget pueda
     * saber si debe mostrar un icono específico por módulo).
     */
    public function knownKinds(): array
    {
        return array_keys($this->scanners);
    }
}
