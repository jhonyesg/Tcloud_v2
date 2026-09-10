<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class UserKeyword extends Model
{
    protected $table = 'user_keyword';
    public $timestamps = false;

    protected $fillable = ['user_id', 'keyword_id', 'category_id', 'created_at'];

    protected $casts = [
        'user_id'    => 'integer',
        'keyword_id' => 'integer',
        'category_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * avisos-scan-coverage-reconciler (12.1.a): cuando un usuario RECIBE una
     * keyword preexistente (asignada por admin, importada, asignada a un
     * cliente nuevo), crear los watermarks NULL para cada (keyword, storage)
     * donde el usuario tiene transcription_access=true. Delega al reconciler.
     */
    protected static function booted(): void
    {
        static::saved(function (UserKeyword $uk) {
            // Sólo actuar cuando es nuevo (created). En updates no tenemos
            // acceso fácil al "wasRecentlyCreated" en saved; usamos
            // created_at vs updated_at como heurística.
            try {
                $reconciler = app(\App\Services\Ia\WatermarkReconciler::class);
                $reconciler->ensureForKeyword((int) $uk->keyword_id);
            } catch (\Throwable $e) {
                Log::warning('user_keyword.hook_failed', [
                    'keyword_id' => $uk->keyword_id,
                    'user_id' => $uk->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // En alta nueva, queremos asegurar desde el momento de creación
        // (no esperar al save subsiguiente).
        static::created(function (UserKeyword $uk) {
            try {
                $reconciler = app(\App\Services\Ia\WatermarkReconciler::class);
                $reconciler->ensureForKeyword((int) $uk->keyword_id);
            } catch (\Throwable $e) {
                Log::warning('user_keyword.created_hook_failed', [
                    'keyword_id' => $uk->keyword_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeWithoutCategory(Builder $query): Builder
    {
        return $query->whereNull('category_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KeywordCategory::class, 'category_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
