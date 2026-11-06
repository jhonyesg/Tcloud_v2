<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Share extends Model
{
    protected $fillable = ['file_id', 'path_snapshot', 'token', 'password_hash', 'expires_at', 'permissions', 'created_by'];

    /**
     * El hash de la contraseña nunca se serializa. Es la segunda barrera:
     * el serializador `ShareController::sharePayload()` ya lo excluye, pero
     * cualquier respuesta que devuelva el modelo crudo queda protegida aqui.
     */
    protected $hidden = ['password_hash'];

    protected $casts = ['expires_at' => 'datetime'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(ShareAccessLog::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}