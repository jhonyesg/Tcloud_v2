<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLog extends Model
{
    protected $fillable = [
        'user_id', 'email_to', 'transcription_id',
        'match_count', 'subject', 'status', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'match_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }
}