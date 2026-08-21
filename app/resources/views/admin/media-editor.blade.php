@extends('layouts.app')

@section('title', 'Editor de Medios - Admin - Tcloud')

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="{
    users: [],
    stats: { clips_this_month: 0, clips_total: 0, active_users: 0, failed_this_month: 0, ramdisk_total_gb: 20, ramdisk_used_gb: 0, ramdisk_free_gb: 20, ramdisk_percent: 0, ramdisk_available: false },
    loading: true,
    savingId: null,
    feedbackId: null,
    feedbackType: '',
    currentMonth: '',

    async init() {
        const now = new Date();
        this.currentMonth = now.toLocaleString('es-ES', { month: 'long', year: 'numeric' });
        await Promise.all([this.loadStats(), this.loadUsers()]);
        this.loading = false;
    },

    async loadStats() {
        const res = await apiFetch('/admin/media-editor/stats', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) this.stats = await res.json();
    },

    async loadUsers() {
        const res = await apiFetch('/admin/media-editor/users', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) this.users = await res.json();
    },

    async toggleEnabled(user) {
        if (user.role === 'admin') return;
        this.savingId = user.id;
        const newVal = !user.media_editor_enabled;
        const res = await apiFetch('/admin/media-editor/users/' + user.id, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ media_editor_enabled: newVal })
        });
        if (res.ok) {
            const data = await res.json();
            user.media_editor_enabled = data.media_editor_enabled;
            user.can_use_media_editor = user.role === 'admin' || data.media_editor_enabled;
            this.showFeedback(user.id, 'ok');
            await this.loadStats();
        } else {
            this.showFeedback(user.id, 'err');
        }
        this.savingId = null;
    },

    async saveLimit(user) {
        this.savingId = user.id;
        const limit = parseInt(user.media_editor_clip_limit) || 0;
        const res = await apiFetch('/admin/media-editor/users/' + user.id, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ media_editor_clip_limit: limit })
        });
        if (res.ok) {
            const data = await res.json();
            user.media_editor_clip_limit = data.media_editor_clip_limit;
            this.showFeedback(user.id, 'ok');
        } else {
            this.showFeedback(user.id, 'err');
        }
        this.savingId = null;
    },

    showFeedback(id, type) {
        this.feedbackId = id;
        this.feedbackType = type;
        setTimeout(() => { if (this.feedbackId === id) { this.feedbackId = null; } }, 2000);
    },

    limitLabel(user) {
        if (user.role === 'admin') return '∞ (admin)';
        const l = user.media_editor_clip_limit;
        return l === 0 ? 'Ilimitado' : l + ' / mes';
    },

    usagePercent(user) {
        const limit = user.media_editor_clip_limit;
        if (limit === 0 || user.role === 'admin') return 0;
        return Math.min(100, Math.round((user.clips_this_month / limit) * 100));
    },

    formatDate(d) {
        if (!d) return 'Nunca';
        return new Date(d).toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
}" x-init="init()">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div>
            <h1 class="text-lg sm:text-2xl font-bold text-gray-800 flex items-center gap-2 sm:gap-3">
                <div class="w-9 h-9 sm:w-10 sm:h-10 bg-violet-100 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-cut text-violet-600"></i>
                </div>
                Editor de Medios
            </h1>
            <p class="text-xs sm:text-sm text-gray-500 mt-1">Gestiona el acceso y los límites del editor de corte por usuario</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="startMediaEditorTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-xl text-sm transition-colors shadow-sm" title="Guía interactiva">
                <i class="fas fa-map-marked-alt"></i>
                <span class="hidden sm:inline">Guía</span>
            </button>
            <span class="text-xs sm:text-sm text-gray-400 capitalize" x-text="currentMonth"></span>
        </div>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 bg-violet-100 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-film text-violet-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800" x-text="stats.clips_this_month"></p>
                <p class="text-xs text-slate-500">Cortes este mes</p>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-layer-group text-blue-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800" x-text="stats.clips_total"></p>
                <p class="text-xs text-slate-500">Cortes totales</p>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-users text-green-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800" x-text="stats.active_users"></p>
                <p class="text-xs text-slate-500">Usuarios con acceso</p>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800" x-text="stats.failed_this_month"></p>
                <p class="text-xs text-slate-500">Errores este mes</p>
            </div>
        </div>
    </div>

    <!-- RAM disk card -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-8">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-indigo-100 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-memory text-indigo-600"></i>
                </div>
                <div>
                    <p class="text-sm font-semibold text-slate-800">RAM Disk FFmpeg</p>
                    <p class="text-xs text-slate-400">/mnt/cliptemp — tmpfs en memoria</p>
                </div>
            </div>
            <template x-if="!stats.ramdisk_available">
                <span class="text-xs text-red-500 font-medium flex items-center gap-1">
                    <i class="fas fa-exclamation-circle"></i> No montado
                </span>
            </template>
            <template x-if="stats.ramdisk_available">
                <span class="text-sm font-mono text-slate-600">
                    <span x-text="stats.ramdisk_used_gb"></span> GB
                    <span class="text-slate-400">/ <span x-text="stats.ramdisk_total_gb"></span> GB</span>
                </span>
            </template>
        </div>
        <template x-if="stats.ramdisk_available">
            <div>
                <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                    <div class="h-2.5 rounded-full transition-all duration-500"
                         :class="stats.ramdisk_percent >= 90 ? 'bg-red-500' : stats.ramdisk_percent >= 70 ? 'bg-amber-400' : 'bg-indigo-500'"
                         :style="'width: ' + Math.max(stats.ramdisk_percent, 0.5) + '%'"></div>
                </div>
                <div class="flex justify-between mt-1.5 text-xs text-slate-400">
                    <span><span x-text="stats.ramdisk_percent"></span>% usado</span>
                    <span><span x-text="stats.ramdisk_free_gb"></span> GB libres</span>
                </div>
            </div>
        </template>
        <template x-if="!stats.ramdisk_available">
            <p class="text-xs text-slate-400 mt-1">El directorio /mnt/cliptemp no está accesible. Verifica que el tmpfs esté montado.</p>
        </template>
    </div>

    <!-- Users section -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 sm:px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Usuarios</h2>
            <p class="text-xs text-slate-400 hidden sm:block">Los cambios se guardan en tiempo real</p>
        </div>

        <div x-show="loading" class="flex items-center justify-center py-16">
            <i class="fas fa-spinner fa-spin text-2xl text-slate-300"></i>
        </div>

        {{-- Vista móvil: tarjetas --}}
        <div x-show="!loading" class="sm:hidden divide-y divide-slate-100">
            <template x-for="user in users" :key="user.id">
                <div class="p-4">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                             :class="user.role === 'admin' ? 'bg-purple-100' : 'bg-slate-100'">
                            <i class="fas fa-user text-sm"
                               :class="user.role === 'admin' ? 'text-purple-600' : 'text-slate-400'"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-slate-800 text-sm truncate" x-text="user.username || user.email"></p>
                            <p class="text-xs text-slate-400 truncate" x-text="user.email" x-show="user.username"></p>
                        </div>
                        <span class="inline-block px-2 py-0.5 text-xs rounded-full font-medium flex-shrink-0"
                              :class="user.role === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-600'"
                              x-text="user.role === 'admin' ? 'Admin' : 'Usuario'"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-xs text-slate-500">
                            <span>Uso: <span class="font-medium text-slate-700" x-text="user.clips_this_month"></span>
                            <span x-show="user.media_editor_clip_limit > 0"> / <span x-text="user.media_editor_clip_limit"></span></span>
                            cortes</span>
                            <span class="ml-2 text-slate-400">Total: <span x-text="user.clips_total"></span></span>
                        </div>
                        <template x-if="user.role === 'admin'">
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-medium">
                                <i class="fas fa-shield-alt text-xs"></i> Siempre activo
                            </span>
                        </template>
                        <template x-if="user.role !== 'admin'">
                            <div class="flex items-center gap-2">
                                <button @click="toggleEnabled(user)"
                                        :disabled="savingId === user.id"
                                        :class="user.media_editor_enabled ? 'bg-violet-600 border-violet-600' : 'bg-slate-200 border-slate-300'"
                                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 transition-colors duration-200 disabled:opacity-60">
                                    <span :class="user.media_editor_enabled ? 'translate-x-5' : 'translate-x-0'"
                                          class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200"></span>
                                </button>
                                <span class="text-xs font-medium"
                                      :class="user.media_editor_enabled ? 'text-violet-700' : 'text-slate-400'"
                                      x-text="user.media_editor_enabled ? 'Activo' : 'Inactivo'"></span>
                            </div>
                        </template>
                    </div>
                    <template x-if="user.role !== 'admin' && user.media_editor_enabled">
                        <div class="flex items-center gap-2 mt-2">
                            <span class="text-xs text-slate-500">Límite mensual:</span>
                            <input type="number" x-model.number="user.media_editor_clip_limit"
                                   min="0" step="1" placeholder="0"
                                   @keydown.enter="saveLimit(user)" @blur="saveLimit(user)"
                                   class="w-20 border border-slate-300 rounded-lg px-2 py-1 text-xs text-center focus:ring-2 focus:ring-violet-400 outline-none">
                            <span class="text-xs text-slate-400">cortes (0=∞)</span>
                        </div>
                    </template>
                </div>
            </template>
            <div x-show="!loading && users.length === 0" class="text-center py-12 text-slate-400 text-sm">
                No hay usuarios registrados.
            </div>
        </div>

        {{-- Vista escritorio: tabla --}}
        <div x-show="!loading" class="hidden sm:block">
            <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Usuario</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Acceso</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Uso este mes</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider w-52">Límite mensual</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Último corte</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="user in users" :key="user.id">
                        <tr class="hover:bg-slate-50 transition-colors">
                            <!-- Usuario -->
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                                         :class="user.role === 'admin' ? 'bg-purple-100' : 'bg-slate-100'">
                                        <i class="fas fa-user text-sm"
                                           :class="user.role === 'admin' ? 'text-purple-600' : 'text-slate-400'"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-slate-800 text-sm" x-text="user.username || user.email"></p>
                                        <p class="text-xs text-slate-400" x-text="user.email" x-show="user.username"></p>
                                        <span class="inline-block mt-0.5 px-2 py-0.5 text-xs rounded-full font-medium"
                                              :class="user.role === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-600'"
                                              x-text="user.role === 'admin' ? 'Admin' : 'Usuario'"></span>
                                    </div>
                                </div>
                            </td>

                            <!-- Acceso toggle -->
                            <td class="px-6 py-4 text-center">
                                <template x-if="user.role === 'admin'">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-medium">
                                        <i class="fas fa-shield-alt text-xs"></i>
                                        Siempre activo
                                    </span>
                                </template>
                                <template x-if="user.role !== 'admin'">
                                    <div class="flex items-center justify-center gap-2">
                                        <button @click="toggleEnabled(user)"
                                                :disabled="savingId === user.id"
                                                :class="user.media_editor_enabled
                                                    ? 'bg-violet-600 border-violet-600'
                                                    : 'bg-slate-200 border-slate-300'"
                                                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 transition-colors duration-200 ease-in-out focus:outline-none disabled:opacity-60">
                                            <span :class="user.media_editor_enabled ? 'translate-x-5' : 'translate-x-0'"
                                                  class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                        </button>
                                        <span class="text-xs font-medium"
                                              :class="user.media_editor_enabled ? 'text-violet-700' : 'text-slate-400'"
                                              x-text="user.media_editor_enabled ? 'Activo' : 'Inactivo'"></span>
                                        <template x-if="feedbackId === user.id">
                                            <i :class="feedbackType === 'ok' ? 'fa-check text-green-500' : 'fa-times text-red-500'"
                                               class="fas text-xs"></i>
                                        </template>
                                    </div>
                                </template>
                            </td>

                            <!-- Uso este mes -->
                            <td class="px-6 py-4 text-center">
                                <template x-if="user.role === 'admin'">
                                    <span class="text-sm text-slate-400" x-text="user.clips_this_month"></span>
                                </template>
                                <template x-if="user.role !== 'admin'">
                                    <div>
                                        <p class="text-sm font-medium text-slate-800 mb-1">
                                            <span x-text="user.clips_this_month"></span>
                                            <span class="text-slate-400 font-normal" x-show="user.media_editor_clip_limit > 0">
                                                / <span x-text="user.media_editor_clip_limit"></span>
                                            </span>
                                        </p>
                                        <template x-if="user.media_editor_clip_limit > 0">
                                            <div class="w-24 mx-auto">
                                                <div class="w-full bg-slate-200 rounded-full h-1.5">
                                                    <div class="h-1.5 rounded-full transition-all"
                                                         :class="usagePercent(user) >= 100 ? 'bg-red-500' : usagePercent(user) >= 75 ? 'bg-amber-500' : 'bg-violet-500'"
                                                         :style="'width: ' + usagePercent(user) + '%'"></div>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="user.media_editor_clip_limit === 0">
                                            <p class="text-xs text-slate-400">ilimitado</p>
                                        </template>
                                    </div>
                                </template>
                            </td>

                            <!-- Límite mensual -->
                            <td class="px-6 py-4 text-center">
                                <template x-if="user.role === 'admin'">
                                    <span class="text-sm text-slate-400">∞</span>
                                </template>
                                <template x-if="user.role !== 'admin'">
                                    <div class="flex items-center justify-center gap-2">
                                        <div class="relative">
                                            <input type="number" x-model.number="user.media_editor_clip_limit"
                                                   min="0" step="1" placeholder="0"
                                                   :disabled="!user.media_editor_enabled"
                                                   @keydown.enter="saveLimit(user)"
                                                   @blur="saveLimit(user)"
                                                   :class="!user.media_editor_enabled ? 'opacity-40 cursor-not-allowed bg-slate-50' : 'bg-white'"
                                                   class="w-24 border border-slate-300 rounded-lg px-3 py-1.5 text-sm text-center focus:ring-2 focus:ring-violet-400 focus:border-violet-400 outline-none">
                                        </div>
                                        <div class="text-left">
                                            <p class="text-xs text-slate-500 leading-tight">cortes</p>
                                            <p class="text-xs text-slate-400 leading-tight">0 = ∞</p>
                                        </div>
                                    </div>
                                </template>
                            </td>

                            <!-- Último corte -->
                            <td class="px-6 py-4 text-sm text-slate-500" x-text="formatDate(user.last_clip_at)"></td>

                            <!-- Total -->
                            <td class="px-6 py-4 text-center">
                                <span class="text-sm font-medium text-slate-700" x-text="user.clips_total"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <div x-show="!loading && users.length === 0" class="text-center py-12 text-slate-400">
                No hay usuarios registrados.
            </div>
            </div>{{-- /overflow-x-auto --}}
        </div>{{-- /hidden sm:block --}}
    </div>

    <!-- Legend / Info -->
    <div class="mt-6 bg-violet-50 border border-violet-200 rounded-xl p-4 flex items-start gap-3">
        <i class="fas fa-info-circle text-violet-500 mt-0.5 flex-shrink-0"></i>
        <div class="text-sm text-violet-800">
            <p class="font-medium mb-1">Cómo funciona el límite mensual</p>
            <ul class="text-violet-700 space-y-0.5 text-xs">
                <li>· <strong>0 = ilimitado</strong> — el usuario puede generar todos los cortes que quiera.</li>
                <li>· Cualquier número > 0 es el máximo de cortes completados en el mes calendario.</li>
                <li>· Los administradores siempre tienen acceso ilimitado, sin importar el límite.</li>
                <li>· Los cortes fallidos no cuentan para el límite.</li>
                <li>· El contador se reinicia automáticamente el 1 de cada mes.</li>
            </ul>
        </div>
    </div>
