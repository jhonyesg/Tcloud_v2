@extends('layouts.app')

@section('title', 'Monitor Redis - Tcloud')

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="{
    status: null,
    loading: true,
    toast: null,
    showInfoModal: false,
    toggling: false,
    configHost: '{{ $config['host'] }}',
    configPort: '{{ $config['port'] }}',
    configDatabase: '{{ $config['database'] }}',
    configPassword: '',
    configTesting: false,
    configSaving: false,
    configTestResult: null,
    showConfig: false,

    async init() {
        await this.loadStatus();
    },

    async loadStatus() {
        this.loading = true;
        try {
            const res = await fetch('/admin/redis/status', {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            this.status = await res.json();
        } catch (e) {
            this.status = { connected: false, error: e.message };
        }
        this.loading = false;
    },

    async toggleDriver() {
        const isRedis = this.status?.session_driver === 'redis';
        const msg = isRedis
            ? '¿Desactivar Redis para sesiones? Las sesiones actuales seguirán activas hasta que PHP-FPM se reinicie. Después se usarán archivos locales.'
            : '¿Activar Redis para sesiones? Al reiniciar PHP-FPM las sesiones pasarán a Redis.';
        if (!confirm(msg)) return;
        this.toggling = true;
        const res = await fetch('/admin/redis/toggle-driver', {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        const data = await res.json();
        this.toggling = false;
        if (data.success) {
            this.status = { ...this.status, session_driver: data.driver };
            this.showToast(data.message, 'success');
        }
    },

    async cleanExpired() {
        if (!confirm('¿Eliminar todas las sesiones expiradas de la BD?')) return;
        const res = await fetch('/admin/redis/clean-expired', {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        const data = await res.json();
        this.showToast(data.message, 'success');
        await this.loadStatus();
    },

    async cleanOrphans() {
        if (!confirm('¿Eliminar registros de BD cuya sesión ya no existe en Redis?')) return;
        const res = await fetch('/admin/redis/clean-orphans', {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        const data = await res.json();
        this.showToast(data.message, 'success');
        await this.loadStatus();
    },

    async testConfig() {
        this.configTesting = true;
        this.configTestResult = null;
        const res = await fetch('/admin/redis/config/test', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            body: JSON.stringify({ host: this.configHost, port: this.configPort, database: this.configDatabase, password: this.configPassword })
        });
        this.configTestResult = await res.json();
        this.configTesting = false;
    },

    async saveConfig() {
        if (!this.configTestResult?.success) {
            if (!confirm('No se ha verificado la conexión. ¿Guardar de todas formas?')) return;
        }
        this.configSaving = true;
        const res = await fetch('/admin/redis/config/save', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            body: JSON.stringify({ host: this.configHost, port: this.configPort, database: this.configDatabase, password: this.configPassword })
        });
        const data = await res.json();
        this.configSaving = false;
        this.showToast(data.message, data.success ? 'success' : 'error');
    },

    showToast(msg, type) {
        this.toast = { msg, type };
        setTimeout(() => this.toast = null, 5000);
    },

    formatNumber(n) {
        if (!n && n !== 0) return '—';
        return Number(n).toLocaleString('es-CO');
    }
}">

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Info Modal -->
    <div x-cloak x-show="showInfoModal" class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(3,21,60,0.7); backdrop-filter: blur(6px);"
         @keydown.escape.window="showInfoModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6" @click.outside="showInfoModal = false">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-bolt text-red-500"></i>
                    </div>
                    <h2 class="text-lg font-bold text-slate-800">¿Para qué sirve Redis aquí?</h2>
                </div>
                <button @click="showInfoModal = false" class="p-2 hover:bg-slate-100 rounded-lg">
                    <i class="fas fa-times text-slate-400"></i>
                </button>
            </div>
            <div class="space-y-4 text-sm text-slate-600">
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-id-badge text-blue-600 text-xs"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-700">Sesiones de usuario</p>
                        <p class="text-slate-500 mt-0.5">Cada login guarda la sesión en Redis. Es más rápido que guardarlas en base de datos o archivos porque Redis vive completamente en memoria RAM. Con Redis puedes tener múltiples servidores web compartiendo el mismo estado de sesión.</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-tachometer-alt text-amber-600 text-xs"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-700">Cache de la aplicación</p>
                        <p class="text-slate-500 mt-0.5">Resultados de consultas pesadas, configuraciones y datos frecuentes se almacenan en Redis para no recalcularlos en cada request. Es lo que ves como "keys de cache" en este panel.</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-shield-alt text-green-600 text-xs"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-700">Control de sesiones activas</p>
                        <p class="text-slate-500 mt-0.5">El módulo de sesiones de Tcloud usa Redis para poder cerrar sesiones remotamente: cuando un admin "mata" una sesión, borra la key de Redis y el usuario queda deslogueado en su próximo request.</p>
                    </div>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 mt-2">
                    <p class="text-xs text-slate-500"><strong>Alternativa sin Redis:</strong> las sesiones se guardan en archivos locales del servidor. Funciona bien en un solo servidor, pero no permite cerrar sesiones remotamente ni escalar horizontalmente.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6 gap-3">
        <div class="min-w-0">
            <h1 class="text-lg sm:text-2xl font-bold text-slate-800">Monitor Redis</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">Estado y estadísticas del servidor Redis</p>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 flex-shrink-0">
            <button @click="showInfoModal = true"
                    class="flex items-center gap-1.5 px-2.5 sm:px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-info-circle"></i>
                <span class="hidden sm:inline">¿Para qué sirve?</span>
            </button>
            <button @click="toggleDriver()" :disabled="toggling || loading"
                    class="flex items-center gap-1.5 px-2.5 sm:px-4 py-2 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                    :class="status?.session_driver === 'redis'
                        ? 'bg-green-100 hover:bg-green-200 text-green-700 border border-green-300'
                        : 'bg-slate-200 hover:bg-slate-300 text-slate-600 border border-slate-300'">
                <i class="fas" :class="toggling ? 'fa-spinner fa-spin' : (status?.session_driver === 'redis' ? 'fa-toggle-on' : 'fa-toggle-off')"></i>
                <span class="hidden sm:inline" x-text="status?.session_driver === 'redis' ? 'Redis activo' : 'Redis inactivo'"></span>
            </button>
            <button @click="loadStatus()" class="flex items-center gap-1.5 px-2.5 sm:px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-sync-alt" :class="loading ? 'fa-spin' : ''"></i>
                <span class="hidden sm:inline">Actualizar</span>
            </button>
        </div>
    </div>

    <!-- Loading -->
    <div x-show="loading" class="flex items-center justify-center py-20">
        <i class="fas fa-spinner fa-spin text-brand-500 text-3xl"></i>
    </div>

    <div x-show="!loading">
        <!-- Connection Status -->
        <div class="bg-white rounded-2xl border shadow-sm p-6 mb-6"
             :class="status?.connected ? 'border-green-200' : 'border-red-200'">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl"
                     :class="status?.connected ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'">
                    <i :class="status?.connected ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i>
                </div>
                <div>
                    <p class="font-bold text-lg" :class="status?.connected ? 'text-green-700' : 'text-red-700'"
                       x-text="status?.connected ? 'Conectado' : 'Error de conexión'"></p>
                    <p class="text-sm text-slate-500" x-show="status?.connected" x-text="'Redis ' + status?.version + ' · Uptime: ' + status?.uptime_human"></p>
                    <p class="text-sm text-red-500" x-show="!status?.connected" x-text="status?.error"></p>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div x-show="status?.connected" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Memory -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-memory text-blue-600"></i>
                    </div>
                    <span class="text-sm font-medium text-slate-600">Memoria usada</span>
                </div>
                <p class="text-2xl font-bold text-slate-800" x-text="status?.used_memory_human"></p>
                <p class="text-xs text-slate-400 mt-1" x-text="'Máx: ' + status?.maxmemory_human"></p>
                <div x-show="status?.memory_pct !== null" class="mt-2">
                    <div class="w-full bg-slate-100 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full transition-all"
                             :class="status?.memory_pct > 80 ? 'bg-red-500' : status?.memory_pct > 60 ? 'bg-yellow-500' : 'bg-green-500'"
                             :style="'width: ' + (status?.memory_pct || 0) + '%'"></div>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" x-text="(status?.memory_pct || 0) + '% del máximo'"></p>
                </div>
            </div>

            <!-- Clients -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-purple-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-plug text-purple-600"></i>
                    </div>
                    <span class="text-sm font-medium text-slate-600">Clientes conectados</span>
                </div>
                <p class="text-2xl font-bold text-slate-800" x-text="formatNumber(status?.connected_clients)"></p>
                <p class="text-xs text-slate-400 mt-1" x-text="'Total comandos: ' + formatNumber(status?.total_commands)"></p>
            </div>

            <!-- Redis sessions -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-key text-orange-600"></i>
                    </div>
                    <span class="text-sm font-medium text-slate-600">Sesiones en Redis</span>
                </div>
                <p class="text-2xl font-bold text-slate-800" x-text="formatNumber(status?.redis_session_count)"></p>
                <p class="text-xs text-slate-400 mt-1">Keys de sesión activas</p>
            </div>

            <!-- DB sessions -->
            <div class="bg-white rounded-2xl border shadow-sm p-5"
                 :class="status?.desync ? 'border-yellow-300 bg-yellow-50' : 'border-slate-200'">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center"
                         :class="status?.desync ? 'bg-yellow-100' : 'bg-green-100'">
                        <i class="fas fa-database" :class="status?.desync ? 'text-yellow-600' : 'text-green-600'"></i>
                    </div>
                    <span class="text-sm font-medium text-slate-600">Sesiones en BD</span>
                </div>
                <p class="text-2xl font-bold text-slate-800" x-text="formatNumber(status?.db_session_count)"></p>
                <div x-show="status?.desync" class="mt-1">
                    <div class="flex items-center gap-1 text-yellow-700">
                        <i class="fas fa-exclamation-triangle text-xs"></i>
                        <p class="text-xs font-medium" x-text="(status?.orphan_db_count || 0) + ' sesión(es) huérfana(s)'"></p>
                    </div>
                    <p class="text-xs text-yellow-600 mt-0.5">Registros BD sin clave en Redis</p>
                </div>
                <div x-show="!status?.desync" class="mt-1 flex items-center gap-1 text-green-600">
                    <i class="fas fa-check text-xs"></i>
                    <p class="text-xs font-medium">Sincronizados</p>
                </div>
            </div>
        </div>

        <!-- Cleanup Actions -->
        <div x-show="status?.connected" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-lg font-semibold text-slate-700 mb-4"><i class="fas fa-broom text-brand-500 mr-2"></i>Mantenimiento</h2>
            <div class="flex flex-wrap gap-3">
                <button @click="cleanExpired()"
                        class="flex items-center gap-2 px-4 py-2.5 bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-700 rounded-xl text-sm font-medium transition-colors">
                    <i class="fas fa-clock"></i>
                    Limpiar sesiones expiradas
                </button>
                <button @click="cleanOrphans()"
                        class="flex items-center gap-2 px-4 py-2.5 bg-red-50 hover:bg-red-100 border border-red-200 text-red-700 rounded-xl text-sm font-medium transition-colors"
                        :disabled="!status?.desync">
                    <i class="fas fa-trash-alt"></i>
                    Limpiar sesiones huérfanas
                    <span x-show="!status?.desync" class="text-xs text-red-400">(sincronizado)</span>
                </button>
            </div>
            <p class="text-xs text-slate-400 mt-3">
                <strong>Expiradas:</strong> elimina registros de BD cuya fecha de expiración ya pasó.<br>
                <strong>Huérfanas:</strong> elimina registros de BD sin clave correspondiente en Redis.
            </p>
        </div>

        <!-- Redis Connection Config -->
        <div class="mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm">
            <button @click="showConfig = !showConfig"
                    class="w-full flex items-center justify-between p-6 hover:bg-slate-50 transition-colors rounded-2xl">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-plug text-indigo-600"></i>
                    </div>
                    <div class="text-left">
                        <p class="font-semibold text-slate-700">Configuración de conexión</p>
                        <p class="text-sm text-slate-400 mt-0.5">
                            Servidor actual: <span class="font-mono text-slate-600" x-text="configHost + ':' + configPort"></span>
                        </p>
                    </div>
                </div>
                <i class="fas text-slate-400 transition-transform" :class="showConfig ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            </button>

            <div x-show="showConfig" x-transition class="px-6 pb-6 border-t border-slate-100 pt-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-1">Host</label>
                        <input type="text" x-model="configHost" placeholder="127.0.0.1"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-1">Puerto</label>
                        <input type="number" x-model="configPort" placeholder="6379"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-1">Base de datos (DB index)</label>
                        <input type="number" x-model="configDatabase" placeholder="0" min="0"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-1">
                            Contraseña
                            <span class="text-slate-400 font-normal">({{ !empty($config['password']) ? 'configurada ✓' : 'sin contraseña' }})</span>
                        </label>
                        <input type="password" x-model="configPassword" placeholder="Vacío = mantener la actual"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        <p class="text-xs text-slate-400 mt-0.5">Al probar y guardar, si está vacío usa la contraseña actual guardada</p>
                    </div>
                </div>

                <!-- Test result -->
                <div x-show="configTestResult" x-transition class="mb-4">
                    <div class="flex items-center gap-2 px-4 py-3 rounded-xl text-sm font-medium"
                         :class="configTestResult?.success ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'">
                        <i :class="configTestResult?.success ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i>
                        <span x-text="configTestResult?.message"></span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button @click="testConfig()" :disabled="configTesting"
                            class="flex items-center gap-2 px-4 py-2.5 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        <i class="fas" :class="configTesting ? 'fa-spinner fa-spin' : 'fa-vial'"></i>
                        <span x-text="configTesting ? 'Probando...' : 'Probar conexión'"></span>
                    </button>
                    <button @click="saveConfig()" :disabled="configSaving"
                            class="flex items-center gap-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas" :class="configSaving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                        <span x-text="configSaving ? 'Guardando...' : 'Guardar configuración'"></span>
                    </button>
                </div>
                <p class="text-xs text-slate-400 mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    Prueba la conexión antes de guardar. Al guardar se actualiza el <code>.env</code> — reinicia PHP-FPM para que tome efecto.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
