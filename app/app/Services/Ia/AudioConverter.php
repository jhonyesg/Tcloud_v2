<?php

namespace App\Services\Ia;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Wrapper sobre ffmpeg local para normalizar el audio a mono 16kHz antes de
 * enviarlo al transcriptor. El códec depende del formato pedido: wav
 * (pcm_s16le, sin pérdida, lo que consume directamente el modelo ASR) u opus
 * (libopus 64k, ~4x menos ancho de banda a cambio de una etapa con pérdida).
 *
 * Si se le pasa un $progressKey, escribe el porcentaje en un archivo de cache
 * para que el modal de progreso del frontend pueda consultarlo vía SSE/poll.
 */
class AudioConverter
{
    /**
     * Flags de códec por formato. Único sitio donde vive esa decisión.
     *
     * @return list<string>
     */
    public static function codecArgs(string $format): array
    {
        return match ($format) {
            'wav' => ['-c:a', 'pcm_s16le'],
            'opus' => ['-c:a', 'libopus', '-b:a', '64k'],
            default => throw new \InvalidArgumentException("Formato de audio no soportado: {$format}"),
        };
    }

    /**
     * Extensión del archivo temporal para un formato. Determina también el
     * Content-Type con el que viaja el multipart.
     */
    public static function extensionFor(string $format): string
    {
        return $format === 'opus' ? 'opus' : 'wav';
    }

