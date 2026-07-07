<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionSegment extends Model
{
    protected $fillable = [
        'transcription_id', 'segment_index',
        'start_seconds', 'end_seconds',
        'text_raw', 'text',
    ];

    protected $casts = [
        'segment_index' => 'integer',
        'start_seconds' => 'float',
        'end_seconds' => 'float',
    ];

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function getStartLabel(): string
    {
        return $this->secondsToHms((float) $this->start_seconds);
    }

    public function getEndLabel(): string
    {
        return $this->secondsToHms((float) $this->end_seconds);
    }

    private function secondsToHms(float $seconds): string
    {
        $total = (int) floor($seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}