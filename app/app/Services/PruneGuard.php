<?php

namespace App\Services;

/**
 * Decide si es seguro borrar filas de `files` que no se vieron en disco.
 *
 * Funcion pura sin dependencias: todo el estado entra por parametros, para que
 * sea comprobable con tests sin base de datos ni sistema de archivos.
 *
 * Contexto: el 2026-07-27 un montaje NFS caido devolvio un escaneo vacio, el
 * sincronizador lo leyo como "borraron todo el contenido" y ejecuto un borrado
 * en cascada del arbol completo. Al quedar todas las carpetas vacias en BD, cada
 * carga de pagina disparo un re-escaneo concurrente y se insertaron 70.762 filas
 * duplicadas. La regla `refuse_on_empty` de aqui habria cortado la cadena en el
 * primer eslabon.
 */
class PruneGuard
{
    public function __construct(private ?array $config = null) {}

    /**
     * @param  int   $dbCount      TOTAL de filas que la BD tiene para esa carpeta, no
     *                             solo las huerfanas: la regla de proporcion compara
     *                             lo que hay contra lo que se vio en disco, asi que
     *                             necesita el denominador completo.
     * @param  int   $diskCount    entradas realmente vistas en disco
     * @param  bool  $scanOk       si el escaneo fue fiable (ScanResult::$ok)
     * @param  int   $linkedCount  numero de candidatas a borrar que tienen FK aguas abajo
     *                             (transcriptions.file_id, shares.file_id,
     *                             media_edit_jobs.source_file_id). Regla 5.
     * @param  bool  $forced       orden explicita: --force-prune o el boton Actualizar
     */
    public function decide(
        int $dbCount,
        int $diskCount,
        bool $scanOk,
        int $linkedCount = 0,
        bool $forced = false
    ): PruneDecision {
        $cfg = $this->config();

        if (!($cfg['enabled'] ?? true)) {
            return PruneDecision::refuse('prune_disabled');
        }

        // 1. Escaneo no fiable: NUNCA se purga, ni con --force. Si no se pudo
        //    leer el disco, no hay ninguna base para afirmar que algo se borro.
        if (!$scanOk) {
            return PruneDecision::refuse('scan_untrusted');
        }

        if ($dbCount <= 0) {
            return PruneDecision::allow();
        }

        // 5. Candidatas con FK aguas abajo: se marcan 'missing', nunca DELETE.
        //    El CASCADE sobre transcriptions.file_id es destructivo: borrar una
        //    fila con transcripcion arrastra la transcripcion, sus segmentos,
        //    sus keyword matches y sus alert logs en una sola sentencia. El
        //    refresco forzado NO levanta esta guarda: solo el operador con
        //    orden explicita (proxima iteracion) podra desacoplar el vinculo.
        //    Evaluar ANTES de forced() para que la proteccion se mantenga
        //    simetrica.
        if ($linkedCount > 0) {
            return PruneDecision::refuse('orphan_linked', [
                'linked' => $linkedCount,
            ]);
        }

        if ($forced) {
            return PruneDecision::allow('forced');
        }

        // 2. Disco vacio con filas en BD. Casi siempre es un montaje caido, no un
        //    borrado real. Esta regla sola habria evitado el incidente completo.
        if (($cfg['refuse_on_empty'] ?? true) && $diskCount === 0) {
            return PruneDecision::refuse('empty_scan');
        }

        // 3. Borrado masivo proporcional. En carpetas diminutas no se aplica:
        //    borrar 1 de 2 archivos es legitimo y supera cualquier ratio.
        $minRows = (int) ($cfg['min_rows_for_ratio'] ?? 5);
        $maxRatio = (float) ($cfg['max_delete_ratio'] ?? 0.34);

        if ($dbCount >= $minRows) {
            $toDelete = max(0, $dbCount - $diskCount);
            $ratio = $toDelete / $dbCount;

            if ($ratio > $maxRatio) {
                return PruneDecision::refuse('mass_delete_ratio', [
                    'ratio' => round($ratio, 3),
                    'max_ratio' => $maxRatio,
                    // Estimacion, no el conteo real: la guarda solo ve totales, no
                    // que filas emparejaron. El numero exacto lo registra el
                    // llamador como 'orphans'.
                    'estimated_deletes' => $toDelete,
                ]);
            }
        }

        return PruneDecision::allow();
    }

    private function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        return function_exists('config')
            ? (array) config('storage_sync.prune', [])
            : [];
    }
}
