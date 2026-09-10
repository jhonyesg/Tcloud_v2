<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserAlertsInteligente extends Model
{
    protected $table = 'user_alerts_inteligentes';

    protected $fillable = [
        'user_id', 'emails', 'enabled', 'keywords_quota', 'emails_quota',
    ];

    protected $casts = [
        'emails' => 'array',
        'enabled' => 'boolean',
        'keywords_quota' => 'integer',
        'emails_quota' => 'integer',
    ];

    /**
     * avisos-scan-coverage-reconciler: cuando un usuario activa o reactiva
     * el módulo (enabled=false→true), el reconciler central crea los
     * watermarks para sus keywords en cada storage con acceso.
     */
    protected static function booted(): void
    {
        static::saved(function (UserAlertsInteligente $uai) {
            if (!$uai->enabled) {
                return;
            }
            try {
                app(\App\Services\Ia\WatermarkReconciler::class)->ensureForUser((int) $uai->user_id);
            } catch (\Throwable $e) {
                Log::warning('user_alerts_inteligente.watermark_seed_failed', [
                    'user_id' => $uai->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasCupo(): bool
    {
        return $this->enabled && $this->keywords_quota > 0;
    }

    public function keywordsUsed(): int
    {
        return (int) $this->user?->userKeywords()->count() ?? 0;
    }

    public function keywordsRemaining(): int
    {
        return max(0, $this->keywords_quota - $this->keywordsUsed());
    }

    public function emailsList(): array
    {
        $emails = $this->emails ?? [];
        return array_values(array_filter(array_map('trim', $emails), fn ($e) => $e !== ''));
    }
}
