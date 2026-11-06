<?php

namespace App\Services\GrabacionesPuntuales;

use App\Models\Canal;
use Illuminate\Support\Facades\DB;

/**
 * Fuente única del cupo de canales por par (grabador × usuario).
 *
 * El límite vive en `grabador_usuario.limite_canales`. Antes de este servicio
 * el mismo dato se leía de tres formas distintas y una de ellas era incorrecta:
 * `CanalController::store` leía `$acceso->limite_canales` sobre un modelo
 * `Grabador` (columna inexistente → null → `usados >= null` siempre true),
 * mientras `create()` leía `$grabador->pivot->limite_canales`. Resultado: todo
 * usuario no-admin quedaba bloqueado con cualquier límite.
 *
 * Contrato:
 *   limite()    -> int|null    null = sin asignación (NO es ilimitado)
 *   uso()       -> int         filas de `canales` del par
 *   resumen()   -> array|null  null = sin asignación
 *   puedeCrear()-> bool        bypass admin incluido
 *
 * `limite = 0` se trata como sin asignación (null) para no convertirlo
 * silenciosamente en "ilimitado" al restar o comparar.
 */
class CupoCanalesService
{
    /**
     * Límite configurado para el par, o null si no hay asignación utilizable.
     */
    public function limite(int $grabadorId, int $userId): ?int
    {
        $valor = DB::table('grabador_usuario')
            ->where('grabador_id', $grabadorId)
            ->where('user_id', $userId)
            ->value('limite_canales');

        if ($valor === null) {
            return null;
        }

        $limite = (int) $valor;

        return $limite > 0 ? $limite : null;
    }

    /**
     * Canales locales del par. Cuenta filas, no remotos.
     */
    public function uso(int $grabadorId, int $userId): int
    {
        return Canal::where('grabador_id', $grabadorId)
            ->where('usuario_id', $userId)
            ->count();
    }

    /**
     * Estado completo del par o null si no hay asignación utilizable.
     *
     * @return array{limite:int,usados:int,disponibles:int,ruta_base:?string}|null
     */
    public function resumen(int $grabadorId, int $userId): ?array
    {
        $fila = DB::table('grabador_usuario')
            ->where('grabador_id', $grabadorId)
            ->where('user_id', $userId)
            ->first(['limite_canales', 'ruta_base']);

        if ($fila === null) {
            return null;
        }

        $limite = (int) $fila->limite_canales;
        if ($limite <= 0) {
            return null;
        }

        $usados = $this->uso($grabadorId, $userId);

        return [
            'limite' => $limite,
            'usados' => $usados,
            'disponibles' => $limite - $usados,
            'ruta_base' => $fila->ruta_base,
        ];
    }

    /**
     * Igual que resumen() pero bloqueando la fila del pivote.
     *
     * SOLO válido dentro de una transacción ya abierta: el lock se libera al
     * terminar la transacción del llamador.
     *
     * @return array{limite:int,usados:int,disponibles:int,ruta_base:?string}|null
     */
    public function resumenBloqueado(int $grabadorId, int $userId): ?array
    {
        $fila = DB::table('grabador_usuario')
            ->where('grabador_id', $grabadorId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first(['limite_canales', 'ruta_base']);

        if ($fila === null) {
            return null;
        }

        $limite = (int) $fila->limite_canales;
        if ($limite <= 0) {
            return null;
        }

        $usados = $this->uso($grabadorId, $userId);

        return [
            'limite' => $limite,
            'usados' => $usados,
            'disponibles' => $limite - $usados,
            'ruta_base' => $fila->ruta_base,
        ];
    }

    /**
     * ¿Puede el usuario crear otro canal en este grabador?
     *
     * El admin no está sujeto al cupo. Sin asignación => false (nunca "infinito").
     */
    public function puedeCrear(int $grabadorId, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        $resumen = $this->resumen($grabadorId, $userId);

        return $resumen !== null && $resumen['disponibles'] > 0;
    }
}
