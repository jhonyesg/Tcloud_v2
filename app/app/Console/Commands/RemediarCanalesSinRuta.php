<?php

namespace App\Console\Commands;

use App\Models\Canal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RemediarCanalesSinRuta extends Command
{
    protected $signature = 'canales:remediar-ruta
                            {--dry-run : Mostrar preview sin escribir cambios}';

    protected $description = 'Rellena ruta_destino en canales con NULL usando grabador_usuario.ruta_base, y sincroniza ruta_descarga con la API del grabador';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $canales = Canal::whereNull('ruta_destino')
            ->whereNotNull('api_canal_id')
            ->whereNotNull('usuario_id')
            ->orderBy('id')
            ->get();

        if ($canales->isEmpty()) {
            $this->info('No hay canales con ruta_destino NULL y api_canal_id poblado. Nada que remediar.');
            return self::SUCCESS;
        }

        $remediados = 0;
        $fallos = 0;
        $sinCambioRemoto = 0;

        foreach ($canales as $canal) {
            $rutaBase = DB::table('grabador_usuario')
                ->where('grabador_id', $canal->grabador_id)
                ->where('user_id', $canal->usuario_id)
                ->value('ruta_base');

            if (!$rutaBase) {
                $this->warn("  [skip] canal id={$canal->id} slot={$canal->slot_nombre} sin ruta_base en pivote");
                continue;
            }

            $grabador = $canal->grabador;
            $rutaRemotaActual = $this->obtenerRutaRemota($grabador->base_url, $canal->api_canal_id);
            $rutaEsperada = rtrim($rutaBase, '/') . '/' . $canal->slot_nombre;

            if ($rutaRemotaActual !== null) {
                $dirRemoto = preg_replace('#/[^/]+\z#', '', $rutaRemotaActual);
                if ($dirRemoto === rtrim($rutaBase, '/')) {
                    $nuevaRuta = $rutaRemotaActual;
                    $accion = 'sync';
                } else {
                    $nuevaRuta = $rutaEsperada;
                    $accion = 'put';
                }
            } else {
                $nuevaRuta = $rutaEsperada;
                $accion = 'put';
            }

            if ($dryRun) {
                $this->line(sprintf(
                    "  [dry-run] canal id=%d slot=%s accion=%s -> %s",
                    $canal->id, $canal->slot_nombre, $accion, $nuevaRuta
                ));
                if ($accion === 'sync') {
                    $sinCambioRemoto++;
                }
                $remediados++;
                continue;
            }

            $canal->update(['ruta_destino' => $nuevaRuta]);

            if ($accion === 'sync') {
                $this->info("  [sync] canal id={$canal->id} slot={$canal->slot_nombre} -> {$nuevaRuta} (case preservado del remoto)");
                $sinCambioRemoto++;
                $remediados++;
                continue;
            }

            try {
                $resp = Http::timeout(10)
                    ->put("{$grabador->base_url}/canales/{$canal->api_canal_id}", [
                        'ruta_descarga' => $nuevaRuta,
                    ]);

                if ($resp->successful()) {
                    $this->info("  [ok] canal id={$canal->id} slot={$canal->slot_nombre} -> {$nuevaRuta}");
                    $remediados++;
                } else {
                    $fallos++;
                    Log::error('RemediarCanalesSinRuta: PUT no exitoso', [
                        'canal_id' => $canal->id,
                        'status' => $resp->status(),
                        'body' => $resp->body(),
                    ]);
                    $this->error("  [fail] canal id={$canal->id} HTTP {$resp->status()}");
                }
            } catch (\Throwable $e) {
                $fallos++;
                Log::error('RemediarCanalesSinRuta: excepción', [
                    'canal_id' => $canal->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  [fail] canal id={$canal->id} " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Resumen: %d remediados (%d con PUT, %d sync case), %d fallos%s',
            $remediados, $remediados - $sinCambioRemoto, $sinCambioRemoto, $fallos,
            $dryRun ? ' (dry-run)' : ''
        ));

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function obtenerRutaRemota(string $baseUrl, int $apiCanalId): ?string
    {
        try {
            $resp = Http::timeout(5)->get("{$baseUrl}/canales/{$apiCanalId}");
            if ($resp->successful()) {
                return $resp->json('data.ruta_descarga');
            }
        } catch (\Throwable $e) {
            Log::warning('RemediarCanalesSinRuta: no se pudo leer remoto', [
                'api_canal_id' => $apiCanalId,
                'error' => $e->getMessage(),
            ]);
        }
        return null;
    }
}