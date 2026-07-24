<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordMatch extends Model
{
    protected $fillable = [
        'transcription_id', 'keyword_id', 'segment_id', 'user_id',
        'snippet', 'matched_at',
    ];

    protected $casts = [
        'matched_at' => 'datetime',
    ];

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(TranscriptionSegment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}