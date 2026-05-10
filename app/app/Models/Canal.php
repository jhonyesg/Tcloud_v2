<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Canal extends Model
{
    use HasFactory;

    protected $table = 'canales';

    protected $fillable = [
        'grabador_id',
        'usuario_id',
        'slot_nombre',
        'api_canal_id',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function grabador(): BelongsTo
    {
        return $this->belongsTo(Grabador::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}