<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint read-only que devuelve la lista de usuarios impersonables desde
 * Mis Avisos: aquellos que tienen el módulo activado
 * (user_alerts_inteligentes.enabled = true). Usado por el banner del
 * admin en /mis-avisos (ver change mis-avisos-admin-preview).
 */
class AdminPreviewController extends Controller
{
    public function impersonatableUsers(): JsonResponse
    {
        $users = User::query()
            ->whereHas('alertsInteligente', function ($q) {
                $q->where('enabled', true);
            })
            ->orderBy('users.username')
            ->get(['users.id', 'users.username', 'users.email']);

        return response()->json([
            'users' => $users->map(function (User $u) {
                return [
                    'id'       => (int) $u->id,
                    'username' => (string) $u->username,
                    'name'     => (string) ($u->username),
                    'email'    => (string) $u->email,
                ];
            })->values(),
        ]);
    }
}
