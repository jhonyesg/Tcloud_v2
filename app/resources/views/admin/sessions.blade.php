@extends('layouts.app')

@section('title', 'Sesiones Activas - Tcloud')

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="{
    sessions: [],
    search: '',
    globalMax: 6,
    globalLifetime: 120,
    loading: true,
    savingSettings: false,
    toast: null,

    async init() {
        await this.loadSessions();
    },

    async loadSessions() {
        this.loading = true;
        const res = await fetch('/admin/sessions', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.status === 401) {
            window.location.href = '/login';
            return;
        }
        if (res.ok) {
            const data = await res.json();
            this.sessions = data.sessions;
            this.globalMax = data.global_max;
            this.globalLifetime = data.global_lifetime;
        }
        this.loading = false;
    },

    get filteredSessions() {
        if (!this.search) return this.sessions;
        const q = this.search.toLowerCase();
        return this.sessions.filter(s =>
            (s.user_email && s.user_email.toLowerCase().includes(q)) ||
            (s.user_username && s.user_username.toLowerCase().includes(q)) ||
            (s.ip_address && s.ip_address.includes(q))
        );
    },

    async killSession(id) {
        if (!confirm('¿Cerrar esta sesión?')) return;
        const isCurrentSession = this.sessions.some(s => s.id === id && s.is_current);
        const res = await fetch('/admin/sessions/' + id, {
            method: 'DELETE',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (res.ok) {
            this.showToast('Sesión cerrada correctamente', 'success');
            if (isCurrentSession) {
                setTimeout(() => window.location.href = '/login', 1200);
            } else {
                await this.loadSessions();
            }
        }
    },

    async killUserSessions(userId, userEmail) {
        if (!confirm('¿Cerrar TODAS las sesiones de ' + userEmail + '?')) return;
        const killingOwnSession = this.sessions.some(s => s.user_id === userId && s.is_current);
        const res = await fetch('/admin/sessions/user/' + userId, {
            method: 'DELETE',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (res.ok) {
            const data = await res.json();
            this.showToast(data.message, 'success');
            if (killingOwnSession) {
                setTimeout(() => window.location.href = '/login', 1200);
            } else {
                await this.loadSessions();
            }
        }
    },

    async saveGlobalSettings() {
        this.savingSettings = true;
        const res = await fetch('/admin/sessions/settings', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ global_max_sessions: this.globalMax, global_session_lifetime: this.globalLifetime })
        });
        this.savingSettings = false;
        if (res.ok) {
            this.showToast('Configuración global guardada', 'success');
        } else {
            this.showToast('Error al guardar', 'error');
        }
    },

    showToast(msg, type) {
        this.toast = { msg, type };
        setTimeout(() => this.toast = null, 3500);
    },

    formatDate(iso) {
        if (!iso) return '—';
        return new Date(iso).toLocaleString('es-CO');
    },

    shortAgent(ua) {
        if (!ua) return 'Desconocido';
        if (ua.includes('Chrome') && !ua.includes('Edg')) return 'Chrome';
        if (ua.includes('Firefox')) return 'Firefox';
        if (ua.includes('Safari') && !ua.includes('Chrome')) return 'Safari';
        if (ua.includes('Edg')) return 'Edge';
        return ua.substring(0, 30) + '...';
    },

    groupedByUser() {
        const map = {};
        this.filteredSessions.forEach(s => {
            const key = s.user_id;
            if (!map[key]) map[key] = { email: s.user_email, username: s.user_username, userId: s.user_id, sessions: [] };
            map[key].sessions.push(s);
        });
        return Object.values(map);
    }
}">

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div>
            <h1 class="text-lg sm:text-2xl font-bold text-slate-800">Sesiones Activas</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">Gestiona las sesiones de todos los usuarios</p>
        </div>
        <button @click="loadSessions()" class="flex items-center gap-2 px-3 sm:px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
            <i class="fas fa-sync-alt"></i>
            <span class="hidden sm:inline">Actualizar</span>
        </button>
    </div>

    <!-- Global Settings -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
        <h2 class="text-lg font-semibold text-slate-700 mb-4"><i class="fas fa-cog text-brand-500 mr-2"></i>Configuración Global</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1">Máximo de sesiones simultáneas (global)</label>
                <input type="number" min="0" x-model.number="globalMax"
                       class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <p class="text-xs text-slate-400 mt-1">0 = sin límite</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1">Duración de sesión global (minutos)</label>
                <input type="number" min="0" x-model.number="globalLifetime"
                       class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <p class="text-xs text-slate-400 mt-1">0 = sin expiración</p>
            </div>
        </div>
        <div class="mt-4 flex justify-end">
            <button @click="saveGlobalSettings()" :disabled="savingSettings"
                    class="px-5 py-2 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition-colors">
                <span x-show="!savingSettings">Guardar configuración global</span>
                <span x-show="savingSettings">Guardando...</span>
            </button>
        </div>
    </div>

    <!-- Search & Sessions Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm">
        <div class="p-4 border-b border-slate-100 flex items-center gap-3">
            <i class="fas fa-search text-slate-400"></i>
            <input type="text" x-model="search" placeholder="Filtrar por email, usuario o IP..."
                   class="flex-1 border-0 focus:ring-0 text-sm text-slate-700 placeholder-slate-400 outline-none">
            <span class="text-xs text-slate-400" x-text="filteredSessions.length + ' sesiones'"></span>
        </div>

        <!-- Loading -->
        <div x-show="loading" class="flex items-center justify-center py-16">
            <i class="fas fa-spinner fa-spin text-brand-500 text-2xl"></i>
        </div>

        <!-- Empty -->
        <div x-show="!loading && filteredSessions.length === 0" class="flex flex-col items-center justify-center py-16 text-slate-400">
            <i class="fas fa-user-slash text-4xl mb-3"></i>
            <p class="text-sm">No hay sesiones activas</p>
        </div>

        <!-- Sessions grouped by user -->
        <div x-show="!loading && filteredSessions.length > 0" class="divide-y divide-slate-100">
            <template x-for="group in groupedByUser()" :key="group.userId">
                <div class="p-4">
                    <!-- User header -->
                    <div class="flex items-center justify-between mb-3 gap-2">
                        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-brand-100 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user text-brand-600 text-xs sm:text-sm"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="font-medium text-slate-800 text-sm truncate" x-text="group.username || group.email"></p>
                                <p class="text-xs text-slate-400 truncate" x-text="group.email" x-show="group.username"></p>
                            </div>
                            <span class="flex-shrink-0 px-2 py-0.5 bg-brand-100 text-brand-700 rounded-full text-xs font-medium" x-text="group.sessions.length + ' sesión(es)'"></span>
                        </div>
                        <button @click="killUserSessions(group.userId, group.email)"
                                class="flex-shrink-0 flex items-center gap-1 px-2 sm:px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-medium rounded-lg transition-colors">
                            <i class="fas fa-ban"></i>
                            <span class="hidden sm:inline">Cerrar todas</span>
                        </button>
                    </div>

                    <!-- Sessions: mobile cards -->
                    <div class="sm:hidden space-y-2 mt-2">
                        <template x-for="s in group.sessions" :key="s.id">
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="text-xs font-mono text-slate-600 truncate" x-text="s.ip_address || '—'"></span>
                                        <span class="px-2 py-0.5 bg-white border border-slate-200 text-slate-600 rounded text-xs" x-text="shortAgent(s.user_agent)"></span>
                                    </div>
                                    <div class="flex items-center gap-1.5 flex-shrink-0">
                                        <span x-show="s.is_current" class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                            <i class="fas fa-circle text-[6px]"></i> Actual
                                        </span>
                                        <button @click="killSession(s.id)"
                                                class="w-7 h-7 flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-600 rounded-lg transition-colors">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs text-slate-500">
                                    <span>Inicio: <span class="text-slate-700" x-text="formatDate(s.created_at)"></span></span>
                                    <span>Actividad: <span class="text-slate-700" x-text="formatDate(s.last_activity_at)"></span></span>
                                    <span class="col-span-2" :class="s.expires_at ? 'text-slate-500' : 'text-green-600'">
                                        Expira: <span x-text="s.expires_at ? formatDate(s.expires_at) : 'Sin expiración'"></span>
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Sessions: desktop table -->
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-slate-400 border-b border-slate-100">
                                    <th class="pb-2 pl-2">IP</th>
                                    <th class="pb-2">Dispositivo</th>
                                    <th class="pb-2">Inicio</th>
                                    <th class="pb-2">Última actividad</th>
                                    <th class="pb-2">Expira</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="s in group.sessions" :key="s.id">
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="py-2 pl-2 text-slate-600 font-mono text-xs" x-text="s.ip_address || '—'"></td>
                                        <td class="py-2 text-slate-600" x-text="shortAgent(s.user_agent)"></td>
                                        <td class="py-2 text-slate-500 text-xs" x-text="formatDate(s.created_at)"></td>
                                        <td class="py-2 text-slate-500 text-xs" x-text="formatDate(s.last_activity_at)"></td>
                                        <td class="py-2 text-xs" :class="s.expires_at ? 'text-slate-500' : 'text-green-600'">
                                            <span x-text="s.expires_at ? formatDate(s.expires_at) : 'Sin expiración'"></span>
                                        </td>
                                        <td class="py-2 text-right">
                                            <span x-show="s.is_current" class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium mr-2">
                                                <i class="fas fa-circle text-[6px]"></i> Esta sesión
                                            </span>
                                            <button @click="killSession(s.id)"
                                                    class="px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs rounded-lg transition-colors">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection
