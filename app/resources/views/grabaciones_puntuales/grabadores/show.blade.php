@extends('layouts.app')

@section('title', 'Grabador: ' . $grabador->nombre . ' - Tcloud')

@section('content')
<div class="p-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('grabadores.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center hover:bg-slate-200 transition-colors">
                <i class="fas fa-arrow-left text-slate-600"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-server text-emerald-600"></i>
                    </div>
                    {{ $grabador->nombre }}
                </h1>
                <p class="text-sm text-gray-500 mt-1">{{ $grabador->ip }}:{{ $grabador->puerto }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <form action="{{ route('grabadores.destroy', $grabador) }}" method="POST"
                  onsubmit="return confirm('¿Eliminar este grabador? Esta acción no se puede deshacer.')">
                @csrf @method('DELETE')
                <button type="submit"
                        class="flex items-center gap-2 px-4 py-2.5 text-red-600 hover:bg-red-50 rounded-xl font-medium transition-colors text-sm">
                    <i class="fas fa-trash-alt text-sm"></i>
                    Eliminar
                </button>
            </form>
        </div>
    </div>

    <!-- Info Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-network-wired text-blue-600 text-sm"></i>
                </div>
                <span class="text-sm font-medium text-slate-500">Conexión</span>
            </div>
            <p class="text-sm text-slate-800 font-mono">{{ $grabador->ip }}:{{ $grabador->puerto }}</p>
            @if($grabador->base_url)
                <p class="text-xs text-slate-400 mt-1 font-mono">{{ $grabador->base_url }}</p>
            @endif
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 bg-emerald-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-signal text-emerald-600 text-sm"></i>
                </div>
                <span class="text-sm font-medium text-slate-500">Estado</span>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $grabador->activo ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $grabador->activo ? 'bg-green-500' : 'bg-red-500' }}"></span>
                {{ $grabador->activo ? 'Activo' : 'Inactivo' }}
            </span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 bg-violet-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-users text-violet-600 text-sm"></i>
                </div>
                <span class="text-sm font-medium text-slate-500">Resumen</span>
            </div>
            <div class="flex gap-6">
                <div>
                    <p class="text-2xl font-bold text-slate-800">{{ $grabador->canales->count() }}</p>
                    <p class="text-xs text-slate-400">Canales</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-slate-800">{{ $grabador->usuarios->count() }}</p>
                    <p class="text-xs text-slate-400">Usuarios</p>
                </div>
            </div>
        </div>
    </div>

    @if($grabador->observaciones)
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 flex items-start gap-3">
        <i class="fas fa-sticky-note text-amber-500 mt-0.5 flex-shrink-0"></i>
        <p class="text-sm text-amber-800">{{ $grabador->observaciones }}</p>
    </div>
    @endif

    <!-- Assigned Users -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800 flex items-center gap-2">
                <i class="fas fa-user-friends text-slate-400 text-sm"></i>
                Usuarios Asignados
            </h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Usuario</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Límite Canales</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Usados</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($grabador->usuarios as $usuario)
                    <?php $usados = $grabador->canales()->where('usuario_id', $usuario->id)->count(); ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-slate-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-slate-400 text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ $usuario->name }}</p>
                                    <p class="text-xs text-slate-400">{{ $usuario->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <form action="{{ route('grabadores.actualizar-asignacion', [$grabador, $usuario->id]) }}"
                                  method="POST" class="inline-flex items-center gap-2">
                                @csrf
                                <input type="number" name="limite_canales"
                                       value="{{ $usuario->pivot->limite_canales }}"
                                       min="1" max="100"
                                       class="w-16 border border-slate-300 rounded-lg px-2 py-1.5 text-sm text-center focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none">
                                <button type="submit"
                                        class="text-emerald-600 hover:text-emerald-800 text-sm font-medium transition-colors">
                                    <i class="fas fa-check text-xs"></i>
                                </button>
                            </form>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-medium text-slate-700">{{ $usados }}</span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <form action="{{ route('grabadores.remover-usuario', [$grabador, $usuario->id]) }}"
                                  method="POST" class="inline"
                                  onsubmit="return confirm('¿Remover este usuario del grabador?')">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition-colors">
                                    <i class="fas fa-times text-xs"></i>
                                    Remover
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center">
                                <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mb-3">
                                    <i class="fas fa-user-slash text-slate-300 text-lg"></i>
                                </div>
                                <p class="text-slate-500 text-sm">Sin usuarios asignados</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Assign User Form -->
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50">
            <form action="{{ route('grabadores.asignar-usuario', $grabador) }}" method="POST"
                  class="flex items-end gap-4">
                @csrf
                <div class="flex-1">
                    <label class="block text-xs font-medium text-slate-500 uppercase tracking-wider mb-1.5">Usuario</label>
                    <select name="user_id" required
                            class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none">
                        <option value="">Seleccionar usuario...</option>
                        @foreach($usuarios->where('role', '!=', 'admin') as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-slate-500 uppercase tracking-wider mb-1.5">Límite</label>
                    <input type="number" name="limite_canales" value="10" required
                           min="1" max="100"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-center focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none">
                </div>
                <button type="submit"
                        class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl font-medium transition-colors shadow-sm text-sm whitespace-nowrap">
                    <i class="fas fa-user-plus text-xs"></i>
                    Asignar
                </button>
            </form>
        </div>
    </div>

    <!-- Channels -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800 flex items-center gap-2">
                <i class="fas fa-broadcast-tower text-slate-400 text-sm"></i>
                Canales en este Grabador
            </h2>
            <a href="{{ route('canales.create') }}"
               class="flex items-center gap-1.5 text-sm text-emerald-600 hover:text-emerald-800 font-medium transition-colors">
                <i class="fas fa-plus text-xs"></i>
                Nuevo Canal
            </a>
        </div>

        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Slot</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Usuario</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">API ID</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($grabador->canales as $canal)
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-6 py-4 text-sm font-medium text-slate-800">{{ $canal->slot_nombre }}</td>
                    <td class="px-6 py-4 text-sm text-slate-600">{{ $canal->usuario->username ?? 'N/A' }}</td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $canal->activo ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $canal->activo ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            {{ $canal->activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-slate-500 font-mono">{{ $canal->api_canal_id ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-6 py-12 text-center">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mb-3">
                                <i class="fas fa-broadcast-tower text-slate-300 text-lg"></i>
                            </div>
                            <p class="text-slate-500 text-sm">Sin canales configurados</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
