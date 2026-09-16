<?php

namespace App\Observers;

use App\Models\User;
use App\Services\SessionService;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    public function __construct(private SessionService $sessionService) {}

    public function deleting(User $user): void
    {
        foreach ($user->sessions()->get() as $session) {
            try {
                $this->sessionService->killSession($session);
            } catch (\Throwable $e) {
                Log::warning('UserObserver: failed to clean Redis for session on user delete', [
                    'user_id'    => $user->id,
                    'session_id' => $session->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
