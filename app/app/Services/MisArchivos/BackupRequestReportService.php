<?php

namespace App\Services\MisArchivos;

use App\Models\BackupRequest;
use Illuminate\Support\Carbon;

/**
 * Reporte de las solicitudes de backup POR CLIENTE, para el ADMINISTRADOR.
 *
 * El módulo de Backup tiene dos caras: el CLIENTE pide el material de
 * producción desde Mis Archivos (botón "Solicitar backup") y ve su propio
 * historial; el ADMINISTRADOR declara qué canal corresponde a qué carpeta
 * del árbol (`/admin/backups`). Hasta ahora no existía ninguna vista que
 * juntara las dos cosas: el único listado de solicitudes es
 * `BackupRequestService::listFor(User $user)`, filtrado por `user_id`, así
 * que el administrador veía su propio historial (vacío) y no sabía quién
 * está pidiendo qué.
 *
 * Este servicio es ese listado agregado. Es de SOLO LECTURA: no crea,
 * no revoca y no escribe nada. La revocación sigue siendo del cliente
 * ("Revocar la solicitud en Mis Archivos es la única forma de desactivar
 * ese enlace", `resources/views/admin/backups.blade.php`).
 *
 * QUÉ CUENTA: por defecto TODAS las solicitudes del período, con el estado
 * EFECTIVO en el detalle (el de `BackupRequest::effectiveStatus()`, no el
 * de la columna: una fila `ready` con el enlace vencido se reporta
 * `expired`). El filtro por estado se aplica sobre la columna y la
 * vigencia, y por eso una fila `ready` sin share puede aparecer en
 * `todas` y reportarse `unavailable` en el detalle: el admin decide.
 *
 * El reporte devuelve además la DEMANDA POR CANAL (storage de origen):
 * es lo que le dice al administrador qué canales se piden y, por tanto,
 * cuáles merecen la pena declarar un vínculo de backup.
 */
class BackupRequestReportService
{
    public const FILTRO_TODAS = 'todas';

    public const FILTRO_VIGENTES = 'vigentes';

    public const FILTRO_EXPIRADAS = 'expiradas';

    public const FILTRO_REVOCADAS = 'revocadas';

    public const FILTRO_SIN_MATERIAL = 'sin_material';

    /**
     * Tope de filas que se leen en memoria. El reporte dice si lo alcanzó
     * (`truncado`) en vez de mostrar un total que no cuadra.
     */
    public const MAX_FILAS = 5000;

