@extends('layouts.app')

@section('title', 'Crear Canal - Tcloud')

@section('content')
<div class="p-6">
    <!-- Page Header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('canales.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center hover:bg-slate-200 transition-colors">
            <i class="fas fa-arrow-left text-slate-600"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                <div class="w-10 h-10 bg-sky-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-plus text-sky-600"></i>
                </div>
                Crear Canal
            </h1>
            <p class="text-sm text-gray-500 mt-1">Configura un nuevo canal de grabación en un grabador</p>
        </div>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-2xl">
        <form action="{{ route('canales.store') }}" method="POST" class="p-6">
            @csrf

            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Grabador</label>
                    <select name="grabador_id" required id="grabadorSelect"
                            class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-sky-400 focus:border-sky-400 outline-none transition-all">
                        <option value="">Seleccionar grabador...</option>
                        @foreach($grabadores as $grabador)
                            <option value="{{ $grabador->id }}">{{ $grabador->nombre }} ({{ $grabador->ip }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre del Slot</label>
                    <input type="text" name="slot_nombre" required
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-sky-400 focus:border-sky-400 outline-none transition-all"
                           placeholder="Ej: Radio_Siglo_01">
                    <div class="mt-2 flex items-center gap-2 text-sm">
                        <span class="text-slate-500">Espacios disponibles:</span>
                        <span id="espaciosDisponibles" class="font-medium text-slate-700">—</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-8 pt-6 border-t border-slate-200">
                <button type="submit"
                        class="flex items-center gap-2 bg-sky-600 hover:bg-sky-700 text-white px-6 py-2.5 rounded-xl font-medium transition-colors shadow-sm">
                    <i class="fas fa-save text-sm"></i>
                    Crear Canal
                </button>
                <a href="{{ route('canales.index') }}"
                   class="px-6 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
const asignaciones = @json($asignaciones);

document.getElementById('grabadorSelect').addEventListener('change', function() {
    const grabadorId = this.value;
    const info = asignaciones[grabadorId];
    const el = document.getElementById('espaciosDisponibles');
    if (info) {
        el.textContent = info.disponibles + ' de ' + info.limite;
        el.className = 'font-medium ' + (info.disponibles > 0 ? 'text-emerald-600' : 'text-red-600');
    } else {
        el.textContent = '—';
        el.className = 'font-medium text-slate-700';
    }
});
</script>
@endpush
@endsection
