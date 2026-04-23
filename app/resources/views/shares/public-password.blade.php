@extends('layouts.app')

@section('title', 'Acceso Protegido - Tcloud')

@section('content')
<div class="min-h-screen bg-[#03153C] flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl p-8">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-purple-600 dark:text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 dark:text-white mb-2">Enlace Protegido</h1>
                <p class="text-slate-500 dark:text-slate-400">Este archivo está protegido con contraseña</p>
            </div>

            @if(isset($error))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4">
                    {{ $error }}
                </div>
            @endif

            <form method="POST" action="/s/{{ $token }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Contraseña</label>
                    <input type="password" name="password" required class="w-full border border-slate-300 dark:border-slate-600 px-4 py-3 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none dark:bg-slate-700 dark:text-white" placeholder="Ingresa la contraseña">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-xl font-medium transition-colors">
                    Acceder
                </button>
            </form>
        </div>
    </div>
</div>
@endsection