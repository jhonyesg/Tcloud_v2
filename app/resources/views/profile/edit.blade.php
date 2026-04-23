@extends('layouts.app')

@section('title', 'Configuración - Tcloud')

@section('content')
<style>
    :root {
        --color-brand-500: #2d5aa0;
        --color-brand-400: #4e75b6;
        --color-brand-300: #7a97c9;
        --color-brand-800: #081d4a;
        --color-brand-700: #112e68;
        --color-brand-900: #03153C;
    }

    .profile-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height-screen: calc(100vh - 3.5rem);
        padding: 2rem 1rem;
    }

    .glass-card {
        background: rgba(8, 29, 74, 0.85);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 1.5rem;
        padding: 2.5rem;
        width: 100%;
        max-width: 480px;
        box-shadow: 
            0 0 60px rgba(45, 90, 160, 0.15),
            0 0 100px rgba(45, 90, 160, 0.08),
            inset 0 0 80px rgba(255, 255, 255, 0.02);
    }

    .form-label {
        color: rgba(180, 180, 200, 0.8);
        font-size: 0.8rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        display: block;
    }

    .glass-input {
        background: rgba(3, 21, 60, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #ffffff;
        transition: all 0.3s ease;
        font-size: 0.95rem;
        padding: 0.85rem 1rem;
        border-radius: 0.75rem;
        width: 100%;
    }

    .glass-input:focus {
        border-color: rgba(45, 90, 160, 0.6);
        box-shadow: 0 0 0 3px rgba(45, 90, 160, 0.2), 0 0 30px rgba(45, 90, 160, 0.1);
        background: rgba(3, 21, 60, 0.6);
        outline: none;
    }

    .glass-input::placeholder {
        color: rgba(180, 180, 200, 0.5);
    }

    .error-message {
        background: rgba(239, 68, 68, 0.15);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #fca5a5;
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
        font-size: 0.875rem;
        margin-bottom: 1rem;
    }

    .success-message {
        background: rgba(34, 197, 94, 0.15);
        border: 1px solid rgba(34, 197, 94, 0.3);
        color: #86efac;
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
        font-size: 0.875rem;
        margin-bottom: 1rem;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--color-brand-500), var(--color-brand-400));
        box-shadow: 0 4px 20px rgba(45, 90, 160, 0.4);
        color: white;
        font-weight: 600;
        padding: 0.85rem 1.5rem;
        border-radius: 0.75rem;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%;
        font-size: 0.95rem;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(45, 90, 160, 0.5);
    }

    .btn-primary:active {
        transform: translateY(0);
    }

    .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--color-brand-300);
        font-size: 0.875rem;
        margin-top: 1.5rem;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .back-link:hover {
        color: #ffffff;
    }

    .field-error {
        color: #fca5a5;
        font-size: 0.8rem;
        margin-top: 0.35rem;
    }
</style>

<div class="profile-wrapper">
    <div class="glass-card">
        <div class="text-center mb-6">
            <h1 class="text-xl font-bold text-white">Configuración</h1>
            <p class="text-sm mt-1" style="color: var(--color-brand-300)">Cambiar contraseña</p>
        </div>

        @if(session('error'))
            <div class="error-message">{{ session('error') }}</div>
        @endif

        @if(session('success'))
            <div class="success-message">{{ session('success') }}</div>
        @endif

        <form action="/profile" method="POST" x-data="{ loading: false }" @submit="loading = true">
            @csrf
            <input type="hidden" name="_method" value="PUT">

            <div class="mb-4">
                <label for="current_password" class="form-label">Contraseña Actual</label>
                <input type="password" id="current_password" name="current_password" 
                       class="glass-input" placeholder="Ingrese su contraseña actual" required>
                @error('current_password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="new_password" class="form-label">Nueva Contraseña</label>
                <input type="password" id="new_password" name="new_password" 
                       class="glass-input" placeholder="Mínimo 8 caracteres" required minlength="8">
                @error('new_password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-6">
                <label for="new_password_confirmation" class="form-label">Confirmar Nueva Contraseña</label>
                <input type="password" id="new_password_confirmation" name="new_password_confirmation" 
                       class="glass-input" placeholder="Repita la nueva contraseña" required>
                @error('new_password_confirmation')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn-primary" :disabled="loading">
                <span x-show="!loading">Actualizar Contraseña</span>
                <span x-show="loading">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Actualizando...
                </span>
            </button>
        </form>

        <a href="/profile/show" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Ver mi perfil
        </a>
    </div>
</div>
@endsection