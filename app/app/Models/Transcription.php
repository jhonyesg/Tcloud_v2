<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transcription extends Model
{
    protected $fillable = [
        'file_id', 'original_name', 'job_id', 'node_url', 'node_id', 'state', 'generate_alerts', 'language',
        'srt_content', 'duration_seconds', 'word_count',
        'started_at', 'finished_at', 'error_message', 'retries',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_seconds' => 'integer',
        'word_count' => 'integer',
        'retries' => 'integer',
        'generate_alerts' => 'boolean',
    ];

    public const STATE_PENDING = 'pending';
    public const STATE_QUEUED = 'queued';
    public const STATE_PROCESSING = 'processing';
    public const STATE_DONE = 'done';
    public const STATE_ERROR = 'error';
    public const STATE_DEAD = 'dead';

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptionSegment::class);
    }

    public function keywordMatches(): HasMany
    {
        return $this->hasMany(KeywordMatch::class);
    }

    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('state', [self::STATE_PENDING, self::STATE_QUEUED, self::STATE_PROCESSING]);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function isTerminalError(): bool
    {
        return in_array($this->state, [self::STATE_ERROR, self::STATE_DEAD], true);
    }
}