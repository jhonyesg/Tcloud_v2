<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log para `storages:merge-duplicates --apply` (change
 * `storage-physical-path-normalization`).
 *
 * Cada fila es un merge confirmado: registra canonical_id, duplicate_id,
 * cuántos files se movieron vs cuántos se deduplicaron, qué tablas de FK
 * se vieron afectadas y quién ejecutó la operación. Es la fuente de verdad
 * para hacer un-merge (Stage 2 rollback) y para auditoría del operador.
 */
class StorageMerge extends Model
{
    protected $table = 'storage_merges';

    public $timestamps = false; // solo executed_at

    protected $fillable = [
        'canonical_id',
        'duplicate_id',
        'files_moved',
        'files_deduped',
        'fk_tables_affected',
        'executed_by_user_id',
        'executed_at',
        'notes',
    ];

    protected $casts = [
        'fk_tables_affected' => 'array',
        'executed_at' => 'datetime',
        'files_moved' => 'integer',
        'files_deduped' => 'integer',
    ];

    public function canonical(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'canonical_id');
    }

    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'duplicate_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }
}
