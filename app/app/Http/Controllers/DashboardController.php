<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use App\Models\StorageProvider;
use App\Models\File;
use App\Models\Share;
use App\Models\UserStorage;

class DashboardController extends Controller
{
    private function personalStorageId(User $user): ?int
    {
        $us = $user->userStorages()
            ->with('storageProvider')
            ->get()
            ->first(fn($us) => (bool) $us->storageProvider->is_personal);

        return $us?->storageProvider->id;
    }

    private function scanInstructivos(): array
    {
        $dir = base_path('../instructivos');
        if (!is_dir($dir)) return [];
        return collect(glob($dir . '/*.pdf'))
            ->map(fn($p) => ['name' => basename($p), 'url' => '/instructivos/' . rawurlencode(basename($p))])
            ->values()
            ->toArray();
    }

    public function index()
    {
        $userId = Session::get('user_id');
        $role = Session::get('user_role');
        $user = User::find($userId);

        if ($role === 'admin') {
            $clipTmpDir  = '/mnt/cliptemp';
            $diskTotal   = @disk_total_space($clipTmpDir) ?: 0;
            $diskFree    = @disk_free_space($clipTmpDir) ?: 0;
            $diskUsed    = $diskTotal - $diskFree;

            $shmDir       = '/dev/shm';
            $shmTotal     = @disk_total_space($shmDir) ?: 0;
            $shmFree      = @disk_free_space($shmDir) ?: 0;
            $shmUsed      = $shmTotal - $shmFree;

            $activeShares = Share::where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })->count();

            return view('dashboard.admin', [
                'stats' => [
                    'total_users'    => User::count(),
                    'total_storages' => StorageProvider::count(),
                    'total_files'    => File::count(),
                    'total_shares'   => Share::count(),
                    'active_shares'  => $activeShares,
                    'storage_used'   => File::sum('size'),
                ],
                'ramdisk' => [
                    'available'  => $diskTotal > 0,
                    'total_gb'   => $diskTotal > 0 ? round($diskTotal / 1073741824, 1) : 0,
                    'used_gb'    => round($diskUsed  / 1073741824, 2),
                    'free_gb'    => $diskTotal > 0 ? round($diskFree  / 1073741824, 1) : 0,
                    'percent'    => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0,
                ],
                'shm' => [
                    'available'  => $shmTotal > 0,
                    'total_gb'   => $shmTotal > 0 ? round($shmTotal / 1073741824, 1) : 0,
                    'used_gb'    => round($shmUsed  / 1073741824, 2),
                    'free_gb'    => $shmTotal > 0 ? round($shmFree  / 1073741824, 1) : 0,
                    'percent'    => $shmTotal > 0 ? round(($shmUsed / $shmTotal) * 100, 1) : 0,
                ],
                'user' => $user,
                'personalStorageId' => $this->personalStorageId($user),
                'instructivos' => $this->scanInstructivos(),
            ]);
        }

        $userStorages = $user->userStorages()->with('storageProvider')->get();

        $mediaEditorEnabled = $user->canUseMediaEditor();

        $activeShares = $user->shares()
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->whereHas('file', fn ($query) => $query->where(function ($fileQuery) {
                $fileQuery->whereNull('availability_state')->orWhere('availability_state', '!=', 'missing');
            }))
            ->count();
        $expiredShares = $user->shares()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
        $unavailableShares = $user->shares()
            ->whereHas('file', fn ($query) => $query->where('availability_state', 'missing'))
            ->count();

        return view('dashboard.user', [
            'user' => $user,
            'storages' => $userStorages,
            'canalesCount' => $user->canales()->count(),
            'mediaEditorEnabled' => $mediaEditorEnabled,
            'mediaEditorClipLimit' => (int) $user->media_editor_clip_limit,
            'mediaEditorClipsUsed' => $mediaEditorEnabled ? $user->mediaEditorClipsThisMonth() : 0,
            'shareStats' => [
                'active' => $activeShares,
                'expired' => $expiredShares,
                'unavailable' => $unavailableShares,
            ],
            'personalStorageId' => $this->personalStorageId($user),
            'instructivos' => $this->scanInstructivos(),
        ]);
    }
}
