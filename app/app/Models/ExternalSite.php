<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ExternalSite extends Model
{
    protected $fillable = ['name', 'url', 'icon', 'color', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'external_site_user')
            ->withPivot('sort_order');
    }
}
