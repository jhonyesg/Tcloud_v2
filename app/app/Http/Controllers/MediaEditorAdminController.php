<?php

namespace App\Http\Controllers;

use App\Models\MediaEditJob;
use App\Models\User;
use Illuminate\Http\Request;

class MediaEditorAdminController extends Controller
{
    public function index()
    {
        return view('admin.media-editor');
    }

    public function users(Request $request)
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        $users = User::selectRaw('users.*,
            (SELECT COUNT(*) FROM media_edit_jobs WHERE user_id = users.id AND status = \'done\'
             AND EXTRACT(MONTH FROM created_at) = ? AND EXTRACT(YEAR FROM created_at) = ?) as clips_this_month,
            (SELECT COUNT(*) FROM media_edit_jobs WHERE user_id = users.id AND status = \'done\') as clips_total,
            (SELECT created_at FROM media_edit_jobs WHERE user_id = users.id ORDER BY created_at DESC LIMIT 1) as last_clip_at',
            [$currentMonth, $currentYear])
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'media_editor_enabled' => $user->media_editor_enabled,
                    'media_editor_clip_limit' => $user->media_editor_clip_limit,
                    'can_use_media_editor' => $user->canUseMediaEditor(),
                    'clips_this_month' => $user->clips_this_month,
                    'clips_total' => $user->clips_total,
                    'last_clip_at' => $user->last_clip_at,
                ];
            });

        return response()->json($users);
    }

    public function stats()
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        $totalClipsMonth = MediaEditJob::where('status', 'done')
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->count();

        $totalClipsAll = MediaEditJob::where('status', 'done')->count();

        $activeUsers = User::where('media_editor_enabled', true)->count();
        $failedJobs = MediaEditJob::where('status', 'failed')
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->count();

        $clipTmpDir   = '/mnt/cliptemp';
        $diskTotal    = @disk_total_space($clipTmpDir) ?: 0;
        $diskFree     = @disk_free_space($clipTmpDir) ?: 0;
        $diskUsed     = $diskTotal - $diskFree;

        return response()->json([
            'clips_this_month'    => $totalClipsMonth,
            'clips_total'         => $totalClipsAll,
            'active_users'        => $activeUsers,
            'failed_this_month'   => $failedJobs,
            'ramdisk_total_gb'    => $diskTotal > 0 ? round($diskTotal / 1073741824, 1) : 0,
            'ramdisk_used_gb'     => round($diskUsed  / 1073741824, 2),
            'ramdisk_free_gb'     => $diskTotal > 0 ? round($diskFree  / 1073741824, 1) : 0,
            'ramdisk_percent'     => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0,
            'ramdisk_available'   => $diskTotal > 0,
        ]);
    }

    public function updateUser(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'media_editor_enabled' => 'sometimes|boolean',
            'media_editor_clip_limit' => 'sometimes|integer|min:0',
        ]);

        $data = [];
        if ($request->has('media_editor_enabled')) {
            $data['media_editor_enabled'] = $request->boolean('media_editor_enabled');
        }
        if ($request->has('media_editor_clip_limit')) {
            $data['media_editor_clip_limit'] = (int) $request->media_editor_clip_limit;
        }

        $user->update($data);

        return response()->json([
            'media_editor_enabled' => $user->media_editor_enabled,
            'media_editor_clip_limit' => $user->media_editor_clip_limit,
        ]);
    }
}
