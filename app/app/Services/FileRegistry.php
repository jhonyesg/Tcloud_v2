<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Punto unico de creacion de filas en `files`.
 *
 * Antes habia tres nociones distintas de identidad, ninguna respaldada por una
 * restriccion en BD:
 *
 *   StorageSyncService  -> (storage_provider_id, parent_id, path)
 *   DiskScannerService  -> (storage_provider_id, path)
 *   FileController      -> (parent_id, name, storage_provider_id)
 *
 * La canonica es `(storage_provider_id, path)`, porque `path` es la ruta
 * completa relativa a base_path: identifica el archivo sin depender de que el
 * padre este bien resuelto. La clave de sync, al estar acotada por `parent_id`,
 * no veia una fila con la misma ruta bajo otro padre y creaba una copia — que es
 * como se propagaba la duplicacion hacia abajo en el arbol.
 *
 * `ensure()` es un upsert semantico: si dos procesos compiten, uno gana y el
 * otro lee al ganador en vez de insertar un duplicado.
 */
class FileRegistry
{
    /** Codigo SQLSTATE de violacion de unicidad en Postgres. */
    private const UNIQUE_VIOLATION = '23505';

    /**
     * Devuelve la fila para (storage, path), creandola si no existe.
     *
     * @param  array<string,mixed>  $attributes  atributos para la creacion
     */
    public function ensure(StorageProvider $storage, string $path, array $attributes): File
    {
        $path = $this->normalizePath($path);

        $existing = $this->find($storage->id, $path);

        if ($existing !== null) {
            $this->heal($existing, $attributes);

            return $existing;
        }

        try {
            return File::create($attributes + [
                'storage_provider_id' => $storage->id,
                'path' => $path,
            ]);
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            // Perdimos la carrera: otro proceso creo la fila entre nuestro SELECT
            // y nuestro INSERT. Leer al ganador es el comportamiento correcto.
            $winner = $this->find($storage->id, $path);

            if ($winner === null) {
                // Violacion de unicidad por otra restriccion distinta a
                // (storage_provider_id, path): no es nuestra carrera.
                throw $e;
            }

            Log::info('file_registry.race_lost', [
                'storage_provider_id' => $storage->id,
                'path' => $path,
                'winner_id' => $winner->id,
            ]);

            $this->heal($winner, $attributes);

            return $winner;
        }
    }

    public function find(int $storageId, string $path): ?File
    {
        return File::where('storage_provider_id', $storageId)
            ->where('path', $this->normalizePath($path))
            ->first();
    }

    /**
     * Corrige una fila existente para que converja al estado esperado.
     *
     * El `parent_id` importa: filas escritas por distintos productores podian
     * quedar colgando de padres distintos para la misma ruta. Sanearlo aqui hace
     * que el arbol converja sin necesidad de borrar y recrear.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function heal(File $file, array $attributes): void
    {
        $changes = [];

        if (array_key_exists('parent_id', $attributes)
            && $file->parent_id !== $attributes['parent_id']) {
            $changes['parent_id'] = $attributes['parent_id'];
        }

        if (!$file->is_folder && array_key_exists('size', $attributes)
            && (int) $file->size !== (int) $attributes['size']) {
            $changes['size'] = $attributes['size'];
        }

        if (array_key_exists('file_modified_at', $attributes) && $attributes['file_modified_at'] !== null) {
            $incoming = $attributes['file_modified_at'];
            if (!$file->file_modified_at || !$file->file_modified_at->eq($incoming)) {
                $changes['file_modified_at'] = $incoming;
            }
        }

        if ($changes !== []) {
            $file->update($changes);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === self::UNIQUE_VIOLATION;
    }

    private function normalizePath(string $path): string
    {
        return ltrim($path, '/');
    }
}
