<?php

namespace App\Modules\Correo\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreoLog extends Model
{
    protected $table = 'correo_log';
    public $timestamps = true;

    const ESTADO_EXITO = 'exito';
    const ESTADO_ERROR = 'error';

    protected $fillable = ['destinatario', 'plantilla', 'asunto', 'body_sent', 'estado', 'error_message', 'sent_at', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wasSuccessful(): bool
    {
        return $this->estado === self::ESTADO_EXITO;
    }
}
