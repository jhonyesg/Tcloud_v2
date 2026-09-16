<?php

namespace App\Services\Dedupe;

/**
 * Decide, para un conjunto de filas de `files`, cual sobrevive y que hay que
 * re-parentar antes de borrar.
 *
 * Logica pura sobre arrays: el comando `files:dedupe` la traduce a SQL, pero el
 * razonamiento se prueba aqui sin base de datos.
 *
 * ## Por que converge en UNA sola pasada
 *
 * 1. `keep_id` es siempre un superviviente, y los supervivientes NUNCA aparecen
 *    como `dup_id`. El mapeo dup -> keep es una funcion de un paso, no una
 *    cadena: no hay clausura transitiva que calcular.
 *
 * 2. Los subarboles duplicados no necesitan ordenarse por profundidad, porque
 *    `path` es la ruta COMPLETA relativa a base_path, no el nombre relativo al
 *    padre. Si la carpeta X esta duplicada 36 veces, los descendientes de cada
 *    copia tienen exactamente el mismo `(storage_provider_id, path)` que los
 *    correspondientes bajo el superviviente: ya son sus propios grupos de
 *    duplicados, con su propio `keep_id`. Fusionar subarboles es re-etiquetar
 *    una columna.
 *
 *    (Si la identidad fuera `(parent_id, name)`, dependeria de la identidad del
 *    padre y SI haria falta procesar por profundidad con pasadas repetidas.)
 *
 * 3. No puede haber colisiones tras la fusion: dos filas solo colisionan bajo el
 *    padre fusionado si comparten `(storage_provider_id, path)` — y entonces una
 *    es el superviviente y la otra esta condenada.
 *
 * 4. No se crean ciclos: el invariante `path = parent.path || '/' || name`
 *    implica que `parent.path` es prefijo estricto de `child.path`; re-parentar
 *    al superviviente de la MISMA ruta lo preserva.
 */
class DedupePlanner
{
    /**
     * @param  list<array{id:int,storage_provider_id:int,path:string,parent_id:?int,has_transcription?:bool}>  $rows
     */
    public function plan(array $rows): DedupePlan
    {
        // Agrupar por la identidad canonica.
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['storage_provider_id'] . "\0" . $row['path']][] = $row;
        }

        $survivorOf = [];   // id condenado => id superviviente
        $survivors = [];    // ids que sobreviven en grupos con duplicados
        $doomed = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            // Superviviente: menor id, PREFIRIENDO el que ya tenga transcripcion.
            // Asi no se tira trabajo de GPU cuando la copia con transcripcion no
            // es la mas antigua.
            usort($group, function (array $a, array $b) {
                $ta = !empty($a['has_transcription']) ? 0 : 1;
                $tb = !empty($b['has_transcription']) ? 0 : 1;

                return $ta <=> $tb ?: $a['id'] <=> $b['id'];
            });

            $keep = $group[0];
            $survivors[$keep['id']] = true;

            foreach (array_slice($group, 1) as $dup) {
                $survivorOf[$dup['id']] = $keep['id'];
                $doomed[] = $dup['id'];
            }
        }

        // Re-parentado: toda fila cuyo padre este condenado pasa al superviviente
        // de ese padre. Debe ocurrir ANTES del DELETE, porque la FK parent_id es
        // ON DELETE CASCADE y arrastraria subarboles legitimos.
        $reparent = [];
        foreach ($rows as $row) {
            $pid = $row['parent_id'] ?? null;
            if ($pid !== null && isset($survivorOf[$pid])) {
                $reparent[$row['id']] = $survivorOf[$pid];
            }
        }

        return new DedupePlan($survivorOf, $reparent, array_keys($survivors), $doomed);
    }
}
