<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Keyword extends Model
{
    protected $fillable = ['text', 'normalized'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_keyword')
            ->withPivot('created_at')
            ->withTimestamps();
    }

    public function getNormalizedAttribute(): string
    {
        return $this->attributes['normalized']
            ?? static::asciiLower($this->text ?? '');
    }

    public function scopeMatchingText(Builder $query, string $normalized): Builder
    {
        return $query->where('normalized', $normalized);
    }

    public static function normalize(string $text): string
    {
        return static::asciiLower(trim($text));
    }

    /**
     * Lowercase + transliteracion ASCII. Usa Str::ascii() de Laravel; si la
     * tabla de transliteracion del vendor no esta disponible (vendor roto),
     * cae a un transliterador manual de acentos comunes del espanol.
     */
    public static function asciiLower(string $text): string
    {
        try {
            return Str::lower(Str::ascii($text));
        } catch (\Throwable $e) {
            $map = [
                'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
                'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                'Ç'=>'c','ç'=>'c',
            ];
            return Str::lower(strtr($text, $map));
        }
    }
}