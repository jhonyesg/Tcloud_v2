<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\PasswordToken;
use App\Models\Share;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserStorage;
use App\Modules\Correo\Services\EmailValidationService;
use App\Modules\Correo\Services\NotificationService;
use App\Services\Auth\PasswordTokenService;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class UserController extends Controller
{
    private NotificationService $notificationService;
    private EmailValidationService $emailValidation;
    private PasswordTokenService $passwordTokenService;
    private SessionService $sessionService;

    public function __construct(
        NotificationService $notificationService,
        EmailValidationService $emailValidation,
        PasswordTokenService $passwordTokenService,
        SessionService $sessionService,
    ) {
        $this->notificationService = $notificationService;
        $this->emailValidation = $emailValidation;
        $this->passwordTokenService = $passwordTokenService;
        $this->sessionService = $sessionService;
    }
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $perPage = $request->input('per_page', 15);
            $users = User::paginate($perPage);
            return response()->json($users);
        }
        return view('admin.users');
    }

    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'username' => 'nullable|string|min:3|unique:users,username',
            'role' => 'required|in:admin,user',
            'personal_quota_bytes' => 'nullable|integer|min:0',
            'send_email' => 'nullable|boolean',
        ]);

        $emailCheck = $this->emailValidation->validate($request->email);
        if (!$emailCheck['valid']) {
            return response()->json([
                'error' => 'No se puede crear el usuario: ' . $emailCheck['reason'],
                'field' => 'email',
            ], 422);
        }

        $user = User::create([
            'email' => $request->email,
            'username' => $request->username ?: null,
            'password_hash' => Hash::make(\Illuminate\Support\Str::random(64)),
            'role' => $request->role,
            'status' => User::STATUS_PENDING,
            'personal_quota_bytes' => $request->personal_quota_bytes ?? 0,
            'personal_used_bytes' => 0,
        ]);

        if ($user->username) {
            $this->createPersonalStorage($user);
        }

        $this->issueSetupAndMail($user, $request);

        return response()->json($user, 201);
    }

    private function issueSetupAndMail(User $user, Request $request): void
    {
        $rawToken = $this->passwordTokenService->issue($user, PasswordToken::TYPE_SETUP, $request->ip());

        $expiresAt = now()->addMinutes((int) config('auth.password_token_ttl', 1440));

        $this->notificationService->send(
            $request->boolean('send_email') ? 'bienvenida-setup' : 'bienvenida-setup',
            $user->email,
            [
                'nombre_usuario'    => $user->username ?? $user->email,
                'email'             => $user->email,
                'set_password_url'  => url('/auth/setup-password/' . $rawToken),
                'expiracion'        => $expiresAt->format('d/m/Y H:i'),
            ]
        );
    }

    public function show(int $id)
    {
        $user = User::findOrFail($id);
        return response()->json($user);
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        $rules = [
            'email'                    => 'sometimes|email|unique:users,email,' . $id,
            'username'                 => 'nullable|string|min:3|unique:users,username,' . $id,
            'role'                     => 'sometimes|in:admin,user',
            'status'                   => 'sometimes|in:pending,active,disabled',
            'personal_quota_bytes'     => 'sometimes|integer|min:0',
            'max_sessions'             => 'sometimes|integer|min:0',
            'session_lifetime_minutes' => 'sometimes|nullable|integer|min:0',
        ];

        if ($request->has('password')) {
            $rules['password'] = 'min:8';
        }

        $request->validate($rules);

        $data = $request->only(['email', 'username', 'role', 'status', 'personal_quota_bytes', 'max_sessions', 'session_lifetime_minutes']);
        if (array_key_exists('username', $data) && $data['username'] === '') {
            $data['username'] = null;
        }
        if (array_key_exists('session_lifetime_minutes', $data) && $data['session_lifetime_minutes'] === '') {
            $data['session_lifetime_minutes'] = null;
        }

        if ($request->has('password')) {
            $data['password_hash'] = Hash::make($request->password);
            $data['status'] = User::STATUS_ACTIVE;
        }

        // Eviction proactiva: si el admin está cambiando el status a algo distinto
        // de 'active', matar todas las sesiones del usuario AHORA (no esperar al
        // próximo request del usuario). Se hace ANTES del update usando la instancia
        // actual del User (cuyo status en memoria sigue siendo el anterior).
        if ($request->has('status')
            && array_key_exists('status', $data)
            && $data['status'] !== User::STATUS_ACTIVE
            && $user->status === User::STATUS_ACTIVE) {
            $this->sessionService->killAllUserSessions($user);
        }

        $user->update($data);

        return response()->json($user);
    }

    public function destroy(int $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === Session::get('user_id')) {
            return response()->json(['error' => 'Cannot delete yourself'], 403);
        }

        // Cleanup files (physical + DB) — chunks to avoid memory exhaustion
        $user->files()->chunkById(100, function ($files) {
            foreach ($files as $file) {
                if (!$file->is_folder) {
                    $storage = $file->storageProvider;
                    if ($storage && $storage->type === 'local') {
                        $fullPath = rtrim($storage->base_path, '/') . '/' . $file->path;
                        if (file_exists($fullPath) && is_file($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                }
                $file->delete();
            }
        });

        // Cleanup shares created by this user
        Share::where('created_by', $user->id)->delete();

        // Cleanup user storages. Borrar al cliente no apaga la transcripción de
        // sus canales: qué se transcribe lo decide API Transcriptor sobre el
        // storage, con independencia de quién tenga acceso.
        UserStorage::where('user_id', $user->id)->delete();

        // Cleanup other NO ACTION dependencies
        DB::table('canales')->where('usuario_id', $user->id)->delete();
        DB::table('media_edit_jobs')->where('user_id', $user->id)->delete();
        DB::table('correo_log')->where('user_id', $user->id)->delete();
        DB::table('correo_config')->where('updated_by', $user->id)->update(['updated_by' => null]);
        DB::table('correo_plantillas')->where('created_by', $user->id)->update(['created_by' => null]);

        // Remaining FKs (user_sessions, external_site_user, grabador_usuario, user_keyword) are CASCADE in DB
        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    public function toggleMediaEditor(int $id)
    {
        $user = User::findOrFail($id);
        $user->update(['media_editor_enabled' => !$user->media_editor_enabled]);
        return response()->json(['media_editor_enabled' => $user->media_editor_enabled]);
    }

    public function profile(Request $request)
    {
        $userId = Session::get('user_id');
        $user = User::findOrFail($userId);

        if ($request->expectsJson()) {
            $request->validate([
                'current_password' => 'required_with:new_password',
                'new_password' => 'nullable|min:8|required_with:current_password',
            ]);

            if ($request->has('new_password')) {
                if (!Hash::check($request->current_password, $user->password_hash)) {
                    return response()->json(['error' => 'Current password is incorrect'], 403);
                }

                $user->update(['password_hash' => Hash::make($request->new_password)]);
            }

            return response()->json([
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'personal_quota_bytes' => $user->personal_quota_bytes,
                'personal_used_bytes' => $user->personal_used_bytes,
            ]);
        }

        if ($request->isMethod('get')) {
            $usedFormatted = $this->formatBytes($user->personal_used_bytes);
            $quotaFormatted = $this->formatBytes($user->personal_quota_bytes);
            $quotaPercent = $user->personal_quota_bytes > 0 
                ? min(100, round(($user->personal_used_bytes / $user->personal_quota_bytes) * 100, 1))
                : 0;

            return view('profile.show', [
                'user' => $user,
                'usedFormatted' => $usedFormatted,
                'quotaFormatted' => $quotaFormatted,
                'quotaPercent' => $quotaPercent,
            ]);
        }

        $request->validate([
            'current_password' => 'required_with:new_password',
            'new_password' => 'nullable|min:8|required_with:current_password',
        ]);

        if ($request->has('new_password')) {
            if (!Hash::check($request->current_password, $user->password_hash)) {
                return back()->with('error', 'La contraseña actual es incorrecta');
            }

            if ($request->new_password !== $request->new_password_confirmation) {
                return back()->with('error', 'La nueva contraseña y su confirmación no coinciden');
            }

            $user->update(['password_hash' => Hash::make($request->new_password)]);
            return back()->with('success', 'Contraseña actualizada correctamente');
        }

        return view('profile.edit');
    }

    public function profileShow()
    {
        $userId = Session::get('user_id');
        $user = User::findOrFail($userId);

        $usedFormatted = $this->formatBytes($user->personal_used_bytes);
        $quotaFormatted = $this->formatBytes($user->personal_quota_bytes);
        $quotaPercent = $user->personal_quota_bytes > 0 
            ? min(100, round(($user->personal_used_bytes / $user->personal_quota_bytes) * 100, 1))
            : 0;

        return view('profile.show', [
            'user' => $user,
            'usedFormatted' => $usedFormatted,
            'quotaFormatted' => $quotaFormatted,
            'quotaPercent' => $quotaPercent,
        ]);
    }

    public function profileEdit()
    {
        return view('profile.edit');
    }

    private function createPersonalStorage(User $user): void
    {
        $basePath = rtrim((string) config('storage.personal_base_path', '/home/www/Usuarios_tcloud/'), '/') . '/' . $user->username;

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        $storage = StorageProvider::create([
            'name'     => 'Personal - ' . $user->username,
            'type'     => 'local',
            'base_path' => $basePath,
            'enabled'  => true,
            'is_personal' => true,
        ]);

        UserStorage::create([
            'user_id'            => $user->id,
            'storage_provider_id' => $storage->id,
            'permissions'        => 'full',
            'can_create_shares'  => true,
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
