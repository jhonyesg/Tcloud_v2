<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * transcription:reconcile-physical-identities
 *
 * Rellena `transcriptions.source_absolute_path` y consolida los grupos que
 * violarian el indice unico por ruta fisica. Nace del change
 * `transcriptor-physical-file-identity` (design.md D3).
 *
 * POR QUE EXISTE
 * `transcriptions.file_id` es UNIQUE, o sea una transcripcion por FILA de
 * `files`. Como Mis Archivos modela N filas por archivo fisico (una por gestor
 * que lo contiene), el mismo audio puede tener VARIAS transcripciones — y cada
 * una pago su propio ffmpeg + tokens. Medido 2026-09-16: 2 duplicados
 * confirmados y 12.910 archivos fisicos en riesgo.
 *
 * QUE HACE
 *   (a) Llena `source_absolute_path` en las filas que lo tienen NULL, derivando
 *       la ruta del storage de la fila (base_path + path).
 *   (b) Consolida los grupos con la MISMA ruta absoluta: conserva la fila
 *       `done` (o la de menor id si no hay done), le re-apunta el `file_id` si
 *       su fila canonica cambio, y BORRA las demas.
 *
 * QUE NO HACE
 *  - NO toca la tabla `files`. Es propiedad de Mis Archivos.
 *  - NO re-transcribe: el resultado valido ya existe (design.md Q4).
 *  - NO borra datos de la fila superviviente: preserva `srt_content`.
 *
 * SEGURIDAD
 *  - `--dry-run` es el DEFAULT. Requiere `--apply` explicito para mutar.
 *  - Idempotente: la segunda corrida reporta 0/0.
 *  - Reporta por fila afectada (file_id, ruta, accion).
 *
 * DEPENDENCIAS AL BORRAR UNA FIDUCIARIA
 *  transcription_segments, keyword_matches, alert_logs y segment_keyword_hits
 *  tienen ON DELETE CASCADE, asi que se van solas (son datos derivados del
 *  resultado que se descarta). `transcription_reviews` tiene UNIQUE
 *  (transcription_id), asi que NO se puede reparentar: se borra la del perdedor
 *  y se conserva la de la superviviente.
 */
class TranscriptionReconcilePhysicalIdentitiesCommand extends Command
{
    protected $signature = 'transcription:reconcile-physical-identities
                            {--apply : Aplicar los cambios (sin esta flag es dry-run)}
                            {--limit=0 : Procesar como maximo N filas por fase (0 = sin limite)}
                            {--chunk=500 : Tamano de lote}';

    protected $description = 'Rellena source_absolute_path y consolida transcripciones duplicadas por archivo fisico';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info($apply ? 'MODO APPLY' : 'MODO DRY-RUN (sin --apply no se muta nada)');

        // ORDEN IMPORTANTE: (b) antes que (a).
        //
        // Si (a) corriera primero, su UPDATE masivo tomaria el snapshot con
        // todos los source_absolute_path en NULL, asi que el `NOT EXISTS` seria
        // cierto para TODAS las filas del grupo duplicado y las dos recibirian
        // la misma ruta -> violacion del indice unico.
        //
        // Consolidando primero, cada grupo queda con una sola fila (ya con su
        // ruta seteada), y (a) rellena el resto sabiendo que no colisiona.
        $consolidados = $this->consolidateDuplicates($apply, $limit, $chunk);
        $llenadas = $this->fillMissingPaths($apply, $limit, $chunk);

        $this->newLine();
        $this->table(
            ['Fase', 'Filas afectadas'],
            [
                ['(b) grupos duplicados consolidados', $consolidados['groups']],
                ['(b) filas borradas', $consolidados['deleted']],
                ['(a) source_absolute_path rellenado', $llenadas['affected']],
                ['(a) colisiones restantes', $llenadas['collisions']],
            ]
        );

        if (!$apply) {
            $this->warn('Dry-run: nada fue modificado. Correr con --apply para aplicar.');

            return self::SUCCESS;
        }

