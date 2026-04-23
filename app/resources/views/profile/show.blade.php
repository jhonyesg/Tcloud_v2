@extends('layouts.app')

@section('title', 'Mi Perfil - Tcloud')

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

    .avatar {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--color-brand-500), var(--color-brand-400));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        box-shadow: 0 4px 20px rgba(45, 90, 160, 0.4);
    }

    .avatar-icon {
        color: white;
        font-size: 2rem;
    }

    .info-label {
        color: rgba(180, 180, 200, 0.7);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 0.35rem;
    }

    .info-value {
        color: #ffffff;
        font-size: 1rem;
        font-weight: 500;
        margin-bottom: 1.25rem;
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.35rem 0.85rem;
        border-radius: 9999px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .role-badge.admin {
        background: rgba(45, 90, 160, 0.25);
        border: 1px solid rgba(45, 90, 160, 0.5);
        color: #7a97c9;
    }

    .role-badge.user {
        background: rgba(78, 117, 182, 0.2);
        border: 1px solid rgba(78, 117, 182, 0.4);
        color: #aab8db;
    }

    .quota-section {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .quota-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }

    .quota-label {
        color: rgba(180, 180, 200, 0.7);
        font-size: 0.8rem;
    }

    .quota-value {
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .quota-bar {
        height: 8px;
        background: rgba(3, 21, 60, 0.6);
        border-radius: 9999px;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .quota-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--color-brand-500), var(--color-brand-400));
        border-radius: 9999px;
        transition: width 0.5s ease;
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
</style>

<div class="profile-wrapper">
    <div class="glass-card">
        <div class="text-center mb-6">
            <div class="avatar">
                <i class="fas fa-user avatar-icon"></i>
            </div>
            <h1 class="text-xl font-bold text-white">Mi Perfil</h1>
        </div>

        <div class="text-left">
            <div>
                <p class="info-label">Correo Electrónico</p>
                <p class="info-value">{{ $user->email }}</p>
            </div>

            <div>
                <p class="info-label">Rol</p>
                <p class="info-value">
                    <span class="role-badge {{ $user->role === 'admin' ? 'admin' : 'user' }}">
                        <i class="fas {{ $user->role === 'admin' ? 'fa-shield-alt' : 'fa-user' }}"></i>
                        {{ $user->role === 'admin' ? 'Administrador' : 'Usuario' }}
                    </span>
                </p>
            </div>

            <div class="quota-section">
                <div class="quota-header">
                    <span class="quota-label">Almacenamiento</span>
                    <span class="quota-value">{{ $usedFormatted }} / {{ $quotaFormatted }}</span>
                </div>
                <div class="quota-bar">
                    <div class="quota-bar-fill" style="width: {{ $quotaPercent }}%"></div>
                </div>
            </div>
        </div>

        <a href="/profile" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Cambiar contraseña
        </a>
    </div>
</div>
@endsection