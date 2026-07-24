<?php

namespace App\Providers;

use App\Models\User;
use App\Models\ExternalSite;
use App\Models\Correction;
use App\Models\UserAlertsInteligente;
use App\Services\Ia\AlertDispatcher;
use App\Services\Ia\AudioConverter;
use App\Services\Ia\CorrectionService;
use App\Services\Ia\KeywordMatcher;
use App\Services\Ia\SrtParser;
use App\Services\Ia\TranscriptionProcessor;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Modulo IA — bindings del transcriptor
        $this->app->singleton(TranscriptorApiClient::class);
        $this->app->singleton(AudioConverter::class);
        $this->app->singleton(SrtParser::class);
        $this->app->singleton(CorrectionService::class);
        $this->app->singleton(TranscriptionProcessor::class);
        $this->app->singleton(KeywordMatcher::class);
        $this->app->singleton(AlertDispatcher::class);
    }

    public function boot(): void
    {
        view()->composer('layouts.app', function ($view) {
            $userId = Session::get('user_id');

            $misAvisosEnabled = false;
            $correctionsPendingCount = 0;

            if (!$userId) {
                $view->with('sidebarQuota', $this->emptyQuota());
                $view->with('userExternalSites', collect());
                $view->with('misAvisosEnabled', $misAvisosEnabled);
                $view->with('correctionsPendingCount', $correctionsPendingCount);
                return;
            }

            $user  = User::find($userId);
            if (!$user) {
                $view->with('sidebarQuota', $this->emptyQuota());
                $view->with('userExternalSites', collect());
                $view->with('misAvisosEnabled', $misAvisosEnabled);
                $view->with('correctionsPendingCount', $correctionsPendingCount);
                return;
            }

            $userExternalSites = $user->externalSites()->where('enabled', true)->get();
            $view->with('userExternalSites', $userExternalSites);

            // Modulo IA — visibilidad "Mis Avisos" y badge "Correcciones" pendientes.
            $misAvisosEnabled = UserAlertsInteligente::where('user_id', $user->id)
                ->where('enabled', true)
                ->exists();
            if (($user->role ?? null) === 'admin') {
                $correctionsPendingCount = (int) Correction::where('status', Correction::STATUS_PENDING)->count();
            }

            $view->with('misAvisosEnabled', $misAvisosEnabled);
            $view->with('correctionsPendingCount', $correctionsPendingCount);

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
                'limit_label'   => $this->formatBytes($limit),
                'percentage'    => $percentage,
                'is_unlimited'  => false,
                'color_class'   => $percentage > 90 ? 'bg-red-400' : 'bg-brand-300',
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
