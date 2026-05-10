@extends('grabaciones_puntuales.layout')

@section('content')
<h2 class="text-2xl font-bold mb-6">Nuevo Grabador</h2>

<form action="{{ route('grabadores.store') }}" method="POST" class="bg-white rounded-lg shadow p-6 max-w-lg">
    @csrf

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Nombre</label>
        <input type="text" name="nombre" required
               class="w-full border rounded px-3 py-2"
               placeholder="Ej: Tcloud Local">
    </div>

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">IP</label>
        <input type="text" name="ip" required
               class="w-full border rounded px-3 py-2"
               placeholder="192.168.0.118">
    </div>

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Puerto</label>
        <input type="number" name="puerto" value="5002" required
               class="w-full border rounded px-3 py-2">
    </div>

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Token (opcional)</label>
        <input type="text" name="token"
               class="w-full border rounded px-3 py-2"
               placeholder="Si la API requiere auth">
    </div>

    <div class="mb-4">
        <label class="block text-gray-700 mb-2">Observaciones</label>
        <textarea name="observaciones" rows="3"
                  class="w-full border rounded px-3 py-2"></textarea>
    </div>

    <div class="flex gap-4">
        <button type="submit"
                class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
            Guardar
        </button>
        <a href="{{ route('grabadores.index') }}"
           class="px-6 py-2 rounded border hover:bg-gray-50">
            Cancelar
        </a>
    </div>
</form>
@endsection