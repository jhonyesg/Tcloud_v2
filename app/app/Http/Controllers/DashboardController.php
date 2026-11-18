<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use App\Services\Dashboard\DashboardDataProvider;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardDataProvider $dataProvider)
    {
    }

    /**
     * Id del storage personal del usuario, o `null` si no tiene.
     *
     * Query directa: materializar la colección completa de `user_storages` con
     * eager load solo para elegir uno costaba 61-106 ms con usuarios de 27-122
     * storages (medido 2026-11-18). El `orderBy('us.id')` conserva exactamente
     * la elección previa (`->get()->first()`), que es por insertion order.
     */
    private function personalStorageId(User $user): ?int
    {
        $id = DB::table('user_storages as us')
            ->join('storage_providers as sp', 'sp.id', '=', 'us.storage_provider_id')
            ->where('us.user_id', $user->id)
            ->where('sp.is_personal', true)
            ->orderBy('us.id')
            ->value('sp.id');

        return $id === null ? null : (int) $id;
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

            $blocks = $this->dataProvider->buildAdmin($user);
            $dashboardData = $this->dataProvider->dataOf($blocks);

            return view('dashboard.admin', [
                'stats' => $dashboardData['stats'],
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
                'dashboardData' => $dashboardData,
                'dashboardFreshness' => $this->dataProvider->freshnessOf($blocks),
                'user' => $user,
                'personalStorageId' => $this->personalStorageId($user),
                'instructivos' => $this->scanInstructivos(),
            ]);
        }

        $userStorages = $user->userStorages()->with('storageProvider')->get();

        $activeShares = $user->shares()
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->whereHas('file')
            ->count();
        $expiredShares = $user->shares()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
        $unavailableShares = $user->shares()
            ->whereDoesntHave('file')
            ->count();

        $blocks = $this->dataProvider->buildClient($user);
        $dashboardData = $this->dataProvider->dataOf($blocks);

        return view('dashboard.user', [
            'user' => $user,
            'storages' => $userStorages,
            'canalesCount' => $user->canales()->count(),
            'dashboardData' => $dashboardData,
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
