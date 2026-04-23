<?php

namespace App\Modules\Correo\Http\Controllers;

use App\Modules\Correo\Services\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorreoConfigController
{
    private ConfigService $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function show(): JsonResponse
    {
        $config = $this->configService->getConfigForDisplay();
        return response()->json($config ? ['data' => $config] : ['data' => null]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer',
            'secure' => 'boolean',
            'user' => 'required|string',
            'password' => 'nullable|string',
            'from_name' => 'required|string',
            'from_email' => 'required|email',
        ]);

        $config = $this->configService->saveConfig($validated);
        return response()->json(['data' => $config]);
    }

    public function testConnection(): JsonResponse
    {
        $config = $this->configService->getActiveConfig();
        if (!$config) {
            return response()->json(['success' => false, 'message' => 'No hay configuración activa'], 400);
        }

        $result = $this->configService->testConnection($config);
        return response()->json($result);
    }
}
