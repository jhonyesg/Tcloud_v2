<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Correction extends Model
{
    protected $fillable = [
        'wrong_text', 'correct_text', 'wrong_normalized',
        'status', 'proposed_by', 'approved_by', 'approved_at',
        'rejected_reason', 'source_segment_id', 'applies_count',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'applies_count' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_MERGED = 'merged';

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sourceSegment(): BelongsTo
    {
        return $this->belongsTo(TranscriptionSegment::class, 'source_segment_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Aplica todas las correcciones aprobadas al texto dado.
     * Aplica en orden de longitud DESC del wrong_normalized para evitar
     * que un substring corto sobreescriba uno mas largo.
     */
    public static function applyToText(string $text): string
    {
        $corrections = static::approved()
            ->orderByRaw('LENGTH(wrong_normalized) DESC')
            ->get(['wrong_normalized', 'correct_text']);

        if ($corrections->isEmpty()) {
            return $text;
        }

        $result = $text;
        foreach ($corrections as $correction) {
            if ($correction->wrong_normalized === '') {
                continue;
            }
            $result = str_ireplace($correction->wrong_normalized, $correction->correct_text, $result);
        }

        return $result;
    }

    public static function normalize(string $text): string
    {
        return Keyword::asciiLower(trim($text));
    }
}