<?php

namespace App\Http\Controllers;

use App\Exceptions\PuntualRequestException;
use App\Models\Medio;
use App\Models\SolicitudPuntual;
use App\Models\User;
use App\Services\MisArchivos\MedioCatalogService;
use App\Services\MisArchivos\PuntualReportService;
use App\Services\MisArchivos\PuntualRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

/**
 * Módulo de Solicitud Puntual de TV y Radio (cara del cliente).
 *
 * El cliente pide una franja horaria o el día completo de un medio que NO
 * tiene asignado, y recibe enlaces públicos de solo lectura con vigencia.
 *
 * El listado es el COMPLEMENTO de lo que el usuario tiene asignado: lo que
 * ya tiene no aparece, porque para eso está Mis Archivos.
 *
 * La AUTORIZACIÓN de la entrega vive en `PuntualRequestService`, no aquí:
 * este controller traduce HTTP a dominio y el servicio decide. Es el mismo
 * patrón que el módulo de backup, y por el mismo motivo: el cliente no
 * tiene permiso de lectura sobre el medio que pide, así que la ruta normal
 * de compartidos daría 403 y debilitarla abriría una escalada.
 */
class SolicitudPuntualController extends Controller
{
    public function __construct(
        private readonly MedioCatalogService $catalog,
        private readonly PuntualRequestService $solicitudes,
        private readonly PuntualReportService $reportes,
    ) {
    }

    /**
     * Página del módulo.
     */
    public function index(): View
    {
        return view('solicitud-puntual.index');
    }

    /**
     * Catálogo de medios que el usuario NO tiene asignados.
     */
    public function catalogo(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $busqueda = trim((string) $request->query('q', ''));
        $tipo = (string) $request->query('kind', '');

        $medios = $this->catalog->availableFor($user)
            ->filter(function (Medio $medio) use ($busqueda, $tipo) {
                if ($tipo !== '' && $medio->media_kind !== $tipo) {
                    return false;
                }

                if ($busqueda === '') {
                    return true;
                }

                $candidatos = [
                    (string) $medio->name,
                    (string) ($medio->region ?? ''),
                    (string) $medio->subpath,
                    (string) ($medio->storage?->name ?? ''),
                ];

                foreach ($candidatos as $candidato) {
                    if (stripos($candidato, $busqueda) !== false) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        return response()->json([
            'medios' => $medios->map(fn (Medio $medio) => [
                'id' => (int) $medio->id,
                'name' => (string) $medio->name,
                'media_kind' => (string) $medio->media_kind,
                'region' => $medio->region,
                'storage' => (string) ($medio->storage?->name ?? ''),
            ])->values(),
            'total' => $medios->count(),
        ]);
    }

    /**
     * Ficha de cobertura y comparación de modos, antes de confirmar.
     */
    public function estimate(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'medio_id' => ['required', 'integer', 'exists:medios,id'],
            'fecha' => ['required', 'string', 'max:10'],
            'desde' => ['required', 'string', 'max:8'],
            'hasta' => ['required', 'string', 'max:8'],
            'modo' => ['nullable', Rule::in(SolicitudPuntual::MODOS)],
        ]);

        $medio = Medio::find($validated['medio_id']);

        if ($medio === null) {
            return response()->json(['error' => 'El medio no existe.'], 404);
        }

        try {
            $estimate = $this->solicitudes->estimate(
                $user,
                $medio,
                (string) $validated['fecha'],
                (string) $validated['desde'],
                (string) $validated['hasta'],
                (string) ($validated['modo'] ?? SolicitudPuntual::MODO_FRANJA),
            );
        } catch (PuntualRequestException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status);
        }

        return response()->json($this->stripInternalIds($estimate));
    }

    /**
     * Quita de la respuesta los identificadores internos del material.
     *
     * La interfaz solo usa el instante de emisión, el nombre y el tamaño.
     * El `file_id` y la ruta física NO viajan: son el oráculo que convierte
     * un id adivinable en una ruta confirmada de material que el cliente no
     * tiene asignado. El servicio los necesita para crear los enlaces, pero
     * eso ocurre dentro del servidor.
     *
     * @param  array<string,mixed>  $estimate
     * @return array<string,mixed>
     */
    private function stripInternalIds(array $estimate): array
    {
        if (isset($estimate['cobertura']['completos']) && is_array($estimate['cobertura']['completos'])) {
            $estimate['cobertura']['completos'] = array_map(function (array $item) {
                unset($item['file_id'], $item['path']);

                return $item;
            }, $estimate['cobertura']['completos']);
        }

        return $estimate;
    }

