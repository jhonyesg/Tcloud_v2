<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class UserStorage extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'storage_provider_id', 'permissions', 'can_create_shares', 'transcription_access'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'transcription_access' => 'boolean',
    ];

/**
     * avisos-scan-coverage-observability-and-ux: hook `created` para
     * cubrir inserts directos con transcription_access=true (caso que el
     * hook `updated` con wasChanged no atrapa).
     */
    protected static function booted(): void
    {
        static::created(function (UserStorage $us) {
            if ($us->transcription_access !== true) {
                return;
            }
            try {
                app(\App\Services\Ia\WatermarkReconciler::class)->ensureForUser((int) $us->user_id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('user_storage.created_hook_failed', [
                    'user_id' => $us->user_id,
                    'storage_id' => $us->storage_provider_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        static::updated(function (UserStorage $us) {
            if (!$us->wasChanged('transcription_access')) {
                return;
            }
            // Sólo false→true dispara reconciliación.
            if ($us->transcription_access !== true) {
                return;
            }
            try {
                $reconciler = app(\App\Services\Ia\WatermarkReconciler::class);
                // Asegurar TODOS los pares (keyword, storage) que el usuario
                // tiene, no sólo los del storage recién habilitado: al activar
                // un storage también se reabre el acceso a keywords que el
                // usuario ya tenía.
                $reconciler->ensureForUser((int) $us->user_id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('user_storage.hook_failed', [
                    'user_id' => $us->user_id,
                    'storage_id' => $us->storage_provider_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function storageProvider()
    {
        return $this->belongsTo(StorageProvider::class);
    }
}
