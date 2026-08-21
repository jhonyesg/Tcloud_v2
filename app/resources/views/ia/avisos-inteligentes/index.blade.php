@extends('layouts.app')

@section('title', 'Avisos Inteligentes - Tcloud')

@section('content')
<div class="p-6" x-data="avisosInteligentes()" x-init="init()">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">Avisos Inteligentes</h1>
        <p class="text-slate-500 mt-0.5">Asigna el módulo a usuarios y gestiona cupo, correos y keywords</p>
    </div>

    <!-- Filtros -->
    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <div class="relative flex-1 min-w-[200px] max-w-xs">
            <input type="text" x-model="search" @input.debounce.400ms="load()" placeholder="Buscar por usuario o email..."
                   class="w-full border border-slate-300 rounded-lg pl-9 pr-4 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
            <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-sm"></i>
        </div>
        <select x-model="moduleFilter" @change="load()" class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
            <option value="">Todos</option>
            <option value="on">Módulo activo</option>
            <option value="off">Módulo inactivo</option>
        </select>
    </div>

    <!-- Tabla -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div x-show="loading" class="flex items-center justify-center py-16">
            <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
        </div>

        <div x-show="!loading && users.length === 0" class="text-center py-16 text-slate-400">
            <i class="fas fa-users text-4xl mb-3 block text-slate-200"></i>
            <p class="font-medium">No hay usuarios que coincidan</p>
        </div>

        <table x-show="!loading && users.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Usuario</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Módulo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Keywords</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell" title="Storages con acceso / storages asignados">Acceso</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="u in users" :key="u.id">
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-sm font-medium text-slate-700" x-text="u.username || u.email"></td>
                        <td class="px-4 py-3 text-sm text-slate-500" x-text="u.email"></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium"
                                  :class="u.alerts_inteligente?.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'">
                                <span class="w-1.5 h-1.5 rounded-full" :class="u.alerts_inteligente?.enabled ? 'bg-green-500' : 'bg-slate-400'"></span>
                                <span x-text="u.alerts_inteligente?.enabled ? 'Activo' : 'Inactivo'"></span>
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-600">
                            <span x-text="(u.keywords_count || 0) + ' / ' + (u.alerts_inteligente?.keywords_quota || 0)"></span>
                        </td>
                        {{-- Acceso = storages con transcription_access=true / storages asignados.
                             Es el control que el admin enciende en la ficha del cliente.
                             No es un atributo del storage; no se cuenta aquí la decisión de
                             api-transcriptor sobre el storage. --}}
                        <td class="px-4 py-3 hidden md:table-cell text-sm">
                            <span class="font-medium"
                                  :class="(u.storages_with_access || 0) > 0 ? 'text-green-700' : 'text-slate-400'"
                                  x-text="(u.storages_with_access || 0) + ' / ' + (u.storages_count || 0)"></span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button @click="openAssign(u)" class="px-3 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors">
                                    <i class="fas fa-edit text-[10px] mr-1"></i>Asignar
                                </button>
                                <a :href="'/ia/avisos-inteligentes/' + u.id"
                                   class="px-3 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors">
                                    <i class="fas fa-eye text-[10px] mr-1"></i>Detalle
                                </a>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div x-show="!loading && meta" class="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-500">
            <span x-text="'Página ' + meta.current_page + ' de ' + meta.last_page"></span>
            <div class="flex gap-2">
                <button @click="prevPage()" :disabled="!links.prev" class="px-3 py-1 border rounded disabled:opacity-40">Anterior</button>
                <button @click="nextPage()" :disabled="!links.next" class="px-3 py-1 border rounded disabled:opacity-40">Siguiente</button>
            </div>
        </div>
    </div>

    <!-- Modal asignar módulo -->
    <div x-cloak x-show="showAssign" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showAssign = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Asignar módulo</h2>
                <p class="text-sm text-slate-500 mb-5" x-text="currentUser?.username || currentUser?.email"></p>

                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <button @click="form.enabled = !form.enabled" :class="form.enabled ? 'bg-green-500' : 'bg-slate-300'" class="relative w-10 h-5 rounded-full transition-colors">
                            <span :class="form.enabled ? 'translate-x-5' : 'translate-x-1'" class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"></span>
                        </button>
                        <span class="text-sm text-slate-600" x-text="form.enabled ? 'Módulo activo' : 'Módulo inactivo'"></span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Cupo de keywords</label>
                        <input type="number" min="0" x-model.number="form.keywords_quota" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Cupo de correos</label>
                        <input type="number" min="0" x-model.number="form.emails_quota" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="saveAssign()" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Guardar</button>
                    <button @click="showAssign = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function avisosInteligentes() {
    return {
        loading: false,
        users: [],
        meta: null,
        links: {},
        page: 1,
        search: '',
        moduleFilter: '',
        showAssign: false,
        currentUser: null,
        form: { enabled: true, keywords_quota: 100, emails_quota: 1 },
        async init() { await this.load(); },
        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ page: this.page });
                if (this.search) params.set('q', this.search);
                if (this.moduleFilter) params.set('module', this.moduleFilter);
                const res = await apiFetch('/ia/avisos-inteligentes?' + params, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const d = await res.json();
                    this.users = d.data || [];
                    this.meta = { current_page: d.current_page, last_page: d.last_page };
                    this.links = { prev: d.prev_page_url, next: d.next_page_url };
                }
            } finally { this.loading = false; }
        },
        prevPage() { if (this.links.prev) { this.page--; this.load(); } },
        nextPage() { if (this.links.next) { this.page++; this.load(); } },
        openAssign(u) {
            this.currentUser = u;
            const cfg = u.alerts_inteligente || {};
            this.form = { enabled: !!cfg.enabled, keywords_quota: cfg.keywords_quota || 100, emails_quota: cfg.emails_quota || 1 };
            this.showAssign = true;
        },
        async saveAssign() {
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.currentUser.id, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showAssign = false; await this.load(); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || 'Error'); }
        },
    };
}
</script>
@endpush
@endsection