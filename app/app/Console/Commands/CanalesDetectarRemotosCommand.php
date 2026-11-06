<?php

namespace App\Console\Commands;

use App\Models\Grabador;
use App\Services\GrabacionesPuntuales\AdopcionRemotaService;
use Illuminate\Console\Command;

/**
 * Detección y adopción de canales que existen en el grabador y no en la
 * plataforma. Dry-run por defecto; `--adoptar` solo integra los `adoptable`.
 *
 * Ver change `medios-puntuales-cupos-y-adopcion-remota`.
 */
class CanalesDetectarRemotosCommand extends Command
{
    protected $signature = 'canales:detectar-remotos
                            {--grabador= : Limitar a un grabador por id}
                            {--user= : Limitar la adopción a un usuario}
                            {--json : Salida JSON (para operación/automatización)}
                            {--adoptar : Integrar los canales con confianza adoptable}';

    protected $description = 'Detecta canales del grabador sin fila local y (opcionalmente) los integra sin tocar el grabador';

    public function handle(AdopcionRemotaService $servicio): int
    {
        $grabadorId = $this->option('grabador');
        $userId = $this->option('user');
        $json = (bool) $this->option('json');
        $adoptar = (bool) $this->option('adoptar');

        $grabadores = Grabador::where('activo', true)
            ->when($grabadorId !== null, fn($q) => $q->where('id', (int) $grabadorId))
            ->orderBy('id')
            ->get();

        if ($grabadores->isEmpty()) {
            if ($json) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => 'No hay grabadores activos que coincidan',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return self::FAILURE;
            }

            $this->error('No hay grabadores activos que coincidan con el filtro.');
            return self::FAILURE;
        }

        $resumen = [];
        $adoptados = [];
        $omitidos = [];

        foreach ($grabadores as $grabador) {
            $resultado = $servicio->detectar($grabador);

            foreach ($resultado['remotos_detectados'] as $detectado) {
                $detectado['grabador_id'] = $grabador->id;
                $detectado['grabador_nombre'] = $grabador->nombre;
                $resultado['detectados'][] = $detectado;
            }

            $resumen[] = [
                'grabador_id' => $grabador->id,
                'grabador_nombre' => $grabador->nombre,
                'tipo' => $grabador->tipo,
                'total_remotos' => $resultado['total_remotos'],
                'vinculados' => $resultado['vinculados'],
                'ya_ok' => $resultado['ya_ok'],
                'sin_match' => $resultado['sin_match'],
                'fuera_de_alcance' => $resultado['fuera_de_alcance'],
                'detectados' => $resultado['remotos_detectados'],
            ];

            if ($adoptar) {
                foreach ($resultado['remotos_detectados'] as $detectado) {
                    if ($detectado['confianza'] !== 'adoptable') {
                        continue;
                    }

                    if ($userId !== null && (int) $detectado['user_id'] !== (int) $userId) {
                        continue;
                    }

                    $r = $servicio->adoptar($grabador, (int) $detectado['user_id'], (int) $detectado['api_canal_id']);

                    if ($r['ok']) {
                        $adoptados[] = ['grabador_id' => $grabador->id] + $r;
                    } else {
                        $omitidos[] = ['grabador_id' => $grabador->id] + $r;
                    }
                }
            }
        }

        $sugeridosPendientes = 0;
        foreach ($resumen as $fila) {
            foreach ($fila['detectados'] as $d) {
                if ($d['confianza'] !== 'adoptable') {
                    $sugeridosPendientes++;
                }
            }
        }

        if ($json) {
            $this->line(json_encode([
                'success' => true,
                'grabadores' => $resumen,
                'adoptados' => $adoptados,
                'omitidos' => $omitidos,
                'sugeridos_pendientes' => $sugeridosPendientes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($resumen as $fila) {
            $this->newLine();
            $this->info(sprintf(
                'Grabador %d (%s, %s) — remotos: %d · vinculados: %d · ya_ok: %d · sin_match: %d · fuera: %d',
                $fila['grabador_id'],
                $fila['grabador_nombre'],
                $fila['tipo'],
                $fila['total_remotos'],
                $fila['vinculados'],
                $fila['ya_ok'],
                $fila['sin_match'],
                $fila['fuera_de_alcance']
            ));

            if (empty($fila['detectados'])) {
                $this->line('  (sin canales remotos huérfanos)');
                continue;
            }

            $this->table(
                ['ID', 'Código', 'Nombre', 'Par', 'Confianza', 'Ruta'],
                array_map(function ($d) {
                    return [
                        $d['api_canal_id'],
                        $d['codigo'],
                        $d['nombre'],
                        $d['user_id'],
                        $d['confianza'],
                        $d['ruta_descarga'],
                    ];
                }, $fila['detectados'])
            );
        }

        if ($adoptar) {
            $this->newLine();
            $this->info(sprintf('Adoptados: %d · Omitidos: %d', count($adoptados), count($omitidos)));

            foreach ($omitidos as $o) {
                $this->warn(sprintf('  omitido [%s] %s: %s', $o['accion'], $o['codigo'], $o['mensaje']));
            }
        }

        if ($sugeridosPendientes > 0) {
            $this->newLine();
            $this->comment(sprintf(
                '%d canal(es) "sugerido" no se adoptan automáticamente: requieren selección manual desde la UI.',
                $sugeridosPendientes
            ));
        }

        if (!$adoptar) {
            $this->newLine();
            $this->comment('Modo dry-run: no se escribió nada. Usa --adoptar para integrar los adoptables.');
        }

        return self::SUCCESS;
    }
}
