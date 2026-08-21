<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Idioma esperado de un canal, identificado por el slug de sus archivos.
 *
 * Ver la migración para el motivo. En resumen: hay canales que emiten en inglés
 * (Teleislas, criollo raizal de San Andrés) o que pinchan música en inglés
 * (uniminuto, lafmplus). Sus transcripciones son correctas y el diccionario no
 * debe tocarlas.
 */
class ChannelLanguage extends Model
{
    public const LANG_ES = 'es';
    public const LANG_EN = 'en';
    public const LANG_MIXED = 'mixed';

    public const LANGUAGES = [self::LANG_ES, self::LANG_EN, self::LANG_MIXED];

    /** Los slugs excluidos cambian a mano y muy de tarde en tarde. */
    private const CACHE_KEY = 'channel_languages:excluded_slugs';
    private const CACHE_TTL = 3600;

    protected $fillable = ['slug', 'label', 'language', 'apply_corrections', 'notes'];

    protected $casts = ['apply_corrections' => 'boolean'];

    public function scopeExcluded($query)
    {
        return $query->where('apply_corrections', false);
    }

    /**
     * Slugs a los que NO se aplica el diccionario.
     *
     * Se cachea porque lo consultan los caminos calientes (ingesta y corrida
     * retroactiva sobre millones de segmentos) y la tabla tiene ~64 filas que
     * casi nunca cambian.
     *
     * @return array<int, string>
     */
    public static function excludedSlugs(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => self::excluded()->pluck('slug')->all()
        );
    }

    public static function forgetExcludedSlugs(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Cualquier cambio invalida la caché: si no, desmarcar un canal no
        // surtiría efecto hasta una hora después.
        static::saved(fn () => self::forgetExcludedSlugs());
        static::deleted(fn () => self::forgetExcludedSlugs());
    }
}
