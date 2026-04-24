<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileToolPlugin extends Model
{
    protected $table = 'file_tool_plugins';

    protected $fillable = [
        'slug',
        'name',
        'type',
        'supported_mimes',
        'resources',
        'config',
        'is_active',
    ];

    protected $casts = [
        'supported_mimes' => 'array',
        'resources' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function userPlugins(): HasMany
    {
        return $this->hasMany(UserFileToolPlugin::class, 'file_tool_plugin_id');
    }

    public function supportsMime(string $mime): bool
    {
        $supported = $this->supported_mimes;
        if (in_array($mime, $supported)) {
            return true;
        }
        foreach ($supported as $pattern) {
            if (str_ends_with($pattern, '/*')) {
                $prefix = rtrim($pattern, '/*');
                if (str_starts_with($mime, $prefix . '/')) {
                    return true;
                }
            }
        }
        return false;
    }
}
