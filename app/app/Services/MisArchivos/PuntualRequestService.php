<?php

namespace App\Services\MisArchivos;

use App\Exceptions\PuntualRequestException;
use App\Models\Medio;
use App\Models\Share;
use App\Models\SolicitudPuntual;
use App\Models\SolicitudPuntualItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ciclo de vida de la solicitud puntual de TV y Radio.
 *
 * Decisiones que este servicio sostiene (spec `puntual-request-workflow`):
 *
 *  1. La SOLICITUD es la fuente de verdad, no el share. `shares.file_id` es
 *     FK `ON DELETE CASCADE`: cuando el prune borra la fila de `files`, el
 *     share muere solo. El control contable tiene que sobrevivir a eso, y
 *     por eso el detalle por archivo guarda el snapshot de nombre, ruta y
 *     bytes.
 *
 *  2. El share lo crea ESTE servicio, no `ShareController::store()`. El
 *     cliente no tiene permiso de lectura sobre el medio que pide (es un
 *     medio que NO tiene asignado): la ruta normal daría 403. Debilitar
 *     `store()` para admitir este caso abriría una escalada sobre todos
 *     los enlaces de la plataforma.
 *
 *  3. Dos modos de entrega:
 *       - `franja`: un enlace por archivo exacto que cubre el rango. NUNCA
 *         un enlace a la carpeta del día: esa carpeta contiene las 96
 *         capturas del día (18 GB en TV) y el cliente pidió una hora.
 *       - `dia`: un único enlace sobre la carpeta del día.
 *
 *  4. Los enlaces tienen vigencia PROPIA (3 días por defecto) y no admiten
 *     quedar sin vencimiento: el guard de compartidos rechaza editar o
 *     borrar un enlace gestionado, y la vía para desactivarlo es revocar la
 *     solicitud.
 *
 *  5. Idempotencia por índice único parcial: dos POST simultáneos para el
 *     mismo (usuario, medio, fecha, rango) no pueden mintear dos juegos de
 *     enlaces. El que pierde la carrera devuelve la solicitud que ganó.
 */
class PuntualRequestService
{
    public function __construct(
        private readonly PuntualResolver $resolver,
        private readonly MedioCatalogService $catalog,
    ) {
    }

    // ------------------------------------------------------------------
    // Previsualización
    // ------------------------------------------------------------------

    /**
     * Ficha de cobertura y comparación de modos, antes de confirmar.
     *
     * @return array<string,mixed>
     */
    public function estimate(
        User $user,
        Medio $medio,
        string $fecha,
        string $desde,
        string $hasta,
        string $modo = SolicitudPuntual::MODO_FRANJA,
    ): array {
        $date = $this->parseDate($fecha);
        $rango = $this->parseRange($desde, $hasta);

        // La previsualización NO materializa el catálogo por su cuenta: si el
        // día no está indexado se dice explícitamente. La materialización
        // ocurre al confirmar (ver `create()`), que es un acto deliberado.
        $cobertura = $this->resolver->coverage($medio, $date, $rango['desde'], $rango['hasta'], false);

        if ($cobertura['estado'] === 'unavailable' && $this->resolver->dayExistsOnDisk($medio, $date)) {
            $enDisco = $this->resolver->countDayFilesOnDisk($medio, $date);

            $cobertura['estado'] = 'material_no_indexado';
            $cobertura['archivos'] = $enDisco;
            $cobertura['mensajes'][] = 'El día tiene grabaciones en disco, pero el índice todavía no las '
                . 'incluyó. Al confirmar la solicitud se indexan y se generan los enlaces.';
        }

        $dia = $this->daySummary($medio, $date);

        $umbral = (int) config('puntual.umbral_dia_minutos', 120);
        $recomendado = $rango['minutos'] > $umbral ? SolicitudPuntual::MODO_DIA : SolicitudPuntual::MODO_FRANJA;

        return [
            'medio' => [
                'id' => (int) $medio->id,
                'name' => (string) $medio->name,
                'media_kind' => (string) $medio->media_kind,
                'region' => $medio->region,
                'storage' => (string) ($medio->storage?->name ?? ''),
            ],
            'fecha' => $date->format('d/m/Y'),
            'rango' => $rango,
            'modo' => $modo,
            'cobertura' => $cobertura,
            'dia' => $dia,
            'comparacion' => [
                'umbral_minutos' => $umbral,
                'recomendado' => $recomendado,
                'franja' => [
                    'archivos' => $cobertura['archivos'],
                    'bytes' => $cobertura['bytes'],
                    'links' => $cobertura['archivos'],
                    'minutos' => $rango['minutos'],
                ],
                'dia' => [
                    'archivos' => $dia['archivos'],
                    'bytes' => $dia['bytes'],
                    'links' => $dia['disponible'] ? 1 : 0,
                    'minutos' => 24 * 60,
                ],
            ],
            'dias_disponibles' => array_slice($this->resolver->availableDays($medio), 0, 60),
        ];
    }

