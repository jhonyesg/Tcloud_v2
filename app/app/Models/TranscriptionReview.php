<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionReview extends Model
{
    protected $fillable = [
        'transcription_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_CORRECT = 'correct';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CORRECT,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_IGNORED,
    ];

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
