<?php

namespace App\Services\Dedupe;

final class DedupePlan
{
    /**
     * @param  array<int,int>  $survivorOf  id condenado => id superviviente
     * @param  array<int,int>  $reparent    id de fila => nuevo parent_id
     * @param  list<int>       $survivors   ids que sobreviven en grupos duplicados
     * @param  list<int>       $doomed      ids a borrar
     */
    public function __construct(
        public readonly array $survivorOf,
        public readonly array $reparent,
        public readonly array $survivors,
        public readonly array $doomed,
    ) {}

    public function isEmpty(): bool
    {
        return $this->doomed === [];
    }

    public function doomedCount(): int
    {
        return count($this->doomed);
    }

    public function reparentCount(): int
    {
        return count($this->reparent);
    }

    /**
     * Propiedad de seguridad frente al CASCADE: ninguna fila que SOBREVIVA puede
     * quedar colgando de un padre condenado.
     *
     * Si esto es falso, ejecutar el DELETE borraria filas legitimas por cascada.
     * El comando lo comprueba tambien en SQL contra los datos reales, antes de
     * borrar nada.
     *
     * @param  list<array{id:int,parent_id:?int}>  $rowsAfterReparent
     */
    public function noSurvivorHangsFromDoomedParent(array $rowsAfterReparent): bool
    {
        $doomed = array_flip($this->doomed);

        foreach ($rowsAfterReparent as $row) {
            $pid = $row['parent_id'] ?? null;
            if ($pid !== null && isset($doomed[$pid]) && !isset($doomed[$row['id']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aplica el plan a las filas en memoria. Solo para tests: permite comprobar
     * la idempotencia re-planificando sobre el resultado.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    public function applyTo(array $rows): array
    {
        $doomed = array_flip($this->doomed);
        $out = [];

        foreach ($rows as $row) {
            if (isset($doomed[$row['id']])) {
                continue;
            }
            if (isset($this->reparent[$row['id']])) {
                $row['parent_id'] = $this->reparent[$row['id']];
            }
            $out[] = $row;
        }

        return $out;
    }
}