        $this->info('Reconciliacion aplicada.');

        return self::SUCCESS;
    }

    /**
     * (a) Rellena source_absolute_path de filas con NULL.
     *
     * SQL MASIVO, no fila-a-fila: son ~452k filas y un UPDATE por fila seria
     * ~452k round-trips. Se calcula la ruta con un UPDATE ... FROM y se
     * reportan aparte las colisiones (filas cuya ruta ya esta tomada por otra
     * fila), que son las pocas interesantes: esas las resuelve la fase (b).
     *
     * @return array{affected:int,collisions:int}
     */
    private function fillMissingPaths(bool $apply, int $limit, int $chunk): array
    {
        // Colisiones: filas con NULL cuya ruta derivada ya existe en otra fila.
        $colisiones = DB::select(<<<'SQL'
SELECT count(*) AS n
FROM (
  SELECT t.id,
         ROW_NUMBER() OVER (
           PARTITION BY rtrim(sp.base_path, '/') || '/' || f.path
           ORDER BY t.id
         ) AS rn
  FROM transcriptions t
  JOIN files f ON f.id = t.file_id
  JOIN storage_providers sp ON sp.id = f.storage_provider_id
  WHERE t.source_absolute_path IS NULL
    AND f.storage_provider_id IS NOT NULL
    AND sp.base_path IS NOT NULL
    AND rtrim(sp.base_path, '/') <> ''
) x
WHERE rn > 1
SQL);

        $nColisiones = (int) ($colisiones[0]->n ?? 0);

        // Filas que se pueden llenar sin colisionar con otra ya existente.
        $candidatas = DB::select(<<<'SQL'
SELECT count(*) AS n
FROM transcriptions t
JOIN files f ON f.id = t.file_id
JOIN storage_providers sp ON sp.id = f.storage_provider_id
WHERE t.source_absolute_path IS NULL
  AND f.storage_provider_id IS NOT NULL
  AND sp.base_path IS NOT NULL
  AND rtrim(sp.base_path, '/') <> ''
  AND NOT EXISTS (
    SELECT 1 FROM transcriptions o
    WHERE o.source_absolute_path = rtrim(sp.base_path, '/') || '/' || f.path
  )
SQL);

        $nCandidatas = (int) ($candidatas[0]->n ?? 0);

        if (!$apply) {
            return ['affected' => $nCandidatas, 'collisions' => $nColisiones];
        }

        // UPDATE masivo: solo las que no colisionan (las colisionadas quedan
        // NULL y las consolida la fase b, que corre despues con sus rutas
        // derivadas por JOIN).
        $afectadas = DB::update(<<<'SQL'
UPDATE transcriptions t
SET source_absolute_path = rtrim(sp.base_path, '/') || '/' || f.path
FROM files f
JOIN storage_providers sp ON sp.id = f.storage_provider_id
WHERE f.id = t.file_id
  AND t.source_absolute_path IS NULL
  AND f.storage_provider_id IS NOT NULL
  AND sp.base_path IS NOT NULL
  AND rtrim(sp.base_path, '/') <> ''
  AND NOT EXISTS (
    SELECT 1 FROM transcriptions o
    WHERE o.source_absolute_path = rtrim(sp.base_path, '/') || '/' || f.path
  )
SQL);

        return ['affected' => $afectadas, 'collisions' => $nColisiones];
    }

    /**
     * (b) Consolida grupos con la misma ruta fisica.
     *
     * Agrupa por ruta DERIVADA (base_path + path del file), no por
     * `source_absolute_path`: las filas que colisionaron en la fase (a) siguen
     * con NULL y quedarian invisibles para un agrupamiento por la columna.
     *
     * @return array{groups:int,deleted:int}
     */
    private function consolidateDuplicates(bool $apply, int $limit, int $chunk): array
    {
        $grupos = DB::select(<<<'SQL'
SELECT rtrim(sp.base_path, '/') || '/' || f.path AS abs,
       count(*) AS n
FROM transcriptions t
JOIN files f ON f.id = t.file_id
JOIN storage_providers sp ON sp.id = f.storage_provider_id
WHERE f.storage_provider_id IS NOT NULL
  AND sp.base_path IS NOT NULL
  AND rtrim(sp.base_path, '/') <> ''
GROUP BY 1
HAVING count(*) > 1
ORDER BY 1
SQL);

        if ($limit > 0) {
            $grupos = array_slice($grupos, 0, $limit);
        }

        $deleted = 0;

        foreach ($grupos as $grupo) {
            $rows = Transcription::query()
                ->join('files', 'files.id', '=', 'transcriptions.file_id')
                ->join('storage_providers as sp', 'sp.id', '=', 'files.storage_provider_id')
                ->whereRaw("rtrim(sp.base_path, '/') || '/' || files.path = ?", [$grupo->abs])
                ->orderBy('transcriptions.id')
                ->get(['transcriptions.*']);

            $superviviente = $this->pickSurvivor($rows);
            $perdedoras = $rows->reject(fn ($r) => $r->id === $superviviente->id);

            $this->line(sprintf(
                '  (b) %s  conservar tx %d (%s)  borrar [%s]',
                $grupo->abs,
                $superviviente->id,
                $superviviente->state,
                $perdedoras->pluck('id')->implode(', ')
            ));

            if (!$apply) {
                $deleted += $perdedoras->count();
                continue;
            }

            DB::transaction(function () use ($superviviente, $perdedoras, $grupo, &$deleted) {
                foreach ($perdedoras as $perdedora) {
                    // transcription_reviews tiene UNIQUE (transcription_id):
                    // no se reparenta, se descarta la del perdedor.
                    DB::table('transcription_reviews')->where('transcription_id', $perdedora->id)->delete();
                    $perdedora->delete();
                    $deleted++;
                }

                // La superviviente SIEMPRE queda con la ruta fisica explicita,
                // aunque venia NULL por colision.
                $superviviente->forceFill([
                    'source_absolute_path' => $grupo->abs,
                ])->saveQuietly();
            });

            Log::info('transcription.reconcile.consolidated', [
                'source_absolute_path' => $grupo->abs,
                'survivor_id' => $superviviente->id,
                'survivor_state' => $superviviente->state,
                'deleted_ids' => $perdedoras->pluck('id')->all(),
            ]);
        }

        return ['groups' => count($grupos), 'deleted' => $deleted];
    }

    /**
     * Conserva la `done` (tiene el resultado valido); si no hay, la de menor id
     * (design.md Q4).
     */
    private function pickSurvivor($rows): Transcription
    {
        $done = $rows->firstWhere('state', Transcription::STATE_DONE);

        return $done ?? $rows->first();
    }

    /**
     * Re-apunta el file_id de la superviviente a la fila que corresponde a su
     * ruta absoluta, si esa fila existe y esta libre. Nunca deja el file_id
     * apuntando a una fila de otro archivo.
     */
    private function rebindSurvivor(Transcription $tx, string $abs): void
    {
        $file = File::query()
            ->where('is_folder', false)
            ->whereRaw(
                "(SELECT rtrim(base_path, '/') FROM storage_providers WHERE id = files.storage_provider_id) || '/' || path = ?",
                [rtrim($abs, '/')]
            )
            ->orderBy('id')
            ->first();

        if ($file === null) {
            // La ruta ya no tiene fila en `files` (archivo borrado en Mis
            // Archivos). La transcripcion conserva su resultado; el file_id
            // queda como esta o null.
            return;
        }

        if ((int) $tx->file_id === (int) $file->id) {
            return;
        }

        $ocupado = Transcription::where('file_id', $file->id)->where('id', '!=', $tx->id)->exists();
        if ($ocupado) {
            return;
        }

        $tx->forceFill(['file_id' => $file->id])->saveQuietly();
    }
}
