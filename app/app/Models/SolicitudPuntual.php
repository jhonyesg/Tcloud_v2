<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud puntual de TV y Radio: el registro de lo que un cliente pidió y
 * de lo que se le entregó.
 *
 * Es la fuente de verdad de la entrega, NO el share. Motivo:
 * `shares.file_id` es FK `ON DELETE CASCADE` y el árbol de origen rota
 * (~48 días de retención): cuando el material sale del disco, el prune del
 * sync borra su fila de `files` y el enlace muere solo. Esta fila sobrevive
 * y permite reportar el material como no disponible, y —esto es lo que la
 * hace distinta del módulo de backup— conservar el detalle de lo entregado
 * para el control contable.
 *
 * Vocabulario cerrado de `status` (con CHECK en BD):
 *  - `ready`       → el material cubrió el rango pedido; hay enlaces vivos.
 *  - `partial`     → el material cubrió PARTE del rango; hay enlaces vivos y
 *                    el hueco quedó cuantificado en `cobertura`.
 *  - `unavailable` → el material no existe (fuera de retención, o nunca se
 *                    emitió). NO hay enlace.
 *  - `revoked`     → el solicitante revocó la solicitud.
 *
 * El estado `expired` NO se guarda: se deriva de `link_expires_at`.
 *
 * @property int $user_id
 * @property int $medio_id
 * @property int|null $share_id
 * @property string $fecha
 * @property string $modo
 * @property string $hora_inicio
 * @property string $hora_fin
 * @property int $duracion_minutos
 * @property string $status
 * @property string $motivo
 * @property array|null $cobertura
 * @property int $archivos_total
 * @property int $bytes_total
 * @property int $links_total
 * @property \Illuminate\Support\Carbon|null $link_expires_at
 */
class SolicitudPuntual extends Model
{
    public const MODO_FRANJA = 'franja';
    public const MODO_DIA = 'dia';

    /** @var list<string> */
    public const MODOS = [
        self::MODO_FRANJA,
        self::MODO_DIA,
    ];

    public const STATUS_READY = 'ready';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_READY,
        self::STATUS_PARTIAL,
        self::STATUS_UNAVAILABLE,
        self::STATUS_REVOKED,
    ];

    /** Estados que leave la solicitud con material servido. */
    public const STATUSES_VIVAS = [
        self::STATUS_READY,
        self::STATUS_PARTIAL,
    ];

    protected $fillable = [
        'user_id',
        'medio_id',
        'share_id',
        'fecha',
        'modo',
        'hora_inicio',
        'hora_fin',
        'duracion_minutos',
        'status',
        'motivo',
        'cobertura',
        'archivos_total',
        'bytes_total',
        'links_total',
        'link_expires_at',
    ];

    protected $casts = [
        'cobertura' => 'array',
        'archivos_total' => 'integer',
        'bytes_total' => 'integer',
        'links_total' => 'integer',
        'duracion_minutos' => 'integer',
        'link_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function medio(): BelongsTo
    {
        return $this->belongsTo(Medio::class, 'medio_id');
    }

    /**
     * Enlace del modo "día": el share de la carpeta del día.
     *
     * En modo "franja" no hay share de cabecera: cada archivo lleva el suyo
     * en `items.share_id`.
     */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class, 'share_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SolicitudPuntualItem::class, 'solicitud_id');
    }

    public function scopeVivas(Builder $query): Builder
    {
        return $query->whereIn('status', self::STATUSES_VIVAS);
    }

    public function scopeDelUsuario(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Estado que ve el cliente: el guardado, con `expired` DERIVADO de la
     * vigencia. Una solicitud `ready` cuyo enlace venció se muestra como
     * expirada, nunca como vigente.
     */
    public function estado(): string
    {
        if ($this->status === self::STATUS_READY && $this->link_expires_at !== null && $this->link_expires_at->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    /**
     * ¿La solicitud conserva material servido y un enlace vigente?
     *
     * Exige las tres cosas: estado vivo, vigencia no vencida y al menos un
     * enlace. Un `share_id` huérfano (borrado en cascada) deja la solicitud
     * sin enlace: el historial la muestra como no disponible, nunca como un
     * enlace roto.
     */
    public function isLive(): bool
    {
        if (!in_array($this->status, self::STATUSES_VIVAS, true)) {
            return false;
        }

        if ($this->link_expires_at !== null && $this->link_expires_at->isPast()) {
            return false;
        }

        return $this->links_total > 0;
    }
}
