<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Keyword extends Model
{
    protected $fillable = ['text', 'normalized'];

    /**
     * avisos-scan-coverage-reconciler: al crear una keyword, asegura los
     * watermarks NULL para cada storage donde cualquier usuario con acceso
     * la tiene habilitada. Delega al servicio central.
     */
    protected static function booted(): void
    {
        static::created(function (Keyword $k) {
            try {
                app(\App\Services\Ia\WatermarkReconciler::class)->ensureForKeyword((int) $k->id);
            } catch (\Throwable $e) {
                Log::warning('keyword.watermark_seed_failed', [
                    'keyword_id' => $k->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        static::deleted(function (Keyword $k) {
            Log::info('keyword.deleted', [
                'keyword_id' => $k->id,
                'text' => $k->text,
                'note' => 'FK cascadeOnDelete limpia keyword_scan_watermarks',
            ]);
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_keyword')
            ->withPivot('created_at')
            ->withTimestamps();
    }

    public function userKeywordEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserKeyword::class, 'keyword_id');
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