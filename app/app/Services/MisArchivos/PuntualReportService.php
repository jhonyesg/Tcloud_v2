<?php

namespace App\Services\MisArchivos;

use App\Models\SolicitudPuntual;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de control de las solicitudes puntuales, para el ADMINISTRADOR.
 *
 * El operador lleva el cobro a mano: necesita saber, por cliente, cuántas
 * solicitudes hizo, cuántos minutos y cuánto material se le entregó, y poder
 * sacarlo en un texto plano para imprimir o archivar.
 *
 * QUÉ CUENTA: por defecto TODAS las solicitudes del período, con el estado
 * en el detalle. Una solicitud revocada se entregó y luego se cortó, así que
 * el operador decide si la cobra: por eso el estado viaja en el reporte y se
 * puede filtrar (`entregadas` = las que tuvieron material).
 *
 * El reporte es de SOLO LECTURA: no toca ninguna fila.
 */
class PuntualReportService
{
    /**
     * Agregado por cliente, con el detalle de sus solicitudes.
     *
     * @param array{desde?:?string,hasta?:?string,estado?:?string,user_id?:?int} $filtros
     * @return array{
     *     desde:?string,
     *     hasta:?string,
     *     estado:string,
     *     totales:array{solicitudes:int,clientes:int,minutos:int,archivos:int,bytes:int,enlaces:int},
     *     clientes:list<array<string,mixed>>
     * }
     */
    public function porCliente(array $filtros = []): array
    {
        $desde = $this->parseDate($filtros['desde'] ?? null);
        $hasta = $this->parseDate($filtros['hasta'] ?? null);
        $estado = (string) ($filtros['estado'] ?? 'todas');

        $query = SolicitudPuntual::query()
            ->with(['medio.storage', 'user'])
            ->when($desde !== null, fn ($q) => $q->whereDate('fecha', '>=', $desde->toDateString()))
            ->when($hasta !== null, fn ($q) => $q->whereDate('fecha', '<=', $hasta->toDateString()))
            ->when(isset($filtros['user_id']) && $filtros['user_id'] !== null, fn ($q) => $q->where('user_id', (int) $filtros['user_id']));

        $this->aplicarEstado($query, $estado);

        $solicitudes = $query->orderBy('user_id')->orderBy('fecha')->orderBy('hora_inicio')->get();

        $porCliente = [];

        foreach ($solicitudes as $solicitud) {
            $userId = (int) $solicitud->user_id;

            if (! isset($porCliente[$userId])) {
                $porCliente[$userId] = [
                    'user_id' => $userId,
                    'username' => (string) ($solicitud->user?->username ?? '—'),
                    // `users` no tiene columna `name`: el nombre visible es
                    // `username`.
                    'nombre' => (string) ($solicitud->user?->username ?? '—'),
                    'email' => (string) ($solicitud->user?->email ?? ''),
                    'solicitudes' => 0,
                    'entregadas' => 0,
                    'vigentes' => 0,
                    'revocadas' => 0,
                    'expiradas' => 0,
                    'sin_material' => 0,
                    'minutos' => 0,
                    'archivos' => 0,
                    'bytes' => 0,
                    'enlaces' => 0,
                    'primera' => null,
                    'ultima' => null,
                    'detalle' => [],
                ];
            }

            $fila = &$porCliente[$userId];

            $estadoFila = $solicitud->estado();
            $fecha = (string) $solicitud->fecha;

            $fila['solicitudes']++;
            $fila['minutos'] += (int) $solicitud->duracion_minutos;
            $fila['archivos'] += (int) $solicitud->archivos_total;
            $fila['bytes'] += (int) $solicitud->bytes_total;
            $fila['enlaces'] += (int) $solicitud->links_total;

            if ((int) $solicitud->archivos_total > 0) {
                $fila['entregadas']++;
            }

            match ($estadoFila) {
                'ready' => $fila['vigentes']++,
                'expired' => $fila['expiradas']++,
                'revoked' => $fila['revocadas']++,
                default => $fila['sin_material']++,
            };

            if ($fila['primera'] === null || $fecha < $fila['primera']) {
                $fila['primera'] = $fecha;
            }

            if ($fila['ultima'] === null || $fecha > $fila['ultima']) {
                $fila['ultima'] = $fecha;
            }

            $fila['detalle'][] = [
                'id' => (int) $solicitud->id,
                'fecha' => $fecha,
                'fecha_legible' => Carbon::parse($fecha)->format('d/m/Y'),
                'hora_inicio' => substr((string) $solicitud->hora_inicio, 0, 8),
                'hora_fin' => substr((string) $solicitud->hora_fin, 0, 8),
                'duracion_minutos' => (int) $solicitud->duracion_minutos,
                'modo' => (string) $solicitud->modo,
                'medio' => (string) ($solicitud->medio?->name ?? '—'),
                'media_kind' => (string) ($solicitud->medio?->media_kind ?? ''),
                'archivos' => (int) $solicitud->archivos_total,
                'bytes' => (int) $solicitud->bytes_total,
                'enlaces' => (int) $solicitud->links_total,
                'estado' => $estadoFila,
                'motivo' => (string) $solicitud->motivo,
            ];

            unset($fila);
        }

        $clientes = array_values($porCliente);

        usort($clientes, fn (array $a, array $b) => $b['solicitudes'] <=> $a['solicitudes']);

        $totales = [
            'solicitudes' => array_sum(array_column($clientes, 'solicitudes')),
            'clientes' => count($clientes),
            'minutos' => array_sum(array_column($clientes, 'minutos')),
            'archivos' => array_sum(array_column($clientes, 'archivos')),
            'bytes' => array_sum(array_column($clientes, 'bytes')),
            'enlaces' => array_sum(array_column($clientes, 'enlaces')),
        ];

        return [
            'desde' => $desde?->toDateString(),
            'hasta' => $hasta?->toDateString(),
            'estado' => $estado,
            'totales' => $totales,
            'clientes' => $clientes,
        ];
    }

