<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupCorreoLog extends Command
{
    protected $signature = 'correo:cleanup-logs
                            {--days=90 : Eliminar registros con más de N días}
                            {--dry-run : Mostrar cuántos se eliminarían sin borrar}';

    protected $description = 'Elimina registros antiguos de correo_log para controlar el crecimiento';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = DB::table('correo_log')
            ->where('sent_at', '<', $cutoff)
            ->count();

        if ($count === 0) {
            $this->info("No hay registros anteriores a {$days} días.");
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Se eliminarían {$count} registros anteriores a {$cutoff->toDateString()}.");
            return Command::SUCCESS;
        }

        DB::table('correo_log')
            ->where('sent_at', '<', $cutoff)
            ->delete();

        $this->info("Eliminados {$count} registros de correo_log anteriores a {$cutoff->toDateString()}.");
        return Command::SUCCESS;
    }
}
