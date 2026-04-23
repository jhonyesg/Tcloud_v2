<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Correo\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function showLogin()
    {
        if (Session::has('user_id')) {
            return redirect('/dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return back()->with('error', 'Credenciales inválidas');
        }

        Session::put('user_id', $user->id);
        Session::put('user_role', $user->role);

        return redirect('/dashboard');
    }

    public function logout()
    {
        Session::flush();
        return redirect('/login');
    }

    public function me(Request $request)
    {
        $userId = Session::get('user_id');
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => 'No authenticated'], 401);
        }

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Str::random(32);
            Session::put('password_reset_token', $token);
            Session::put('password_reset_email', $user->email);

            $this->notificationService->send(
                'recuperar-password',
                $user->email,
                [
                    'nombre_usuario' => $user->email,
                    'enlace_recuperacion' => url('/auth/reset-password/' . $token),
                ]
            );
        }

        return back()->with('success', 'Si tu correo existe en el sistema, recibirás un enlace de recuperación.');
    }

    public function showResetPassword(string $token)
    {
        $storedToken = Session::get('password_reset_token');

        if (!$storedToken || $storedToken !== $token) {
            return redirect('/login')->with('error', 'Token inválido o expirado.');
        }

        return view('auth.reset-password', ['token' => $token]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $storedToken = Session::get('password_reset_token');
        $email = Session::get('password_reset_email');

        if (!$storedToken || $storedToken !== $request->token || !$email) {
            return redirect('/login')->with('error', 'Token inválido o expirado.');
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update(['password_hash' => Hash::make($request->password)]);

            Session::forget('password_reset_token');
            Session::forget('password_reset_email');

            return redirect('/login')->with('success', 'Contraseña actualizada correctamente.');
        }

        return redirect('/login')->with('error', 'Usuario no encontrado.');
    }
}