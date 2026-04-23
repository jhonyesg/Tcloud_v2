<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareAccessLog extends Model
{
    protected $table = 'share_access_log';

    public $timestamps = false;

    protected $fillable = ['share_id', 'accessed_at', 'ip_address'];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }
}
