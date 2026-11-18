<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle por archivo entregado en una solicitud puntual.
 *
 * Es el registro contable. Por eso `name`, `path` y `size` son SNAPSHOT:
 * si el prune del sync borra la fila de `files`, la fila de `files` del
 * archivo desaparece y con ella el share (`ON DELETE CASCADE`), pero este
 * registro conserva qué se entregó y cuánto pesaba.
 *
 * `slot_inicio`/`slot_fin` son los instantes de la GRILLA de emisión del
 * medio (la hora en que se emitió el programa). `capture_inicio`/
 * `capture_fin` son los instantes REALES de captura (del nombre del archivo
 * y de su mtime), que no coinciden con la grilla: el grabador arranca el
 * archivo siguiente unos segundos después de que termina el anterior.
 *
 * @property int $solicitud_id
 * @property int|null $file_id
 * @property int|null $share_id
 * @property \Illuminate\Support\Carbon $slot_inicio
 * @property \Illuminate\Support\Carbon $slot_fin
 * @property \Illuminate\Support\Carbon|null $capture_inicio
 * @property \Illuminate\Support\Carbon|null $capture_fin
 * @property string $name
 * @property string $path
 * @property int $size
 */
class SolicitudPuntualItem extends Model
{
    protected $fillable = [
        'solicitud_id',
        'file_id',
        'share_id',
        'slot_inicio',
        'slot_fin',
        'capture_inicio',
        'capture_fin',
        'name',
        'path',
        'size',
    ];

    protected $casts = [
        'slot_inicio' => 'datetime',
        'slot_fin' => 'datetime',
        'capture_inicio' => 'datetime',
        'capture_fin' => 'datetime',
        'size' => 'integer',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudPuntual::class, 'solicitud_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class, 'share_id');
    }
}
