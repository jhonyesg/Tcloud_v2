<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFileToolPlugin extends Model
{
    protected $table = 'user_file_tool_plugins';

    protected $fillable = [
        'user_id',
        'file_tool_plugin_id',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(FileToolPlugin::class, 'file_tool_plugin_id');
    }

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }
}
