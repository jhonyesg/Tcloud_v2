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
 *
 * Cambio `files-mirror-consolidation` (2026-09-19):
 * Despues de cada ensure(), invoca `FileMirrorLinker::reconcileAfterUpsert()`
 * para que la fila recien creada/upserted se conecte via `canonical_folder_id`
 * con su contraparte en otro storage si existe. Asi, el sync deja los files
 * linkeados cross-storage automaticamente — sin necesidad de un comando de
 * backfill. El backfill (set-based) cubre los files historicos que sync ya
 * indexo antes del fix.
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
            $row = File::create($attributes + [
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

        // Cambio files-mirror-consolidation: reconciliar con mirror canónico
        // despues de crear la fila. Solo si no es un folder: los folders se
        // reconcilian cuando sus files hijos se procesan, evitando overhead
        // en cada escaneo.
        if (!$row->is_folder) {
            try {
                app(FileMirrorLinker::class)->reconcileAfterUpsert($row);
            } catch (\Throwable $e) {
                // No bloquear el flujo de sync si la reconciliacion falla.
                // El comando `files:repair-file-mirrors --apply` cubre el backfill.
                Log::warning('file_registry.mirror_reconcile_failed', [
                    'file_id' => $row->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $row;
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

        if (array_key_exists('availability_state', $attributes)
            && $file->availability_state !== $attributes['availability_state']) {
            $changes['availability_state'] = $attributes['availability_state'];
        }

        if (array_key_exists('last_verified_at', $attributes) && $attributes['last_verified_at'] !== null) {
            $changes['last_verified_at'] = $attributes['last_verified_at'];
        }

        if (array_key_exists('missing_since_at', $attributes)) {
            $changes['missing_since_at'] = $attributes['missing_since_at'];
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
