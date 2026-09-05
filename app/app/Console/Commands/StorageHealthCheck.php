<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\MountGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Watchdog de accesibilidad de los StorageProvider.
 *
 * Corre cada 5 min (registrado en routes/console.php) y para cada storage
 * habilitado calcula accesibilidad combinando:
 *  - is_dir(base_path)
 *  - is_readable(base_path)
 *  - MountGuard::detachedAncestor(base_path)
 *
 * Persiste `is_accessible` + `last_checked_at` cuando el valor calculado
 * difiere del almacenado (no escribe si no hay cambio: evita churn en BD).
 *
 * Si detecta una transición 0 → 1 en un storage con `kind='external'`,
 * despacha `storage:reconcile --storage={id}` como proceso independiente
 * (Process::start) y registra un TTL en Cache para no redespachar en el
 * siguiente tick si la transición es estable.
 *
 * Tablas que NUNCA toca: files, transcriptions, shares, media_edit_jobs.
 */
class StorageHealthCheck extends Command
{
    protected $signature = 'storage:health
                            {--once : Ejecuta un solo tick y termina}';

    protected $description = 'Verifica accesibilidad de cada storage y dispara reconciliación cuando un disco externo remonta.';

    private bool $dispatchEnabled = true;

    public function handle(): int
    {
        $this->dispatchEnabled = (bool) config('storage_sync.health.dispatch_reconcile', true);

        $lockKey = 'storage:health:tick';
        $lock = Cache::lock($lockKey, 280);

        if (!$lock->get()) {
            $this->line('[skip] tick anterior aun en curso');
            return Command::SUCCESS;
        }

        try {
            return $this->tick();
        } finally {
            $lock->release();
        }
    }

    private function tick(): int
    {
        $mountGuard = app(MountGuard::class);

        $storages = StorageProvider::where('enabled', true)->get();

        $checked = 0;
        $changed = 0;
        $reconciled = 0;

        foreach ($storages as $storage) {
            $checked++;

            $accessible = $this->computeAccessible($storage, $mountGuard);
            $previous = (bool) $storage->is_accessible;

            if ($accessible === $previous) {
                continue;
            }

            $storage->forceFill([
                'is_accessible' => $accessible,
                'last_checked_at' => now(),
            ])->saveQuietly();

            $changed++;

            Log::info('storage_health.transition', [
                'storage_id' => $storage->id,
                'name' => $storage->name,
                'kind' => $storage->kind,
                'from' => $previous,
                'to' => $accessible,
            ]);

            // Solo los externos remontan y pueden necesitar reconciliación.
            // kind puede ser null en BD antes de la migración 2026_09_05; tratar
            // null como 'local' para evitar dispatch accidental.
            if (
                $accessible === true
                && $previous === false
                && ($storage->kind ?? 'local') === 'external'
                && $this->dispatchEnabled
            ) {
                if ($this->dispatchReconcile($storage)) {
                    $reconciled++;
                }
            }
        }

        $this->line("checked={$checked} changed={$changed} reconciled={$reconciled}");

        return Command::SUCCESS;
    }

    private function computeAccessible(StorageProvider $storage, MountGuard $mountGuard): bool
    {
        if ($storage->type !== 'local') {
            return (bool) $storage->is_accessible;
        }

        $base = $storage->base_path;
        if (!$base) {
            return false;
        }

        clearstatcache(true, $base);

        return is_dir($base)
            && is_readable($base)
            && $mountGuard->detachedAncestor($base) === null;
    }

    /**
     * Despacha `storage:reconcile` como proceso independiente con TTL
     * anti-redundancia en Redis. Devuelve true si se despachó, false si
     * ya hay un TTL activo.
     */
    private function dispatchReconcile(StorageProvider $storage): bool
    {
        $ttlKey = "health_reconcile:{$storage->id}";
        $ttl = (int) config('storage_sync.health.reconcile_cooldown', 280);

        if (!Cache::add($ttlKey, true, $ttl)) {
            return false;
        }

        try {
            Process::start([
                PHP_BINARY,
                base_path('artisan'),
                'storage:reconcile',
                '--storage=' . $storage->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('storage_health.reconcile_dispatch_failed', [
                'storage_id' => $storage->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        Log::info('storage_health.reconcile_dispatched', [
            'storage_id' => $storage->id,
            'kind' => $storage->kind,
            'previous_accessible' => false,
        ]);

        return true;
    }
}