    /**
     * Reporte en texto plano, para imprimir o archivar.
     *
     * Ancho fijo y sin caracteres de dibujo: se pega en un correo o se
     * imprime sin que se rompa la alineación.
     */
    public function toTxt(array $reporte): string
    {
        $lineas = [];
        $ancho = 96;

        $lineas[] = str_repeat('=', $ancho);
        $lineas[] = 'CONTROL DE SOLICITUDES PUNTUALES DE TV Y RADIO';
        $lineas[] = str_repeat('=', $ancho);
        $lineas[] = 'Generado:  ' . now()->format('d/m/Y H:i');
        $lineas[] = 'Periodo:   ' . $this->periodo($reporte);
        $lineas[] = 'Filtro:    ' . $this->etiquetaEstado((string) $reporte['estado']);
        $lineas[] = '';
        $lineas[] = sprintf(
            'TOTALES:   %d %s · %d %s · %d minutos · %d archivos · %s',
            $reporte['totales']['solicitudes'],
            $reporte['totales']['solicitudes'] === 1 ? 'solicitud' : 'solicitudes',
            $reporte['totales']['clientes'],
            $reporte['totales']['clientes'] === 1 ? 'cliente' : 'clientes',
            $reporte['totales']['minutos'],
            $reporte['totales']['archivos'],
            $this->bytes((int) $reporte['totales']['bytes'])
        );
        $lineas[] = '';

        if ($reporte['clientes'] === []) {
            $lineas[] = 'Sin solicitudes en el periodo.';
            $lineas[] = '';

            return implode("\n", $lineas);
        }

        foreach ($reporte['clientes'] as $cliente) {
            $lineas[] = str_repeat('-', $ancho);
            $lineas[] = sprintf(
                'CLIENTE: %s (%s)%s',
                $cliente['nombre'] !== '' ? $cliente['nombre'] : $cliente['username'],
                $cliente['username'],
                $cliente['email'] !== '' ? ' · ' . $cliente['email'] : ''
            );
            $lineas[] = str_repeat('-', $ancho);
            $lineas[] = sprintf(
                'Solicitudes: %d   Entregadas: %d   Vigentes: %d   Expiradas: %d   Revocadas: %d   Sin material: %d',
                $cliente['solicitudes'],
                $cliente['entregadas'],
                $cliente['vigentes'],
                $cliente['expiradas'],
                $cliente['revocadas'],
                $cliente['sin_material']
            );
            $lineas[] = sprintf(
                'Minutos: %d   Archivos: %d   Tamaño: %s   Enlaces: %d',
                $cliente['minutos'],
                $cliente['archivos'],
                $this->bytes((int) $cliente['bytes']),
                $cliente['enlaces']
            );
            $lineas[] = sprintf(
                'Primera solicitud: %s   Ultima: %s',
                $this->fecha($cliente['primera']),
                $this->fecha($cliente['ultima'])
            );
            $lineas[] = '';
            $lineas[] = sprintf(
                '%-10s  %-28s %-17s %4s %7s %10s  %s',
                'FECHA',
                'MEDIO',
                'HORARIO',
                'MIN',
                'ARCHIV',
                'TAMAÑO',
                'ESTADO'
            );
            $lineas[] = str_repeat('-', $ancho);

            foreach ($cliente['detalle'] as $fila) {
                $lineas[] = sprintf(
                    '%-10s  %-28s %-17s %4d %7d %10s  %s',
                    $fila['fecha_legible'],
                    $this->recortar($fila['medio'], 28),
                    $fila['hora_inicio'] . '-' . $fila['hora_fin'],
                    $fila['duracion_minutos'],
                    $fila['archivos'],
                    $this->bytes((int) $fila['bytes']),
                    $this->estadoLegible((string) $fila['estado'])
                );
            }

            $lineas[] = '';
        }

        $lineas[] = str_repeat('=', $ancho);
        $lineas[] = 'Nota: el reporte incluye todas las solicitudes del filtro. Una solicitud';
        $lineas[] = 'revocada se entrego y luego se corto: revisa la columna ESTADO para';
        $lineas[] = 'decidir si se cobra.';
        $lineas[] = '';

        return implode("\n", $lineas);
    }

