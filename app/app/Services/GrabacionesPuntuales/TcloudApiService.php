<?php

namespace App\Services\GrabacionesPuntuales;

use App\Models\Grabador;
use App\Models\Canal;
use Illuminate\Support\Facades\Http;

class TcloudApiService
{
    public function crearCanal(Grabador $grabador, Canal $canal): array
    {
        try {
            $esRadio = $grabador->tipo === 'radio';
            $rutaDescarga = $canal->ruta_destino;
            if (empty($rutaDescarga)) {
                $rutaBase = \DB::table('grabador_usuario')
                    ->where('grabador_id', $grabador->id)
                    ->where('user_id', $canal->usuario_id)
                    ->value('ruta_base');
                if ($rutaBase) {
                    $rutaDescarga = rtrim($rutaBase, '/') . '/' . $canal->slot_nombre;
                } elseif ($canal->link_origen) {
                    \Log::warning('TcloudApiService::crearCanal sin ruta_base en pivote, usando fallback', [
                        'canal_id' => $canal->id,
                        'motivo' => 'sin_ruta_local_ni_pivote',
                    ]);
                    $rutaDescarga = $this->generarRutaDescarga($grabador, $canal);
                }
            }
            $payload = [
                'codigo' => $this->generarCodigo($canal->slot_nombre),
                'nombre' => $canal->slot_nombre,
                'categoria' => $esRadio ? 'radio' : 'nacional',
                'link_origen' => $canal->link_origen,
                'ruta_descarga' => $rutaDescarga,
                'duracion_grabacion' => $canal->duracion_grabacion ?? '00:21:00',
                'formato_salida' => $canal->formato_salida ?? ($esRadio ? '.mp3' : '.mp4'),
                'ffmpeg_args_pre' => $canal->ffmpeg_args_pre ?? '-re',
                'ffmpeg_args_post' => $canal->ffmpeg_args_post ?? ($esRadio ? '-acodec libmp3lame' : '-c copy'),
                'max_fallos' => 200,
                'detalle' => $canal->detalle,
                'activo' => 1,
            ];

            $response = Http::timeout(10)->post("{$grabador->base_url}/canales", $payload);

            if ($response->successful()) {
                $data = $response->json('data');
                return [
                    'success' => true,
                    'api_canal_id' => $data['id'] ?? null,
                ];
            }

            \Log::warning("TcloudApiService::crearCanal falló", [
                'grabador' => $grabador->id,
                'canal' => $canal->id,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
            ];
        } catch (\Exception $e) {
            \Log::error("TcloudApiService::crearCanal excepción", [
                'grabador' => $grabador->id,
                'canal' => $canal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'No se pudo conectar con el grabador: ' . $e->getMessage(),
            ];
        }
    }

    public function actualizarCanal(Grabador $grabador, Canal $canal, array $datos): array
    {
        if (!$canal->api_canal_id) {
            return ['success' => false, 'error' => 'Canal no tiene api_canal_id — primero regístralo con link_origen'];
        }

        try {
            $response = Http::timeout(10)->put("{$grabador->base_url}/canales/{$canal->api_canal_id}", $datos);

            if ($response->successful()) {
                return ['success' => true];
            }

            \Log::warning("TcloudApiService::actualizarCanal falló", [
                'grabador' => $grabador->id,
                'canal' => $canal->id,
                'api_canal_id' => $canal->api_canal_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
            ];
        } catch (\Exception $e) {
            \Log::error("TcloudApiService::actualizarCanal excepción", [
                'grabador' => $grabador->id,
                'canal' => $canal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'No se pudo conectar con el grabador: ' . $e->getMessage(),
            ];
        }
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

    public function buscarPorCodigo(Grabador $grabador, string $codigo): ?array
    {
        $remotos = $this->getCanalesRemotos($grabador);
        foreach ($remotos as $remoto) {
            if (isset($remoto['codigo']) && $remoto['codigo'] === $codigo) {
                return $remoto;
            }
        }
        return null;
    }

    public function eliminarPorCodigo(Grabador $grabador, string $codigo): array
    {
        $remoto = $this->buscarPorCodigo($grabador, $codigo);
        if (!$remoto || empty($remoto['id'])) {
            return ['success' => true, 'noop' => true];
        }
        return $this->eliminarCanal($grabador, (int) $remoto['id']);
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
        return strtolower(str_replace([' ', '_'], '_', $slotNombre));
    }

    private function generarRutaDescarga(Grabador $grabador, Canal $canal): string
    {
        $slug = strtolower(str_replace([' ', '_'], '_', $canal->slot_nombre));
        $tipo = $grabador->tipo === 'tv' ? 'television' : 'radio';
        return "/www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/{$tipo}/{$slug}";
    }
}
