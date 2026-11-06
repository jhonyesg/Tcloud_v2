<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Solicitud de backup que originó este enlace, si la hay.
     *
     * Los enlaces de backup los administra el submódulo de Mis Archivos: su
     * vigencia (2 días) y su alcance (solo lectura) son parte del contrato de
     * la solicitud, no una opción del propietario del enlace. Por eso el
     * módulo de compartidos se niega a editarlos o borrarlos: la vía es
     * revocar la solicitud.
     */
    public function backupRequest(): HasOne
    {
        return $this->hasOne(BackupRequest::class, 'share_id');
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