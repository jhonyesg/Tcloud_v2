<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FileToolPlugin;
use App\Models\User;
use App\Models\UserFileToolPlugin;
use App\Services\FileToolPluginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserFileToolController extends Controller
{
    private FileToolPluginService $pluginService;

    public function __construct(FileToolPluginService $pluginService)
    {
        $this->pluginService = $pluginService;
    }

    public function userPlugins(int $userId): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $plugins = $this->pluginService->getPluginsForUser($userId);
        return response()->json(['data' => $plugins]);
    }

    public function assign(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'plugin_id' => 'required|exists:file_tool_plugins,id',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $plugin = FileToolPlugin::find($validated['plugin_id']);
        if (!$plugin || !$plugin->is_active) {
            return response()->json(['message' => 'Plugin not found or inactive'], 404);
        }

        $expiresAt = isset($validated['expires_at']) ? new \DateTime($validated['expires_at']) : null;
        $userPlugin = $this->pluginService->assignPluginToUser($user, $plugin, $expiresAt);

        return response()->json(['data' => $userPlugin, 'message' => 'Plugin assigned successfully'], 201);
    }

    public function revoke(int $userId, int $pluginId): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $revoked = $this->pluginService->revokePluginFromUser($userId, $pluginId);
        if (!$revoked) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        return response()->json(['message' => 'Plugin revoked successfully']);
    }

    public function allAssignments(): JsonResponse
    {
        $assignments = UserFileToolPlugin::with(['user', 'plugin'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $assignments]);
    }
}
