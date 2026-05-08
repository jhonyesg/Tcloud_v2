<?php

namespace App\Services;

use App\Models\FileToolPlugin;
use App\Models\User;
use App\Models\UserFileToolPlugin;
use Illuminate\Support\Collection;

class FileToolPluginService
{
    public function getActivePlugins(): Collection
    {
        return FileToolPlugin::where('is_active', true)->get();
    }

    public function getPluginBySlug(string $slug): ?FileToolPlugin
    {
        return FileToolPlugin::where('slug', $slug)->where('is_active', true)->first();
    }

    public function getPluginsForUser(int $userId): Collection
    {
        $userPlugins = UserFileToolPlugin::where('user_id', $userId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereHas('plugin', function ($query) {
                $query->where('is_active', true);
            })
            ->with('plugin')
            ->get()
            ->pluck('plugin');

        if ($userPlugins->isEmpty()) {
            return $this->getDefaultPlugins();
        }

        return $userPlugins;
    }

    public function getDefaultPlugins(): Collection
    {
        return FileToolPlugin::where('is_active', true)
            ->where('is_default', true)
            ->get();
    }

    public function getDefaultPluginsForMime(string $mime): Collection
    {
        $plugins = $this->getDefaultPlugins();
        return $plugins->filter(function ($plugin) use ($mime) {
            return $plugin->supportsMime($mime);
        });
    }

    public function getPluginsForMime(int $userId, string $mime): Collection
    {
        $plugins = $this->getPluginsForUser($userId);
        return $plugins->filter(function ($plugin) use ($mime) {
            return $plugin->supportsMime($mime);
        });
    }

    public function assignPluginToUser(User $user, FileToolPlugin $plugin, ?\DateTimeInterface $expiresAt = null): UserFileToolPlugin
    {
        return UserFileToolPlugin::updateOrCreate(
            [
                'user_id' => $user->id,
                'file_tool_plugin_id' => $plugin->id,
            ],
            [
                'is_active' => true,
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function revokePluginFromUser(int $userId, int $pluginId): bool
    {
        $userPlugin = UserFileToolPlugin::where('user_id', $userId)
            ->where('file_tool_plugin_id', $pluginId)
            ->first();

        if ($userPlugin) {
            $userPlugin->is_active = false;
            return $userPlugin->save();
        }

        return false;
    }

    public function isPluginActiveForUser(int $userId, int $pluginId): bool
    {
        $userPlugin = UserFileToolPlugin::where('user_id', $userId)
            ->where('file_tool_plugin_id', $pluginId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        return $userPlugin !== null;
    }

    public function validatePluginResources(FileToolPlugin $plugin): array
    {
        $missing = [];
        $basePath = public_path('plugins/' . $plugin->slug);

        if (!is_dir($basePath)) {
            $missing[] = 'Plugin directory not found: ' . $basePath;
            return $missing;
        }

        $resources = $plugin->resources;
        foreach ($resources['js'] ?? [] as $js) {
            $fullPath = public_path(ltrim($js, '/'));
            if (!file_exists($fullPath)) {
                $missing[] = 'JS file not found: ' . $fullPath;
            }
        }
        foreach ($resources['css'] ?? [] as $css) {
            $fullPath = public_path(ltrim($css, '/'));
            if (!file_exists($fullPath)) {
                $missing[] = 'CSS file not found: ' . $fullPath;
            }
        }

        return $missing;
    }

    public function createPlugin(array $data): FileToolPlugin
    {
        $plugin = FileToolPlugin::create($data);
        $missing = $this->validatePluginResources($plugin);
        if (!empty($missing)) {
            $plugin->is_active = false;
            $plugin->save();
        }
        return $plugin;
    }

    public function updatePlugin(FileToolPlugin $plugin, array $data): FileToolPlugin
    {
        $plugin->update($data);
        $missing = $this->validatePluginResources($plugin);
        if (!empty($missing)) {
            $plugin->is_active = false;
            $plugin->save();
        }
        return $plugin->fresh();
    }
}
