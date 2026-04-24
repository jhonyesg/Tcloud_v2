<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FileToolPlugin;
use App\Services\FileToolPluginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileToolPluginController extends Controller
{
    private FileToolPluginService $pluginService;

    public function __construct(FileToolPluginService $pluginService)
    {
        $this->pluginService = $pluginService;
    }

    public function index(): JsonResponse
    {
        $plugins = FileToolPlugin::all();
        return response()->json(['data' => $plugins]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|max:50|unique:file_tool_plugins,slug',
            'name' => 'required|string|max:100',
            'type' => 'required|in:viewer,editor,player',
            'supported_mimes' => 'required|array',
            'resources' => 'required|array',
            'config' => 'nullable|array',
        ]);

        $plugin = $this->pluginService->createPlugin($validated);
        return response()->json(['data' => $plugin], 201);
    }

    public function show(int $id): JsonResponse
    {
        $plugin = FileToolPlugin::find($id);
        if (!$plugin) {
            return response()->json(['message' => 'Plugin not found'], 404);
        }
        return response()->json(['data' => $plugin]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plugin = FileToolPlugin::find($id);
        if (!$plugin) {
            return response()->json(['message' => 'Plugin not found'], 404);
        }

        $validated = $request->validate([
            'slug' => 'sometimes|string|max:50|unique:file_tool_plugins,slug,' . $id,
            'name' => 'sometimes|string|max:100',
            'type' => 'sometimes|in:viewer,editor,player',
            'supported_mimes' => 'sometimes|array',
            'resources' => 'sometimes|array',
            'config' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $plugin = $this->pluginService->updatePlugin($plugin, $validated);
        return response()->json(['data' => $plugin]);
    }

    public function destroy(int $id): JsonResponse
    {
        $plugin = FileToolPlugin::find($id);
        if (!$plugin) {
            return response()->json(['message' => 'Plugin not found'], 404);
        }

        $plugin->delete();
        return response()->json(['message' => 'Plugin deleted']);
    }

    public function validateResources(int $id): JsonResponse
    {
        $plugin = FileToolPlugin::find($id);
        if (!$plugin) {
            return response()->json(['message' => 'Plugin not found'], 404);
        }

        $missing = $this->pluginService->validatePluginResources($plugin);
        if (empty($missing)) {
            return response()->json(['valid' => true, 'message' => 'All resources found']);
        }
        return response()->json(['valid' => false, 'missing' => $missing]);
    }
}
