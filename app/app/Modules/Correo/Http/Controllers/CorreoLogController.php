<?php

namespace App\Modules\Correo\Http\Controllers;

use App\Modules\Correo\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class CorreoLogController
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->notificationService->getLogs()]);
    }
}
