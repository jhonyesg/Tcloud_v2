@extends('grabaciones_puntuales.layout')

@section('content')
<h2 class="text-2xl font-bold mb-6">Editar Canal</h2>

<form action="{{ route('canales.update', $canal) }}" method="POST" class="bg-white rounded-lg shadow p-6 max-w-lg">
    @csrf @method('PUT')

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Grabador</label>
        <input type="text" value="{{ $canal->grabador->nombre }} ({{ $canal->grabador->ip }})"
               class="w-full border rounded px-3 py-2 bg-gray-100" readonly>
    </div>

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Nombre del Slot</label>
        <input type="text" name="slot_nombre" value="{{ $canal->slot_nombre }}" required
               class="w-full border rounded px-3 py-2">
    </div>

    <div class="mb-4">
        <label class="flex items-center gap-2">
            <input type="checkbox" name="activo" value="1"
                   {{ $canal->activo ? 'checked' : '' }}
                   class="rounded">
            <span>Activo (permitir grabaciones)</span>
        </label>
    </div>

    <div class="flex gap-4">
        <button type="submit"
                class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
            Guardar Cambios
        </button>
        <a href="{{ route('canales.index') }}"
           class="px-6 py-2 rounded border hover:bg-gray-50">
            Cancelar
        </a>
    </div>
</form>
@endsection