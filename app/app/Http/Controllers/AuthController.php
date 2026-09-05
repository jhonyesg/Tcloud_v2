<?php

namespace App\Http\Controllers;

use App\Models\PasswordToken;
use App\Models\User;
use App\Modules\Correo\Services\EmailValidationService;
use App\Modules\Correo\Services\NotificationService;
use App\Services\Auth\PasswordTokenService;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class AuthController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private SessionService $sessionService,
        private EmailValidationService $emailValidation,
        private PasswordTokenService $passwordTokenService,
    ) {}

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
            'login' => 'required|string|min:3',
            'password' => 'required',
        ]);

        $login = strtolower(trim($request->login));
        $user = str_contains($login, '@')
            ? User::whereRaw('LOWER(email) = ?', [$login])->first()
            : User::whereRaw('LOWER(username) = ?', [$login])->first();

        if (!$user) {
            return back()->withInput($request->only('login'))->with('error', 'No existe una cuenta con ese usuario o correo electrónico.');
        }

        if (!Hash::check($request->password, $user->password_hash)) {
            return back()->withInput($request->only('login'))->with('error', 'La contraseña es incorrecta. Verifica e intenta de nuevo.');
        }

        if (!$user->isActive()) {
            return back()->withInput($request->only('login'))->with(
                'error',
                'Tu cuenta aún no está activa. Revisa el correo de bienvenida que te envió el administrador para establecer tu contraseña. Si no te llegó, pídele al admin que la reactive.'
            );
        }

        $maxSessions = $this->sessionService->getEffectiveMaxSessions($user);
        if ($maxSessions > 0 && $this->sessionService->countActiveSessions($user) >= $maxSessions) {
            return back()->withInput($request->only('login'))->with('error', 'Límite de sesiones simultáneas superado. Cierra una sesión desde otro dispositivo e intenta de nuevo.');
        }

        Session::regenerate();
        Session::put('user_id', $user->id);
        Session::put('user_role', $user->role);
        Session::put('user_email', $user->email);
        Session::put('user_username', $user->username);

        $this->sessionService->createSession($user, $request);

        return redirect('/dashboard');
    }

    public function logout()
    {
        $sessionId = Session::getId();
        $record = \App\Models\UserSession::where('session_id', $sessionId)->first();
        if ($record) {
            $this->sessionService->killSession($record);
        }
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
            'username' => $user->username,
            'role' => $user->role,
            'can_use_media_editor' => $user->canUseMediaEditor(),
        ]);
    }

    public function ping(Request $request)
    {
        Session::put('_last_ping', now()->timestamp);
        return response()->json(['ok' => true]);
    }

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $genericResponse = 'Si tu correo existe, es válido y la cuenta está activa, recibirás un enlace para restablecer tu contraseña.';

        $emailCheck = $this->emailValidation->validate($request->email);
        if (!$emailCheck['valid']) {
            Log::info('forgot-password: email inválido o no entregable', [
                'email' => $request->email,
                'reason' => $emailCheck['reason'],
            ]);
            return back()->with('success', $genericResponse);
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($request->email)])
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if ($user) {
            $rawToken = $this->passwordTokenService->issue($user, PasswordToken::TYPE_RESET, $request->ip());
            $expiresAt = now()->addMinutes((int) config('auth.password_token_ttl', 1440));

            $this->notificationService->send(
                'recuperar-password',
                $user->email,
                [
                    'nombre_usuario'      => $user->username ?? $user->email,
                    'enlace_recuperacion' => url('/auth/reset-password/' . $rawToken),
                    'expiracion'          => $expiresAt->format('d/m/Y H:i'),
                ]
            );
        }

        return back()->with('success', $genericResponse);
    }

    public function showResetPassword(string $token)
    {
        $hash = PasswordToken::hash($token);
        $tokenRecord = PasswordToken::where('token_hash', $hash)
            ->where('type', PasswordToken::TYPE_RESET)
            ->first();

        if (!$tokenRecord || $tokenRecord->used_at !== null || $tokenRecord->expires_at->isPast()) {
            return redirect('/login')->with('error', 'El enlace de recuperación es inválido o expiró. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".');
        }

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $tokenRecord->user->email,
            'username' => $tokenRecord->user->username,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = $this->passwordTokenService->consume($request->token, $request->ip());

        if (!$user) {
            return redirect('/login')->with('error', 'El enlace de recuperación es inválido o expiró.');
        }

        $this->passwordTokenService->applyPassword($user, $request->password);

        return redirect('/login')->with('success', 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.');
    }

    public function showSetupPassword(string $token)
    {
        $hash = PasswordToken::hash($token);
        $tokenRecord = PasswordToken::where('token_hash', $hash)
            ->where('type', PasswordToken::TYPE_SETUP)
            ->first();

        if (!$tokenRecord || $tokenRecord->used_at !== null || $tokenRecord->expires_at->isPast()) {
            return redirect('/login')->with('error', 'El enlace de bienvenida es inválido o expiró. Pídele al administrador que te lo reenvíe.');
        }

        return view('auth.setup-password', [
            'token' => $token,
            'email' => $tokenRecord->user->email,
            'username' => $tokenRecord->user->username,
        ]);
    }

    public function setupPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $hash = PasswordToken::hash($request->token);
        $tokenRecord = PasswordToken::where('token_hash', $hash)
            ->where('type', PasswordToken::TYPE_SETUP)
            ->first();

        if (!$tokenRecord || $tokenRecord->used_at !== null || $tokenRecord->expires_at->isPast()) {
            return redirect('/login')->with('error', 'El enlace de bienvenida es inválido o expiró. Pídele al administrador que te lo reenvíe.');
        }

        $user = $tokenRecord->user;

        $this->passwordTokenService->consume($request->token, $request->ip());
        $this->passwordTokenService->applyPassword($user, $request->password);

        return redirect('/login')->with('success', 'Contraseña establecida correctamente. Ya puedes iniciar sesión.');
    }
}
