@extends('grabaciones_puntuales.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold">Grabador: {{ $grabador->nombre }}</h2>
    <div class="flex gap-2">
        <a href="{{ route('grabadores.edit', $grabador) }}"
           class="bg-yellow-500 text-white px-4 py-2 rounded hover:bg-yellow-600">
            Editar
        </a>
        <form action="{{ route('grabadores.destroy', $grabador) }}" method="POST"
              onsubmit="return confirm('¿Eliminar grabador?')">
            @csrf @method('DELETE')
            <button type="submit"
                    class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                Eliminar
            </button>
        </form>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-lg mb-4">Información</h3>
        <p><strong>IP:</strong> {{ $grabador->ip }}</p>
        <p><strong>Puerto:</strong> {{ $grabador->puerto }}</p>
        <p><strong>Base URL:</strong> {{ $grabador->base_url }}</p>
        <p><strong>Estado:</strong>
            <span class="px-2 py-1 rounded text-xs {{ $grabador->activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                {{ $grabador->activo ? 'Activo' : 'Inactivo' }}
            </span>
        </p>
        @if($grabador->observaciones)
            <p><strong>Notas:</strong> {{ $grabador->observaciones }}</p>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-lg mb-4">Resumen</h3>
        <p><strong>Canales totales:</strong> {{ $grabador->canales->count() }}</p>
        <p><strong>Usuarios asignados:</strong> {{ $grabador->usuarios->count() }}</p>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h3 class="font-bold text-lg mb-4">Usuarios Asignados</h3>

    <table class="w-full mb-4">
        <thead>
            <tr>
                <th class="text-left">Usuario</th>
                <th class="text-left">Límite Canales</th>
                <th class="text-left">Usados</th>
                <th class="text-left">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($grabador->usuarios as $usuario)
            <?php
                $usados = $grabador->canales()->where('usuario_id', $usuario->id)->count();
            ?>
            <tr>
                <td>{{ $usuario->name }} ({{ $usuario->email }})</td>
                <td>{{ $usuario->pivot->limite_canales }}</td>
                <td>{{ $usados }}</td>
                <td>
                    <form action="{{ route('grabadores.actualizar-asignacion', [$grabador, $usuario->id]) }}"
                          method="POST" class="inline-flex gap-2">
                        @csrf
                        <input type="number" name="limite_canales"
                               value="{{ $usuario->pivot->limite_canales }}"
                               min="1" max="100" class="w-20 border rounded px-2 py-1">
                        <button type="submit" class="text-blue-600 hover:underline">Actualizar</button>
                    </form>
                    <form action="{{ route('grabadores.remover-usuario', [$grabador, $usuario->id]) }}"
                          method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-red-600 hover:underline">Remover</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-gray-500 text-center py-2">Sin usuarios asignados</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <form action="{{ route('grabadores.asignar-usuario', $grabador) }}" method="POST"
          class="flex gap-4 items-end">
        @csrf
        <div>
            <label class="block text-sm">Usuario</label>
            <select name="user_id" required class="border rounded px-3 py-2">
                <option value="">Seleccionar...</option>
                @foreach($usuarios->where('role', '!=', 'admin') as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm">Límite canales</label>
            <input type="number" name="limite_canales" value="10" required
                   min="1" max="100" class="border rounded px-3 py-2 w-20">
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
            Asignar
        </button>
    </form>
</div>

<div class="bg-white rounded-lg shadow p-6">
    <h3 class="font-bold text-lg mb-4">Canales en este Grabador</h3>
    <table class="w-full">
        <thead>
            <tr>
                <th class="text-left">Slot</th>
                <th class="text-left">Usuario</th>
                <th class="text-left">Estado</th>
                <th class="text-left">API ID</th>
            </tr>
        </thead>
        <tbody>
            @forelse($grabador->canales as $canal)
            <tr>
                <td>{{ $canal->slot_nombre }}</td>
                <td>{{ $canal->usuario->name ?? 'N/A' }}</td>
                <td>
                    <span class="px-2 py-1 rounded text-xs {{ $canal->activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $canal->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                </td>
                <td>{{ $canal->api_canal_id ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-gray-500 text-center py-2">Sin canales</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection