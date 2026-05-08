<?php

namespace App\Http\Controllers;

use App\Services\FileToolPluginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileToolController extends Controller
{
    private FileToolPluginService $pluginService;

    public function __construct(FileToolPluginService $pluginService)
    {
        $this->pluginService = $pluginService;
    }

    public function available(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $mime = $request->query('mime');
        if ($mime) {
            $plugins = $this->pluginService->getPluginsForMime($user->id, $mime);
        } else {
            $plugins = $this->pluginService->getPluginsForUser($user->id);
        }

        return response()->json(['data' => $plugins]);
    }

    public function defaultForMime(Request $request): JsonResponse
    {
        $mime = $request->query('mime');
        if (!$mime) {
            return response()->json(['data' => []]);
        }

        $plugins = $this->pluginService->getDefaultPluginsForMime($mime);
        return response()->json(['data' => $plugins]);
    }
}