    // ------------------------------------------------------------------
    // Alta
    // ------------------------------------------------------------------

    /**
     * Atiende una solicitud: valida, crea los enlaces y registra el
     * histórico contable.
     *
     * @throws PuntualRequestException
     */
    public function create(
        User $user,
        Medio $medio,
        string $fecha,
        string $desde,
        string $hasta,
        string $motivo = '',
        string $modo = SolicitudPuntual::MODO_FRANJA,
    ): SolicitudPuntual {
        // El motivo es OPCIONAL: el operador lo escribe cuando le sirve para
        // el control manual, pero no es un requisito para pedir. Si viene,
        // se guarda; si no, queda vacío.
        $motivo = trim($motivo);

        if ($motivo !== '' && mb_strlen($motivo) > 1000) {
            throw new PuntualRequestException(
                'motivo_invalido',
                'El motivo no puede superar los 1000 caracteres.',
            );
        }

        $this->assertSolicitable($user, $medio);

        $date = $this->parseDate($fecha);

        // En modo DÍA el rango es el día completo: lo que se pide es el día,
        // no una franja. El horario recibido se ignora a propósito.
        $rango = $modo === SolicitudPuntual::MODO_DIA
            ? ['desde' => '00:00:00', 'hasta' => '23:59:59', 'minutos' => 24 * 60]
            : $this->parseRange($desde, $hasta);

        $maximo = (int) config('puntual.max_franja_minutos', 720);

        if ($modo === SolicitudPuntual::MODO_FRANJA && $rango['minutos'] > $maximo) {
            throw new PuntualRequestException(
                'franja_muy_larga',
                "Una franja no puede superar {$maximo} minutos. Pide el día completo.",
            );
        }

        $cobertura = $this->resolver->coverage($medio, $date, $rango['desde'], $rango['hasta']);

        if ($cobertura['estado'] === 'unavailable' || $cobertura['archivos'] === 0) {
            throw new PuntualRequestException(
                'material_no_disponible',
                'No hay material de ese medio para esa fecha y horario.',
                404,
            );
        }

        if ($modo === SolicitudPuntual::MODO_DIA) {
            $dia = $this->daySummary($medio, $date);

            if (! $dia['disponible']) {
                throw new PuntualRequestException(
                    'material_no_disponible',
                    'No hay carpeta del día para ese medio.',
                    404,
                );
            }
        }

        $expiresAt = $this->validityUntil();
        $status = $cobertura['estado'] === 'parcial'
            ? SolicitudPuntual::STATUS_PARTIAL
            : SolicitudPuntual::STATUS_READY;

        try {
            $solicitud = DB::transaction(function () use (
                $user,
                $medio,
                $date,
                $rango,
                $motivo,
                $modo,
                $cobertura,
                $status,
                $expiresAt,
            ) {
                $share = null;
                $items = [];

                if ($modo === SolicitudPuntual::MODO_DIA) {
                    $folder = $this->resolver->dayFolder($medio, $date);

                    if ($folder === null) {
                        throw new PuntualRequestException(
                            'material_no_disponible',
                            'No hay carpeta del día para ese medio.',
                            404,
                        );
                    }

                    $share = $this->createShare($user, $folder->id, (string) $folder->path, $expiresAt);
                } else {
                    foreach ($cobertura['completos'] as $entregable) {
                        $slotShare = $this->createShare(
                            $user,
                            (int) $entregable['file_id'],
                            (string) $entregable['path'],
                            $expiresAt,
                        );

                        $items[] = [
                            'file_id' => (int) $entregable['file_id'],
                            'share_id' => (int) $slotShare->id,
                            'slot_inicio' => $date->copy()->setTimeFromTimeString((string) $entregable['inicio']),
                            'slot_fin' => $date->copy()->setTimeFromTimeString((string) $entregable['fin']),
                            'capture_inicio' => $date->copy()->setTimeFromTimeString((string) $entregable['inicio']),
                            'capture_fin' => $entregable['fin_real'] !== null
                                ? $date->copy()->setTimeFromTimeString((string) $entregable['fin_real'])
                                : null,
                            'name' => (string) $entregable['name'],
                            'path' => (string) $entregable['path'],
                            'size' => (int) $entregable['size'],
                        ];
                    }
                }

                $solicitud = SolicitudPuntual::create([
                    'user_id' => $user->id,
                    'medio_id' => $medio->id,
                    'share_id' => $share?->id,
                    'fecha' => $date->format('Y-m-d'),
                    'modo' => $modo,
                    'hora_inicio' => $rango['desde'],
                    'hora_fin' => $rango['hasta'],
                    'duracion_minutos' => $rango['minutos'],
                    'status' => $status,
                    'motivo' => $motivo,
                    'cobertura' => [
                        'estado' => $cobertura['estado'],
                        'pct' => $cobertura['cobertura_pct'],
                        'step' => $cobertura['step'],
                        'huecos' => $cobertura['huecos'],
                        'mensajes' => $cobertura['mensajes'],
                    ],
                    'archivos_total' => $modo === SolicitudPuntual::MODO_DIA
                        ? $this->daySummary($medio, $date)['archivos']
                        : $cobertura['archivos'],
                    'bytes_total' => $modo === SolicitudPuntual::MODO_DIA
                        ? $this->daySummary($medio, $date)['bytes']
                        : $cobertura['bytes'],
                    'links_total' => $modo === SolicitudPuntual::MODO_DIA ? 1 : count($items),
                    'link_expires_at' => $expiresAt,
                ]);

                foreach ($items as $item) {
                    $item['solicitud_id'] = $solicitud->id;
                    SolicitudPuntualItem::create($item);
                }

                return $solicitud;
            });
        } catch (QueryException $e) {
            // Índice único parcial `(user_id, medio_id, fecha, hora_inicio,
            // hora_fin) WHERE status IN ('ready','partial')`: dos POST
            // simultáneos para el mismo rango no pueden mintear dos juegos de
            // enlaces públicos. El que pierde la carrera devuelve la
            // solicitud que ganó.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existente = SolicitudPuntual::query()
                ->where('user_id', $user->id)
                ->where('medio_id', $medio->id)
                ->where('fecha', $date->format('Y-m-d'))
                ->where('hora_inicio', $rango['desde'])
                ->where('hora_fin', $rango['hasta'])
                ->whereIn('status', SolicitudPuntual::STATUSES_VIVAS)
                ->first();

            if ($existente === null) {
                throw $e;
            }

            return $existente;
        }

        Log::info('puntual.request.created', [
            'solicitud_id' => $solicitud->id,
            'actor_id' => $user->id,
            'medio_id' => $medio->id,
            'modo' => $modo,
            'archivos' => $solicitud->archivos_total,
            'bytes' => $solicitud->bytes_total,
        ]);

        return $solicitud->refresh();
    }

