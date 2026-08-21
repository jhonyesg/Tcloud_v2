@extends('layouts.app')

@section('title', 'Dashboard - Tcloud')

@section('content')
<div class="p-6">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Bienvenido, {{ $user->email }}</h1>
            <p class="text-slate-500 mt-0.5">Gestiona tus archivos y carpetas</p>
        </div>
        <button onclick="startUserDashboardTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
            <i class="fas fa-map-marked-alt"></i>
            <span class="hidden sm:inline">Guía</span>
        </button>
    </div>

    <!-- Quick-access cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <a href="/files" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-brand-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-folder text-brand-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Mis Archivos</h3>
                    <p class="text-xs text-slate-500">{{ $storages->count() }} storages asignados</p>
                </div>
            </div>
        </a>

        <a href="/shares" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-brand-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-link text-brand-500 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Compartidos</h3>
                    <p class="text-xs text-slate-500">{{ $user->shares->count() }} enlaces activos</p>
                </div>
            </div>
        </a>

        <a href="/profile" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-green-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-user-cog text-green-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Mi Perfil</h3>
                    <p class="text-xs text-slate-500">Configuración</p>
                </div>
            </div>
        </a>

        @if($personalStorageId)
        <a href="/files?storage_id={{ $personalStorageId }}"
           class="bg-white rounded-xl shadow-sm border border-amber-200 p-5 hover:shadow-md hover:border-amber-400 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-user-circle text-amber-500 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Mi Espacio Personal</h3>
                    <p class="text-xs text-amber-600">Almacenamiento exclusivo tuyo</p>
                </div>
            </div>
        </a>
        @endif

        @if($canalesCount > 0)
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-satellite-dish text-orange-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Medios Puntuales</h3>
                    <p class="text-xs text-slate-500">{{ $canalesCount }} canal{{ $canalesCount !== 1 ? 'es' : '' }} asignado{{ $canalesCount !== 1 ? 's' : '' }}</p>
                </div>
            </div>
        </div>
        @endif

        @if($mediaEditorEnabled)
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-film text-purple-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Editor de Medios</h3>
                    <p class="text-xs text-slate-500">
                        Habilitado ·
                        @if($mediaEditorClipLimit > 0)
                            {{ $mediaEditorClipsUsed }}/{{ $mediaEditorClipLimit }} clips este mes
                        @else
                            Sin límite de clips
                        @endif
                    </p>
                </div>
            </div>
        </div>
        @endif
    </div>

    @if(count($instructivos) > 0)
    <div class="mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-9 h-9 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-book-open text-red-600 text-sm"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800 text-sm">Guías e Instructivos</h3>
                    <p class="text-xs text-slate-400">Documentación de uso de la plataforma</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($instructivos as $doc)
                <button type="button" onclick="openInstructivo('{{ $doc['url'] }}')"
                        class="flex items-center gap-3 p-3 bg-red-50 hover:bg-red-100 border border-red-200 rounded-xl transition-colors text-left w-full">
                    <div class="w-10 h-10 bg-red-600 rounded-lg flex items-center justify-center shrink-0">
                        <i class="fas fa-file-pdf text-white"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800 text-sm truncate">{{ pathinfo($doc['name'], PATHINFO_FILENAME) }}</p>
                        <p class="text-xs text-red-500 mt-0.5">PDF · Click para abrir</p>
                    </div>
                </button>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    @if($storages->isNotEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Storages Asignados</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($storages as $storage)
                <a href="/files?storage_id={{ $storage->storage_provider_id }}"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-brand-50 rounded-lg transition-colors">
                    <div class="w-10 h-10 bg-brand-100 rounded-lg flex items-center justify-center shrink-0">
                        <i class="fas fa-hdd text-brand-600"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-slate-800 text-sm truncate">{{ $storage->storageProvider->name }}</p>
                        <span class="inline-block px-2 py-0.5 rounded text-xs mt-1
                            @if($storage->permissions === 'full') bg-green-100 text-green-700
                            @elseif($storage->permissions === 'write') bg-brand-100 text-brand-700
                            @elseif($storage->permissions === 'upload') bg-amber-100 text-amber-700
                            @else bg-slate-100 text-slate-600 @endif">
                            {{ ucfirst($storage->permissions) }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Mis Sesiones -->
    <div class="mt-6" x-data="{
        sessions: [],
        loading: false,
        open: false,
        toast: null,

        async toggle() {
            this.open = !this.open;
            if (this.open && this.sessions.length === 0) await this.loadSessions();
        },

        async loadSessions() {
            this.loading = true;
            const res = await apiFetch('/user/sessions', { credentials: 'include', headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                this.sessions = data.sessions;
            }
            this.loading = false;
        },

        async closeSession(id) {
            if (!confirm('¿Cerrar esta sesión?')) return;
            const res = await apiFetch('/user/sessions/' + id, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
            });
            const data = await res.json();
            if (res.ok) {
                this.showToast('Sesión cerrada', 'success');
                await this.loadSessions();
            } else {
                this.showToast(data.error || 'Error', 'error');
            }
        },

        async closeOthers() {
            if (!confirm('¿Cerrar todas las sesiones excepto la actual?')) return;
            const res = await apiFetch('/user/sessions/others', {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
            });
            const data = await res.json();
            if (res.ok) {
                this.showToast(data.message, 'success');
                await this.loadSessions();
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
            return ua.substring(0, 24);
        }
    }">
        <!-- Toast -->
        <div x-cloak x-show="toast" x-transition
             :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
             class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
            <span x-text="toast?.msg"></span>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200">
            <button @click="toggle()" class="w-full flex items-center justify-between p-5 hover:bg-slate-50 transition-colors rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-brand-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-shield-alt text-brand-600 text-sm"></i>
                    </div>
                    <div class="text-left">
                        <p class="font-semibold text-slate-700 text-sm">Mis sesiones activas</p>
                        <p class="text-xs text-slate-400">Ver y gestionar tus dispositivos conectados</p>
                    </div>
                </div>
                <i class="fas text-slate-400 transition-transform" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            </button>

            <div x-show="open" x-transition class="px-5 pb-5">
                <!-- Loading -->
                <div x-show="loading" class="flex items-center justify-center py-8">
                    <i class="fas fa-spinner fa-spin text-brand-500"></i>
                </div>

                <!-- Sessions table -->
                <div x-show="!loading">
                    <div x-show="sessions.length === 0" class="text-center py-8 text-slate-400 text-sm">
                        No hay sesiones activas registradas.
                    </div>
                    <div x-show="sessions.length > 0">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-slate-400 border-b border-slate-100">
                                        <th class="pb-2">Dispositivo</th>
                                        <th class="pb-2">IP</th>
                                        <th class="pb-2">Inicio</th>
                                        <th class="pb-2">Última actividad</th>
                                        <th class="pb-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <template x-for="s in sessions" :key="s.id">
                                        <tr>
                                            <td class="py-2.5 text-slate-700">
                                                <span x-text="shortAgent(s.user_agent)"></span>
                                                <span x-show="s.is_current" class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                                    <i class="fas fa-circle text-[6px]"></i> Esta sesión
                                                </span>
                                            </td>
                                            <td class="py-2.5 text-slate-500 font-mono text-xs" x-text="s.ip_address || '—'"></td>
                                            <td class="py-2.5 text-slate-400 text-xs" x-text="formatDate(s.created_at)"></td>
                                            <td class="py-2.5 text-slate-400 text-xs" x-text="formatDate(s.last_activity_at)"></td>
                                            <td class="py-2.5 text-right">
                                                <button x-show="!s.is_current"
                                                        @click="closeSession(s.id)"
                                                        class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs rounded-lg transition-colors font-medium">
                                                    Cerrar
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 flex justify-between items-center">
                            <span class="text-xs text-slate-400" x-text="sessions.length + ' sesión(es) activa(s)'"></span>
                            <button @click="closeOthers()"
                                    x-show="sessions.filter(s => !s.is_current).length > 0"
                                    class="px-3 py-1.5 bg-red-50 hover:bg-red-100 border border-red-200 text-red-600 text-xs rounded-lg transition-colors font-medium">
                                <i class="fas fa-sign-out-alt mr-1"></i>Cerrar todas las otras sesiones
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startUserDashboardTour() {
    function scrollTo(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        return el;
    }

    function getCardByTitle(title) {
        var cards = document.querySelectorAll('a, .bg-white.rounded-xl');
        for (var i = 0; i < cards.length; i++) {
            var h3 = cards[i].querySelector('h3');
            if (h3 && h3.textContent.trim() === title) return cards[i];
        }
        return null;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Bienvenido a Tcloud',
                content: 'Este es tu panel principal. Desde aquí accedes a tus <strong>archivos</strong>, ' +
                         '<strong>enlaces compartidos</strong>, <strong>perfil</strong>, ' +
                         'y tus <strong>storages asignados</strong>. ' +
                         'También encuentras la documentación oficial en <strong>Guías e Instructivos</strong>.',
                icon: 'fa-home',
                color: '#6366f1',
                selector: null,
                position: 'center'
            },
            {
                title: 'Mis Archivos',
                content: 'Tarjeta de acceso directo a tu gestor de archivos. ' +
                         'Muestra cuántos storages tienes asignados. ' +
                         'Haz clic para entrar al listado de storages y explorar tus archivos.',
                icon: 'fa-folder-open',
                color: '#6366f1',
                selector: function () { return getCardByTitle('Mis Archivos'); },
                position: 'bottom',
                onShow: function () {
                    var c = getCardByTitle('Mis Archivos');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Compartidos',
                content: 'Acceso a tus <strong>enlaces públicos</strong> (shares). ' +
                         'Aquí puedes ver, copiar y revocar los enlaces que has generado para compartir archivos.',
                icon: 'fa-link',
                color: '#6366f1',
                selector: function () { return getCardByTitle('Compartidos'); },
                position: 'bottom',
                onShow: function () {
                    var c = getCardByTitle('Compartidos');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Mi Perfil',
                content: 'Configuración de tu cuenta: cambio de contraseña, datos personales, ' +
                         'gestión de sesiones activas y preferencias.',
                icon: 'fa-user-cog',
                color: '#16a34a',
                selector: function () { return getCardByTitle('Mi Perfil'); },
                position: 'bottom',
                onShow: function () {
                    var c = getCardByTitle('Mi Perfil');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Mi Espacio Personal',
                content: 'Acceso directo a tu <strong>storage personal</strong> exclusivo. ' +
                         'A diferencia de los storages compartidos, nadie más tiene acceso. ' +
                         'Es ideal para guardar archivos privados.',
                icon: 'fa-user-circle',
                color: '#f59e0b',
                selector: function () { return getCardByTitle('Mi Espacio Personal'); },
                position: 'bottom',
                onShow: function () {
                    var c = getCardByTitle('Mi Espacio Personal');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Medios Puntuales',
                content: 'Si te han asignado canales de grabación, aparece esta tarjeta con el conteo. ' +
                         'Haz clic para ver el detalle y ejecutar grabaciones.',
                icon: 'fa-satellite-dish',
                color: '#ea580c',
                selector: function () { return getCardByTitle('Medios Puntuales'); },
                position: 'bottom',
                onShow: function () {
                    var c = getCardByTitle('Medios Puntuales');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Editor de Medios',
                content: 'Si tienes habilitado el Editor de Medios, esta tarjeta muestra el estado y tu ' +
                         '<strong>consumo mensual</strong> (clips usados / límite). ' +
                         'Si el límite es 0, no tienes restricción.',
                icon: 'fa-film',
                color: '#7c3aed',
                selector: function () { return getCardByTitle('Editor de Medios'); },
                position: 'bottom',
                onShow: function () {
                    var c = getCardByTitle('Editor de Medios');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Guías e Instructivos',
                content: 'Documentación oficial en PDF. ' +
                         'Haz clic en cualquier PDF para abrirlo en el navegador. ' +
                         'Esta sección se irá actualizando conforme evolucione la plataforma.',
                icon: 'fa-book-open',
                color: '#dc2626',
                selector: function () {
                    var btn = document.querySelector('button[onclick*="openInstructivo"]');
                    return btn || null;
                },
                position: 'top',
                onShow: function () {
                    var btn = document.querySelector('button[onclick*="openInstructivo"]');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Storages Asignados',
                content: 'Lista de todos los <strong>storages</strong> a los que tienes acceso con tus <strong>permisos</strong>: ' +
                         '<span style="color:#16a34a"><strong>Full</strong></span> (completo), ' +
                         '<span style="color:#6366f1"><strong>Write</strong></span> (escritura), ' +
                         '<span style="color:#f59e0b"><strong>Upload</strong></span> (subida) o ' +
                         '<span style="color:#475569"><strong>Read</strong></span> (lectura). ' +
                         'Haz clic para entrar a cualquiera.',
                icon: 'fa-hdd',
                color: '#3b82f6',
                selector: function () {
                    var h3s = document.querySelectorAll('h3.text-lg');
                    for (var i = 0; i < h3s.length; i++) {
                        if (h3s[i].textContent.trim() === 'Storages Asignados') return h3s[i];
                    }
                    return null;
                },
                position: 'top',
                onShow: function () {
                    var h3s = document.querySelectorAll('h3.text-lg');
                    for (var i = 0; i < h3s.length; i++) {
                        if (h3s[i].textContent.trim() === 'Storages Asignados') {
                            scrollTo(h3s[i]);
                            return;
                        }
                    }
                }
            },
            {
                title: 'Mis Sesiones',
                content: 'Sección desplegable con tus <strong>dispositivos conectados</strong>. ' +
                         'Puedes ver dispositivos, IPs, fechas de inicio/actividad y <strong>cerrar otras sesiones</strong> ' +
                         'por seguridad si ves algo sospechoso.',
                icon: 'fa-shield-alt',
                color: '#475569',
                selector: 'button[\\@click="toggle()"]',
                position: 'top',
                onShow: function () {
                    var btn = document.querySelector('button[\\@click="toggle()"]');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Guía Completada',
                content: 'Conoces tu panel personal y todas sus funciones. ' +
                         'Desde aquí puedes acceder a archivos, storage personal, shares, perfil y sesiones. ' +
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
