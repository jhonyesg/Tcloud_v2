<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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