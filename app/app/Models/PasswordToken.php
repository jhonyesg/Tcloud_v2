<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordToken extends Model
{
    protected $table = 'password_tokens';

    public const TYPE_SETUP = 'setup';
    public const TYPE_RESET = 'reset';

    protected $fillable = [
        'user_id',
        'token_hash',
        'type',
        'expires_at',
        'used_at',
        'ip_created',
        'ip_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
