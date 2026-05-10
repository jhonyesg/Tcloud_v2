<?php

namespace App\Services\GrabacionesPuntuales;

use App\Models\Grabador;
use App\Models\Canal;
use Illuminate\Support\Facades\Http;

class TcloudApiService
{
    public function crearCanal(Grabador $grabador, Canal $canal): array
    {
        $response = Http::post("{$grabador->base_url}/canales", [
            'codigo' => $this->generarCodigo($canal->slot_nombre),
            'nombre' => $canal->slot_nombre,
            'categoria' => 'radio',
        ]);

        if ($response->successful()) {
            $data = $response->json('data');
            return [
                'success' => true,
                'api_canal_id' => $data['id'] ?? null,
            ];
        }

        return [
            'success' => false,
            'error' => $response->body(),
        ];
    }

    public function actualizarCanal(Grabador $grabador, Canal $canal, array $datos): array
    {
        if (!$canal->api_canal_id) {
            return ['success' => false, 'error' => 'Canal no tiene api_canal_id'];
        }

        $response = Http::put("{$grabador->base_url}/canales/{$canal->api_canal_id}", $datos);

        if ($response->successful()) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => $response->body(),
        ];
    }

    public function eliminarCanal(Grabador $grabador, int $apiCanalId): array
    {
        $response = Http::delete("{$grabador->base_url}/canales/{$apiCanalId}");

        if ($response->successful()) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => $response->body(),
        ];
    }

    public function getCanalesRemotos(Grabador $grabador): array
    {
        $response = Http::get("{$grabador->base_url}/canales");

        if ($response->successful()) {
            return $response->json('data') ?? [];
        }

        return [];
    }

    public function iniciarGrabacion(Grabador $grabador, int $apiCanalId): array
    {
        $response = Http::post("{$grabador->base_url}/grabador/iniciar", [
            'canal_id' => $apiCanalId,
        ]);

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json('data')];
        }

        return [
            'success' => false,
            'error' => $response->body(),
        ];
    }

    public function estadoGrabacion(Grabador $grabador): array
    {
        $response = Http::get("{$grabador->base_url}/grabador/estado");

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json('data')];
        }

        return [
            'success' => false,
            'error' => $response->body(),
        ];
    }

    private function generarCodigo(string $slotNombre): string
    {
        return strtolower(str_replace(' ', '_', $slotNombre));
    }
}