@extends('layouts.app')

@section('title', 'No Encontrado - Tcloud')

@section('content')
<div class="min-h-screen bg-[#03153C] flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl p-8">
            <div class="w-20 h-20 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-red-600 dark:text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white mb-2">Enlace No Encontrado</h1>
            <p class="text-slate-500 dark:text-slate-400 mb-6">Este enlace compartido no existe o ha sido eliminado.</p>
            <a href="/" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-medium transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Ir al Inicio
            </a>
        </div>
    </div>
</div>
@endsection