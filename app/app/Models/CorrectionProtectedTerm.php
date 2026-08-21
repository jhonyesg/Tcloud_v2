<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exclusiones dinámicas que el AI Suggest nunca va a traducir.
 *
 * A diferencia de `protected_brands` (config literal con marcas tech/hardware
 * SIEMPRE activas), esta tabla la gestiona el admin desde
 * `/ia/correcciones → IA Suggest → Exclusiones` con cache de 5 min.
 *
 * Soporta soft-archive (`archived_at`); un término archivado puede restaurarse
 * sin perder el historial (created_by, created_at, notes).
 *
 * (corrections-protected-terms-admin)
 */
class CorrectionProtectedTerm extends Model
{
    protected $table = 'correction_protected_terms';

    protected $fillable = [
        'term', 'category', 'notes', 'created_by',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const CATEGORY_EVENT = 'event';
    public const CATEGORY_BRAND = 'brand';
    public const CATEGORY_PRODUCT = 'product';
    public const CATEGORY_ORG = 'org';
    public const CATEGORY_PERSON = 'person';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_EVENT => 'Evento (Black Friday, Copa América…)',
        self::CATEGORY_BRAND => 'Marca (Open English, EPM…)',
        self::CATEGORY_PRODUCT => 'Producto (AirPods, Netflix…)',
        self::CATEGORY_ORG => 'Organización (British Council…)',
        self::CATEGORY_PERSON => 'Persona (frecuente en emisiones)',
        self::CATEGORY_OTHER => 'Otro término a no traducir',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->whereNotNull('archived_at');
    }

    public function scopeCategory(Builder $q, string $cat): Builder
    {
        return $q->where('category', $cat);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lista plana de términos activos (lowercased) para usar directo en
     * `looksLikeBrandOrProperNoun`. NO usar para UI (usa `listAll()`).
     *
     * @return array<int, string>
     */
    public static function termsListActive(): array
    {
        return static::query()
            ->active()
            ->pluck('term')
            ->map(fn($t) => mb_strtolower(trim((string) $t)))
            ->filter(fn($t) => $t !== '')
            ->unique()
            ->values()
            ->all();
    }
}
