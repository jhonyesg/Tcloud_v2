<?php

namespace App\Providers;

use App\Models\User;
use App\Models\ExternalSite;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        view()->composer('layouts.app', function ($view) {
            $userId = Session::get('user_id');

            if (!$userId) {
                $view->with('sidebarQuota', $this->emptyQuota());
                $view->with('userExternalSites', collect());
                return;
            }

            $user  = User::find($userId);
            if (!$user) {
                $view->with('sidebarQuota', $this->emptyQuota());
                $view->with('userExternalSites', collect());
                return;
            }

            $userExternalSites = $user->externalSites()->where('enabled', true)->get();
            $view->with('userExternalSites', $userExternalSites);

            $used  = (int) $user->personal_used_bytes;
            $limit = (int) $user->personal_quota_bytes;

            if ($limit === 0) {
                $view->with('sidebarQuota', [
                    'used_label'   => $this->formatBytes($used),
                    'limit_label'  => 'Ilimitado',
                    'percentage'   => 0,
                    'is_unlimited' => true,
                    'color_class'  => 'bg-brand-300',
                ]);
                return;
            }

            $percentage = (int) min(100, ($used / $limit) * 100);

            $view->with('sidebarQuota', [
                'used_label'   => $this->formatBytes($used),
                'limit_label'  => $this->formatBytes($limit),
                'percentage'   => $percentage,
                'is_unlimited' => false,
                'color_class'  => $percentage > 90 ? 'bg-red-400' : 'bg-brand-300',
            ]);
        });
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2) . ' GB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2) . ' MB';
        }
        return number_format($bytes / 1_024, 2) . ' KB';
    }

    private function emptyQuota(): array
    {
        return [
            'used_label'   => '0 KB',
            'limit_label'  => '0 KB',
            'percentage'   => 0,
            'is_unlimited' => false,
            'color_class'  => 'bg-brand-300',
        ];
    }
}
