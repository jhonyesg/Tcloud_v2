<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificacion de disponibilidad fisica de los recursos catalogados.
 *
 * Que resuelve: el panel de compartidos necesita poder FILTRAR por "archivo no
 * disponible" y depurar esos enlaces. Sin un estado persistido, ese filtro
 * obligaria a sondear el disco en cada request y, peor, confundiria "el
 * archivo no existe" con "el storage no estaba montado" — que no es lo mismo.
 *
 * Estados (spec `share-file-availability`):
 *   - `available`: el filesystem confirma que la ruta existe.
 *   - `missing`  : el filesystem confirma que la ruta NO existe. Solo se
 *                   afirma cuando el storage es local, accesible y el
 *                   directorio padre es atravesable.
 *   - `unknown`  : el filesystem no dio una respuesta confiable (storage no
 *                   local, inaccesible, base_path no resoluble, padre no
 *                   traversable, error de E/S). El sistema NO afirma que el
 *                   archivo fue borrado.
 *
 * FRONTERA DE ESCRITURA (design.md, decision D2): el estado es un dato
 * DERIVADO de una sonda de filesystem, no catalogo. Este servicio lo escribe
 * con un UPDATE acotado sobre las tres columnas de disponibilidad y NO toca
 * ningun otro atributo de la fila `files` ni su marca de actualizacion.
 * Mis Archivos sigue siendo el unico modulo que crea y actualiza el catalogo.
 */
class ShareAvailabilityService
{
    /**
     * Verifica cada recurso una sola vez.
     *
     * La entrada es una coleccion de RECURSOS, uno por enlace: dos enlaces
     * sobre el mismo archivo son dos elementos de la entrada pero una sola
     * sonda de filesystem. Por eso el servicio deduplica la sonda y devuelve
     * el estado POR RECURSO; el consumidor (ShareController) lo proyecta a
     * enlace, que es la unidad que cuenta el contrato.
     *
     * @param  Collection<int, File>  $files
     * @return array{
     *     states: array<int, string>,
     *     reasons: array<int, string>,
     *     files_checked: int,
     *     files_available: int,
     *     files_missing: int,
     *     files_unknown: int
     * }
     */
    public function verify(Collection $files): array
    {
        $states = [];
        $reasons = [];

        foreach ($files->filter()->unique('id') as $file) {
            try {
                [$state, $reason] = $this->verifyFile($file);
            } catch (Throwable $e) {
                // Un fallo puntual no puede abortar el lote: el recurso queda
                // en unknown y el resto del lote sigue verificandose.
                Log::warning('shares.availability.verify_failed', [
                    'file_id' => $file->id ?? null,
                    'error' => $e->getMessage(),
                ]);

                $this->markUnknown($file);
                $state = 'unknown';
                $reason = 'verification_error';
            }

            $states[(int) $file->id] = $state;
            $reasons[(int) $file->id] = $reason;
        }

        return [
            'states' => $states,
            'reasons' => $reasons,
            'files_checked' => count($states),
            'files_available' => count(array_filter($states, fn (string $s) => $s === 'available')),
            'files_missing' => count(array_filter($states, fn (string $s) => $s === 'missing')),
            'files_unknown' => count(array_filter($states, fn (string $s) => $s === 'unknown')),
        ];
    }

    /**
     * @return array{0: 'available'|'missing'|'unknown', 1: string}
     */
    private function verifyFile(File $file): array
    {
        $storage = $this->storageOf($file);

        if ($storage === null) {
            $this->markUnknown($file);

            return ['unknown', 'storage_not_found'];
        }

        // `kind` es el campo canonico del storage; `type` queda deprecado.
        if ((string) ($storage->kind ?? 'local') !== 'local') {
            $this->markUnknown($file);

            return ['unknown', 'storage_not_local'];
        }

        if ($storage->is_accessible === false) {
            $this->markUnknown($file);

            return ['unknown', 'storage_inaccessible'];
        }

        $absolute = $file->physicalPath();
        if ($absolute === null) {
            $this->markUnknown($file);

            return ['unknown', 'storage_path_unavailable'];
        }

        // El padre debe existir y ser atravesable. Si no lo es, el filesystem
        // no nos da una respuesta confiable sobre el recurso: `missing` seria
        // una afirmacion falsa.
        $basePath = realpath((string) $storage->base_path);
        $parentPath = realpath(dirname($absolute));

        if ($basePath === false || !is_dir($basePath)) {
            $this->markUnknown($file);

            return ['unknown', 'storage_path_unavailable'];
        }

        if ($parentPath === false || !is_dir($parentPath)) {
            $this->markUnknown($file);

            return ['unknown', 'parent_path_unavailable'];
        }

        if (! $this->isWithin($parentPath, $basePath)) {
            $this->markUnknown($file);

            return ['unknown', 'path_outside_storage'];
        }

        if (file_exists($absolute)) {
            $this->markAvailable($file);

            return ['available', 'path_exists'];
        }

        $this->markMissing($file);

        return ['missing', 'path_not_found'];
    }

    private function storageOf(File $file): ?StorageProvider
    {
        if ($file->relationLoaded('storageProvider')) {
            return $file->storageProvider;
        }

        return $file->storageProvider()->first();
    }

    private function markAvailable(File $file): void
    {
        $this->persist($file, 'available', $this->nowTz(), null);
    }

    private function markMissing(File $file): void
    {
        // Conserva la fecha desde la que se detecto la ausencia.
        $since = $file->missing_since_at
            ? $file->missing_since_at->format('Y-m-d H:i:sP')
            : $this->nowTz();

        $this->persist($file, 'missing', $this->nowTz(), $since);
    }

    private function markUnknown(File $file): void
    {
        // `last_verified_at` queda en null: no hubo una verificacion
        // confiable, y `missing_since_at` se limpia porque no se puede
        // afirmar que el recurso este ausente.
        $this->persist($file, 'unknown', null, null);
    }

    /**
     * UPDATE acotado: solo las tres columnas de disponibilidad. No se usa
     * `save()` del modelo porque eso escribiria todos los atributos modificados
     * de una fila que no es de este modulo y bumpearia `updated_at`.
     */
    private function persist(File $file, string $state, ?string $verifiedAt, ?string $missingSince): void
    {
        DB::table('files')
            ->where('id', $file->id)
            ->update([
                'availability_state' => $state,
                'last_verified_at' => $verifiedAt,
                'missing_since_at' => $missingSince,
            ]);
    }

    /**
     * Instante con offset explicito: no depende del timezone de la sesion
     * PostgreSQL (ver AGENTS.md, "Zona horaria de PostgreSQL").
     */
    private function nowTz(): string
    {
        return now()->format('Y-m-d H:i:sP');
    }

    private function isWithin(string $path, string $base): bool
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path . DIRECTORY_SEPARATOR, $base);
    }
}
