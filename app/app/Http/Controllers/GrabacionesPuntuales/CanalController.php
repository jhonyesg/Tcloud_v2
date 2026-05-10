<?php

namespace App\Http\Controllers\GrabacionesPuntuales;

use App\Http\Controllers\Controller;
use App\Models\Grabador;
use App\Models\Canal;
use App\Services\GrabacionesPuntuales\TcloudApiService;
use Illuminate\Http\Request;

class CanalController extends Controller
{
    private TcloudApiService $apiService;

    public function __construct(TcloudApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function index()
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            $canales = Canal::with(['grabador', 'usuario'])->get();
            return view('grabaciones_puntuales.canales.index', compact('canales'));
        }

        $canales = Canal::where('usuario_id', $user->id)
            ->with('grabador')
            ->get();

        return view('grabaciones_puntuales.canales.index', compact('canales'));
    }

    public function create()
    {
        $user = auth()->user();
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
        $user = auth()->user();
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
        $user = auth()->user();

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        return view('grabaciones_puntuales.canales.edit', compact('canal'));
    }

    public function update(Request $request, Canal $canal)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        $request->validate([
            'slot_nombre' => 'required|string|max:50',
            'activo' => 'boolean',
        ]);

        $canal->update($request->only(['slot_nombre', 'activo']));

        if ($canal->api_canal_id) {
            $grabador = $canal->grabador;
            $this->apiService->actualizarCanal($grabador, $canal, [
                'nombre' => $canal->slot_nombre,
            ]);
        }

        return redirect()->route('canales.index')
            ->with('success', 'Canal actualizado');
    }

    public function destroy(Canal $canal)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        if ($canal->api_canal_id) {
            $this->apiService->eliminarCanal($canal->grabador, $canal->api_canal_id);
        }

        $canal->delete();

        return redirect()->route('canales.index')
            ->with('success', 'Canal eliminado');
    }

    public function ejecutar(Canal $canal)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && $canal->usuario_id !== $user->id) {
            abort(403);
        }

        if (!$canal->activo) {
            return back()->with('error', 'El canal está inactivo');
        }

        if (!$canal->api_canal_id) {
            return back()->with('error', 'El canal no tiene configuración en el grabador');
        }

        $resultado = $this->apiService->iniciarGrabacion($canal->grabador, $canal->api_canal_id);

        if ($resultado['success']) {
            return back()->with('success', 'Grabación iniciada');
        }

        return back()->with('error', 'Error: ' . ($resultado['error'] ?? 'Desconocido'));
    }

    public function estado()
    {
        $user = auth()->user();
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
}