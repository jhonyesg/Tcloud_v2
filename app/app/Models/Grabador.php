<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Grabador extends Model
{
    use HasFactory;

    protected $table = 'grabadores';

    protected $fillable = [
        'nombre',
        'ip',
        'puerto',
        'base_url',
        'token',
        'activo',
        'observaciones',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'grabador_usuario')
            ->withPivot('limite_canales')
            ->withTimestamps();
    }

    public function canales(): HasMany
    {
        return $this->hasMany(Canal::class);
    }

    public function getBaseUrlCompletaAttribute(): string
    {
        return "http://{$this->ip}:{$this->puerto}/api";
    }
}