    // ------------------------------------------------------------------
    // Revocación
    // ------------------------------------------------------------------

    /**
     * ¿Este medio se puede pedir por este usuario?
     *
     * Tres reglas, las tres del contrato:
     *  1. El medio tiene que estar activo (un medio dado de baja no se
     *     ofrece ni se sirve).
     *  2. El medio tiene que NO estar asignado al solicitante: el módulo
     *     es para el complemento. Pedir lo que ya se tiene es ruido para
     *     el control contable.
     *  3. El medio no puede vivir en el árbol de producción del backup.
     *
     * El catálogo ya filtra por las dos primeras, pero un POST directo se
     * salta el filtro: aquí se vuelve a comprobar.
     */
    private function assertSolicitable(User $user, Medio $medio): void
    {
        if (! $medio->activo) {
            throw new PuntualRequestException(
                'medio_inactivo',
                'Ese medio no está disponible para solicitudes.',
            );
        }

        $storage = $medio->storage;

        if ($storage !== null && $this->catalog->isProductionTree($storage)) {
            throw new PuntualRequestException(
                'medio_no_solicitable',
                'Ese medio no se puede pedir por este módulo.',
            );
        }

        // El admin tiene acceso a todo: el filtro de complemento no le
        // aplica (ver `MedioCatalogService::availableFor()`).
        if ($user->isAdmin()) {
            return;
        }

        $asignados = $this->catalog->assignedBasePaths($user);

        if ($asignados !== [] && $this->catalog->isAssignedTo($medio, $asignados)) {
            throw new PuntualRequestException(
                'medio_asignado',
                'Ese medio ya lo tienes asignado. Para ese material usa Mis Archivos.',
            );
        }
    }

