@extends('layouts.app')

@section('title', $site->name . ' - Tcloud')

@section('content')
<div class="h-full w-full flex flex-col" x-data="{
    loaded: false,
    blocked: false,
    timer: null,
    init() {
        this.timer = setTimeout(() => {
            if (!this.loaded) this.blocked = true;
        }, 12000);
    },
    onLoad() {
        this.loaded = true;
        this.blocked = false;
        clearTimeout(this.timer);
    },
    onError() {
        this.blocked = true;
        clearTimeout(this.timer);
    }
}">
    {{-- Área principal --}}
    <div class="flex-1 relative overflow-hidden">
        {{-- Fallback: site bloqueado --}}
        <div x-show="blocked" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 z-10">
            <div class="text-center max-w-sm px-6">
                <div class="w-16 h-16 bg-amber-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-ban text-amber-500 text-2xl"></i>
                </div>
                <h3 class="font-semibold text-slate-700 mb-2">No se puede mostrar este sitio</h3>
                <p class="text-sm text-slate-500 mb-5">
                    El sitio <strong>{{ $site->name }}</strong> no permite ser abierto dentro de esta plataforma
                    (política de seguridad del servidor externo).
                </p>
                <a href="{{ $site->url }}" target="_blank"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-xl transition-colors">
                    <i class="fas fa-external-link-alt"></i>
                    Abrir en nueva pestaña
                </a>
            </div>
        </div>

        {{-- Spinner de carga --}}
        <div x-show="!loaded && !blocked" class="absolute inset-0 flex items-center justify-center bg-slate-50 z-10">
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-brand-400 text-2xl mb-3"></i>
                <p class="text-sm text-slate-400">Cargando {{ $site->name }}…</p>
            </div>
        </div>

        {{-- iframe --}}
        <iframe
            src="{{ $site->url }}"
            class="w-full h-full border-0"
            x-on:load="onLoad()"
            x-on:error="onError()"
            allow="fullscreen"
            referrerpolicy="no-referrer-when-downgrade"
        ></iframe>
    </div>
</div>
@endsection
