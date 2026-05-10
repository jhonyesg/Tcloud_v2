<?php

namespace App\Http\Controllers\GrabacionesPuntuales;

use App\Http\Controllers\Controller;
use App\Models\Grabador;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class GrabadorController extends Controller
{
    private function getUser(): ?\App\Models\User
    {
        $userId = Session::get('user_id');
        return $userId ? \App\Models\User::find($userId) : null;
    }

    private function requireAdmin(): void
    {
        $user = $this->getUser();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }
    }

    public function index()
    {
        $this->requireAdmin();
        $grabadores = Grabador::with('usuarios')->get();
        return view('grabaciones_puntuales.grabadores.index', compact('grabadores'));
    }

    public function create()
    {
        $this->requireAdmin();
        return view('grabaciones_puntuales.grabadores.create');
    }

    public function store(Request $request)
    {
        $this->requireAdmin();
        $request->validate([
            'nombre' => 'required|string|max:100',
            'ip' => 'required|ip',
            'puerto' => 'required|integer|min:1|max:65535',
            'token' => 'nullable|string',
            'observaciones' => 'nullable|string',
        ]);

        $baseUrl = "http://{$request->ip}:{$request->puerto}/api";

        $grabador = Grabador::create([
            'nombre' => $request->nombre,
            'ip' => $request->ip,
            'puerto' => $request->puerto,
            'base_url' => $baseUrl,
            'token' => $request->token,
            'activo' => true,
            'observaciones' => $request->observaciones,
        ]);

        return redirect()->route('grabadores.index')
            ->with('success', 'Grabador creado exitosamente');
    }

    public function show(Grabador $grabador)
    {
        $this->requireAdmin();
        $grabador->load('usuarios');
        $usuarios = User::where('role', '!=', 'admin')->get();
        return view('grabaciones_puntuales.grabadores.show', compact('grabador', 'usuarios'));
    }

    public function update(Request $request, Grabador $grabador)
    {
        $this->requireAdmin();
        $request->validate([
            'nombre' => 'required|string|max:100',
            'ip' => 'required|ip',
            'puerto' => 'required|integer|min:1|max:65535',
            'activo' => 'boolean',
            'observaciones' => 'nullable|string',
        ]);

        $grabador->update($request->only(['nombre', 'ip', 'puerto', 'activo', 'observaciones']));

        $baseUrl = "http://{$request->ip}:{$request->puerto}/api";
        $grabador->update(['base_url' => $baseUrl]);

        return redirect()->route('grabadores.show', $grabador)
            ->with('success', 'Grabador actualizado');
    }

    public function destroy(Grabador $grabador)
    {
        $this->requireAdmin();
        $grabador->delete();
        return redirect()->route('grabadores.index')
            ->with('success', 'Grabador eliminado');
    }

    public function asignarUsuario(Request $request, Grabador $grabador)
    {
        $this->requireAdmin();
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'limite_canales' => 'required|integer|min:1|max:100',
        ]);

        $user = User::find($request->user_id);

        if ($user->isAdmin()) {
            return back()->with('error', 'No se puede asignar a un administrador');
        }

        $yaExiste = \DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->where('user_id', $request->user_id)
            ->exists();

        if ($yaExiste) {
            return back()->with('error', 'El usuario ya tiene acceso a este grabador');
        }

        \DB::table('grabador_usuario')->insert([
            'grabador_id' => $grabador->id,
            'user_id' => $request->user_id,
            'limite_canales' => $request->limite_canales,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Usuario asignado exitosamente');
    }

    public function actualizarAsignacion(Request $request, Grabador $grabador, int $userId)
    {
        $this->requireAdmin();
        $request->validate([
            'limite_canales' => 'required|integer|min:1|max:100',
        ]);

        \DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->where('user_id', $userId)
            ->update(['limite_canales' => $request->limite_canales]);

        return back()->with('success', 'Límite actualizado');
    }

    public function removerUsuario(Grabador $grabador, int $userId)
    {
        $this->requireAdmin();
        \DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->where('user_id', $userId)
            ->delete();

        return back()->with('success', 'Usuario removido del grabador');
    }

    public function probarConexion(Grabador $grabador)
    {
        try {
            $response = Http::get("{$grabador->base_url}/canales");
            if ($response->successful()) {
                return response()->json(['success' => true, 'message' => 'Conexión exitosa']);
            }
            return response()->json(['success' => false, 'message' => 'Error: ' . $response->status()]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}