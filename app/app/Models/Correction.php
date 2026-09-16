<?php

namespace App\Models;

use App\Services\Ia\CorrectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Correction extends Model
{
    protected $fillable = [
        'wrong_text', 'correct_text', 'wrong_normalized',
        'status', 'proposed_by', 'approved_by', 'approved_at',
        'rejected_reason', 'source_segment_id', 'applies_count', 'source',
        'risk_level',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'applies_count' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_MERGED = 'merged';

    // Risk levels para context-shift protection (changes/2026-08-02-corrections-dictionary-atomicity).
    // low    : segura, se aplica automáticamente.
    // medium : se aplica pero conviene auditar.
    // high   : se omite del applyToText() automático; solo con --include-high-risk.
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

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
     * Excluye correcciones marcadas como risk='high' (muletillas, falsos amigos).
     * Usado por applyToText() cuando no se pasa includeHighRisk=true.
     */
    public function scopeSafe(Builder $query): Builder
    {
        return $query->where('risk_level', '!=', self::RISK_HIGH);
    }

    /**
     * Aplica todas las correcciones aprobadas al texto dado.
     * Aplica en orden de longitud DESC del wrong_normalized para evitar
     * que un substring corto sobreescriba uno mas largo, y respeta frontera
     * de palabra para no matchear dentro de otra palabra (ej. "Active to"
     * dentro de "attractive touristic").
     *
     * Por defecto excluye reglas con risk_level='high' (muletillas, falsos
     * amigos) que podrían distorsionar el tono/contexto del texto. Pasar
     * includeHighRisk=true para incluirlas (uso consciente, ej: bulk force
     * del admin).
     *
     * DELEGA en CorrectionService: este método tenía su propia implementación
     * con `preg_replace('/\b...\b/i')` SIN flag `/u`, lo que rompía la frontera
     * de palabra en texto acentuado igual que el bug de bytes del servicio.
     * Al haber dos implementaciones, los tests validaban una semántica distinta
     * a la de producción (que va por CorrectionService::applyToSegments).
     */
    public static function applyToText(string $text, bool $includeHighRisk = false): string
    {
        return app(CorrectionService::class)->applyToText($text, $includeHighRisk);
    }

    public static function normalize(string $text): string
    {
        return Keyword::asciiLower(trim($text));
    }
}