    /**
     * Revoca una solicitud: sus enlaces dejan de servir el material.
     *
     * @throws PuntualRequestException
     */
    public function revoke(User $user, SolicitudPuntual $solicitud): SolicitudPuntual
    {
        // Revocar es una decisión de CONTROL CONTABLE, no una acción del
        // cliente. Un cliente podía pedir el material, descargarlo y revocar
        // el enlace para que no quedara rastro del uso; el registro contable
        // quedaba igual pero el operador perdía la evidencia del enlace.
        // Ahora solo el admin revoca.
        if (! $user->isAdmin()) {
            throw new PuntualRequestException(
                'no_permission',
                'Solo un administrador puede revocar una solicitud.',
                403,
            );
        }

        if ($solicitud->status === SolicitudPuntual::STATUS_REVOKED) {
            return $solicitud;
        }

        $shareIds = $solicitud->items()->whereNotNull('share_id')->pluck('share_id')->all();

        if ($solicitud->share_id !== null) {
            $shareIds[] = $solicitud->share_id;
        }

        foreach (Share::query()->whereIn('id', $shareIds)->get() as $share) {
            // La vista pública cachea los escalares del enlace una hora:
            // sin invalidarla, el enlace seguiría sirviendo el material.
            Cache::forget("share:meta:{$share->token}");
            $share->delete();
        }

        $solicitud->update(['status' => SolicitudPuntual::STATUS_REVOKED]);

        Log::info('puntual.request.revoked', [
            'solicitud_id' => $solicitud->id,
            'actor_id' => $user->id,
        ]);

        return $solicitud->refresh();
    }

    // ------------------------------------------------------------------
    // Historial
    // ------------------------------------------------------------------

