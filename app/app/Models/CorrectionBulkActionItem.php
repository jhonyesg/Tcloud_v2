<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectionBulkActionItem extends Model
{
    protected $table = 'correction_bulk_action_items';
    public $timestamps = false;  // tabla no tiene created_at/updated_at

    protected $fillable = [
        'bulk_action_id', 'correction_id', 'previous_status',
        'merge_target_id', 'merge_previous_correct_text',
        'destroy_snapshot', 'applied',
    ];

    protected $casts = [
        'applied' => 'boolean',
        'destroy_snapshot' => 'array',
    ];

    public function bulkAction(): BelongsTo
    {
        return $this->belongsTo(CorrectionBulkAction::class, 'bulk_action_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(Correction::class, 'correction_id');
    }
}