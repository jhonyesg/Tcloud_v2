<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaEditJob extends Model
{
    protected $fillable = [
        'user_id',
        'source_file_id',
        'source_file_name',
        'segments_json',
        'output_filename',
        'status',
        'error_message',
    ];

    protected $casts = [
        'segments_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
