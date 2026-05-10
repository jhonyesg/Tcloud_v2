@extends('layouts.app')

@section('title', 'Nuevo Grabador - Tcloud')

@section('content')
<div class="p-6">
    <!-- Page Header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('grabadores.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center hover:bg-slate-200 transition-colors">
            <i class="fas fa-arrow-left text-slate-600"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-plus text-emerald-600"></i>
                </div>
                Nuevo Grabador
            </h1>
            <p class="text-sm text-gray-500 mt-1">Registra un nuevo dispositivo grabador en el sistema</p>
        </div>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-2xl">
        <form action="{{ route('grabadores.store') }}" method="POST" class="p-6">
            @csrf

            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre</label>
                    <input type="text" name="nombre" required
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none transition-all"
                           placeholder="Ej: Tcloud Local">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Dirección IP</label>
                        <input type="text" name="ip" required
                               class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm font-mono focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none transition-all"
                               placeholder="192.168.0.118">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Puerto</label>
                        <input type="number" name="puerto" value="5002" required
                               class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Token <span class="text-slate-400 font-normal">(opcional)</span></label>
                    <input type="text" name="token"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none transition-all"
                           placeholder="Si la API requiere autenticación">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Observaciones</label>
                    <textarea name="observaciones" rows="3"
                              class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none transition-all resize-none"
                              placeholder="Notas adicionales sobre este grabador..."></textarea>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-8 pt-6 border-t border-slate-200">
                <button type="submit"
                        class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-xl font-medium transition-colors shadow-sm">
                    <i class="fas fa-save text-sm"></i>
                    Guardar Grabador
                </button>
                <a href="{{ route('grabadores.index') }}"
                   class="px-6 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