</div>

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startMediaEditorTour() {
    function getAlpine() {
        var allData = document.querySelectorAll('[x-data]');
        for (var i = 0; i < allData.length; i++) {
            var el = allData[i];
            if (el._x_dataStack && el._x_dataStack[0]) {
                var data = el._x_dataStack[0];
                if (data.users !== undefined && data.stats !== undefined) return data;
            }
        }
        var first = document.querySelector('[x-data]');
        return first ? (first._x_dataStack ? first._x_dataStack[0] : null) : null;
    }

    var alpine = getAlpine();
    if (alpine) alpine.loading = false;

    // Helper: scroll suave a un elemento y devolverlo
    function scrollTo(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        }
        return el;
    }

    // Helper: obtener la primera fila no-admin en la tabla
    function getFirstNonAdminRow() {
        var rows = document.querySelectorAll('table tbody tr');
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].querySelector('button[\\@click="toggleEnabled"]')) return rows[i];
        }
        return rows[0] || null;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Editor de Medios',
                content: 'Esta herramienta permite a los usuarios crear cortes (clips) de archivos multimedia directamente desde el navegador. ' +
                         'Aquí controlas quién tiene acceso al editor, cuántos cortes puede generar cada mes y supervisas el estado del sistema de procesamiento (FFmpeg).',
                icon: 'fa-cut',
                color: '#6366f1',
                selector: null,
                position: 'center'
            },
            {
                title: 'Estadísticas del Mes',
                content: 'Cuatro tarjetas resumen la actividad: <strong style="color:#7c3aed">Cortes este mes</strong>, ' +
                         '<strong style="color:#3b82f6">Cortes totales</strong> (histórico), ' +
                         '<strong style="color:#16a34a">Usuarios con acceso</strong> y ' +
                         '<strong style="color:#dc2626">Errores este mes</strong>. ' +
                         'Sirven para detectar picos de uso o problemas de procesamiento.',
                icon: 'fa-chart-pie',
                color: '#3b82f6',
                selector: '.grid.grid-cols-1.sm\\:grid-cols-2.lg\\:grid-cols-4',
                position: 'bottom',
                onShow: function () {
                    scrollTo('.grid.grid-cols-1.sm\\:grid-cols-2.lg\\:grid-cols-4');
                }
            },
            {
                title: 'RAM Disk FFmpeg',
                content: 'FFmpeg procesa los clips en un disco temporal en memoria (<strong>tmpfs</strong> montado en <code>/mnt/cliptemp</code>). ' +
                         'La barra muestra el % usado: <span style="color:#6366f1">azul (&lt;70%)</span>, ' +
                         '<span style="color:#f59e0b">ámbar (70-90%)</span>, ' +
                         '<span style="color:#dc2626">rojo (≥90%)</span>. ' +
                         '<strong style="color:#dc2626">Si supera el 90%, los nuevos cortes pueden fallar por falta de espacio.</strong> ' +
                         'Si aparece "No montado", los cortes no se podrán procesar.',
                icon: 'fa-memory',
                color: '#06b6d4',
                selector: function () {
                    var card = document.querySelector('.bg-white.rounded-xl.shadow-sm.border.mb-8');
                    if (card) {
                        var header = card.querySelector('.flex.items-center.justify-between');
                        return header || card;
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    scrollTo('.bg-white.rounded-xl.shadow-sm.border.mb-8');
                }
            },
            {
                title: 'Encabezados de la Tabla',
                content: 'La tabla muestra las columnas: <strong>Usuario</strong>, <strong>Acceso</strong>, <strong>Uso este mes</strong>, ' +
                         '<strong>Límite mensual</strong>, <strong>Último corte</strong> y <strong>Total</strong>. ' +
                         'A continuación explicamos cada accionable por fila.',
                icon: 'fa-columns',
                color: '#2563eb',
                selector: 'table thead',
                position: 'bottom',
                onShow: function () {
                    scrollTo('table thead');
                }
            },
            {
                title: 'Columna: Usuario',
                content: 'Muestra el avatar, nombre de usuario, email y rol (<span style="color:#7c3aed">Admin</span> o <span style="color:#475569">Usuario</span>). ' +
                         'Los administradores tienen acceso ilimitado y no se pueden deshabilitar.',
                icon: 'fa-user',
                color: '#7c3aed',
                selector: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var cell = row.querySelector('td:first-child');
                        if (cell) return cell;
                    }
                    return document.querySelector('table tbody tr td:first-child') || null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstNonAdminRow();
                    if (row) scrollTo(row.querySelector('td:first-child'));
                }
            },
            {
                title: 'Activar / Desactivar Acceso',
                content: 'Este interruptor (<strong style="color:#7c3aed">toggle violeta</strong>) habilita o deshabilita el acceso al Editor de Medios para cada usuario. ' +
                         '<strong style="color:#16a34a">Activo</strong> (violeta) = puede generar cortes. ' +
                         '<strong style="color:#94a3b8">Inactivo</strong> (gris) = botón deshabilitado, no ve la herramienta. ' +
                         'Los cambios se guardan automáticamente al hacer clic. ' +
                         '<span style="color:#94a3b8">Los administradores siempre están activos (no aparece toggle).</span>',
                icon: 'fa-toggle-on',
                color: '#7c3aed',
                selector: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var btn = row.querySelector('button[\\@click="toggleEnabled"]');
                        if (btn) return btn;
                    }
                    return document.querySelector('table tbody tr button[\\@click="toggleEnabled"]') || null;
                },
                position: 'left',
                onShow: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var btn = row.querySelector('button[\\@click="toggleEnabled"]');
                        if (btn) scrollTo(btn);
                    }
                }
            },
            {
                title: 'Uso Este Mes',
                content: 'Muestra <strong>cortes consumidos este mes</strong>, con barra de progreso. ' +
                         'La barra cambia de color: <span style="color:#7c3aed">violeta</span> (&lt;75%), ' +
                         '<span style="color:#f59e0b">ámbar</span> (75-99%), <span style="color:#dc2626">rojo</span> (100% agotado). ' +
                         'Si el límite es 0 (ilimitado) no muestra barra.',
                icon: 'fa-chart-bar',
                color: '#f59e0b',
                selector: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length >= 3) return cells[2];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstNonAdminRow();
                    if (row) scrollTo(row.querySelectorAll('td')[2]);
                }
            },
            {
                title: 'Límite Mensual',
                content: 'Edita este campo numérico para definir el máximo de cortes mensuales del usuario. ' +
                         '<strong style="color:#7c3aed">0 = ilimitado</strong>. ' +
                         'Los cambios se guardan al presionar <strong>Enter</strong> o perder el foco (blur). ' +
                         'Solo se puede editar cuando el usuario está <strong>Activo</strong>. ' +
                         'El contador se reinicia automáticamente el 1 de cada mes.',
                icon: 'fa-sliders-h',
                color: '#7c3aed',
                selector: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var input = row.querySelector('input[type="number"]');
                        if (input) return input;
                    }
                    return document.querySelector('table tbody tr input[type="number"]') || null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var input = row.querySelector('input[type="number"]');
                        if (input) scrollTo(input);
                    }
                }
            },
            {
                title: 'Último Corte',
                content: 'Fecha y hora del último clip generado por este usuario. ' +
                         'Si nunca ha usado el editor, muestra <strong>Nunca</strong>. ' +
                         'Útil para identificar usuarios inactivos.',
                icon: 'fa-clock',
                color: '#475569',
                selector: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length >= 5) return cells[4];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstNonAdminRow();
                    if (row) scrollTo(row.querySelectorAll('td')[4]);
                }
            },
            {
                title: 'Total de Cortes',
                content: 'Acumulado histórico de cortes completados por el usuario. ' +
                         'No se reinicia cada mes; sirve para medir la actividad total.',
                icon: 'fa-hashtag',
                color: '#3b82f6',
                selector: function () {
                    var row = getFirstNonAdminRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length >= 6) return cells[5];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstNonAdminRow();
                    if (row) scrollTo(row.querySelectorAll('td')[5]);
                }
            },
            {
                title: 'Nota sobre los Límites',
                content: 'Cómo funciona el sistema de límites: ' +
                         '<ul style="margin:6px 0 0 16px;padding:0;">' +
                         '<li><strong>0 = ilimitado</strong>: el usuario puede generar todos los cortes que quiera.</li>' +
                         '<li>Cualquier número > 0 es el máximo de cortes completados en el mes calendario.</li>' +
                         '<li>Los administradores siempre tienen acceso ilimitado, ignorando el límite.</li>' +
                         '<li>Los cortes fallidos <strong>no cuentan</strong> para el límite.</li>' +
                         '<li>El contador se reinicia automáticamente el 1 de cada mes.</li>' +
                         '</ul>',
                icon: 'fa-info-circle',
                color: '#7c3aed',
                selector: '.bg-violet-50.border-violet-200',
                position: 'top',
                onShow: function () {
                    scrollTo('.bg-violet-50.border-violet-200');
                }
            },
            {
                title: 'Guía Completada',
                content: 'Conoces todo lo necesario para gestionar el Editor de Medios: ' +
                         '<strong>activar/desactivar</strong> usuarios, <strong>configurar límites mensuales</strong>, ' +
                         'monitorear el <strong>disco RAM de FFmpeg</strong> y supervisar las <strong>estadísticas</strong>. ' +
                         'Repite esta guía cuando quieras con el botón morado.',
                icon: 'fa-check-circle',
                color: '#16a34a',
                selector: null,
                position: 'center'
            }
        ]
    });
}
</script>
@endsection
