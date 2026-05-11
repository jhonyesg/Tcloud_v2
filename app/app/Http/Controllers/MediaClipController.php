<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\MediaEditJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\Process\Process;

class MediaClipController extends Controller
{
    private ?string $tmpConcatFile = null;

    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    public function history(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $jobs = MediaEditJob::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($j) => [
                'id'              => $j->id,
                'source_file_id'  => $j->source_file_id,
                'source_file_name'=> $j->source_file_name,
                'output_filename' => $j->output_filename,
                'segments'        => $j->segments_json,
                'status'          => $j->status,
                'error_message'   => $j->error_message,
                'created_at'      => $j->created_at->toIso8601String(),
            ]);

        return response()->json($jobs);
    }

    public function reclip(Request $request, int $jobId)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $job = MediaEditJob::where('id', $jobId)->where('user_id', $user->id)->first();
        if (!$job) return response()->json(['error' => 'Job no encontrado'], 404);

        return response()->json([
            'source_file_id'   => $job->source_file_id,
            'source_file_name' => $job->source_file_name,
            'segments'         => $job->segments_json,
        ]);
    }

    public function clip(Request $request, int $id)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);
        if (!$user->canUseMediaEditor()) return response()->json(['error' => 'Editor de medios no habilitado para tu cuenta'], 403);

        $isPreview = $request->boolean('preview', false);

        if (!$isPreview && $user->hasReachedClipLimit()) {
            $limit = $user->media_editor_clip_limit;
            return response()->json(['error' => "Límite mensual alcanzado ({$limit} cortes/mes). Contacta al administrador."], 403);
        }

        if ($request->has('sequence')) {
            return $isPreview
                ? $this->previewSequence($request, $id, $user)
                : $this->processSequence($request, $id, $user);
        }

        return $isPreview
            ? $this->previewLegacySegments($request, $id, $user)
            : $this->processLegacySegments($request, $id, $user);
    }

    public function serveTemp(string $token)
    {
        $path = sys_get_temp_dir() . '/clippreview_' . $token;
        if (!file_exists($path)) return response()->json(['error' => 'Preview no encontrado o expirado'], 404);

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        return response()->file($path, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ── New sequence mode ──────────────────────────────────────────

    private function processSequence(Request $request, int $primaryFileId, User $user)
    {
        $sequence = $request->input('sequence', []);
        if (empty($sequence)) {
            return response()->json(['error' => 'La secuencia está vacía.'], 422);
        }

        // Load and validate every referenced file
        $fileMap = [];
        foreach ($sequence as $item) {
            $fid = (int) ($item['fileId'] ?? 0);
            if (!$fid) return response()->json(['error' => 'ID de archivo inválido en la secuencia.'], 422);

            if (!isset($fileMap[$fid])) {
                $file    = File::find($fid);
                $storage = $file?->storageProvider;
                if (!$file || !$storage || $storage->type !== 'local') {
                    return response()->json(['error' => "Archivo {$fid} no encontrado o no es local."], 404);
                }
                $path = rtrim($storage->base_path, '/') . '/' . ltrim($file->path, '/');
                if (!file_exists($path)) {
                    return response()->json(['error' => "Archivo físico no encontrado: {$file->name}"], 404);
                }
                $fileMap[$fid] = ['file' => $file, 'path' => $path];
            }
        }

        $primaryFile = $fileMap[$primaryFileId]['file'] ?? File::find($primaryFileId);
        $ext         = strtolower(pathinfo($primaryFile->name, PATHINFO_EXTENSION));
        $baseName    = pathinfo($primaryFile->name, PATHINFO_FILENAME);
        $outputName  = $baseName . '_corte.' . $ext;
        $tmpOutput   = sys_get_temp_dir() . '/' . uniqid('clip_', true) . '.' . $ext;

        $job = MediaEditJob::create([
            'user_id'          => $user->id,
            'source_file_id'   => $primaryFileId,
            'source_file_name' => $primaryFile->name,
            'segments_json'    => $sequence,
            'output_filename'  => $outputName,
            'status'           => 'processing',
        ]);

        try {
            $cmd = $this->buildSequenceCommand($sequence, $fileMap, $tmpOutput, $ext);

            $process = new Process($cmd);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($tmpOutput)) {
                $err = $process->getErrorOutput() ?: $process->getOutput();
                $job->update(['status' => 'failed', 'error_message' => substr($err, -1000)]);
                @unlink($tmpOutput);
                @unlink($this->tmpConcatFile);
                return response()->json(['error' => 'Error FFmpeg: ' . substr($err, -300)], 500);
            }

            @unlink($this->tmpConcatFile);
            $job->update(['status' => 'done']);
            return $this->streamFile($tmpOutput, $outputName, $ext);

        } catch (\Exception $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            @unlink($tmpOutput);
            @unlink($this->tmpConcatFile);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function buildSequenceCommand(array $sequence, array $fileMap, string $outputPath, string $ext): array
    {
        $this->tmpConcatFile = null;

        // Single segment: input-side seek + stream copy (instantáneo)
        if (count($sequence) === 1) {
            $item = $sequence[0];
            $path = $fileMap[(int) $item['fileId']]['path'];
            $cmd  = ['ffmpeg', '-y'];
            if (isset($item['start']) && (float) $item['start'] > 0) {
                $cmd[] = '-ss'; $cmd[] = (string) $item['start'];
            }
            $cmd[] = '-i'; $cmd[] = $path;
            if (isset($item['start'], $item['end'])) {
                $duration = max(0, (float) $item['end'] - (float) $item['start']);
                $cmd[] = '-t'; $cmd[] = (string) $duration;
            } elseif (isset($item['end'])) {
                $cmd[] = '-to'; $cmd[] = (string) $item['end'];
            }
            array_push($cmd, '-c', 'copy', $outputPath);
            return $cmd;
        }

        // Múltiples segmentos: concat demuxer con -c copy (sin re-codificar)
        $lines = ['ffconcat version 1.0'];
        foreach ($sequence as $item) {
            $path    = $fileMap[(int) $item['fileId']]['path'];
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $path);
            $lines[] = "file '{$escaped}'";
            if (isset($item['start'])) { $lines[] = 'inpoint '  . $item['start']; }
            if (isset($item['end']))   { $lines[] = 'outpoint ' . $item['end']; }
        }

        $this->tmpConcatFile = tempnam(sys_get_temp_dir(), 'ffconcat_') . '.txt';
        file_put_contents($this->tmpConcatFile, implode("\n", $lines) . "\n");

        return ['ffmpeg', '-y', '-f', 'concat', '-safe', '0',
                '-i', $this->tmpConcatFile, '-c', 'copy', $outputPath];
    }

    // ── Preview mode (same FFmpeg logic, keeps temp file) ──────────

    private function previewSequence(Request $request, int $primaryFileId, User $user): \Illuminate\Http\JsonResponse
    {
        $sequence = $request->input('sequence', []);
        if (empty($sequence)) return response()->json(['error' => 'Secuencia vacía'], 422);

        $fileMap = [];
        foreach ($sequence as $item) {
            $fid = (int) ($item['fileId'] ?? 0);
            if (!$fid) return response()->json(['error' => 'ID inválido'], 422);
            if (!isset($fileMap[$fid])) {
                $file = File::find($fid);
                $storage = $file?->storageProvider;
                if (!$file || !$storage || $storage->type !== 'local') {
                    return response()->json(['error' => "Archivo {$fid} no encontrado o no local"], 404);
                }
                $path = rtrim($storage->base_path, '/') . '/' . ltrim($file->path, '/');
                if (!file_exists($path)) return response()->json(['error' => "Archivo físico no encontrado"], 404);
                $fileMap[$fid] = ['file' => $file, 'path' => $path];
            }
        }

        $primaryFile = $fileMap[$primaryFileId]['file'] ?? File::find($primaryFileId);
        $ext = strtolower(pathinfo($primaryFile->name, PATHINFO_EXTENSION));
        $previewToken = uniqid('prev_', true);
        $tmpOutput = sys_get_temp_dir() . '/clippreview_' . $previewToken . '.' . $ext;

        try {
            $cmd = $this->buildSequenceCommand($sequence, $fileMap, $tmpOutput, $ext);
            $process = new Process($cmd);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($tmpOutput)) {
                @unlink($tmpOutput);
                @unlink($this->tmpConcatFile);
                $err = $process->getErrorOutput() ?: $process->getOutput();
                return response()->json(['error' => 'Error FFmpeg: ' . substr($err, -300)], 500);
            }

            @unlink($this->tmpConcatFile);
            $this->scheduleCleanup($tmpOutput, 300);

            $mimeMap = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
            return response()->json([
                'preview_url' => '/media/clip-preview/' . $previewToken,
                'mime'        => $mimeMap[$ext] ?? 'application/octet-stream',
                'size'        => filesize($tmpOutput),
            ]);
        } catch (\Exception $e) {
            @unlink($tmpOutput);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function previewLegacySegments(Request $request, int $id, User $user): \Illuminate\Http\JsonResponse
    {
        $file = File::find($id);
        if (!$file) return response()->json(['error' => 'File not found'], 404);

        $segments = $request->input('segments', []);
        if (empty($segments)) return response()->json(['error' => 'Segments required'], 422);

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Solo storage local'], 422);
        }

        $filePath = rtrim($storage->base_path, '/') . '/' . ltrim($file->path, '/');
        if (!file_exists($filePath)) return response()->json(['error' => 'Archivo no encontrado'], 422);

        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $previewToken = uniqid('prev_', true);
        $tmpOutput = sys_get_temp_dir() . '/clippreview_' . $previewToken . '.' . $ext;

        try {
            $cmd = count($segments) === 1
                ? $this->buildSingleSegmentCommand($filePath, $segments[0], $tmpOutput)
                : $this->buildMultiSegmentCommand($filePath, $segments, $tmpOutput, $ext);

            $process = new Process($cmd);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                @unlink($tmpOutput);
                return response()->json(['error' => 'FFmpeg error'], 500);
            }

            $this->scheduleCleanup($tmpOutput, 300);

            $mimeMap = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
            return response()->json([
                'preview_url' => '/media/clip-preview/' . $previewToken,
                'mime'        => $mimeMap[$ext] ?? 'application/octet-stream',
                'size'        => filesize($tmpOutput),
            ]);
        } catch (\Exception $e) {
            @unlink($tmpOutput);
            @unlink($this->tmpConcatFile);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function scheduleCleanup(string $path, int $seconds): void
    {
        $cmd = sprintf('(sleep %d && rm -f %s) &', $seconds, escapeshellarg($path));
        exec($cmd);
    }

    private function processLegacySegments(Request $request, int $id, User $user)
    {
        $file = File::find($id);
        if (!$file) return response()->json(['error' => 'File not found'], 404);

        $segments = $request->input('segments', []);
        if (empty($segments) || !is_array($segments)) {
            return response()->json(['error' => 'At least one segment is required'], 422);
        }
        foreach ($segments as $seg) {
            if (($seg['start'] ?? null) === null || ($seg['end'] ?? null) === null
                || $seg['start'] < 0 || $seg['end'] <= $seg['start']) {
                return response()->json(['error' => 'Each segment must have start >= 0 and end > start'], 422);
            }
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Media editing is only supported for local storage'], 422);
        }

        $filePath = rtrim($storage->base_path, '/') . '/' . ltrim($file->path, '/');
        if (!file_exists($filePath)) return response()->json(['error' => 'Source file not found on disk'], 422);

        $ext           = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $outputFilename = pathinfo($file->name, PATHINFO_FILENAME) . '_corte.' . $ext;
        $tmpOutput     = sys_get_temp_dir() . '/' . uniqid('clip_', true) . '.' . $ext;

        $job = MediaEditJob::create([
            'user_id' => $user->id, 'source_file_id' => $file->id,
            'source_file_name' => $file->name, 'segments_json' => $segments,
            'output_filename' => $outputFilename, 'status' => 'processing',
        ]);

        try {
            $cmd = count($segments) === 1
                ? $this->buildSingleSegmentCommand($filePath, $segments[0], $tmpOutput)
                : $this->buildMultiSegmentCommand($filePath, $segments, $tmpOutput, $ext);

            $process = new Process($cmd);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                $err = $process->getErrorOutput() ?: $process->getOutput();
                $job->update(['status' => 'failed', 'error_message' => substr($err, -1000)]);
                @unlink($tmpOutput);
                return response()->json(['error' => 'FFmpeg processing failed', 'detail' => substr($err, -500)], 500);
            }

            $job->update(['status' => 'done']);
            return $this->streamFile($tmpOutput, $outputFilename, $ext);

        } catch (\Exception $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            @unlink($tmpOutput);
            return response()->json(['error' => 'Internal error: ' . $e->getMessage()], 500);
        }
    }

    // ── Thumbnails de timeline ─────────────────────────────────────

    public function thumbnails(Request $request, int $id)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $file    = File::find($id);
        $storage = $file?->storageProvider;
        if (!$file || !$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Archivo no encontrado'], 404);
        }

        $srcPath = rtrim($storage->base_path, '/') . '/' . ltrim($file->path, '/');
        if (!file_exists($srcPath)) return response()->json(['error' => 'Archivo físico no encontrado'], 404);

        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
            return response()->json(['thumbs' => []]);
        }

        $thumbDir = storage_path("app/clip-thumbs/{$id}");
        $count    = 20;

        // Regenerar si no existen o el archivo fue modificado
        $markerFile  = $thumbDir . '/.mtime';
        $srcMtime    = (string) filemtime($srcPath);
        $cachedMtime = file_exists($markerFile) ? trim(file_get_contents($markerFile)) : '';
        $hasCache    = $cachedMtime === $srcMtime
            && count(glob($thumbDir . '/thumb_*.jpg') ?: []) >= $count;

        if (!$hasCache) {
            set_time_limit(180);
            try {
                $ok = $this->generateClipThumbnails($srcPath, $thumbDir, $count);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if (!$ok) return response()->json(['thumbs' => []]);
            @mkdir($thumbDir, 0755, true);
            file_put_contents($markerFile, $srcMtime);
        }

        $thumbs = [];
        for ($i = 0; $i < $count; $i++) {
            $file_path = storage_path(sprintf('app/clip-thumbs/%d/thumb_%02d.jpg', $id, $i + 1));
            if (file_exists($file_path)) $thumbs[] = "/files/{$id}/clip-thumb/{$i}";
        }
        return response()->json(['thumbs' => $thumbs]);
    }

    public function thumb(Request $request, int $id, int $n)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $path = storage_path(sprintf('app/clip-thumbs/%d/thumb_%02d.jpg', $id, $n + 1));
        if (!file_exists($path)) abort(404);

        return response()->file($path, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function generateClipThumbnails(string $srcPath, string $thumbDir, int $count): bool
    {
        // Obtener duración con ffprobe
        $probe = new Process([
            'ffprobe', '-v', 'quiet', '-print_format', 'json',
            '-show_format', $srcPath,
        ]);
        $probe->setTimeout(15);
        $probe->run();
        if (!$probe->isSuccessful()) return false;

        $info     = json_decode($probe->getOutput(), true);
        $duration = (float) ($info['format']['duration'] ?? 0);
        if ($duration <= 0) return false;

        @mkdir($thumbDir, 0755, true);
        foreach (glob($thumbDir . '/thumb_*.jpg') ?: [] as $f) @unlink($f);

        // Un seek por frame con -ss antes de -i: salta directo al keyframe sin
        // decodificar el video completo. Rápido incluso en archivos de 1+ hora.
        $generated = 0;
        for ($i = 0; $i < $count; $i++) {
            $t       = ($i / $count) * $duration;
            $outFile = sprintf('%s/thumb_%02d.jpg', $thumbDir, $i + 1);
            $cmd = [
                'ffmpeg', '-y',
                '-ss', number_format($t, 3, '.', ''),
                '-i', $srcPath,
                '-frames:v', '1',
                '-vf', 'scale=160:90',
                '-q:v', '4',
                $outFile,
            ];
            try {
                $p = new Process($cmd);
                $p->setTimeout(20);
                $p->run();
                if (file_exists($outFile)) $generated++;
            } catch (\Throwable $e) {
                // Sigue con el siguiente frame si uno falla
            }
        }

        return $generated > 0;
    }

    // ── Shared helpers ─────────────────────────────────────────────

    private function streamFile(string $tmpOutput, string $filename, string $ext)
    {
        $mimeMap = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
        $mime    = $mimeMap[$ext] ?? 'application/octet-stream';
        $size    = filesize($tmpOutput);

        return response()->streamDownload(function () use ($tmpOutput) {
            $h = fopen($tmpOutput, 'rb');
            while (!feof($h)) { echo fread($h, 65536); flush(); }
            fclose($h);
            @unlink($tmpOutput);
        }, $filename, ['Content-Type' => $mime, 'Content-Length' => $size]);
    }

    private function buildSingleSegmentCommand(string $input, array $segment, string $output): array
    {
        return ['ffmpeg', '-y', '-i', $input,
            '-ss', (string) $segment['start'], '-to', (string) $segment['end'],
            '-c', 'copy', $output];
    }

    private function buildMultiSegmentCommand(string $input, array $segments, string $output, string $ext): array
    {
        if ($ext === 'mp3') return $this->buildMultiSegmentAudioCommand($input, $segments, $output);

        $hasAudio = $this->fileHasAudioStream($input);
        $n = count($segments);
        $filter = '';
        $streams = '';
        foreach ($segments as $i => $seg) {
            $filter  .= "[0:v]trim=start={$seg['start']}:end={$seg['end']},setpts=PTS-STARTPTS[v{$i}];";
            if ($hasAudio) {
                $filter  .= "[0:a]atrim=start={$seg['start']}:end={$seg['end']},asetpts=PTS-STARTPTS[a{$i}];";
                $streams .= "[v{$i}][a{$i}]";
            } else {
                $streams .= "[v{$i}]";
            }
        }

        if ($hasAudio) {
            $filter .= "{$streams}concat=n={$n}:v=1:a=1[outv][outa]";
            return ['ffmpeg', '-y', '-i', $input, '-filter_complex', $filter,
                '-map', '[outv]', '-map', '[outa]',
                '-c:v', 'libx264', '-preset', 'fast', '-c:a', 'aac', $output];
        }

        $filter .= "{$streams}concat=n={$n}:v=1:a=0[outv]";
        return ['ffmpeg', '-y', '-i', $input, '-filter_complex', $filter,
            '-map', '[outv]',
            '-c:v', 'libx264', '-preset', 'fast', $output];
    }

    private function fileHasAudioStream(string $path): bool
    {
        $cmd = ['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path];
        $process = new Process($cmd);
        $process->setTimeout(10);
        $process->run();
        return trim($process->getOutput()) !== '';
    }

    private function buildMultiSegmentAudioCommand(string $input, array $segments, string $output): array
    {
        $n = count($segments);
        $filter = '';
        $streams = '';
        foreach ($segments as $i => $seg) {
            $filter  .= "[0:a]atrim=start={$seg['start']}:end={$seg['end']},asetpts=PTS-STARTPTS[a{$i}];";
            $streams .= "[a{$i}]";
        }
        $filter .= "{$streams}concat=n={$n}:v=0:a=1[outa]";

        return ['ffmpeg', '-y', '-i', $input, '-filter_complex', $filter,
            '-map', '[outa]', '-c:a', 'libmp3lame', $output];
    }
}
