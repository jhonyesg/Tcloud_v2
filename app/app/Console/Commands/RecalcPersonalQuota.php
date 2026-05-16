<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalcPersonalQuota extends Command
{
    protected $signature = 'files:recalc-personal-quota
                            {user_id? : ID del usuario a recalcular (todos si se omite)}';

    protected $description = 'Recalcula personal_used_bytes desde los archivos reales en storage personal';

    public function handle(): int
    {
        $userId = $this->argument('user_id');

        $query = DB::table('users');
        if ($userId) {
            $query->where('id', (int) $userId);
        }

        $users = $query->get(['id', 'email', 'personal_used_bytes']);

        if ($users->isEmpty()) {
            $this->error('No se encontraron usuarios.');
            return Command::FAILURE;
        }

        $this->info("Recalculando cuota para {$users->count()} usuario(s)...");
        $updated = 0;

        foreach ($users as $user) {
            $real = (int) DB::selectOne("
                SELECT COALESCE(SUM(f.size), 0) AS total
                FROM files f
                INNER JOIN storage_providers sp ON sp.id = f.storage_provider_id
                WHERE f.owner_id = ?
                  AND f.is_folder = FALSE
                  AND sp.base_path LIKE '/home/www/Usuarios_tcloud/%'
            ", [$user->id])->total;

            if ($real !== (int) $user->personal_used_bytes) {
                DB::table('users')->where('id', $user->id)->update(['personal_used_bytes' => $real]);
                $this->line(sprintf(
                    '  %-40s %s → %s',
                    $user->email,
                    $this->formatBytes($user->personal_used_bytes),
                    $this->formatBytes($real)
                ));
                $updated++;
            }
        }

        $this->info($updated > 0
            ? "Listo: {$updated} usuario(s) corregido(s)."
            : 'Todo correcto, ningún valor fue modificado.');

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
