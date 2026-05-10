@extends('grabaciones_puntuales.layout')

@section('content')
<h2 class="text-2xl font-bold mb-6">Crear Canal</h2>

<form action="{{ route('canales.store') }}" method="POST" class="bg-white rounded-lg shadow p-6 max-w-lg">
    @csrf

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Grabador</label>
        <select name="grabador_id" required class="w-full border rounded px-3 py-2" id="grabadorSelect">
            <option value="">Seleccionar grabador...</option>
            @foreach($grabadores as $grabador)
                <option value="{{ $grabador->id }}">{{ $grabador->nombre }} ({{ $grabador->ip }})</option>
            @endforeach
        </select>
    </div>

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Nombre del Slot</label>
        <input type="text" name="slot_nombre" required
               class="w-full border rounded px-3 py-2"
               placeholder="Ej: Radio_Siglo_01">
        <p class="text-sm text-gray-500 mt-1">
            Espacios disponibles: <span id="espaciosDisponibles">—</span>
        </p>
    </div>

    <div class="flex gap-4">
        <button type="submit"
                class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
            Crear Canal
        </button>
        <a href="{{ route('canales.index') }}"
           class="px-6 py-2 rounded border hover:bg-gray-50">
            Cancelar
        </a>
    </div>
</form>

<script>
const asignaciones = @json($asignaciones);

document.getElementById('grabadorSelect').addEventListener('change', function() {
    const grabadorId = this.value;
    const info = asignaciones[grabadorId];
    document.getElementById('espaciosDisponibles').textContent = info ? info.disponibles : '—';
});
</script>
@endsection