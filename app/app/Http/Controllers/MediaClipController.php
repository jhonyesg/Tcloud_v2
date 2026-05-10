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
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$user->canUseMediaEditor()) {
            return response()->json(['error' => 'Media editor not enabled for your account'], 403);
        }

        if ($user->hasReachedClipLimit()) {
            $limit = $user->media_editor_clip_limit;
            return response()->json(['error' => "Límite mensual alcanzado ({$limit} cortes/mes). Contacta al administrador para ampliar tu límite."], 403);
        }

        $file = File::find($id);
        if (!$file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $segments = $request->input('segments', []);
        if (empty($segments) || !is_array($segments)) {
            return response()->json(['error' => 'At least one segment is required'], 422);
        }

        foreach ($segments as $seg) {
            $start = $seg['start'] ?? null;
            $end = $seg['end'] ?? null;
            if ($start === null || $end === null || $start < 0 || $end <= $start) {
                return response()->json(['error' => 'Each segment must have start >= 0 and end > start'], 422);
            }
        }

        $storage = $file->storageProvider;
        if (!$storage || $storage->type !== 'local') {
            return response()->json(['error' => 'Media editing is only supported for local storage files'], 422);
        }

        $basePath = rtrim($storage->base_path, '/');
        $filePath = $basePath . '/' . ltrim($file->path, '/');

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Source file not found on disk'], 422);
        }

        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $nameWithoutExt = pathinfo($file->name, PATHINFO_FILENAME);
        $outputFilename = $nameWithoutExt . '_corte.' . $ext;
        $tmpOutput = sys_get_temp_dir() . '/' . uniqid('clip_', true) . '.' . $ext;

        $job = MediaEditJob::create([
            'user_id' => $user->id,
            'source_file_id' => $file->id,
            'source_file_name' => $file->name,
            'segments_json' => $segments,
            'output_filename' => $outputFilename,
            'status' => 'processing',
        ]);

        try {
            if (count($segments) === 1) {
                $cmd = $this->buildSingleSegmentCommand($filePath, $segments[0], $tmpOutput);
            } else {
                $cmd = $this->buildMultiSegmentCommand($filePath, $segments, $tmpOutput, $ext);
            }

            $process = new Process($cmd);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                $errorDetail = $process->getErrorOutput() ?: $process->getOutput();
                $job->update(['status' => 'failed', 'error_message' => substr($errorDetail, -1000)]);
                if (file_exists($tmpOutput)) {
                    unlink($tmpOutput);
                }
                return response()->json(['error' => 'FFmpeg processing failed', 'detail' => substr($errorDetail, -500)], 500);
            }

            $job->update(['status' => 'done']);

            $mimeMap = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';

            return response()->streamDownload(function () use ($tmpOutput) {
                $handle = fopen($tmpOutput, 'rb');
                while (!feof($handle)) {
                    echo fread($handle, 65536);
                    flush();
                }
                fclose($handle);
                unlink($tmpOutput);
            }, $outputFilename, [
                'Content-Type' => $mime,
                'Content-Length' => filesize($tmpOutput),
            ]);

        } catch (\Exception $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            if (file_exists($tmpOutput)) {
                unlink($tmpOutput);
            }
            return response()->json(['error' => 'Internal error: ' . $e->getMessage()], 500);
        }
    }

    private function buildSingleSegmentCommand(string $input, array $segment, string $output): array
    {
        return [
            'ffmpeg', '-y',
            '-i', $input,
            '-ss', (string) $segment['start'],
            '-to', (string) $segment['end'],
            '-c', 'copy',
            $output,
        ];
    }

    private function buildMultiSegmentCommand(string $input, array $segments, string $output, string $ext): array
    {
        // Build filter_complex concat for multiple segments
        $filterParts = [];
        $concatInputs = '';
        $n = count($segments);

        $selectParts = [];
        foreach ($segments as $i => $seg) {
            $selectParts[] = sprintf("between(t,%s,%s)", $seg['start'], $seg['end']);
        }

        // Use trim + concat approach with filter_complex
        $filterInputs = '';
        $concatStreams = '';
        foreach ($segments as $i => $seg) {
            $filterInputs .= sprintf("[0:v]trim=start=%s:end=%s,setpts=PTS-STARTPTS[v%d];", $seg['start'], $seg['end'], $i);
            $filterInputs .= sprintf("[0:a]atrim=start=%s:end=%s,asetpts=PTS-STARTPTS[a%d];", $seg['start'], $seg['end'], $i);
            $concatStreams .= "[v{$i}][a{$i}]";
        }

        $filterComplex = $filterInputs . $concatStreams . "concat=n={$n}:v=1:a=1[outv][outa]";

        $videoCodec = in_array($ext, ['mp4', 'm4a']) ? ['libx264', '-preset', 'fast', '-c:a', 'aac'] : ['libx264', '-preset', 'fast', '-c:a', 'libmp3lame'];
        if ($ext === 'mp3') {
            // audio-only: different filter (no video)
            return $this->buildMultiSegmentAudioCommand($input, $segments, $output);
        }

        return [
            'ffmpeg', '-y',
            '-i', $input,
            '-filter_complex', $filterComplex,
            '-map', '[outv]',
            '-map', '[outa]',
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-c:a', 'aac',
            $output,
        ];
    }

    private function buildMultiSegmentAudioCommand(string $input, array $segments, string $output): array
    {
        $n = count($segments);
        $filterParts = '';
        $concatStreams = '';

        foreach ($segments as $i => $seg) {
            $filterParts .= sprintf("[0:a]atrim=start=%s:end=%s,asetpts=PTS-STARTPTS[a%d];", $seg['start'], $seg['end'], $i);
            $concatStreams .= "[a{$i}]";
        }

        $filterComplex = $filterParts . $concatStreams . "concat=n={$n}:v=0:a=1[outa]";

        return [
            'ffmpeg', '-y',
            '-i', $input,
            '-filter_complex', $filterComplex,
            '-map', '[outa]',
            '-c:a', 'libmp3lame',
            $output,
        ];
    }
}
