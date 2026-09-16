<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupTmpfsCommand extends Command
{
    protected $signature = 'transcription:cleanup-tmpfs';
    protected $description = 'Borra archivos temporales de conversion de audio y progreso en /dev/shm con mas de 1 hora de antiguedad.';

    public function handle(): int
    {
        $dirs = [
            '/dev/shm/tcloud-transcription',
            '/dev/shm/tcloud-transcription-progress',
        ];

        $deleted = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = glob($dir . '/*');
            if ($files === false) continue;
            $cutoff = time() - 3600; // 1 hora
            foreach ($files as $file) {
                if (!is_file($file)) continue;
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            $this->info("Cleanup-tmpfs: {$deleted} archivos temporales borrados.");
        }
        return Command::SUCCESS;
    }
}