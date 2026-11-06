<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de backup de una carpeta-día del árbol de producción.
 *
 * Es la fuente de verdad de la solicitud, NO el share. Motivo:
 * `shares.file_id` es FK `ON DELETE CASCADE` y el árbol de backup rota
 * (~13 carpetas-día rodantes): cuando la carpeta-día sale del disco, el
 * prune del sync borra su fila de `files` y el share muere solo, dejando un
 * enlace 404 sin explicación. Esta fila sobrevive y permite reportar el
 * material como no disponible.
 *
 * Vocabulario cerrado de `status` (con CHECK en BD):
 *  - `ready`       → hay enlace activo.
 *  - `unavailable` → el backup de ese día no existe. NO hay enlace.
 *  - `expired`     → el enlace venció su vigencia.
 *  - `revoked`     → el cliente revocó la solicitud.
 *
 * @property int $user_id
 * @property int $source_storage_id
 * @property int|null $source_folder_id
 * @property int|null $backup_storage_id
 * @property int|null $share_id
 * @property string $day
 * @property string $motivo
 * @property string $status
 * @property string|null $backup_path
 * @property \Illuminate\Support\Carbon|null $link_expires_at
 */
class BackupRequest extends Model
{
    public const STATUS_READY = 'ready';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_READY,
        self::STATUS_UNAVAILABLE,
        self::STATUS_EXPIRED,
        self::STATUS_REVOKED,
    ];

    protected $fillable = [
        'user_id',
        'source_storage_id',
        'source_folder_id',
        'backup_storage_id',
        'share_id',
        'day',
        'motivo',
        'status',
        'backup_path',
        'link_expires_at',
    ];

    protected $casts = [
        'link_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sourceStorage(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'source_storage_id');
    }

    public function sourceFolder(): BelongsTo
    {
        return $this->belongsTo(File::class, 'source_folder_id');
    }

    public function backupStorage(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'backup_storage_id');
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class, 'share_id');
    }

    /**
     * Solicitudes con enlace potencialmente activo. El filtro fino (share
     * vivo, no expirado) lo hace `isLive()`: el SQL no puede mirar la tabla
     * `shares` sin un join que no vale la pena para un historial de usuario.
     */
    public function scopeVivas(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    /**
     * ¿La solicitud tiene un enlace usable ahora mismo?
     *
     * Exige las tres cosas: estado `ready`, `share_id` presente, y
     * vigencia no vencida. Un `share_id` huérfano (borrado en cascada) o un
     * enlace vencido dejan la solicitud sin enlace: el historial la muestra
     * como no disponible, nunca como enlace roto.
     */
    public function isLive(): bool
    {
        if ($this->status !== self::STATUS_READY) {
            return false;
        }

        if ($this->link_expires_at !== null && $this->link_expires_at->isPast()) {
            return false;
        }

        return $this->share_id !== null;
    }
}
