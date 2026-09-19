<?php

namespace App\Services\Ia;

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Identidad FISICA del archivo para el pipeline de transcripcion.
 *
 * El problema que resuelve: `transcriptions.file_id` es UNIQUE, o sea una
 * transcripcion por FILA de `files`. Como Mis Archivos modela N filas por
 * archivo fisico (una por gestor que lo contiene — el usuario navega y se crea
 * fila), el mismo audio puede generar dos transcripciones y DOS ENVIOS al
 * upstream: ffmpeg y tokens pagados dos veces. Medido 2026-09-16: 2 duplicados
 * confirmados y 12.910 archivos fisicos en riesgo.
 *
 * La identidad del pipeline es la RUTA ABSOLUTA:
 *   rtrim(storage_providers.base_path, '/') . '/' . files.path
 * del dueño efectivo. Se persiste en `transcriptions.source_absolute_path`
 * con indice UNIQUE, asi que el doble pago es IMPOSIBLE, no solo improbable.
 *
 * FRONTERA (change module-independence-boundary): esta clase es SOLO LECTURA
 * sobre `files`. `files` es la tabla soberana de Mis Archivos; el transcriptor
 * no crea, actualiza, borra ni trashea filas alli. Si falta la fila, se
 * delega a Mis Archivos (ver TranscriptionInventoryInvoker), no se inventa.
 */
class PhysicalFileIdentity
{
    public function __construct(
        private StorageHierarchyService $hierarchy,
    ) {}

    /**
     * Ruta absoluta de una fila `files` existente.
     */
    public function absolutePathOf(File $file): string
    {
        $storage = $file->relationLoaded('storageProvider')
            ? $file->storageProvider
            : $file->storageProvider()->first();

        if ($storage === null) {
            // Sin storage no hay ruta fisica resoluble. Devolver una cadena
            // unica por file_id evita colisiones espurias en el indice.
            return '__unknown_storage__/' . $file->id;
        }

        return rtrim((string) $storage->base_path, '/') . '/' . ltrim((string) $file->path, '/');
    }

    /**
     * Fila canonica de un archivo fisico: la que pertenece al dueño efectivo.
     *
     * SOLO LECTURA. Devuelve null si ninguna fila `files` del dueño efectivo
     * representa esa ruta — resolver ese caso es responsabilidad de Mis
     * Archivos, no del transcriptor.
     */
    public function resolve(string $absolutePath): ?File
    {
        $owner = $this->hierarchy->ownerOf($absolutePath);

        if ($owner === null) {
            return null;
        }

        $relative = $this->relativePathFor($absolutePath, $owner);

        if ($relative === null) {
            return null;
        }

        return File::where('storage_provider_id', $owner->id)
            ->where('path', $relative)
            ->where('is_folder', false)
            ->first();
    }

    /**
     * Ruta relativa de una ruta absoluta bajo el base_path del storage dado.
     * Null si la ruta no cae dentro.
     */
    public function relativePathFor(string $absolutePath, StorageProvider $storage): ?string
    {
        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            return null;
        }

        $abs = rtrim($absolutePath, '/');

        if ($abs === $base) {
            return null;
        }

        if (!str_starts_with($abs . '/', $base . '/')) {
            return null;
        }

        return ltrim(substr($abs, strlen($base)), '/');
    }

    /**
     * Devuelve la transcripcion del archivo fisico, creandola si no existe.
     *
     * Idempotente por `source_absolute_path`. `$canonical` puede ser null
     * (archivo borrado en Mis Archivos): la transcripcion conserva su ruta y
     * el `file_id` queda null, porque la FK es ON DELETE SET NULL.
     *
     * @param  array<string,mixed>  $attributes  atributos para la creacion
     */
    public function firstOrCreateTranscription(
        string $absolutePath,
        ?File $canonical = null,
        array $attributes = []
    ): Transcription {
        $existente = Transcription::where('source_absolute_path', $absolutePath)->first();

        if ($existente !== null) {
            // Sanear el file_id si apuntaba a otra fila. Cuidado: `file_id`
            // tiene UNIQUE propio, asi que solo se re-vincula si el destino
            // esta libre; si otra transcripcion ya lo ocupa, se deja como
            // esta (la reconciliacion se encarga del grupo completo).
            if ($canonical !== null && (int) $existente->file_id !== (int) $canonical->id) {
                $ocupado = Transcription::where('file_id', $canonical->id)
                    ->where('id', '!=', $existente->id)
                    ->exists();

                if (!$ocupado) {
                    $existente->forceFill(['file_id' => $canonical->id])->saveQuietly();
                }
            }

            return $existente;
        }

        try {
            return Transcription::create($attributes + [
                'file_id' => $canonical?->id,
                'source_absolute_path' => $absolutePath,
            ]);
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            // Carrera: otro proceso creo la fila entre nuestro SELECT y el
            // INSERT. Leer al ganador es el comportamiento correcto.
            $winner = Transcription::where('source_absolute_path', $absolutePath)->first();

            if ($winner === null) {
                throw $e;
            }

            Log::info('physical_file_identity.race_lost', [
                'source_absolute_path' => $absolutePath,
                'winner_id' => $winner->id,
            ]);

            return $winner;
        }
    }

    /**
     * True si ya existe una transcripcion para la ruta (cualquier estado).
     * Usado por el descubrimiento para saltar sin crear.
     */
    public function hasTranscription(string $absolutePath): bool
    {
        return Transcription::where('source_absolute_path', $absolutePath)->exists();
    }

    /**
     * Resuelve la ruta absoluta de una transcripcion, priorizando
     * `source_absolute_path` (estable) y cayendo al file si aquel es null
     * (filas creadas antes de la migracion).
     */
    public function absolutePathOfTranscription(Transcription $transcription): ?string
    {
        if (!empty($transcription->source_absolute_path)) {
            return (string) $transcription->source_absolute_path;
        }

        $file = $transcription->file;

        return $file !== null ? $this->absolutePathOf($file) : null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}