    /**
     * Nombre del archivo de exportacion.
     */
    public function nombreArchivo(array $reporte): string
    {
        $desde = $reporte['desde'] !== null ? str_replace('-', '', $reporte['desde']) : 'inicio';
        $hasta = $reporte['hasta'] !== null ? str_replace('-', '', $reporte['hasta']) : now()->format('Ymd');

        return "control-solicitudes-{$desde}-{$hasta}.txt";
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    private function aplicarEstado($query, string $estado): void
    {
        switch ($estado) {
            case 'entregadas':
                $query->where('archivos_total', '>', 0);
                break;
            case 'vigentes':
                $query->whereIn('status', SolicitudPuntual::STATUSES_VIVAS)
                    ->where(function ($q) {
                        $q->whereNull('link_expires_at')->orWhere('link_expires_at', '>', now());
                    });
                break;
            case 'revocadas':
                $query->where('status', SolicitudPuntual::STATUS_REVOKED);
                break;
            case 'sin_material':
                $query->where('status', SolicitudPuntual::STATUS_UNAVAILABLE);
                break;
            default:
                break;
        }
    }

    /**
     * Estado en español para el texto plano.
     */
    private function estadoLegible(string $estado): string
    {
        return [
            'ready' => 'vigente',
            'expired' => 'expirada',
            'revoked' => 'revocada',
            'unavailable' => 'sin material',
            'partial' => 'parcial',
        ][$estado] ?? $estado;
    }

    private function etiquetaEstado(string $estado): string
    {
        return [
            'todas' => 'todas las solicitudes',
            'entregadas' => 'solo las que entregaron material',
            'vigentes' => 'solo las vigentes',
            'revocadas' => 'solo las revocadas',
            'sin_material' => 'solo las que no tenian material',
        ][$estado] ?? 'todas las solicitudes';
    }

    private function parseDate(?string $valor): ?Carbon
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function periodo(array $reporte): string
    {
        $desde = $reporte['desde'] !== null ? Carbon::parse($reporte['desde'])->format('d/m/Y') : 'sin limite';
        $hasta = $reporte['hasta'] !== null ? Carbon::parse($reporte['hasta'])->format('d/m/Y') : 'hoy';

        return "{$desde} a {$hasta}";
    }

    private function fecha(?string $valor): string
    {
        return $valor !== null ? Carbon::parse($valor)->format('d/m/Y') : '—';
    }

    /**
     * Bytes en formato legible, con coma decimal (es-CO).
     */
    private function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        if ($bytes < 1073741824) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }

    private function recortar(string $texto, int $largo): string
    {
        if (mb_strlen($texto) <= $largo) {
            return $texto;
        }

        return mb_substr($texto, 0, $largo - 1) . '…';
    }
}
