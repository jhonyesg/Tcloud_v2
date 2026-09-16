<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrectionBulkAction extends Model
{
    protected $table = 'correction_bulk_actions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;  // tabla no tiene created_at/updated_at

    protected $fillable = [
        'id', 'action', 'performed_by', 'performed_at', 'expires_at',
        'undone_at', 'undone_by', 'superseded_at', 'item_count', 'notes',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
        'expires_at' => 'datetime',
        'undone_at' => 'datetime',
        'superseded_at' => 'datetime',
        'item_count' => 'integer',
    ];

    public const ACTION_BULK_APPROVE = 'bulk_approve';
    public const ACTION_BULK_REJECT  = 'bulk_reject';
    public const ACTION_BULK_DESTROY = 'bulk_destroy';

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CorrectionBulkActionItem::class, 'bulk_action_id');
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canBeUndone(): bool
    {
        if ($this->action === self::ACTION_BULK_DESTROY) return false;
        return !$this->isUndone() && !$this->isSuperseded() && !$this->isExpired();
    }
}