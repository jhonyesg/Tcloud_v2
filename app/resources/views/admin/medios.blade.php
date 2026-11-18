@extends('layouts.app')

@section('title', 'Medios de TV y Radio - Tcloud')

@section('content')
<div class="p-6" x-data="mediosAdmin()" x-init="init()">

    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Medios de TV y Radio</h1>
            <p class="text-slate-500 mt-0.5">
                Catálogo de canales que el cliente puede pedir en el módulo de Solicitud Puntual.
                <span class="text-slate-400" x-text="totalMedios + ' declarados'"></span>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <input type="search" x-model="search" @input.debounce.400ms="reload()"
                   placeholder="Buscar medio, región o ruta…"
                   class="w-64 px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            <select x-model="kind" @change="reload()"
                    class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="">Todos los tipos</option>
                <option value="tv">TV</option>
                <option value="radio">Radio</option>
            </select>
        </div>
    </div>

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Contenedores: los que tienen sub-canales por declarar -->
    <div class="mb-6">
        <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Contenedores por remapear</h2>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <template x-for="c in containers" :key="c.id">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-800 truncate" x-text="c.name"></p>
                            <p class="text-xs text-slate-400 truncate mt-0.5" :title="c.base_path" x-text="c.base_path"></p>
                        </div>
                        <span class="shrink-0 px-2 py-0.5 rounded-full text-xs font-semibold"
                              :class="c.pendientes > 0 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'"
                              x-text="c.pendientes > 0 ? c.pendientes + ' pendientes' : 'al dia'"></span>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-xs text-slate-500">
                            <span x-text="c.detectados"></span> canales detectados
                        </span>
                        <button @click="openDetect(c)"
                                :disabled="c.pendientes === 0"
                                class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg text-xs font-medium transition-colors">
                            Remapear
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <p x-show="containers.length === 0" class="text-sm text-slate-400">
            No hay contenedores con layout agrupado.
        </p>
    </div>

    <!-- Medios declarados -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Medios declarados</h2>
            <span class="text-xs text-slate-500" x-text="medios.length + ' en la vista'"></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Medio</th>
                        <th class="px-4 py-2 text-left font-semibold">Región</th>
                        <th class="px-4 py-2 text-left font-semibold">Tipo</th>
                        <th class="px-4 py-2 text-left font-semibold">Storage</th>
                        <th class="px-4 py-2 text-left font-semibold">Ruta interna</th>
                        <th class="px-4 py-2 text-left font-semibold">Estado</th>
                        <th class="px-4 py-2 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="m in medios" :key="m.id">
                        <tr :class="m.activo ? '' : 'opacity-50'">
                            <td class="px-4 py-2 font-medium text-slate-800" x-text="m.name"></td>
                            <td class="px-4 py-2 text-slate-600" x-text="m.region || '—'"></td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                      :class="m.media_kind === 'tv' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                      x-text="m.media_kind.toUpperCase()"></span>
                            </td>
                            <td class="px-4 py-2 text-slate-600" x-text="m.storage?.name || '—'"></td>
                            <td class="px-4 py-2 text-slate-400 font-mono text-xs" x-text="m.subpath || '(raíz)'"></td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                      :class="m.activo ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'"
                                      x-text="m.activo ? 'activo' : 'inactivo'"></span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button @click="toggleActivo(m)"
                                        class="text-xs font-medium px-2 py-1 rounded-lg"
                                        :class="m.activo ? 'text-slate-600 hover:bg-slate-100' : 'text-brand-600 hover:bg-brand-50'">
                                    <span x-text="m.activo ? 'Dar de baja' : 'Reactivar'"></span>
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="medios.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                            No hay medios que coincidan.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: previsualización del remapeo -->
    <div x-cloak x-show="detectOpen" x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
         @keydown.escape.window="detectOpen = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-200 flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-bold text-slate-800">Remapear sub-canales</h3>
                    <p class="text-sm text-slate-500 mt-0.5" x-text="detectTarget ? detectTarget.name : ''"></p>
                </div>
                <button @click="detectOpen = false" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="px-6 py-3 bg-amber-50 border-b border-amber-200 text-sm text-amber-800">
                <i class="fas fa-circle-info mr-1"></i>
                Se declararán medios en el catálogo. No se crean storages ni se tocan las filas de <code>files</code>.
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4">
                <div x-show="detectLoading" class="text-sm text-slate-400 py-6 text-center">
                    <i class="fas fa-spinner fa-spin mr-2"></i> Leyendo el disco…
                </div>

                <table x-show="!detectLoading" class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold">Canal</th>
                            <th class="px-2 py-2 text-left font-semibold">Nombre</th>
                            <th class="px-2 py-2 text-left font-semibold">Región</th>
                            <th class="px-2 py-2 text-left font-semibold">Tipo</th>
                            <th class="px-2 py-2 text-left font-semibold">Medio sobre</th>
                            <th class="px-2 py-2 text-left font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="p in proposals" :key="p.subpath">
                            <tr :class="p.declared ? 'opacity-50' : ''">
                                <td class="px-2 py-1.5 font-mono text-xs text-slate-600" x-text="p.subpath"></td>
                                <td class="px-2 py-1.5 text-slate-800" x-text="p.name"></td>
                                <td class="px-2 py-1.5 text-slate-600" x-text="p.region || '—'"></td>
                                <td class="px-2 py-1.5">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-semibold"
                                          :class="p.media_kind === 'tv' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                          x-text="p.media_kind.toUpperCase()"></span>
                                </td>
                                <td class="px-2 py-1.5 text-slate-600" x-text="p.target_storage_name"></td>
                                <td class="px-2 py-1.5">
                                    <span class="text-xs"
                                          :class="p.declared ? 'text-slate-400' : 'text-green-600 font-medium'"
                                          x-text="p.declared ? 'ya declarado' : 'nuevo'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
                <span class="text-sm text-slate-500">
                    <span x-text="pendingCount"></span> por declarar de <span x-text="proposals.length"></span> detectados
                </span>
                <div class="flex items-center gap-2">
                    <button @click="detectOpen = false"
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                    <button @click="applyDetect()" :disabled="pendingCount === 0 || applying"
                            class="px-4 py-2 bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-check mr-1" :class="applying ? 'fa-spin' : ''"></i>
                        <span x-text="applying ? 'Declarando…' : 'Declarar ' + pendingCount"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
