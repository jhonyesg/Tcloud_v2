<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza defensiva de WAVs huérfanos en /dev/shm/tcloud-transcription/.
 *
 * Tras el fix del fd leak en TranscriptorApiClient (2026-08-12) este comando
 * NO debería encontrar nada que limpiar, pero queda como red de seguridad:
 * si un crash, kill -9 o bug futuro deja archivos WAV con fd abierto durante
 * horas, este comando los libera cuando ya nadie los referencia.
 *
 * Reglas (todas deben cumplirse para borrar):
 *  1. mtime > --max-age minutos (default 30): no tocar jobs en curso.
 *  2. El archivo NO aparece en `lsof +D` (sin fd abierto en ningun proceso).
 *     Si lsof no está disponible, fallback a `fuser` (exit code != 0 = nadie
 *     lo tiene abierto).
 *  3. `--dry-run`: lista lo que borraría, no toca nada.
 *
 * NO confundir con TranscriptionCleanupTmpfs (alias existente): aquel usa
 * un umbral de 1h y borra sin verificar fds. Este comando es el "seguro" del
 * primero.
 */
class TranscriptionCleanupOrphanWavCommand extends Command
{
    protected $signature = 'transcription:cleanup-orphan-wav
                            {--max-age=30 : Edad mínima en minutos para considerar un WAV como huérfano}
                            {--dry-run : Solo lista, no borra}';

    protected $description = 'Borra WAVs huérfanos en /dev/shm/tcloud-transcription/ sin fd abierto.';

    public function handle(): int
    {
        $dir = '/dev/shm/tcloud-transcription';
        if (!is_dir($dir)) {
            $this->info("Directorio {$dir} no existe. Nada que limpiar.");
            return Command::SUCCESS;
        }

        $maxAgeMin = max(1, (int) $this->option('max-age'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = time() - ($maxAgeMin * 60);

        $files = glob($dir . '/*.{wav,opus}', GLOB_BRACE);
        if ($files === false || count($files) === 0) {
            $this->info("Sin WAVs/Opus en {$dir}.");
            return Command::SUCCESS;
        }

        $found = 0;
        $deleted = 0;
        $skippedFd = 0;
        $skippedAge = 0;

        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $mtime = filemtime($file);
            if ($mtime === false || $mtime > $cutoff) {
                $skippedAge++;
                continue;
            }
            $found++;

            if ($this->hasOpenFds($file)) {
                $skippedFd++;
                Log::warning("transcription:cleanup-orphan-wav: omitido (fd abierto)", [
                    'path' => $file,
                    'mtime' => $mtime,
                ]);
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] borraría: {$file} (mtime=" . date('Y-m-d H:i:s', $mtime) . ")");
                $deleted++;
                continue;
            }

            if (@unlink($file)) {
                $deleted++;
                Log::info("transcription:cleanup-orphan-wav: borrado", [
                    'path' => $file,
                    'mtime' => $mtime,
                    'age_min' => round((time() - $mtime) / 60),
                ]);
            } else {
                Log::warning("transcription:cleanup-orphan-wav: unlink falló", [
                    'path' => $file,
                ]);
            }
        }

        $this->info(sprintf(
            "Encontrados: %d | Borrados%s: %d | Omitidos (fd abierto): %d | Omitidos (mtime < %d min): %d",
            $found,
            $dryRun ? ' [dry-run]' : '',
            $deleted,
            $skippedFd,
            $maxAgeMin,
            $skippedAge,
        ));

        return Command::SUCCESS;
    }

    /**
     * Devuelve true si algun proceso tiene el archivo abierto.
     * Intenta lsof primero; si no esta disponible, fuser.
     * Sin ninguna de las dos herramientas, devuelve false (asumimos libre:
     * borrar un wav en curso solo hara que el job falle y se reencole).
     */
    private function hasOpenFds(string $file): bool
    {
        $lsof = trim((string) shell_exec('command -v lsof 2>/dev/null'));
        if ($lsof !== '') {
            // lsof +D recorre el dir completo y lista cualquier path que matchee.
            $output = shell_exec(sprintf(
                '%s +D -- %s 2>/dev/null',
                escapeshellcmd($lsof),
                escapeshellarg(dirname($file)),
            ));
            if (!is_string($output)) {
                return false;
            }
            $base = basename($file);
            foreach (explode("\n", $output) as $line) {
                if (str_contains($line, $base)) {
                    return true;
                }
            }
            return false;
        }

        $fuser = trim((string) shell_exec('command -v fuser 2>/dev/null'));
        if ($fuser !== '') {
            // fuser devuelve 0 si hay proceso usandolo, != 0 si no.
            $rc = 0;
            $out = [];
            exec(sprintf(
                '%s %s 2>/dev/null',
                escapeshellcmd($fuser),
                escapeshellarg($file),
            ), $out, $rc);
            return $rc === 0;
        }

        return false;
    }
}