<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Correo\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class UserController extends Controller
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
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
            'password' => 'required|min:8',
            'role' => 'required|in:admin,user',
            'personal_quota_bytes' => 'nullable|integer|min:0',
            'send_email' => 'nullable|boolean',
        ]);

        $user = User::create([
            'email' => $request->email,
            'username' => $request->username ?: null,
            'password_hash' => Hash::make($request->password),
            'role' => $request->role,
            'personal_quota_bytes' => $request->personal_quota_bytes ?? 0,
            'personal_used_bytes' => 0,
        ]);

        if ($request->boolean('send_email')) {
            $this->notificationService->send(
                'bienvenida',
                $user->email,
                [
                    'nombre_usuario' => $user->email,
                ]
            );
        }

        return response()->json($user, 201);
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
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'username' => 'nullable|string|min:3|unique:users,username,' . $id,
            'role' => 'sometimes|in:admin,user',
            'personal_quota_bytes' => 'sometimes|integer|min:0',
        ];

        if ($request->has('password')) {
            $rules['password'] = 'min:8';
        }

        $request->validate($rules);

        $data = $request->only(['email', 'username', 'role', 'personal_quota_bytes']);
        if (array_key_exists('username', $data) && $data['username'] === '') {
            $data['username'] = null;
        }

        if ($request->has('password')) {
            $data['password_hash'] = Hash::make($request->password);
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
