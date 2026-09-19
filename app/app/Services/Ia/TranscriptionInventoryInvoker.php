<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use App\Models\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Invocacion del inventario de Mis Archivos desde API Transcriptor.
 *
 * FRONTERA (capability module-independence-boundary, design.md D5): cuando el
 * transcriptor detecta que un storage habilitado no tiene inventario en
 * `files`, NO escanea el disco — invoca la interfaz publica de Mis Archivos,
 * que es la misma que usa el boton "Actualizar" (`StorageSyncService`).
 *
 * DECISION Q2: ENCOLADO, no sincrono. Tres razones:
 *
 *  1. Aislamiento de NFS. `MountGuard` existe justamente porque un montaje
 *     caido deja el punto de montaje legible y puede bloquear en IO. Una
 *     llamada sincrona dentro del tick de 2 minutos heredaria ese cuelgue y
 *     pararia el pipeline completo. Encolada, el cuelgue queda en un worker.
 *  2. Escala del arbol. Storage 47 tiene 2.834 carpetas con archivos; un sync
 *     sincrono de minutos excede el ciclo del tick.
 *  3. El cron ya cubre el caso normal: `storage:sync --all` corre cada 15 min,
 *     asi que esperar un tick (2 min) es despreciable frente a bloquear.
 *
 * Límite de frecuencia: marca de "solicitado" por storage con ventana alineada
 * al cron (15 min por defecto). Sin esto, cada tick de 2 min despacharia un
 * sync. La marca se limpia cuando el sync termina.
 */
class TranscriptionInventoryInvoker
{
    private const REQUESTED_PREFIX = 'transcriptor.inventory.requested.';

    /** Ventana minima entre invocaciones por storage, en segundos. */
    public const DEFAULT_WINDOW_SECONDS = 900;

    /**
     * True si el storage no tiene NINGUNA fila `files` para el dia Bogota
     * actual. Es la senal de "inventario ausente" que dispara la invocacion.
     */
    public function isEmptyForToday(StorageProvider $storage): bool
    {
        $hoy = BogotaTime::todayStart();

        return !File::where('storage_provider_id', $storage->id)
            ->where('is_folder', false)
            ->where('file_modified_at', '>=', $hoy)
            ->exists();
    }

    /**
     * Despacha el sync de Mis Archivos en background, respetando la ventana
     * minima. Devuelve true si se despacho, false si estaba en ventana.
     *
     * NO espera el resultado: el tick resuelve candidatos del inventario
     * existente y retoma en el ciclo siguiente si estaba vacio.
     */
    public function requestSync(StorageProvider $storage): bool
    {
        $key = self::REQUESTED_PREFIX . $storage->id;
        $window = $this->windowSeconds();

        if (Cache::has($key)) {
            return false;
        }

        Cache::put($key, BogotaTime::now()->toIso8601String(), $window);

        $cmd = sprintf(
            '%s artisan storage:sync %d --user=1',
            $this->phpBinary(),
            $storage->id
        );

        $ok = $this->dispatchBackground($cmd, 'inv_' . $storage->id);

        Log::info('transcriptor.inventory.requested', [
            'storage_id' => $storage->id,
            'storage_name' => $storage->name,
            'dispatched' => $ok,
            'window_seconds' => $window,
        ]);

        if (!$ok) {
            // Si no se pudo despachar, liberar la marca para reintentar antes
            // de la ventana completa.
            Cache::forget($key);
        }

        return $ok;
    }

    /**
     * Limpia la marca de "solicitado". La llama el propio `storage:sync` al
     * terminar, para que un storage que sigue vacio no quede marcado mas alla
     * de lo necesario.
     */
    public function clearRequest(StorageProvider $storage): void
    {
        Cache::forget(self::REQUESTED_PREFIX . $storage->id);
    }

    public function windowSeconds(): int
    {
        $raw = \App\Models\SystemSetting::get('transcriptor_inventory_window_seconds');

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_WINDOW_SECONDS;
        }

        $v = (int) $raw;

        return $v < 60 ? self::DEFAULT_WINDOW_SECONDS : $v;
    }

    /**
     * Lanza el comando separado del proceso PHP. Mismo patron que
     * `RunsBackgroundCommands::execBackground`: `setsid bash -c '...' &`
     * desliga el proceso del arbol de PHP-FPM.
     */
    private function dispatchBackground(string $cmd, string $tag): bool
    {
        $log = '/tmp/kilo_artisan_bg.log';
        $script = sprintf(
            'echo "[%s] start $(date -Is)" >> %s; %s >> %s 2>&1; echo "[%s] end $(date -Is)" >> %s',
            $tag,
            $log,
            $cmd,
            $log,
            $tag,
            $log
        );

        $wrapped = sprintf("setsid bash -c %s > /dev/null 2>&1 &", escapeshellarg($script));

        exec($wrapped, $out, $code);

        return $code === 0;
    }

    private function phpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
            return escapeshellarg(PHP_BINARY);
        }

        return 'php84';
    }
}
