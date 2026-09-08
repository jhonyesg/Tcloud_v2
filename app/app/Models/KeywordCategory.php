<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class KeywordCategory extends Model
{
    public const SCOPE_ADMIN = 'admin';
    public const SCOPE_USER  = 'user';

    protected $table = 'keyword_categories';

    protected $fillable = [
        'owner_scope',
        'owner_id',
        'name',
        'slug',
        'color_hex',
    ];

    protected $casts = [
        'owner_id' => 'integer',
    ];

    /**
     * Normaliza `owner_scope` que está guardada como char(8) y PostgreSQL devuelve
     * "user    " (con padding de espacios). Para que las comparaciones PHP
     * estrictas funcionen, sobreescribimos el accessor y siempre devolvemos
     * trim(). Las queries SQL no se ven afectadas (PG ya es space-insensitive
     * para char(N)).
     */
    public function getOwnerScopeAttribute($value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function userKeywordEntries(): HasMany
    {
        return $this->hasMany(UserKeyword::class, 'category_id');
    }

    public function scopeAdmin(Builder $query): Builder
    {
        return $query->where('owner_scope', self::SCOPE_ADMIN)->whereNull('owner_id');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_scope', self::SCOPE_USER)->where('owner_id', $userId);
    }

    public function scopeVisibleFor(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where(function (Builder $qa) {
                $qa->where('owner_scope', self::SCOPE_ADMIN)->whereNull('owner_id');
            })->orWhere(function (Builder $qq) use ($userId) {
                $qq->where('owner_scope', self::SCOPE_USER)
                   ->where('owner_id', $userId);
            });
        });
    }

    public function isAdminOwned(): bool
    {
        return $this->owner_scope === self::SCOPE_ADMIN && $this->owner_id === null;
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->owner_scope === self::SCOPE_USER && (int) $this->owner_id === $userId;
    }

    public static function normalizeSlug(string $name): string
    {
        $slug = Str::slug(trim($name));
        return $slug !== '' ? Str::limit($slug, 96, '') : '';
    }

    public static function isValidHex(?string $hex): bool
    {
        return is_string($hex) && (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $hex);
    }
}