    /**
     * Historial del solicitante: paginado, buscable y ordenable.
     *
     * @param array{q?:string,estado?:string,sort?:string,dir?:string,page?:int,por_pagina?:int} $filtros
     *
     * @return array<string,mixed>
     */
    public function listFor(User $user, array $filtros = []): array
    {
        $query = SolicitudPuntual::query()
            ->where('user_id', $user->id)
            ->with(['medio.storage'])
            ->withCount('items');

        $busqueda = trim((string) ($filtros['q'] ?? ''));

        if ($busqueda !== '') {
            $query->where(function ($q) use ($busqueda) {
                $q->whereHas('medio', function ($mq) use ($busqueda) {
                    $mq->where('name', 'ilike', '%' . $busqueda . '%')
                        ->orWhere('region', 'ilike', '%' . $busqueda . '%');
                })->orWhere('motivo', 'ilike', '%' . $busqueda . '%');
            });
        }

        $estado = (string) ($filtros['estado'] ?? '');

        if ($estado === 'expired') {
            $query->whereIn('status', SolicitudPuntual::STATUSES_VIVAS)
                ->whereNotNull('link_expires_at')
                ->where('link_expires_at', '<=', now());
        } elseif ($estado === 'ready') {
            $query->whereIn('status', SolicitudPuntual::STATUSES_VIVAS)
                ->where(function ($q) {
                    $q->whereNull('link_expires_at')
                        ->orWhere('link_expires_at', '>', now());
                });
        } elseif ($estado === 'unavailable') {
            $query->where('status', SolicitudPuntual::STATUS_UNAVAILABLE);
        } elseif ($estado === 'revoked') {
            $query->where('status', SolicitudPuntual::STATUS_REVOKED);
        }

        $columnas = [
            'fecha' => 'fecha',
            'medio' => 'medio_id',
            'minutos' => 'duracion_minutos',
            'bytes' => 'bytes_total',
            'archivos' => 'archivos_total',
            'estado' => 'status',
            'creada' => 'created_at',
        ];

        $sort = (string) ($filtros['sort'] ?? 'creada');
        $columna = $columnas[$sort] ?? 'created_at';
        $dir = strtolower((string) ($filtros['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $total = (clone $query)->count();

        $porPagina = (int) ($filtros['por_pagina'] ?? config('puntual.por_pagina', 20));
        $porPagina = max(1, min($porPagina, (int) config('puntual.max_por_pagina', 100)));
        $pagina = max(1, (int) ($filtros['page'] ?? 1));

        $rows = $query
            ->orderBy($columna, $dir)
            ->orderBy('id', 'desc')
            ->forPage($pagina, $porPagina)
            ->get();

        return [
            'data' => $rows->map(fn (SolicitudPuntual $s) => $this->payload($s))->all(),
            'total' => $total,
            'page' => $pagina,
            'por_pagina' => $porPagina,
            'pages' => (int) ceil($total / $porPagina),
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    /**
     * Un registro listo para la interfaz.
     *
     * @return array<string,mixed>
     */
    public function payload(SolicitudPuntual $solicitud): array
    {
        $solicitud->loadMissing(['medio.storage', 'items.share']);

        $links = [];

        if ($solicitud->share !== null && $solicitud->estado() !== 'revoked') {
            $links[] = [
                'nombre' => 'Carpeta del día',
                'url' => url('/s/' . $solicitud->share->token),
            ];
        }

        foreach ($solicitud->items as $item) {
            if ($item->share === null) {
                continue;
            }

            $links[] = [
                'nombre' => (string) $item->name,
                'url' => url('/s/' . $item->share->token),
                'bytes' => (int) $item->size,
                'inicio' => $item->slot_inicio?->format('H:i:s'),
            ];
        }

        return [
            'id' => (int) $solicitud->id,
            'fecha' => (string) $solicitud->fecha,
            'fecha_legible' => \Illuminate\Support\Carbon::parse((string) $solicitud->fecha)->format('d/m/Y'),
            'hora_inicio' => $this->timeOf((string) $solicitud->hora_inicio),
            'hora_fin' => $this->timeOf((string) $solicitud->hora_fin),
            'duracion_minutos' => (int) $solicitud->duracion_minutos,
            'modo' => (string) $solicitud->modo,
            'status' => $solicitud->estado(),
            'motivo' => (string) $solicitud->motivo,
            'medio' => [
                'id' => (int) ($solicitud->medio_id ?? 0),
                'name' => (string) ($solicitud->medio?->name ?? ''),
                'media_kind' => (string) ($solicitud->medio?->media_kind ?? ''),
                'region' => $solicitud->medio?->region,
                'storage' => (string) ($solicitud->medio?->storage?->name ?? ''),
            ],
            'archivos_total' => (int) $solicitud->archivos_total,
            'bytes_total' => (int) $solicitud->bytes_total,
            'links_total' => (int) $solicitud->links_total,
            'link_expires_at' => $solicitud->link_expires_at?->toIso8601String(),
            'cobertura' => $solicitud->cobertura,
            'links' => $links,
            'created_at' => $solicitud->created_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Resumen del día completo de un medio.
     *
     * @return array{disponible:bool,archivos:int,bytes:int}
     */
    private function daySummary(Medio $medio, Carbon $date): array
    {
        $folder = $this->resolver->dayFolder($medio, $date);

        if ($folder === null) {
            return ['disponible' => false, 'archivos' => 0, 'bytes' => 0];
        }

        $files = $this->resolver->filesForDay($medio, $date);
        $bytes = $files->sum(fn ($file) => (int) ($file->size ?? 0));

        return [
            'disponible' => true,
            'archivos' => $files->count(),
            'bytes' => (int) $bytes,
        ];
    }

    /**
     * Crea un enlace público de solo lectura con la vigencia del flujo.
     */
    private function createShare(User $user, int $fileId, string $pathSnapshot, Carbon $expiresAt): Share
    {
        return Share::create([
            'file_id' => $fileId,
            'path_snapshot' => $pathSnapshot,
            'token' => Str::random(32),
            'password_hash' => null,
            'expires_at' => $expiresAt,
            'permissions' => 'read',
            'created_by' => $user->id,
        ]);
    }

    /**
     * Instante de expiración: vigencia configurada (3 días por defecto)
     * desde ahora.
     */
    private function validityUntil(): Carbon
    {
        $days = max(1, (int) config('puntual.link_validity_days', 3));

        return now()->addDays($days);
    }

    private function parseDate(string $fecha): Carbon
    {
        try {
            $date = Carbon::createFromFormat('d/m/Y', trim($fecha));

            if (! $date) {
                throw new PuntualRequestException('fecha_invalida', 'La fecha no es válida.');
            }
        } catch (\Throwable $e) {
            throw new PuntualRequestException('fecha_invalida', 'La fecha no es válida.');
        }

        return $date->startOfDay();
    }

    /**
     * @return array{desde:string,hasta:string,minutos:int}
     */
    private function parseRange(string $desde, string $hasta): array
    {
        $desde = $this->normalizeTime($desde);
        $hasta = $this->normalizeTime($hasta);

        try {
            $inicio = Carbon::createFromFormat('H:i:s', $desde);
            $fin = Carbon::createFromFormat('H:i:s', $hasta);
        } catch (\Throwable $e) {
            throw new PuntualRequestException('rango_invalido', 'El rango horario no es válido.');
        }

        if (! $inicio || ! $fin) {
            throw new PuntualRequestException('rango_invalido', 'El rango horario no es válido.');
        }

        $minutos = (int) round(($fin->getTimestamp() - $inicio->getTimestamp()) / 60);

        if ($minutos <= 0) {
            throw new PuntualRequestException(
                'rango_invalido',
                'La hora de fin tiene que ser posterior a la de inicio.',
            );
        }

        return ['desde' => $desde, 'hasta' => $hasta, 'minutos' => $minutos];
    }

    /**
     * Acepta `H:i`, `H:i:s` y `H:i:s.u`; devuelve siempre `H:i:s`.
     */
    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        // Hora de 00 a 23: Carbon normaliza `24:00` a la medianoche del
        // día siguiente y `25:00` revienta la columna `time` de PostgreSQL.
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
            $hora = (int) $m[1];
            $minuto = (int) $m[2];
            $segundo = (int) ($m[3] ?? 0);

            if ($hora > 23 || $minuto > 59 || $segundo > 59) {
                throw new PuntualRequestException('rango_invalido', 'El rango horario no es válido.');
            }

            return sprintf('%02d:%02d:%02d', $hora, $minuto, $segundo);
        }

        throw new PuntualRequestException('rango_invalido', 'El rango horario no es válido.');
    }

    /**
     * `H:i:s` (o `H:i:s.u`) → `H:i:s`.
     */
    private function timeOf(string $time): string
    {
        return $this->normalizeTime($time);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}
