<?php

namespace App\Observers;

use App\Models\File;
use App\Models\Transcription;
use App\Services\Ia\RecordedAt;
use Illuminate\Support\Facades\Log;

/**
 * change 2026-09-10-mis-avisos-program-date-filter — Task 2.2.
 *
 * Calcula `recorded_at` automáticamente al crear una transcripción, y la
 * hace inmutable después (updating).
 *
 * - `creating`: si `recorded_at` no viene seteado, calcular via
 *   `RecordedAt::resolve(name, file.file_modified_at, finished_at)`.
 *   Si ya viene seteado (ej. backfill manual), respetar.
 * - `updating`: si está intentando cambiar `recorded_at` de una fila existente
 *   a un valor distinto, log y forzar el valor original.
 *   La inmutabilidad evita que código accidentalmente haga
 *   `$transcription->recorded_at = ...; $transcription->save()` y pise
 *   la fecha del programa.
 */
class TranscriptionObserver
{
    public function creating(Transcription $transcription): void
    {
        // Si ya viene seteado explícitamente, respetar (caso backfill/test).
        if ($transcription->recorded_at !== null) {
            return;
        }

        // Necesitamos el nombre del archivo y file_modified_at. Resolver
        // desde la relación si está cargada, o desde BD si no.
        $fileName = $transcription->original_name;
        $fileModifiedAt = null;

        if ($transcription->file_id) {
            $file = $transcription->file ?? File::find($transcription->file_id);
            if ($file) {
                $fileName = $file->name;
                $fileModifiedAt = $file->file_modified_at;
            }
        }

        $resolved = RecordedAt::resolve(
            $fileName,
            $fileModifiedAt,
            $transcription->finished_at,
        );

        if ($resolved !== null) {
            $transcription->recorded_at = $resolved;
        } else {
            // Caso degenerado: ninguna fuente dio fecha. Log para visibilidad.
            Log::warning('TranscriptionObserver: recorded_at no se pudo resolver', [
                'file_id' => $transcription->file_id,
                'original_name' => $transcription->original_name,
                'has_file_modified_at' => $fileModifiedAt !== null,
                'has_finished_at' => $transcription->finished_at !== null,
            ]);
        }
    }

    public function updating(Transcription $transcription): void
    {
        // Solo actuar si recorded_at está cambiando Y la fila ya tiene un valor.
        // (La creación ya setea el valor en `creating`; aquí protegemos updates.)
        if (!$transcription->isDirty('recorded_at')) {
            return;
        }
        $original = $transcription->getOriginal('recorded_at');
        if ($original !== null) {
            Log::warning('TranscriptionObserver: intento de cambio de recorded_at bloqueado', [
                'transcription_id' => $transcription->id,
                'original' => (string) $original,
                'attempted' => (string) $transcription->recorded_at,
            ]);
            // Forzar al valor original — el cambio nunca aplica.
            $transcription->recorded_at = $original;
        }
        // Si original es null (caso backfill durante la misma operación), no
        // bloquear — permitimos el set inicial. La próxima vez ya será inmutable.
    }
}
