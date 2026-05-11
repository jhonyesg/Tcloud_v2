@extends('layouts.app')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold">Canales</h2>
    @if(!$user || !$user->isAdmin())
        <a href="{{ route('canales.create') }}"
           class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
            <i class="fas fa-plus"></i> Crear Canal
        </a>
    @endif
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                @if($user && $user->isAdmin())
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Grabador</th>
                @endif
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Slot</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">API ID</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse($canales as $canal)
            <tr>
                @if($user && $user->isAdmin())
                    <td class="px-6 py-4">{{ $canal->usuario->username ?? 'N/A' }}</td>
                    <td class="px-6 py-4">{{ $canal->grabador->nombre }}</td>
                @endif
                <td class="px-6 py-4 font-medium">{{ $canal->slot_nombre }}</td>
                <td class="px-6 py-4">{{ $canal->api_canal_id ?? '—' }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 rounded text-xs {{ $canal->activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $canal->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                </td>
                <td class="px-6 py-4 flex gap-2">
                    <a href="{{ route('canales.edit', $canal) }}"
                       class="text-indigo-600 hover:underline">Editar</a>
                    @if($canal->activo && $canal->api_canal_id)
                        <form action="{{ route('canales.ejecutar', $canal) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-green-600 hover:underline">
                                Ejecutar
                            </button>
                        </form>
                    @endif
                    <form action="{{ route('canales.destroy', $canal) }}" method="POST"
                          onsubmit="return confirm('¿Eliminar?')" class="inline">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-red-600 hover:underline">Eliminar</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="{{ $user && $user->isAdmin() ? '6' : '4' }}" class="px-6 py-4 text-center text-gray-500">
                    No hay canales
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection