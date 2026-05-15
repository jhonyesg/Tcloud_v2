<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    public $timestamps = false;
    protected $table = 'user_sessions';
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'created_at',
        'last_activity_at',
        'expires_at',
    ];

    protected $casts = [
        'created_at'       => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
