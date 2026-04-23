<?php

namespace App\Modules\Correo\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreoConfig extends Model
{
    protected $table = 'correo_config';
    protected $fillable = ['host', 'port', 'secure', 'username', 'password_encrypted', 'from_name', 'from_email', 'is_active', 'updated_by'];

    protected $hidden = ['password_encrypted'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function setPasswordEncryptedAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['password_encrypted'] = null;
            return;
        }
        // Si ya viene cifrado, no volver a cifrar
        if (str_starts_with($value, 'eyJpdiI6')) {
            $this->attributes['password_encrypted'] = $value;
            return;
        }
        $this->attributes['password_encrypted'] = encrypt($value);
    }

    public function getPasswordDecryptedAttribute(): ?string
    {
        if (!$this->attributes['password_encrypted']) {
            return null;
        }
        $value = $this->attributes['password_encrypted'];
        // Si el valor no parece estar cifrado por Laravel (no empieza con eyJpdiI6), devolverlo tal cual
        if (!str_starts_with($value, 'eyJpdiI6')) {
            return $value;
        }
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    public function toSmtpArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'secure' => $this->secure,
            'auth' => [
                'user' => $this->username,
                'pass' => $this->password_decrypted,
            ],
        ];
    }
}
