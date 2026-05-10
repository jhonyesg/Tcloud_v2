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
    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    public function clip(Request $request, int $id)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);
        if (!$user->canUseMediaEditor()) return response()->json(['error' => 'Editor de medios no habilitado para tu cuenta'], 403);
        if ($user->hasReachedClipLimit()) {
            $limit = $user->media_editor_clip_limit;
            return response()->json(['error' => "Límite mensual alcanzado ({$limit} cortes/mes). Contacta al administrador."], 403);
        }

        // New format: sequence of {fileId, start?, end?}
        if ($request->has('sequence')) {
            return $this->processSequence($request, $id, $user);
        }

        // Legacy format: segments from single file
        return $this->processLegacySegments($request, $id, $user);
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
                return response()->json(['error' => 'Error FFmpeg: ' . substr($err, -300)], 500);
            }

            $job->update(['status' => 'done']);
            return $this->streamFile($tmpOutput, $outputName, $ext);

        } catch (\Exception $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            @unlink($tmpOutput);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function buildSequenceCommand(array $sequence, array $fileMap, string $outputPath, string $ext): array
    {
        $isAudio = in_array($ext, ['mp3', 'm4a']);

        // Map fileId → ffmpeg input index
        $inputs      = [];
        $inputIndex  = [];
        foreach ($sequence as $item) {
            $fid = (int) $item['fileId'];
            if (!isset($inputIndex[$fid])) {
                $inputIndex[$fid] = count($inputs);
                $inputs[]         = $fileMap[$fid]['path'];
            }
        }

        // Optimisation: single input + single item, use -c copy
        if (count($inputs) === 1 && count($sequence) === 1) {
            $item = $sequence[0];
            if (isset($item['start'], $item['end'])) {
                return ['ffmpeg', '-y', '-i', $inputs[0],
                    '-ss', (string) $item['start'], '-to', (string) $item['end'],
                    '-c', 'copy', $outputPath];
            }
            // Full file copy
            return ['ffmpeg', '-y', '-i', $inputs[0], '-c', 'copy', $outputPath];
        }

        // Build filter_complex for N clips
        $filterParts = [];
        $n           = count($sequence);

        foreach ($sequence as $i => $item) {
            $idx      = $inputIndex[(int) $item['fileId']];
            $hasStart = isset($item['start']);
            $hasEnd   = isset($item['end']);
            $trimArg  = '';
            if ($hasStart || $hasEnd) {
                $trimArg = ($hasStart ? 'start=' . $item['start'] : '') . ($hasStart && $hasEnd ? ':' : '') . ($hasEnd ? 'end=' . $item['end'] : '');
            }

            if ($isAudio) {
                if ($trimArg !== '') {
                    $filterParts[] = "[{$idx}:a]atrim={$trimArg},asetpts=PTS-STARTPTS[a{$i}]";
                } else {
                    $filterParts[] = "[{$idx}:a]anull[a{$i}]";
                }
            } else {
                if ($trimArg !== '') {
                    $filterParts[] = "[{$idx}:v]trim={$trimArg},setpts=PTS-STARTPTS[v{$i}]";
                    $filterParts[] = "[{$idx}:a]atrim={$trimArg},asetpts=PTS-STARTPTS[a{$i}]";
                } else {
                    $filterParts[] = "[{$idx}:v]null[v{$i}]";
                    $filterParts[] = "[{$idx}:a]anull[a{$i}]";
                }
            }
        }

        $cmd = ['ffmpeg', '-y'];
        foreach ($inputs as $p) { $cmd[] = '-i'; $cmd[] = $p; }

        if ($isAudio) {
            $joined = implode('', array_map(fn($i) => "[a{$i}]", range(0, $n - 1)));
            $filterParts[] = "{$joined}concat=n={$n}:v=0:a=1[aout]";
            $cmd[] = '-filter_complex';
            $cmd[] = implode(';', $filterParts);
            $cmd[] = '-map'; $cmd[] = '[aout]';
            if ($ext === 'mp3') {
                array_push($cmd, '-c:a', 'libmp3lame', '-q:a', '2');
            } else {
                array_push($cmd, '-c:a', 'aac', '-b:a', '192k');
            }
        } else {
            $vj = implode('', array_map(fn($i) => "[v{$i}]", range(0, $n - 1)));
            $aj = implode('', array_map(fn($i) => "[a{$i}]", range(0, $n - 1)));
            $filterParts[] = "{$vj}{$aj}concat=n={$n}:v=1:a=1[vout][aout]";
            $cmd[] = '-filter_complex';
            $cmd[] = implode(';', $filterParts);
            array_push($cmd, '-map', '[vout]', '-map', '[aout]',
                '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
                '-c:a', 'aac', '-b:a', '192k');
        }

        $cmd[] = $outputPath;
        return $cmd;
    }

    // ── Legacy segments mode (kept for compatibility) ──────────────

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