function mediosAdmin() {
    return {
        medios: [],
        containers: [],
        totalMedios: {{ $totalMedios ?? 0 }},
        search: '{{ $search ?? '' }}',
        kind: '{{ $kind ?? '' }}',
        toast: null,
        detectOpen: false,
        detectLoading: false,
        detectTarget: null,
        proposals: [],
        applying: false,

        get pendingCount() {
            return this.proposals.filter(p => !p.declared).length;
        },

        init() {
            this.load();
        },

        load() {
            this.reload();
        },

        async reload() {
            const params = new URLSearchParams();
            if (this.search) params.set('q', this.search);
            if (this.kind) params.set('kind', this.kind);

            try {
                const res = await fetch(`/admin/medios?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!res.ok) throw new Error('respuesta ' + res.status);

                const data = await res.json();
                this.medios = data.medios ?? [];
                this.containers = data.containers ?? [];
                this.totalMedios = data.total ?? this.medios.length;
            } catch (e) {
                this.showToast('No se pudo cargar el catálogo: ' + e.message, 'error');
            }
        },

        async openDetect(container) {
            this.detectTarget = container;
            this.proposals = [];
            this.detectOpen = true;
            this.detectLoading = true;

            try {
                const res = await fetch(`/admin/medios/detect?storage_id=${container.id}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!res.ok) throw new Error('respuesta ' + res.status);

                const data = await res.json();
                this.proposals = data.canales ?? [];
            } catch (e) {
                this.showToast('No se pudo leer el contenedor: ' + e.message, 'error');
                this.detectOpen = false;
            } finally {
                this.detectLoading = false;
            }
        },

        async applyDetect() {
            if (!this.detectTarget) return;

            this.applying = true;

            try {
                const res = await fetch('/admin/medios/remapear', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ storage_id: this.detectTarget.id }),
                });

                if (!res.ok) throw new Error('respuesta ' + res.status);

                const data = await res.json();
                this.detectOpen = false;
                this.showToast(`${data.escritos} medios declarados`, 'success');
                this.reload();
            } catch (e) {
                this.showToast('No se pudieron declarar los medios: ' + e.message, 'error');
            } finally {
                this.applying = false;
            }
        },

        async toggleActivo(m) {
            try {
                const res = await fetch(`/admin/medios/${m.id}`, {
                    method: m.activo ? 'DELETE' : 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: m.activo ? undefined : JSON.stringify({ activo: true }),
                });

                if (!res.ok) throw new Error('respuesta ' + res.status);

                this.showToast(m.activo ? 'Medio dado de baja' : 'Medio reactivado', 'success');
                this.reload();
            } catch (e) {
                this.showToast('No se pudo actualizar el medio: ' + e.message, 'error');
            }
        },

        showToast(msg, type = 'success') {
            this.toast = { msg, type };
            setTimeout(() => { this.toast = null; }, 4000);
        },
    };
}
</script>
@endpush