    /**
     * Crea la solicitud y devuelve el registro con sus enlaces.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'medio_id' => ['required', 'integer', 'exists:medios,id'],
            'fecha' => ['required', 'string', 'max:10'],
            'desde' => ['required', 'string', 'max:8'],
            'hasta' => ['required', 'string', 'max:8'],
            'motivo' => ['required', 'string', 'max:1000'],
            'modo' => ['nullable', Rule::in(SolicitudPuntual::MODOS)],
        ]);

        $medio = Medio::find($validated['medio_id']);

        if ($medio === null) {
            return response()->json(['error' => 'El medio no existe.'], 404);
        }

        try {
            $solicitud = $this->solicitudes->create(
                $user,
                $medio,
                (string) $validated['fecha'],
                (string) $validated['desde'],
                (string) $validated['hasta'],
                (string) $validated['motivo'],
                (string) ($validated['modo'] ?? SolicitudPuntual::MODO_FRANJA),
            );
        } catch (PuntualRequestException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status);
        }

        return response()->json(
            ['solicitud' => $this->solicitudes->payload($solicitud)],
            201,
        );
    }

    /**
     * Historial de solicitudes del usuario.
     */
    public function historial(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $filtros = [
            'q' => (string) $request->query('q', ''),
            'estado' => (string) $request->query('estado', ''),
            'sort' => (string) $request->query('sort', 'creada'),
            'dir' => (string) $request->query('dir', 'desc'),
            'page' => (int) $request->query('page', 1),
            'por_pagina' => (int) $request->query('por_pagina', (int) config('puntual.por_pagina', 20)),
        ];

        return response()->json($this->solicitudes->listFor($user, $filtros));
    }

    /**
     * Revoca una solicitud propia.
     */
    public function destroy(Request $request, SolicitudPuntual $solicitud): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $revocada = $this->solicitudes->revoke($user, $solicitud);
        } catch (PuntualRequestException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status);
        }

        return response()->json([
            'solicitud' => $this->solicitudes->payload($revocada),
        ]);
    }

    // ------------------------------------------------------------------
    // Control administrativo (solo admin)
    // ------------------------------------------------------------------

    /**
     * Reporte de solicitudes por cliente.
     *
     * Es la pestaña que el cliente NO tiene: el operador la usa para llevar
     * el control manual de lo que pidió cada uno.
     */
    public function control(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $filtros = $this->filtrosReporte($request);

        return response()->json($this->reportes->porCliente($filtros));
    }

    /**
     * Exportación del reporte en texto plano.
     *
     * Se descarga como adjunto para imprimir o archivar.
     */
    public function exportar(Request $request)
    {
        $user = $this->getUser();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $reporte = $this->reportes->porCliente($this->filtrosReporte($request));
        $contenido = $this->reportes->toTxt($reporte);
        $nombre = $this->reportes->nombreArchivo($reporte);

        return response($contenido, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Lista de clientes con solicitudes, para el selector del reporte.
     */
    public function clientes(): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null || ! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientes = \App\Models\SolicitudPuntual::query()
            ->join('users', 'users.id', '=', 'solicitud_puntual.user_id')
            ->select('users.id', 'users.username', 'users.name')
            ->distinct()
            ->orderBy('users.username')
            ->get()
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'username' => (string) $c->username,
                'nombre' => (string) ($c->name ?? $c->username),
            ])
            ->values();

        return response()->json(['clientes' => $clientes]);
    }

    /**
     * @return array{desde:?string,hasta:?string,estado:string,user_id:?int}
     */
    private function filtrosReporte(Request $request): array
    {
        return [
            'desde' => $request->query('desde') !== null ? (string) $request->query('desde') : null,
            'hasta' => $request->query('hasta') !== null ? (string) $request->query('hasta') : null,
            'estado' => (string) $request->query('estado', 'todas'),
            'user_id' => $request->query('user_id') !== null ? (int) $request->query('user_id') : null,
        ];
    }

    /**
     * El usuario de la sesión.
     *
     * SIEMPRE `session('user_id')`, nunca `auth()->user()`: la sesión de
     * Tcloud es propia y `auth()` no refleja el usuario conectado.
     */
    private function getUser(): ?User
    {
        $userId = Session::get('user_id');

        return $userId ? User::find($userId) : null;
    }
}
