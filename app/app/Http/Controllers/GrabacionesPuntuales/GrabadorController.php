<?php

namespace App\Http\Controllers\GrabacionesPuntuales;

use App\Http\Controllers\Controller;
use App\Models\Grabador;
use App\Models\Canal;
use App\Models\User;
use App\Services\GrabacionesPuntuales\TcloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class GrabadorController extends Controller
{
    private TcloudApiService $apiService;

    public function __construct(TcloudApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    private function requireAdmin(): void
    {
        $user = $this->getUser();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }
    }

    public function index(Request $request)
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        if ($request->ajax()) {
            if ($user->isAdmin()) {
                $grabadores = Grabador::with(['usuarios', 'canales.usuario'])->withCount('canales')->get();
            } else {
                $grabadores = $user->grabadores()->with(['canales' => function ($q) use ($user) {
                    $q->where('usuario_id', $user->id)->orWhereNull('usuario_id');
                }, 'canales.usuario'])->withCount('canales')->get();
            }
            return response()->json($grabadores);
        }

        $this->requireAdmin();
        return view('grabaciones_puntuales.grabadores.index');
    }

    public function store(Request $request)
    {
        $this->requireAdmin();

        $request->validate([
            'nombre' => 'required|string|max:100',
            'tipo' => 'required|in:radio,tv',
            'ip' => 'required|ip',
            'puerto' => 'required|integer|min:1|max:65535',
            'token' => 'nullable|string',
            'observaciones' => 'nullable|string',
        ]);

        $baseUrl = "http://{$request->ip}:{$request->puerto}/api";

        $grabador = Grabador::create([
            'nombre' => $request->nombre,
            'tipo' => $request->tipo,
            'ip' => $request->ip,
            'puerto' => $request->puerto,
            'base_url' => $baseUrl,
            'token' => $request->token,
            'activo' => true,
            'observaciones' => $request->observaciones,
        ]);

        $grabador->load(['usuarios', 'canales.usuario'])->loadCount('canales');

        return response()->json($grabador, 201);
    }

    public function show(Request $request, Grabador $grabador)
    {
        $user = $this->getUser();
        if (!$user) return redirect('/login');

        if ($request->ajax()) {
            $grabador->load(['usuarios', 'canales.usuario'])->loadCount('canales');
            return response()->json($grabador);
        }

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
            'tipo' => 'required|in:radio,tv',
            'ip' => 'required|ip',
            'puerto' => 'required|integer|min:1|max:65535',
            'activo' => 'boolean',
            'observaciones' => 'nullable|string',
        ]);

        $grabador->update([
            'nombre' => $request->nombre,
            'tipo' => $request->tipo,
            'ip' => $request->ip,
            'puerto' => $request->puerto,
            'base_url' => "http://{$request->ip}:{$request->puerto}/api",
            'activo' => $request->boolean('activo'),
            'observaciones' => $request->observaciones,
        ]);

        $grabador->load(['usuarios', 'canales.usuario'])->loadCount('canales');
        return response()->json($grabador);
    }

    public function destroy(Grabador $grabador)
    {
        $this->requireAdmin();
        $grabador->delete();
        return response()->json(['success' => true]);
    }

    public function asignarUsuario(Request $request, Grabador $grabador)
    {
        $this->requireAdmin();

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'nombre_base' => 'required|string|max:50|regex:/^[a-zA-Z0-9_]+$/',
            'ruta_base' => 'required|string|max:500',
        ]);

        $user = User::find($request->user_id);

        if ($user->isAdmin()) {
            return response()->json(['error' => 'No se puede asignar a un administrador'], 422);
        }

        $yaExiste = DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->where('user_id', $request->user_id)
            ->exists();

        if ($yaExiste) {
            return response()->json(['error' => 'El usuario ya tiene acceso a este grabador'], 422);
        }

        $nombreBase = $request->nombre_base;
        $rutaBase = rtrim($request->ruta_base, '/');

        $canalesNombres = [];
        for ($i = 1; $i <= 10; $i++) {
            $canalesNombres[] = $nombreBase . '_' . str_pad($i, 2, '0', STR_PAD_LEFT);
        }

        foreach ($canalesNombres as $nombre) {
            $existe = Canal::where('grabador_id', $grabador->id)
                ->where('slot_nombre', $nombre)
                ->exists();
            if ($existe) {
                return response()->json([
                    'error' => "Ya existe un canal con el nombre '{$nombre}' en este grabador"
                ], 422);
            }
        }

        DB::table('grabador_usuario')->insert([
            'grabador_id' => $grabador->id,
            'user_id' => $request->user_id,
            'limite_canales' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $canalesCreados = [];
        $errores = [];

        for ($i = 1; $i <= 10; $i++) {
            $slotNombre = $nombreBase . '_' . str_pad($i, 2, '0', STR_PAD_LEFT);
            $rutaDestino = $rutaBase . '/' . $slotNombre;
            $esRadio = $grabador->tipo === 'radio';

            $canal = Canal::create([
                'grabador_id' => $grabador->id,
                'usuario_id' => $request->user_id,
                'slot_nombre' => $slotNombre,
                'ruta_destino' => $rutaDestino,
                'duracion_grabacion' => '00:21:00',
                'formato_salida' => $esRadio ? '.mp3' : '.mp4',
                'ffmpeg_args_pre' => '-re',
                'ffmpeg_args_post' => $esRadio ? '-acodec libmp3lame' : '-c copy',
                'activo' => true,
            ]);

            $resultado = $this->apiService->crearCanal($grabador, $canal);

            if ($resultado['success'] && !empty($resultado['api_canal_id'])) {
                $canal->update(['api_canal_id' => $resultado['api_canal_id']]);
            } else {
                $errores[] = "Canal {$slotNombre}: " . ($resultado['error'] ?? 'Error en API');
            }

            $canalesCreados[] = $canal;
        }

        $grabador->load('usuarios');

        if (!empty($errores)) {
            return response()->json([
                'warning' => 'Canales creados pero algunos no se registraron en el grabador',
                'errores' => $errores,
                'usuarios' => $grabador->usuarios
            ]);
        }

        return response()->json($grabador->usuarios);
    }

    public function actualizarAsignacion(Request $request, Grabador $grabador, int $userId)
    {
        $this->requireAdmin();

        $request->validate([
            'limite_canales' => 'required|integer|min:1|max:100',
        ]);

        DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->where('user_id', $userId)
            ->update(['limite_canales' => $request->limite_canales, 'updated_at' => now()]);

        $grabador->load('usuarios');
        return response()->json($grabador->usuarios);
    }

    public function removerUsuario(Grabador $grabador, int $userId)
    {
        $this->requireAdmin();

        $canalesDelUsuario = Canal::where('grabador_id', $grabador->id)
            ->where('usuario_id', $userId)
            ->get();

        foreach ($canalesDelUsuario as $canal) {
            if ($canal->api_canal_id) {
                $this->apiService->eliminarCanal($grabador, $canal->api_canal_id);
            }
            $canal->delete();
        }

        DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->where('user_id', $userId)
            ->delete();

        $grabador->load('usuarios');
        return response()->json($grabador->usuarios);
    }

    public function probarConexion(Grabador $grabador)
    {
        try {
            $response = Http::timeout(5)->get("{$grabador->base_url}/canales");
            if ($response->successful()) {
                $canales = $response->json('data') ?? [];
                return response()->json([
                    'success' => true,
                    'message' => 'Conexión exitosa',
                    'canales_remotos' => count($canales),
                    'endpoints' => [
                        'GET /canales' => 'OK',
                        'Base URL' => $grabador->base_url,
                    ],
                ]);
            }
            return response()->json([
                'success' => false,
                'message' => 'Error HTTP: ' . $response->status(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function getUsers()
    {
        $this->requireAdmin();
        return response()->json(User::where('role', '!=', 'admin')->get());
    }
}
