<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserKeyword extends Model
{
    protected $table = 'user_keyword';
    public $timestamps = false;

    protected $fillable = ['user_id', 'keyword_id', 'category_id', 'created_at'];

    protected $casts = [
        'user_id'    => 'integer',
        'keyword_id' => 'integer',
        'category_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeWithoutCategory(Builder $query): Builder
    {
        return $query->whereNull('category_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KeywordCategory::class, 'category_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
