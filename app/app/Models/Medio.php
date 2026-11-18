<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Medio direccionable de TV o Radio.
 *
 * Un medio NO es un `storage_providers`: es un índice sobre
 * `(storage_id, subpath)` que permite pedir una franja horaria concreta de
 * un canal.
 *
 * Por qué no un storage: los sub-canales de los contenedores Emisoras
 * 01/02/03/04 (RD1, RD4, RD6, Radios/Bogota — ~84) viven dentro del
 * `base_path` del storage que los contiene. Crearles un storage propio hace
 * que el cron `storage:sync --all` (cada 15 min) los vuelva a catalogar
 * bajo el contenedor: el mismo archivo quedaría en dos catálogos paralelos y
 * la navegación BD-first quedaría huérfana. Precedente: el change
 * `solicitud-backup-mis-archivos` ya descartó ese patrón.
 *
 * `subpath` vacío = el medio ES el storage completo (canales planos como
 * `01 Caracol Tv`). `subpath` con valor = el canal cuelga de una carpeta
 * interna del storage (`Antioquia/BLU` dentro de `02 Emisoras 01 Reg`).
 *
 * @property int $storage_id
 * @property string $subpath
 * @property string $name
 * @property string $media_kind
 * @property string|null $region
 * @property bool $activo
 */
class Medio extends Model
{
    public const KIND_TV = 'tv';
    public const KIND_RADIO = 'radio';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_TV,
        self::KIND_RADIO,
    ];

    protected $fillable = [
        'storage_id',
        'subpath',
        'name',
        'media_kind',
        'region',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function storage(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'storage_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudPuntual::class, 'medio_id');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeDeStorage(Builder $query, int $storageId): Builder
    {
        return $query->where('storage_id', $storageId);
    }

    /**
     * Ruta física del medio: la del storage más su ruta interna.
     *
     * Es la ruta contra la que se decide si un usuario "ya tiene" el medio:
     * si tiene lectura sobre un storage ancestro de esta ruta, puede entrar
     * al canal en Mis Archivos y el medio no le aparece en el módulo.
     */
    public function physicalPath(): string
    {
        $base = rtrim((string) $this->storage?->base_path, '/');
        $sub = trim((string) $this->subpath, '/');

        if ($sub === '') {
            return $base;
        }

        return $base . '/' . $sub;
    }

    /**
     * ¿El medio es el storage completo (sin carpeta regional)?
     */
    public function isRootLevel(): bool
    {
        return trim((string) $this->subpath, '/') === '';
    }
}