    /**
     * Agregado por cliente, con el detalle de sus solicitudes.
     *
     * @param array{desde?:?string,hasta?:?string,estado?:?string,user_id?:?int|string} $filtros
     * @return array{
     *     desde:?string,
     *     hasta:?string,
     *     estado:string,
     *     truncado:bool,
     *     totales:array{solicitudes:int,clientes:int,vigentes:int,expiradas:int,revocadas:int,sin_material:int,canales:int},
     *     clientes:list<array<string,mixed>>,
     *     canales:list<array<string,mixed>>
     * }
     */
    public function porCliente(array $filtros = []): array
    {
        $desde = $this->parseDate($filtros['desde'] ?? null);
        $hasta = $this->parseDate($filtros['hasta'] ?? null);
        $estado = $this->estadoValido((string) ($filtros['estado'] ?? self::FILTRO_TODAS));
        $userId = $this->parseUserId($filtros['user_id'] ?? null);

        $query = BackupRequest::query()
            ->with(['user', 'sourceStorage', 'share'])
            ->when($desde !== null, fn ($q) => $q->whereDate('created_at', '>=', $desde->toDateString()))
            ->when($hasta !== null, fn ($q) => $q->whereDate('created_at', '<=', $hasta->toDateString()))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId));

        $this->aplicarEstado($query, $estado);

        $requests = $query
            ->orderBy('user_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_FILAS + 1)
            ->get();

        $truncado = $requests->count() > self::MAX_FILAS;
        if ($truncado) {
            $requests = $requests->take(self::MAX_FILAS);
        }

        $porCliente = [];
        $porCanal = [];

        foreach ($requests as $request) {
            $userKey = (int) $request->user_id;

            if (! isset($porCliente[$userKey])) {
                $porCliente[$userKey] = $this->clienteVacio($request);
            }

            $fila = &$porCliente[$userKey];
            $efectivo = $request->effectiveStatus();

            $fila['solicitudes']++;
            $fila[$this->contadorDe($efectivo)]++;

            $createdAt = $request->created_at;

            if ($createdAt !== null) {
                $sello = $createdAt->toDateTimeString();
                if ($fila['primera'] === null || $sello < $fila['primera']) {
                    $fila['primera'] = $sello;
                }
                if ($fila['ultima'] === null || $sello > $fila['ultima']) {
                    $fila['ultima'] = $sello;
                }
            }

            $fila['detalle'][] = $this->detalleDe($request, $efectivo);

            unset($fila);

            // Demanda por canal: qué storage de origen se está pidiendo.
            $canalKey = (int) $request->source_storage_id;

            if (! isset($porCanal[$canalKey])) {
                $porCanal[$canalKey] = [
                    'storage_id' => $canalKey,
                    'nombre' => (string) ($request->sourceStorage?->name ?? '—'),
                    'solicitudes' => 0,
                    'clientes' => [],
                    'vigentes' => 0,
                ];
            }

            $porCanal[$canalKey]['solicitudes']++;
            $porCanal[$canalKey]['clientes'][$userKey] = true;
            if ($efectivo === BackupRequest::STATUS_READY) {
                $porCanal[$canalKey]['vigentes']++;
            }
        }

        $clientes = array_values($porCliente);

        usort($clientes, fn (array $a, array $b) => $b['solicitudes'] <=> $a['solicitudes']);

        $canales = array_map(function (array $canal): array {
            $canal['clientes'] = count($canal['clientes']);

            return $canal;
        }, array_values($porCanal));

        usort($canales, fn (array $a, array $b) => $b['solicitudes'] <=> $a['solicitudes']);

        return [
            'desde' => $desde?->toDateString(),
            'hasta' => $hasta?->toDateString(),
            'estado' => $estado,
            'truncado' => $truncado,
            'totales' => [
                'solicitudes' => array_sum(array_column($clientes, 'solicitudes')),
                'clientes' => count($clientes),
                'vigentes' => array_sum(array_column($clientes, 'vigentes')),
                'expiradas' => array_sum(array_column($clientes, 'expiradas')),
                'revocadas' => array_sum(array_column($clientes, 'revocadas')),
                'sin_material' => array_sum(array_column($clientes, 'sin_material')),
                'canales' => count($canales),
            ],
            'clientes' => $clientes,
            'canales' => $canales,
        ];
    }

    /**
     * Clientes que tienen al menos una solicitud, para el selector del
     * reporte. No aplica el filtro de estado: el selector ofrece todo lo
     * que hay, y el filtro se encarga de acotar.
     *
     * @return list<array{id:int,username:string,nombre:string,email:string,solicitudes:int}>
     */
    public function clientesConSolicitudes(): array
    {
        return BackupRequest::query()
            ->selectRaw('user_id, count(*) as solicitudes')
            ->groupBy('user_id')
            ->orderByDesc('solicitudes')
            ->get()
            ->map(function ($fila): array {
                $user = \App\Models\User::find($fila->user_id);

                return [
                    'id' => (int) $fila->user_id,
                    'username' => (string) ($user?->username ?? '—'),
                    // `users` no tiene columna `name`: el nombre visible es
                    // `username`.
                    'nombre' => (string) ($user?->username ?? '—'),
                    'email' => (string) ($user?->email ?? ''),
                    'solicitudes' => (int) $fila->solicitudes,
                ];
            })
            ->values()
            ->all();
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
        $lineas[] = 'SOLICITUDES DE BACKUP DE PRODUCCION POR CLIENTE';
        $lineas[] = str_repeat('=', $ancho);
        $lineas[] = 'Generado:  ' . now()->format('d/m/Y H:i');
        $lineas[] = 'Periodo:   ' . $this->periodo($reporte);
        $lineas[] = 'Filtro:    ' . $this->etiquetaEstado((string) $reporte['estado']);
        $lineas[] = '';
        $lineas[] = sprintf(
            'TOTALES:   %d %s · %d %s · %d vigentes · %d expiradas · %d revocadas · %d sin material · %d %s',
            $reporte['totales']['solicitudes'],
            $reporte['totales']['solicitudes'] === 1 ? 'solicitud' : 'solicitudes',
            $reporte['totales']['clientes'],
            $reporte['totales']['clientes'] === 1 ? 'cliente' : 'clientes',
            $reporte['totales']['vigentes'],
            $reporte['totales']['expiradas'],
            $reporte['totales']['revocadas'],
            $reporte['totales']['sin_material'],
            $reporte['totales']['canales'],
            $reporte['totales']['canales'] === 1 ? 'canal' : 'canales'
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
                'Solicitudes: %d   Vigentes: %d   Expiradas: %d   Revocadas: %d   Sin material: %d',
                $cliente['solicitudes'],
                $cliente['vigentes'],
                $cliente['expiradas'],
                $cliente['revocadas'],
                $cliente['sin_material']
            );
            $lineas[] = sprintf(
                'Primera solicitud: %s   Ultima: %s',
                $this->fecha($cliente['primera']),
                $this->fecha($cliente['ultima'])
            );
            $lineas[] = '';
            $lineas[] = sprintf(
                '%-10s  %-26s %-16s %-10s  %s',
                'DIA',
                'CANAL',
                'ESTADO',
                'ENLACE',
                'MOTIVO'
            );
            $lineas[] = str_repeat('-', $ancho);

            foreach ($cliente['detalle'] as $fila) {
                $lineas[] = sprintf(
                    '%-10s  %-26s %-16s %-10s  %s',
                    $fila['fecha_legible'],
                    $this->recortar((string) $fila['storage']['nombre'], 26),
                    $this->estadoLegible((string) $fila['status']),
                    $fila['link'] !== null ? 'sí' : '—',
                    $this->recortar((string) $fila['motivo'], 28)
                );
            }

            $lineas[] = '';
        }

        $lineas[] = str_repeat('-', $ancho);
        $lineas[] = 'DEMANDA POR CANAL (storage de origen)';
        $lineas[] = str_repeat('-', $ancho);
        $lineas[] = sprintf('%-40s %10s %10s', 'CANAL', 'SOLICITUD', 'CLIENTES');

        foreach ($reporte['canales'] as $canal) {
            $lineas[] = sprintf(
                '%-40s %10d %10d',
                $this->recortar((string) $canal['nombre'], 40),
                $canal['solicitudes'],
                $canal['clientes']
            );
        }

        $lineas[] = '';
        $lineas[] = str_repeat('=', $ancho);
        $lineas[] = 'Nota: el enlace vive 2 dias y lo revoca el cliente desde Mis Archivos.';
        $lineas[] = 'El estado mostrado es el efectivo: un enlace vencido se reporta como expirada.';
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

        return "solicitudes-backup-{$desde}-{$hasta}.txt";
    }

    /**
     * Fila de cliente vacía. Los contadores por estado usan el nombre del
     * estado como clave, igual que el vocabulario cerrado del modelo.
     *
     * @return array<string,mixed>
     */
    private function clienteVacio(BackupRequest $request): array
    {
        return [
            'user_id' => (int) $request->user_id,
            'username' => (string) ($request->user?->username ?? '—'),
            // `users` no tiene columna `name`: el nombre visible es `username`.
            'nombre' => (string) ($request->user?->username ?? '—'),
            'email' => (string) ($request->user?->email ?? ''),
            'solicitudes' => 0,
            'vigentes' => 0,
            'expiradas' => 0,
            'revocadas' => 0,
            'sin_material' => 0,
            'primera' => null,
            'ultima' => null,
            'detalle' => [],
        ];
    }

    /**
     * Contador del cliente que corresponde a un estado efectivo.
     */
    private function contadorDe(string $efectivo): string
    {
        return match ($efectivo) {
            BackupRequest::STATUS_READY => 'vigentes',
            BackupRequest::STATUS_EXPIRED => 'expiradas',
            BackupRequest::STATUS_REVOKED => 'revocadas',
            default => 'sin_material',
        };
    }

    /**
     * Detalle de una solicitud para el reporte.
     *
     * @return array<string,mixed>
     */
    private function detalleDe(BackupRequest $request, string $efectivo): array
    {
        return [
            'id' => (int) $request->id,
            'day' => (string) $request->day,
            'fecha_legible' => $this->diaLegible((string) $request->day),
            'storage' => [
                'id' => (int) $request->source_storage_id,
                'nombre' => (string) ($request->sourceStorage?->name ?? '—'),
            ],
            'backup_path' => $request->backup_path,
            'status' => $efectivo,
            'status_message' => $this->statusMessage($efectivo),
            'motivo' => (string) $request->motivo,
            'created_at' => $request->created_at?->toIso8601String(),
            'link' => $this->enlaceDe($request),
        ];
    }

    /**
     * Enlace público de la solicitud, o `null` si el enlace no sirve.
     *
     * Solo se expone cuando la solicitud está VIVA: el enlace de una
     * solicitud expirada o revocada no sirve de nada y no se expone.
     *
     * @return array{public_url:string,expires_at:?string}|null
     */
    private function enlaceDe(BackupRequest $request): ?array
    {
        if (! $request->isLive()) {
            return null;
        }

        $share = $request->relationLoaded('share') ? $request->share : $request->share()->first();

        if ($share === null || $share->token === null) {
            return null;
        }

        return [
            'public_url' => url('/s/' . $share->token),
            'expires_at' => $request->link_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Filtro por estado. Se aplica sobre la columna y la vigencia; el
     * detalle puede mostrar un estado efectivo distinto (una fila `ready`
     * sin share se reporta `unavailable`).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function aplicarEstado($query, string $estado): void
    {
        switch ($estado) {
            case self::FILTRO_VIGENTES:
                $query->where('status', BackupRequest::STATUS_READY)
                    ->where(function ($q) {
                        $q->whereNull('link_expires_at')->orWhere('link_expires_at', '>', now());
                    });
                break;
            case self::FILTRO_EXPIRADAS:
                $query->where('status', BackupRequest::STATUS_READY)
                    ->whereNotNull('link_expires_at')
                    ->where('link_expires_at', '<=', now());
                break;
            case self::FILTRO_REVOCADAS:
                $query->where('status', BackupRequest::STATUS_REVOKED);
                break;
            case self::FILTRO_SIN_MATERIAL:
                $query->where('status', BackupRequest::STATUS_UNAVAILABLE);
                break;
            default:
                break;
        }
    }

    private function estadoValido(string $estado): string
    {
        $validos = [
            self::FILTRO_TODAS,
            self::FILTRO_VIGENTES,
            self::FILTRO_EXPIRADAS,
            self::FILTRO_REVOCADAS,
            self::FILTRO_SIN_MATERIAL,
        ];

        return in_array($estado, $validos, true) ? $estado : self::FILTRO_TODAS;
    }

    private function parseUserId($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $id = (int) $valor;

        return $id > 0 ? $id : null;
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

    /**
     * El día del backup se guarda como `DDMMYYYY` (el nombre de la carpeta
     * en el árbol de producción).
     */
    private function diaLegible(string $day): string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $day, $m) === 1) {
            return "{$m[1]}/{$m[2]}/{$m[3]}";
        }

        return $day;
    }

    private function statusMessage(string $estado): string
    {
        return match ($estado) {
            BackupRequest::STATUS_READY => 'El enlace está disponible.',
            BackupRequest::STATUS_EXPIRED => 'El enlace venció su vigencia.',
            BackupRequest::STATUS_REVOKED => 'El cliente revocó la solicitud.',
            default => 'Ese día no tiene backup disponible.',
        };
    }

    private function estadoLegible(string $estado): string
    {
        return [
            BackupRequest::STATUS_READY => 'vigente',
            BackupRequest::STATUS_EXPIRED => 'expirada',
            BackupRequest::STATUS_REVOKED => 'revocada',
            BackupRequest::STATUS_UNAVAILABLE => 'sin material',
        ][$estado] ?? $estado;
    }

    private function etiquetaEstado(string $estado): string
    {
        return [
            self::FILTRO_TODAS => 'todas las solicitudes',
            self::FILTRO_VIGENTES => 'solo las vigentes',
            self::FILTRO_EXPIRADAS => 'solo las expiradas',
            self::FILTRO_REVOCADAS => 'solo las revocadas',
            self::FILTRO_SIN_MATERIAL => 'solo las que no tienen material',
        ][$estado] ?? 'todas las solicitudes';
    }

    private function periodo(array $reporte): string
    {
        $desde = $reporte['desde'] !== null ? Carbon::parse($reporte['desde'])->format('d/m/Y') : 'sin limite';
        $hasta = $reporte['hasta'] !== null ? Carbon::parse($reporte['hasta'])->format('d/m/Y') : 'hoy';

        return "{$desde} a {$hasta}";
    }

    private function fecha(?string $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        try {
            return Carbon::parse($valor)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $valor;
        }
    }

    private function recortar(string $texto, int $largo): string
    {
        if (mb_strlen($texto) <= $largo) {
            return $texto;
        }

        return mb_substr($texto, 0, $largo - 1) . '…';
    }
}
