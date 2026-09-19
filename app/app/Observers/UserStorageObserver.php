<?php

namespace App\Observers;

use App\Models\StorageProvider;
use App\Models\UserStorage;

/**
 * Change `files-canonical-owner-by-storage` (2026-09-18):
 *
 * Invalida la cache del owner canonico de un storage cuando se crea, actualiza
 * o elimina un `user_storages`. El owner canonico depende de quien tiene
 * `permissions='full'` en el storage, asi que cualquier mutacion de
 * `permissions` o `storage_provider_id` debe invalidar la cache para que
 * el siguiente `StorageProvider::canonicalOwnerIdCached()` recalcule.
 *
 * Conservador: invalidamos en TODOS los eventos de la fila, no solo cuando
 * cambian columnas relevantes. Es una key Redis por storage — invalidar de
 * mas es barato y evita falsos positivos del harness de regresion.
 */
class UserStorageObserver
{
    public function saved(UserStorage $userStorage): void
    {
        $this->forget($userStorage);
    }

    public function deleted(UserStorage $userStorage): void
    {
        $this->forget($userStorage);
    }

    private function forget(UserStorage $userStorage): void
    {
        if ($userStorage->storage_provider_id) {
            StorageProvider::forgetCanonicalOwnerCache((int) $userStorage->storage_provider_id);
        }
    }
}
