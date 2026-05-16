<?php

namespace App\Http\Controllers\GrabacionesPuntuales;

use App\Http\Controllers\Controller;
use App\Models\Grabador;
use App\Models\Canal;
use App\Models\User;
use App\Services\GrabacionesPuntuales\TcloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CanalController extends Controller
{
    private TcloudApiService $apiService;

    public function __construct(TcloudApiService $apiService)
    {
        $this->apiService = $apiService;
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
            $acceso = $user->isAdmin()
                ? (object) ['limite_canales' => 999]
                : $user->grabadores()->where('grabador_id', $grabador->id)->first();

            $canalesCreados = Canal::where('grabador_id', $grabador->id)
                ->where('usuario_id', $user->id)
                ->count();

            $asignaciones[$grabador->id] = [
                'limite' => $acceso->limite_canales ?? 0,
                'creados' => $canalesCreados,
                'disponibles' => ($acceso->limite_canales ?? 0) - $canalesCreados,
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

        if (!$user->isAdmin()) {
            $acceso = $user->grabadores()->where('grabador_id', $grabador->id)->first();
            if (!$acceso) {
                return back()->with('error', 'No tienes acceso a este grabador');
            }

            $canalesCreados = Canal::where('grabador_id', $grabador->id)
                ->where('usuario_id', $user->id)
                ->count();

            if ($canalesCreados >= $acceso->limite_canales) {
                return back()->with('error', 'Has alcanzado tu límite de canales en este grabador');
            }
        }

        $yaExiste = Canal::where('grabador_id', $grabador->id)
            ->where('slot_nombre', $request->slot_nombre)
            ->exists();

        if ($yaExiste) {
            return back()->with('error', 'Ya existe un canal con ese nombre en este grabador');
        }

        $canal = Canal::create([
            'grabador_id' => $grabador->id,
            'usuario_id' => $user->id,
            'slot_nombre' => $request->slot_nombre,
            'activo' => true,
        ]);

        $this->apiService->crearCanal($grabador, $canal);

        return redirect()->route('canales.index')
            ->with('success', 'Canal creado exitosamente');
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
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Canal guardado pero no se pudo registrar en el grabador: ' . ($resultado['error'] ?? 'Error desconocido'),
                ], 422);
            }
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
        }

        $canal->update([
            'api_canal_id' => null,
            'link_origen' => null,
            'detalle' => null,
            'ruta_destino' => null,
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