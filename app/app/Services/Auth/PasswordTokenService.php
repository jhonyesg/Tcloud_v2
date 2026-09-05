<?php

namespace App\Services\Auth;

use App\Models\PasswordToken;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Emite y consume tokens para los dos flows de contraseña:
 *
 *   - `setup`: bienvenida a un usuario recién creado por el admin.
 *     Al consumir, materializa el password_hash, pone al usuario en
 *     `active` y crea storage personal si tiene username.
 *
 *   - `reset`: recuperación desde "¿olvidaste tu contraseña?".
 *     Al consumir, solo cambia el password_hash.
 *
 * Una sola tabla (`password_tokens`) y un solo servicio mantienen la
 * lógica unificada: single-active-per-(user,type), invalidación
 * idempotente, expiración común.
 */
class PasswordTokenService
{
    public function issue(User $user, string $type, ?string $ipCreated = null): string
    {
        if (!in_array($type, [PasswordToken::TYPE_SETUP, PasswordToken::TYPE_RESET], true)) {
            throw new \InvalidArgumentException("Unknown token type: {$type}");
        }

        $rawToken = Str::random(32);
        $hash = PasswordToken::hash($rawToken);
        $ttl = (int) config('auth.password_token_ttl', 1440);

        DB::transaction(function () use ($user, $type, $hash, $ttl, $ipCreated) {
            $this->invalidate($user, $type);

            PasswordToken::create([
                'user_id'     => $user->id,
                'token_hash'  => $hash,
                'type'        => $type,
                'expires_at'  => Carbon::now()->addMinutes($ttl),
                'ip_created'  => $ipCreated,
            ]);
        });

        return $rawToken;
    }

    /**
     * Marca como usados todos los tokens pendientes (sin `used_at`) del
     * par (user, type). Llamado antes de emitir uno nuevo para que solo
     * haya un token activo a la vez por tipo.
     */
    public function invalidate(User $user, string $type): void
    {
        PasswordToken::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);
    }

    /**
     * Busca el token por su hash, valida que esté vigente y aplica el
     * side-effect por tipo. Devuelve el User si todo salió bien, o null
     * si el token no existe / está expirado / ya se usó. La respuesta
     * genérica (null) no filtra cuál de las tres condiciones falló.
     */
    public function consume(string $rawToken, ?string $ipUsed = null): ?User
    {
        $hash = PasswordToken::hash($rawToken);

        $token = PasswordToken::where('token_hash', $hash)->first();
        if (!$token || $token->used_at !== null || $token->expires_at->isPast()) {
            return null;
        }

        return DB::transaction(function () use ($token, $ipUsed) {
            $token->update([
                'used_at' => Carbon::now(),
                'ip_used' => $ipUsed,
            ]);

            $user = $token->user;

            if ($token->type === PasswordToken::TYPE_SETUP) {
                $user->status = User::STATUS_ACTIVE;
                $user->save();

                if ($user->username && !$this->userHasPersonalStorage($user)) {
                    $this->createPersonalStorage($user);
                }
            }

            return $user;
        });
    }

    /**
     * Aplica una nueva contraseña al usuario. Usado tanto por `setup`
     * (después de consumir el token) como por `reset`.
     */
    public function applyPassword(User $user, string $password): void
    {
        $user->password_hash = Hash::make($password);
        $user->save();
    }

    private function userHasPersonalStorage(User $user): bool
    {
        return $user->userStorages()
            ->whereHas('storageProvider', function ($q) use ($user) {
                $q->where('name', 'Personal - ' . $user->username);
            })
            ->exists();
    }

    private function createPersonalStorage(User $user): void
    {
        $basePath = rtrim((string) config('storage.personal_base_path', '/home/www/Usuarios_tcloud/'), '/') . '/' . $user->username;

        if (!is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }

        $storage = StorageProvider::create([
            'name'      => 'Personal - ' . $user->username,
            'type'      => 'local',
            'base_path' => $basePath,
            'enabled'   => true,
            'is_personal' => true,
        ]);

        UserStorage::create([
            'user_id'            => $user->id,
            'storage_provider_id' => $storage->id,
            'permissions'        => 'full',
            'can_create_shares'  => true,
        ]);
    }
}
