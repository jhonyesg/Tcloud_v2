<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupShareAccessLog extends Command
{
    protected $signature = 'shares:cleanup-logs
                            {--days=90 : Eliminar registros con más de N días}
                            {--dry-run : Mostrar cuántos se eliminarían sin borrar}';

    protected $description = 'Elimina registros antiguos de share_access_log para controlar el crecimiento';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = DB::table('share_access_log')
            ->where('accessed_at', '<', $cutoff)
            ->count();

        if ($count === 0) {
            $this->info("No hay registros anteriores a {$days} días.");
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Se eliminarían {$count} registros anteriores a {$cutoff->toDateString()}.");
            return Command::SUCCESS;
        }

        DB::table('share_access_log')
            ->where('accessed_at', '<', $cutoff)
            ->delete();

        $this->info("Eliminados {$count} registros de share_access_log anteriores a {$cutoff->toDateString()}.");
        return Command::SUCCESS;
    }
}
