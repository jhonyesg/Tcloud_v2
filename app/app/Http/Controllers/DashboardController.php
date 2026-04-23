<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use App\Models\StorageProvider;
use App\Models\File;
use App\Models\Share;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = Session::get('user_id');
        $role = Session::get('user_role');
        $user = User::find($userId);

        if ($role === 'admin') {
            return view('dashboard.admin', [
                'stats' => [
                    'total_users' => User::count(),
                    'total_storages' => StorageProvider::count(),
                    'total_files' => File::count(),
                    'total_shares' => Share::count(),
                    'storage_used' => File::sum('size'),
                ],
                'user' => $user,
            ]);
        }

        $userStorages = $user->userStorages()->with('storageProvider')->get();
        $recentFiles = File::where('owner_id', $userId)->orderBy('created_at', 'desc')->limit(10)->get();

        return view('dashboard.user', [
            'user' => $user,
            'storages' => $userStorages,
            'recentFiles' => $recentFiles,
            'quota' => [
                'used' => $user->personal_used_bytes,
                'limit' => $user->personal_quota_bytes,
            ],
        ]);
    }
}