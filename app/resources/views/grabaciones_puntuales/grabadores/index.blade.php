@extends('grabaciones_puntuales.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold">Grabadores</h2>
    <a href="{{ route('grabadores.create') }}"
       class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
        <i class="fas fa-plus"></i> Nuevo Grabador
    </a>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Puerto</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Canales</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse($grabadores as $grabador)
            <tr>
                <td class="px-6 py-4 font-medium">{{ $grabador->nombre }}</td>
                <td class="px-6 py-4">{{ $grabador->ip }}</td>
                <td class="px-6 py-4">{{ $grabador->puerto }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 rounded text-xs {{ $grabador->activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $grabador->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                </td>
                <td class="px-6 py-4">{{ $grabador->canales->count() }}</td>
                <td class="px-6 py-4 flex gap-2">
                    <a href="{{ route('grabadores.show', $grabador) }}"
                       class="text-indigo-600 hover:underline">Ver</a>
                    <button onclick="probarConexion({{ $grabador->id }})"
                            class="text-green-600 hover:underline">Probar</button>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                    No hay grabadores registrados
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<script>
function probarConexion(grabadorId) {
    fetch(`/grabaciones-puntuales/grabadores/${grabadorId}/probar`)
        .then(r => r.json())
        .then(d => {
            alert(d.success ? '✓ Conexión exitosa' : '✗ ' + d.message);
        });
}
</script>
@endsection