@extends('layouts.app')

@section('title', 'Sites Externos - Tcloud')

@section('content')
<div class="p-6" x-data="externalSitesAdmin()" x-init="init()">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Sites Externos</h1>
            <p class="text-slate-500 mt-0.5">Gestiona sitios web embebidos y sus asignaciones</p>
        </div>
        <button @click="openCreate()"
                class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
            <i class="fas fa-plus"></i>
            <span x-show="true">Nuevo Site</span>
        </button>
    </div>

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Tabla -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div x-show="loading" class="flex items-center justify-center py-16">
            <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
        </div>

        <div x-show="!loading">
            <div x-show="sites.length === 0" class="text-center py-16 text-slate-400">
                <i class="fas fa-globe text-4xl mb-3 block text-slate-200"></i>
                <p class="font-medium">No hay sites registrados</p>
                <p class="text-sm mt-1">Crea el primero con el botón "Nuevo Site"</p>
            </div>

            <table x-show="sites.length > 0" class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Site</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">URL</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Estado</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Usuarios</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="site in sites" :key="site.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                                         :style="'background-color:' + colorBg(site.color)">
                                        <i :class="'fas ' + site.icon" :style="'color:' + colorText(site.color)"></i>
                                    </div>
                                    <span class="font-medium text-slate-800 text-sm" x-text="site.name"></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                <a :href="site.url" target="_blank"
                                   class="text-xs text-brand-600 hover:underline truncate max-w-[200px] block" x-text="site.url"></a>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="site.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'"
                                      class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full" :class="site.enabled ? 'bg-green-500' : 'bg-slate-400'"></span>
                                    <span x-text="site.enabled ? 'Activo' : 'Inactivo'"></span>
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden sm:table-cell">
                                <button @click="openUsers(site)"
                                        class="flex items-center gap-1.5 px-3 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors">
                                    <i class="fas fa-users text-[10px]"></i>
                                    Usuarios
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="openEdit(site)"
                                            class="w-8 h-8 flex items-center justify-center bg-slate-100 hover:bg-brand-50 text-slate-500 hover:text-brand-600 rounded-lg transition-colors">
                                        <i class="fas fa-edit text-xs"></i>
                                    </button>
                                    <button @click="deleteSite(site)"
                                            class="w-8 h-8 flex items-center justify-center bg-slate-100 hover:bg-red-50 text-slate-500 hover:text-red-600 rounded-lg transition-colors">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Crear/Editar -->
    <div x-cloak x-show="showForm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl" @click.away="showForm = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5" x-text="editingId ? 'Editar Site' : 'Nuevo Site Externo'"></h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Nombre</label>
                        <input type="text" x-model="form.name" placeholder="Ej: Panel de Prensa"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">URL (debe ser HTTPS)</label>
                        <input type="url" x-model="form.url" placeholder="https://ejemplo.com/panel"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-2 uppercase tracking-wide">Icono</label>
                        <div class="grid grid-cols-10 gap-1.5">
                            <template x-for="ico in icons" :key="ico">
                                <button type="button" @click="form.icon = ico"
                                        :class="form.icon === ico ? 'bg-brand-100 border-brand-400 text-brand-700' : 'bg-slate-50 border-slate-200 text-slate-500 hover:bg-slate-100'"
                                        class="w-9 h-9 flex items-center justify-center rounded-lg border text-sm transition-all"
                                        :title="ico">
                                    <i :class="'fas ' + ico"></i>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-2 uppercase tracking-wide">Color</label>
                        <div class="flex gap-2 flex-wrap">
                            <template x-for="col in colors" :key="col.name">
                                <button type="button" @click="form.color = col.name"
                                        :class="form.color === col.name ? 'ring-2 ring-offset-1 ring-slate-400 scale-110' : 'hover:scale-105'"
                                        class="w-8 h-8 rounded-full transition-all"
                                        :style="'background-color:' + col.hex"
                                        :title="col.name"></button>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="button" @click="form.enabled = !form.enabled"
                                :class="form.enabled ? 'bg-green-500' : 'bg-slate-300'"
                                class="relative w-10 h-5 rounded-full transition-colors">
                            <span :class="form.enabled ? 'translate-x-5' : 'translate-x-1'"
                                  class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"></span>
                        </button>
                        <span class="text-sm text-slate-600" x-text="form.enabled ? 'Site activo' : 'Site inactivo'"></span>
                    </div>

                    <!-- Preview -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                             :style="'background-color:' + colorBg(form.color)">
                            <i :class="'fas ' + form.icon" :style="'color:' + colorText(form.color)"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-700" x-text="form.name || 'Nombre del site'"></p>
                            <p class="text-xs text-slate-400 truncate max-w-[250px]" x-text="form.url || 'https://...'"></p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="saveSite()"
                            class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-save mr-1.5"></i>
                        <span x-text="editingId ? 'Guardar cambios' : 'Crear site'"></span>
                    </button>
                    <button @click="showForm = false"
                            class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Usuarios -->
    <div x-cloak x-show="showUsers" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showUsers = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Usuarios asignados</h2>
                <p class="text-sm text-slate-500 mb-5" x-text="'Site: ' + (currentSite?.name || '')"></p>

                <!-- Buscador -->
                <div class="relative mb-4">
                    <input type="text" x-model="userSearch" @input.debounce.300ms="searchUsers()"
                           placeholder="Buscar usuario para asignar..."
                           class="w-full border border-slate-300 rounded-lg pl-9 pr-4 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-sm"></i>
                </div>
                <div x-show="availableUsers().length > 0" class="mb-4 border border-slate-200 rounded-lg overflow-hidden divide-y divide-slate-100 max-h-40 overflow-y-auto">
                    <template x-for="u in availableUsers()" :key="u.id">
                        <div class="flex items-center justify-between px-3 py-2 hover:bg-slate-50">
                            <div>
                                <p class="text-sm font-medium text-slate-700" x-text="u.username || u.email"></p>
                                <p class="text-xs text-slate-400" x-text="u.email"></p>
                            </div>
                            <button @click="assignUser(u)"
                                    class="px-3 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 text-xs font-medium rounded-lg transition-colors">
                                Asignar
                            </button>
                        </div>
                    </template>
                </div>
                <div x-show="availableUsers().length === 0 && userResults.length > 0" class="mb-4 py-2 text-center text-xs text-slate-400">
                    Todos los usuarios encontrados ya están asignados
                </div>

                <!-- Asignados -->
                <div class="space-y-2 max-h-52 overflow-y-auto">
                    <div x-show="assignedUsers.length === 0" class="text-center py-6 text-slate-400 text-sm">
                        Sin usuarios asignados
                    </div>
                    <template x-for="u in assignedUsers" :key="u.id">
                        <div class="flex items-center justify-between px-3 py-2 bg-slate-50 rounded-lg">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 bg-brand-100 rounded-full flex items-center justify-center text-xs font-bold text-brand-700"
                                     x-text="(u.username || u.email || '?').charAt(0).toUpperCase()"></div>
                                <div>
                                    <p class="text-sm font-medium text-slate-700" x-text="u.username || u.email"></p>
                                    <p class="text-xs text-slate-400" x-text="u.email"></p>
                                </div>
                            </div>
                            <button @click="removeUser(u)"
                                    class="w-7 h-7 flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-500 rounded-lg transition-colors">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>
                    </template>
                </div>

                <button @click="showUsers = false"
                        class="w-full mt-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function externalSitesAdmin() {
    return {
        sites: [],
        loading: true,
        showForm: false,
        showUsers: false,
        editingId: null,
        currentSite: null,
        toast: null,
        userSearch: '',
        userResults: [],
        assignedUsers: [],
        form: { name: '', url: '', icon: 'fa-globe', color: 'blue', enabled: true },

        availableUsers() {
            const assignedIds = new Set(this.assignedUsers.map(u => u.id));
            return this.userResults.filter(u => !assignedIds.has(u.id));
        },

        icons: [
            'fa-globe','fa-tv','fa-chart-bar','fa-chart-line','fa-video',
            'fa-newspaper','fa-broadcast-tower','fa-satellite-dish','fa-camera',
            'fa-film','fa-music','fa-microphone','fa-rss','fa-link',
            'fa-desktop','fa-server','fa-database','fa-cloud','fa-cog',
            'fa-shield-alt','fa-lock','fa-users','fa-bullhorn','fa-tools',
        ],
        colors: [
            { name: 'blue',   hex: '#2563eb' },
            { name: 'green',  hex: '#16a34a' },
            { name: 'red',    hex: '#dc2626' },
            { name: 'purple', hex: '#9333ea' },
            { name: 'amber',  hex: '#d97706' },
            { name: 'cyan',   hex: '#0891b2' },
            { name: 'rose',   hex: '#e11d48' },
            { name: 'slate',  hex: '#64748b' },
        ],
        colorBg(c) {
            const m = { blue:'#dbeafe', green:'#dcfce7', red:'#fee2e2', purple:'#f3e8ff', amber:'#fef3c7', cyan:'#cffafe', rose:'#ffe4e6', slate:'#f1f5f9' };
            return m[c] || '#f1f5f9';
        },
        colorText(c) {
            const m = { blue:'#2563eb', green:'#16a34a', red:'#dc2626', purple:'#9333ea', amber:'#d97706', cyan:'#0891b2', rose:'#e11d48', slate:'#64748b' };
            return m[c] || '#64748b';
        },

        async init() {
            await this.load();
        },

        async load() {
            this.loading = true;
            const res = await fetch('/admin/external-sites', { credentials: 'include', headers: { 'Accept': 'application/json' } });
            if (res.ok) this.sites = await res.json();
            this.loading = false;
        },

        openCreate() {
            this.editingId = null;
            this.form = { name: '', url: '', icon: 'fa-globe', color: 'blue', enabled: true };
            this.showForm = true;
        },

        openEdit(site) {
            this.editingId = site.id;
            this.form = { name: site.name, url: site.url, icon: site.icon, color: site.color, enabled: site.enabled };
            this.showForm = true;
        },

        async saveSite() {
            const method = this.editingId ? 'PUT' : 'POST';
            const url = this.editingId ? `/admin/external-sites/${this.editingId}` : '/admin/external-sites';
            const res = await fetch(url, {
                method,
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(this.form),
            });
            const data = await res.json();
            if (res.ok) {
                this.showForm = false;
                await this.load();
                this.showToast(this.editingId ? 'Site actualizado' : 'Site creado', 'success');
            } else {
                this.showToast(Object.values(data.errors || {})[0]?.[0] || data.message || 'Error', 'error');
            }
        },

        async deleteSite(site) {
            if (!confirm(`¿Eliminar el site "${site.name}"? Se quitará de todos los usuarios.`)) return;
            const res = await fetch(`/admin/external-sites/${site.id}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            });
            if (res.ok) { await this.load(); this.showToast('Site eliminado', 'success'); }
            else this.showToast('Error al eliminar', 'error');
        },

        async openUsers(site) {
            this.currentSite = site;
            this.userSearch = '';
            this.userResults = [];
            await this.loadAssigned();
            await this.searchUsers();
            this.showUsers = true;
        },

        async loadAssigned() {
            const res = await fetch(`/admin/external-sites/${this.currentSite.id}/users`, {
                credentials: 'include', headers: { 'Accept': 'application/json' },
            });
            if (res.ok) { const d = await res.json(); this.assignedUsers = d.users; }
        },

        async searchUsers() {
            const res = await fetch(`/admin/users/search?q=${encodeURIComponent(this.userSearch)}`, {
                credentials: 'include', headers: { 'Accept': 'application/json' },
            });
            if (res.ok) { const d = await res.json(); this.userResults = d.users || d; }
        },

        async assignUser(u) {
            const res = await fetch(`/admin/external-sites/${this.currentSite.id}/users`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ user_id: u.id }),
            });
            if (res.ok) { this.userSearch = ''; this.userResults = []; await this.loadAssigned(); this.showToast('Usuario asignado', 'success'); }
            else this.showToast('Error al asignar', 'error');
        },

        async removeUser(u) {
            const res = await fetch(`/admin/external-sites/${this.currentSite.id}/users/${u.id}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            });
            if (res.ok) { await this.loadAssigned(); this.showToast('Asignación eliminada', 'success'); }
            else this.showToast('Error', 'error');
        },

        showToast(msg, type) {
            this.toast = { msg, type };
            setTimeout(() => this.toast = null, 3500);
        },
    };
}
</script>
@endsection
