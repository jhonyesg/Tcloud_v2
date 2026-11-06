<?php

namespace App\Http\Controllers\GrabacionesPuntuales;

use App\Http\Controllers\Controller;
use App\Models\Grabador;
use App\Models\Canal;
use App\Models\User;
use App\Services\GrabacionesPuntuales\AdopcionRemotaService;
use App\Services\GrabacionesPuntuales\CupoCanalesService;
use App\Services\GrabacionesPuntuales\TcloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class CanalController extends Controller
{
    private TcloudApiService $apiService;

    private CupoCanalesService $cupo;

    private AdopcionRemotaService $adopcion;

    public function __construct(
        TcloudApiService $apiService,
        CupoCanalesService $cupo,
        AdopcionRemotaService $adopcion
    ) {
        $this->apiService = $apiService;
        $this->cupo = $cupo;
        $this->adopcion = $adopcion;
    }

    private function getUser(): ?\App\Models\User
    {
        $userId = Session::get('user_id');
        return $userId ? \App\Models\User::find($userId) : null;
    }

    public function index()
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        if ($user->isAdmin()) {
            $canales = Canal::with(['grabador', 'usuario'])->get();
        } else {
            $canales = Canal::where('usuario_id', $user->id)
                ->with('grabador')
                ->get();
        }

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json($canales);
        }

        return view('grabaciones_puntuales.canales.index', compact('canales', 'user'));
    }

    public function create()
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        $grabadores = $user->isAdmin()
            ? Grabador::where('activo', true)->get()
            : $user->grabadores()->where('activo', true)->get();

        $asignaciones = [];
        foreach ($grabadores as $grabador) {
            if ($user->isAdmin()) {
                $usados = Canal::where('grabador_id', $grabador->id)
                    ->where('usuario_id', $user->id)
                    ->count();

                $asignaciones[$grabador->id] = [
                    'limite' => 999,
                    'creados' => $usados,
                    'disponibles' => 999,
                ];
                continue;
            }

            $resumen = $this->cupo->resumen($grabador->id, $user->id);

            $asignaciones[$grabador->id] = $resumen === null
                ? ['limite' => 0, 'creados' => 0, 'disponibles' => 0]
                : [
                    'limite' => $resumen['limite'],
                    'creados' => $resumen['usados'],
                    'disponibles' => $resumen['disponibles'],
                ];
        }

        return view('grabaciones_puntuales.canales.create', compact('grabadores', 'asignaciones'));
    }

    public function store(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        $request->validate([
            'grabador_id' => 'required|exists:grabadores,id',
            'slot_nombre' => 'required|string|max:50',
        ]);

        $grabador = Grabador::find($request->grabador_id);

        if (!$grabador) {
            return back()->with('error', 'El grabador seleccionado no existe')->withInput();
        }

        $slotNombre = trim((string) $request->slot_nombre);

        // Chequeo de cupo + INSERT en una sola transacción con la fila del
        // pivote bloqueada: dos POST concurrentes del mismo usuario no pueden
        // exceder el límite por carrera.
        try {
            $canal = DB::transaction(function () use ($user, $grabador, $slotNombre) {
                $resumen = null;

                if (!$user->isAdmin()) {
                    $resumen = $this->cupo->resumenBloqueado($grabador->id, $user->id);

                    if ($resumen === null) {
                        throw new \RuntimeException('sin_asignacion');
                    }

                    if ($resumen['disponibles'] <= 0) {
                        throw new \RuntimeException(sprintf(
                            'cupo_alcanzado:%d:%d',
                            $resumen['limite'],
                            $resumen['usados']
                        ));
                    }
                }

                $yaExiste = Canal::where('grabador_id', $grabador->id)
                    ->where('slot_nombre', $slotNombre)
                    ->lockForUpdate()
                    ->exists();

                if ($yaExiste) {
                    throw new \RuntimeException('slot_duplicado');
                }

                $rutaBase = $resumen['ruta_base'] ?? null;
                if ($rutaBase === null && !$user->isAdmin()) {
                    $rutaBase = DB::table('grabador_usuario')
                        ->where('grabador_id', $grabador->id)
                        ->where('user_id', $user->id)
                        ->value('ruta_base');
                }

                return Canal::create([
                    'grabador_id' => $grabador->id,
                    'usuario_id' => $user->id,
                    'slot_nombre' => $slotNombre,
                    'ruta_destino' => $rutaBase ? rtrim($rutaBase, '/') . '/' . $slotNombre : null,
                    'activo' => true,
                ]);
            });
        } catch (\RuntimeException $e) {
            $motivo = $e->getMessage();

            if ($motivo === 'sin_asignacion') {
                return back()->with('error', 'No tienes acceso a este grabador')->withInput();
            }

            if ($motivo === 'slot_duplicado') {
                return back()->with('error', 'Ya existe un canal con ese nombre en este grabador')->withInput();
            }

            if (str_starts_with($motivo, 'cupo_alcanzado:')) {
                [, $limite, $usados] = explode(':', $motivo);

                return back()->with(
                    'error',
                    "Has alcanzado tu límite de {$limite} canales ({$usados} en uso)"
                )->withInput();
            }

            throw $e;
        }

        $resultado = $this->apiService->crearCanal($grabador, $canal);

        if (!$resultado['success'] && $this->esErrorCodigoDuplicado($resultado['error'] ?? '')) {
            $codigo = $this->generarCodigoLocal($slotNombre);
            $remoto = $this->apiService->buscarPorCodigo($grabador, $codigo);
            if ($remoto && !empty($remoto['id'])) {
                $canal->update([
                    'api_canal_id' => (int) $remoto['id'],
                    'link_origen' => $canal->link_origen ?: ($remoto['link_origen'] ?? null),
                    'detalle' => $canal->detalle ?: ($remoto['detalle'] ?? null),
                    'ruta_destino' => $canal->ruta_destino ?: ($remoto['ruta_descarga'] ?? $canal->ruta_destino),
                ]);
                return redirect()->route('canales.index')
                    ->with('success', "Canal '{$canal->slot_nombre}' creado y vinculado al canal existente en el grabador (ID {$remoto['id']})");
            }
        }

        if (!$resultado['success']) {
            return back()->with('warning', "Canal guardado localmente, pero no se pudo registrar en el grabador: " . ($resultado['error'] ?? 'Error desconocido'));
        }

        return redirect()->route('canales.index')
            ->with('success', 'Canal creado exitosamente');
    }

    private function generarCodigoLocal(string $slotNombre): string
    {
        return strtolower(str_replace([' ', '_'], '_', $slotNombre));
    }

    private function esErrorCodigoDuplicado(string $error): bool
    {
        return stripos($error, 'ya existe') !== false
            || stripos($error, 'código') !== false && stripos($error, 'duplicate') !== false;
    }

    public function edit(Canal $canal)
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        $canal->load('grabador');

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json($canal);
        }

        return view('grabaciones_puntuales.canales.edit', compact('canal'));
    }

    public function update(Request $request, Canal $canal)
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        $request->validate([
            'slot_nombre' => 'required|string|max:50',
            'link_origen' => 'nullable|url|max:500',
            'activo' => 'boolean',
            'detalle' => 'nullable|string|max:500',
            'duracion_grabacion' => 'nullable|string|max:20',
            'ffmpeg_args_pre' => 'nullable|string|max:100',
            'ffmpeg_args_post' => 'nullable|string|max:100',
            'formato_salida' => 'nullable|in:.mp3,.mp4',
        ]);

        $campos = $request->only([
            'slot_nombre', 'link_origen', 'activo', 'detalle',
            'duracion_grabacion', 'ffmpeg_args_pre', 'ffmpeg_args_post', 'formato_salida',
        ]);

        $esRadio = $canal->grabador->tipo === 'radio';
        if (empty($campos['ffmpeg_args_pre'])) {
            $campos['ffmpeg_args_pre'] = '-re';
        }
        if (empty($campos['ffmpeg_args_post'])) {
            $campos['ffmpeg_args_post'] = $esRadio ? '-acodec libmp3lame' : '-c copy';
        }
        if (empty($campos['duracion_grabacion'])) {
            $campos['duracion_grabacion'] = '00:21:00';
        }
        if (empty($campos['formato_salida'])) {
            $campos['formato_salida'] = $esRadio ? '.mp3' : '.mp4';
        }

        $canal->update($campos);

        if (empty($canal->ruta_destino) && $canal->usuario_id && $canal->grabador_id) {
            $rutaBase = DB::table('grabador_usuario')
                ->where('grabador_id', $canal->grabador_id)
                ->where('user_id', $canal->usuario_id)
                ->value('ruta_base');
            if ($rutaBase) {
                $canal->ruta_destino = rtrim($rutaBase, '/') . '/' . $canal->slot_nombre;
                $canal->save();
            }
        }

        if ($canal->link_origen && !$canal->api_canal_id) {
            $grabador = $canal->grabador;
            $resultado = $this->apiService->crearCanal($grabador, $canal);
            if ($resultado['success'] && $resultado['api_canal_id']) {
                $canal->update(['api_canal_id' => $resultado['api_canal_id']]);
                return response()->json([
                    'success' => true,
                    'message' => 'Canal registrado en el grabador exitosamente',
                    'canal' => $canal->fresh(),
                ]);
            } elseif ($this->esErrorCodigoDuplicado($resultado['error'] ?? '')) {
                $codigo = $this->generarCodigoLocal($canal->slot_nombre);
                $remoto = $this->apiService->buscarPorCodigo($grabador, $codigo);
                if ($remoto && !empty($remoto['id'])) {
                    $canal->update([
                        'api_canal_id' => (int) $remoto['id'],
                        'link_origen' => $canal->link_origen ?: ($remoto['link_origen'] ?? null),
                        'detalle' => $canal->detalle ?: ($remoto['detalle'] ?? null),
                        'ruta_destino' => $canal->ruta_destino ?: ($remoto['ruta_descarga'] ?? $canal->ruta_destino),
                    ]);
                    return response()->json([
                        'success' => true,
                        'message' => "Canal vinculado al existente en el grabador (ID {$remoto['id']})",
                        'canal' => $canal->fresh(),
                    ]);
                }
            }
            return response()->json([
                'success' => false,
                'message' => 'Canal guardado pero no se pudo registrar en el grabador: ' . ($resultado['error'] ?? 'Error desconocido'),
            ], 422);
        } elseif ($canal->api_canal_id) {
            $grabador = $canal->grabador;
            $datos = [
                'nombre' => $canal->slot_nombre,
                'link_origen' => $canal->link_origen,
                'detalle' => $canal->detalle,
                'duracion_grabacion' => $canal->duracion_grabacion,
                'ffmpeg_args_pre' => $canal->ffmpeg_args_pre,
                'ffmpeg_args_post' => $canal->ffmpeg_args_post,
                'formato_salida' => $canal->formato_salida,
                'max_fallos' => 200,
            ];
            $resultado = $this->apiService->actualizarCanal($grabador, $canal, $datos);
            if ($resultado['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Canal actualizado y sincronizado con el grabador',
                    'canal' => $canal->fresh(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Canal guardado pero no se pudo sincronizar: ' . ($resultado['error'] ?? 'Error desconocido'),
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Canal actualizado (agrega link_origen para sincronizar con el grabador)',
            'canal' => $canal->fresh(),
        ]);
    }

    public function destroy(Request $request, Canal $canal)
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            \Log::error('Canal destroy: No user_id in session. Session data: ' . json_encode(Session::all()));
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'No autenticado'], 401);
            }
            return redirect('/login');
        }

        $user = User::find($userId);
        if (!$user) {
            \Log::error('Canal destroy: User not found for user_id: ' . $userId);
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'Usuario no encontrado'], 401);
            }
            return redirect('/login');
        }

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        \Log::info('Canal destroy: Resetting canal ' . $canal->id . ' by user ' . $user->id);

        if ($canal->api_canal_id) {
            $this->apiService->eliminarCanal($canal->grabador, $canal->api_canal_id);
        } elseif ($canal->link_origen) {
            $codigo = $this->generarCodigoLocal($canal->slot_nombre);
            $this->apiService->eliminarPorCodigo($canal->grabador, $codigo);
        }

        $canal->update([
            'api_canal_id' => null,
            'link_origen' => null,
            'detalle' => null,
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'canal' => $canal->fresh()]);
        }

        return redirect()->route('canales.index')
            ->with('success', 'Canal reseteado — listo para reconfigurar');
    }

    public function ejecutar(Request $request, Canal $canal)
    {
        $user = $this->getUser();
        if (!$user) {
            if ($request->ajax()) return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            return redirect('/login');
        }

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            if ($request->ajax()) return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            abort(403);
        }

        if (!$canal->activo) {
            if ($request->ajax()) return response()->json(['success' => false, 'message' => 'El canal está inactivo']);
            return back()->with('error', 'El canal está inactivo');
        }

        if (!$canal->api_canal_id) {
            if ($request->ajax()) return response()->json(['success' => false, 'message' => 'El canal no tiene configuración en el grabador']);
            return back()->with('error', 'El canal no tiene configuración en el grabador');
        }

        $resultado = $this->apiService->iniciarGrabacion($canal->grabador, $canal->api_canal_id);

        if ($resultado['success']) {
            if ($request->ajax()) return response()->json(['success' => true, 'message' => 'Grabación iniciada']);
            return back()->with('success', 'Grabación iniciada');
        }

        $error = 'Error: ' . ($resultado['error'] ?? 'Desconocido');
        if ($request->ajax()) return response()->json(['success' => false, 'message' => $error]);
        return back()->with('error', $error);
    }

    public function estado()
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        $grabadores = $user->isAdmin()
            ? Grabador::where('activo', true)->get()
            : $user->grabadores()->where('activo', true)->get();

        $estados = [];
        foreach ($grabadores as $grabador) {
            $resultado = $this->apiService->estadoGrabacion($grabador);
            $estados[$grabador->id] = $resultado;
        }

        return view('grabaciones_puntuales.estado', compact('estados', 'grabadores'));
    }

    public function sincronizar(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $grabadores = Grabador::where('activo', true)->get();

        $actualizados = 0;
        $yaOk = 0;
        $sinMatch = 0;
        $detectados = [];
        $fueraDeAlcance = 0;
        $totalRemotos = 0;

        foreach ($grabadores as $grabador) {
            $resultado = $this->adopcion->detectar($grabador);

            $actualizados += $resultado['vinculados'];
            $yaOk += $resultado['ya_ok'];
            $sinMatch += $resultado['sin_match'];
            $fueraDeAlcance += $resultado['fuera_de_alcance'];
            $totalRemotos += $resultado['total_remotos'];

            foreach ($resultado['remotos_detectados'] as $detectado) {
                $detectado['grabador_id'] = $grabador->id;
                $detectado['grabador_nombre'] = $grabador->nombre;
                $detectado['tipo'] = $grabador->tipo;
                $detectados[] = $detectado;
            }
        }

        $msg = "Sincronización completa: {$actualizados} " . ($actualizados === 1 ? 'canal actualizado' : 'canales actualizados');
        if ($sinMatch > 0) {
            $msg .= ", {$sinMatch} sin coincidencia en la API";
        }
        if (count($detectados) > 0) {
            $msg .= ', ' . count($detectados) . ' canal(es) del grabador sin registrar en la plataforma';
        }

        return response()->json([
            'success' => true,
            'actualizados' => $actualizados,
            'ya_ok' => $yaOk,
            'sin_match' => $sinMatch,
            'total_remotos' => $totalRemotos,
            'fuera_de_alcance' => $fueraDeAlcance,
            'remotos_detectados' => $detectados,
            'adoptables' => array_values(array_filter(
                $detectados,
                fn($d) => $d['confianza'] === 'adoptable'
            )),
            'sugeridos' => array_values(array_filter(
                $detectados,
                fn($d) => $d['confianza'] === 'sugerido'
            )),
            'message' => $msg,
        ]);
    }

    /**
     * Detección pura (sin mutar nada) para el panel de cupos del módulo
     * Grabadores. Devuelve los canales remotos sin fila local.
     */
    public function detectar(Request $request, Grabador $grabador): \Illuminate\Http\JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $resultado = $this->adopcion->detectar($grabador);

        $porUsuario = [];
        foreach ($resultado['remotos_detectados'] as $detectado) {
            $porUsuario[$detectado['user_id']][] = $detectado;
        }

        return response()->json([
            'success' => true,
            'grabador_id' => $grabador->id,
            'total_remotos' => $resultado['total_remotos'],
            'vinculados' => $resultado['vinculados'],
            'ya_ok' => $resultado['ya_ok'],
            'sin_match' => $resultado['sin_match'],
            'fuera_de_alcance' => $resultado['fuera_de_alcance'],
            'remotos_detectados' => $resultado['remotos_detectados'],
            'por_usuario' => $porUsuario,
        ]);
    }

    /**
     * Adopta canales remotos detectados: INSERT local espejo, sin tocar el
     * grabador. Nunca se rechaza por cupo (el canal ya graba); si el par queda
     * excedido, la respuesta lo reporta para que el admin ajuste el límite.
     */
    public function adoptar(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'grabador_id' => 'required|exists:grabadores,id',
            'user_id' => 'required|exists:users,id',
            'api_canal_ids' => 'required|array|min:1',
            'api_canal_ids.*' => 'integer',
        ]);

        $grabador = Grabador::find($validated['grabador_id']);
        $userId = (int) $validated['user_id'];

        $adoptados = [];
        $yaOk = [];
        $omitidos = [];

        foreach ($validated['api_canal_ids'] as $apiCanalId) {
            $resultado = $this->adopcion->adoptar($grabador, $userId, (int) $apiCanalId);

            if (!$resultado['ok']) {
                $omitidos[] = $resultado;
            } elseif ($resultado['accion'] === 'adoptado') {
                $adoptados[] = $resultado;
            } else {
                $yaOk[] = $resultado;
            }
        }

        $estado = $this->cupo->resumen($grabador->id, $userId);

        $partes = [];
        if (count($adoptados) > 0) {
            $partes[] = count($adoptados) . ' integrado(s)';
        }
        if (count($yaOk) > 0) {
            $partes[] = count($yaOk) . ' ya estaba(n) registrado(s)';
        }
        if (count($omitidos) > 0) {
            $partes[] = count($omitidos) . ' omitido(s)';
        }

        return response()->json([
            'success' => true,
            'adoptados' => $adoptados,
            'ya_ok' => $yaOk,
            'omitidos' => $omitidos,
            'par' => [
                'grabador_id' => $grabador->id,
                'user_id' => $userId,
                'usados' => $estado['usados'] ?? 0,
                'limite' => $estado['limite'] ?? 0,
                'excedido' => $estado !== null && $estado['usados'] > $estado['limite'],
            ],
            'message' => $partes ? implode(', ', $partes) : 'Nada que integrar',
        ]);
    }

    public function detalle(Canal $canal)
    {
        $user = $this->getUser();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);

        if (!$canal->api_canal_id) {
            return response()->json([
                'success' => false,
                'error' => 'Canal no registrado en la API del grabador',
            ]);
        }

        $grabador = $canal->grabador;
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->get("{$grabador->base_url}/canales/{$canal->api_canal_id}");

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json('data'),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'No se pudo conectar con el grabador: ' . $e->getMessage(),
            ]);
        }
    }
}