    public function convert(string $srcPath, string $dstPath, string $format = 'wav', ?string $progressKey = null): void
    {
        // Se valida antes de tocar disco o lanzar procesos: un formato
        // desconocido es un error de configuración, no un fallo de ffmpeg.
        $codecArgs = self::codecArgs($format);

        if (!is_file($srcPath) || !is_readable($srcPath)) {
            throw new \RuntimeException("Archivo de origen no legible: {$srcPath}");
        }

        $ffmpeg = (new ExecutableFinder())->find('ffmpeg');
        if ($ffmpeg === null) {
            throw new \RuntimeException('ffmpeg no encontrado en el PATH. Instale ffmpeg >= 4.0.');
        }

        // Total de duración del archivo de origen (segundos) para calcular %.
        $duration = $this->probeDuration($ffmpeg, $srcPath);
        $progressFile = $progressKey ? $this->progressFilePath($progressKey) : null;

        // Verificar que el archivo tenga al menos un stream de audio; si es
        // video-only ffmpeg falla con "Output file #0 does not contain any stream".
        $audioMap = $this->probeFirstAudioStreamIndex($srcPath);
        if ($audioMap === null) {
            $msg = "El archivo no contiene pistas de audio (parece ser video-only). "
                . "No se puede transcribir: {$srcPath}";
            if ($progressFile) {
                $this->writeProgress($progressFile, [
                    'phase' => 'error',
                    'percent' => 0,
                    'error' => $msg,
                ]);
            }
            throw new \RuntimeException($msg);
        }

        // Escribir fase inicial
        if ($progressFile) {
            $this->writeProgress($progressFile, [
                'phase' => 'converting',
                'percent' => 1,
                'current_seconds' => 0,
                'total_seconds' => $duration,
                'dst_path' => $dstPath,
            ]);
        }

        $cmd = [
            $ffmpeg, '-y',
            '-i', $srcPath,
            '-map', $audioMap,
            '-vn', '-ac', '1', '-ar', '16000',
            ...$codecArgs,
            '-progress', 'pipe:1',
            '-nostats',
            $dstPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(600);

        // Mientras corre, parsear la salida de -progress que viene en formato key=value.
        $process->run(function ($type, $buffer) use ($progressFile, $duration) {
            if (!$progressFile || $type !== Process::OUT) return;
            foreach (explode("\n", $buffer) as $line) {
                if (strpos($line, 'out_time_ms=') === 0) {
                    $us = (int) trim(substr($line, 12));
                    $cur = $us / 1_000_000; // a segundos
                    $pct = $duration > 0 ? min(98, (int) round(($cur / $duration) * 70)) : 0;
                    $this->writeProgress($progressFile, [
                        'phase' => 'converting',
                        'percent' => $pct,
                        'current_seconds' => round($cur, 2),
                        'total_seconds' => $duration,
                    ]);
                }
            }
        });

        if (!$process->isSuccessful() || !file_exists($dstPath)) {
            if ($progressFile) {
                $this->writeProgress($progressFile, [
                    'phase' => 'error',
                    'percent' => 0,
                    'error' => substr($process->getErrorOutput() ?: $process->getOutput(), -1000),
                ]);
            }
            throw new \RuntimeException('ffmpeg falló: ' . substr($process->getErrorOutput() ?: $process->getOutput(), -1000));
        }

        if ($progressFile) {
            $this->writeProgress($progressFile, [
                'phase' => 'converting',
                'percent' => 70,
                'current_seconds' => $duration,
                'total_seconds' => $duration,
            ]);
        }
    }

    /**
     * Devuelve el path del binario ffmpeg o null si no está disponible.
     */
    public function whichFfmpeg(): ?string
    {
        return (new ExecutableFinder())->find('ffmpeg');
    }

    /**
     * Devuelve la duración total del archivo de audio (segundos, float).
     * Usa ffprobe si está disponible, si no ffmpeg -i.
     */
    private function probeDuration(string $ffmpeg, string $srcPath): float
    {
        $probe = (new ExecutableFinder())->find('ffprobe');
        if ($probe !== null) {
            $p = new Process([$probe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $srcPath]);
            $p->setTimeout(10);
            $p->run();
            if ($p->isSuccessful()) {
                return (float) trim($p->getOutput());
            }
        }
        // Fallback: parsear "Duration: HH:MM:SS.cc" de ffmpeg -i
        $p = new Process([$ffmpeg, '-i', $srcPath]);
        $p->setTimeout(10);
        $p->run();
        $out = $p->getErrorOutput() . $p->getOutput();
        if (preg_match('/Duration:\s*(\d+):(\d+):(\d+\.\d+)/', $out, $m)) {
            return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
        }
        return 0.0;
    }

    /**
     * Devuelve el índice del primer stream de audio del archivo (ej. "0:1")
     * o null si el archivo no contiene ningún stream de audio.
     */
    private function probeFirstAudioStreamIndex(string $srcPath): ?string
    {
        $probe = (new ExecutableFinder())->find('ffprobe');
        if ($probe === null) {
            // Sin ffprobe no podemos verificar: dejar que ffmpeg decida.
            return '0:a';
        }
        $p = new Process([
            $probe, '-v', 'error',
            '-select_streams', 'a',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            $srcPath,
        ]);
        $p->setTimeout(10);
        $p->run();
        if (!$p->isSuccessful()) {
            return '0:a';
        }
        $first = trim($p->getOutput());
        // ffprobe csv: una línea por stream de audio; tomar la primera no vacía.
        foreach (preg_split('/\r?\n/', $first) as $line) {
            $line = trim($line);
            if ($line !== '') {
                // Devolver como "0:<index>" para usarlo en -map 0:INDEX
                return '0:' . $line;
            }
        }
        return null;
    }

    /**
     * Archivo donde se persiste el progreso en vivo (un JSON por job).
     */
    public function progressFilePath(string $key): string
    {
        // Progreso en RAM (tmpfs) si está disponible, fallback a sys_temp_dir.
        $base = (is_dir('/dev/shm') && is_writable('/dev/shm')) ? '/dev/shm' : sys_get_temp_dir();
        return $base . '/tcloud-transcription-progress/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
    }

    private function writeProgress(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
        $data['updated_at'] = microtime(true);
        @file_put_contents($path, json_encode($data));
    }

    /**
     * Lee el progreso actual del job.
     */
    public static function readProgress(string $key): ?array
    {
        $base = (is_dir('/dev/shm') && is_writable('/dev/shm')) ? '/dev/shm' : sys_get_temp_dir();
        $path = $base . '/tcloud-transcription-progress/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
        if (!file_exists($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}