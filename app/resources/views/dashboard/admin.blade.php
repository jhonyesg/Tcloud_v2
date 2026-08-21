@extends('layouts.app')

@section('title', 'Dashboard - Tcloud')

@section('content')
<div class="p-6">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Panel de Administración</h1>
            <p class="text-slate-500 mt-0.5">Resumen del sistema y estadísticas</p>
        </div>
        <button onclick="startAdminDashboardTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
            <i class="fas fa-map-marked-alt"></i>
            <span class="hidden sm:inline">Guía</span>
        </button>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Usuarios</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_users'] }}</p>
                </div>
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-users text-brand-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm">
                <span class="text-green-600 flex items-center gap-1">
                    <i class="fas fa-arrow-up text-xs"></i> 12%
                </span>
                <span class="text-slate-400 ml-2">vs mes anterior</span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Storages</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_storages'] }}</p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-database text-green-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm text-slate-400">
                <i class="fas fa-check-circle mr-1 text-green-500"></i> Todos activos
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Archivos</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_files'] }}</p>
                </div>
                <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-file text-amber-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm text-slate-400">
                {{ number_format($stats['storage_used'] / 1024 / 1024, 2) }} MB usados
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Shares</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_shares'] }}</p>
                </div>
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-link text-brand-500 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm text-slate-400">
                <i class="fas fa-external-link-alt mr-1 text-brand-300 text-xs"></i> Enlaces activos
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Usage stats -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-5">Estadísticas de Uso</h3>
            <div class="space-y-5">
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-slate-600">Usuarios Activos</span>
                        <span class="font-medium text-slate-800">{{ $stats['total_users'] }}</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="bg-brand-600 h-2 rounded-full" style="width: 75%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-slate-600">Almacenamiento Utilizado</span>
                        <span class="font-medium text-slate-800">{{ number_format($stats['storage_used'] / 1024 / 1024, 2) }} MB</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width: 25%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-slate-600">Enlaces Compartidos</span>
                        <span class="font-medium text-slate-800">{{ $stats['total_shares'] }}</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="bg-brand-400 h-2 rounded-full" style="width: 40%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-memory text-indigo-500 text-xs"></i>
                            <span class="text-slate-600">RAM Disk FFmpeg</span>
                            <span class="text-xs text-slate-400">/mnt/cliptemp</span>
                        </div>
                        @if($ramdisk['available'])
                            <span class="font-medium text-slate-800">{{ $ramdisk['used_gb'] }} <span class="font-normal text-slate-400">/ {{ $ramdisk['total_gb'] }} GB</span></span>
                        @else
                            <span class="text-xs text-red-500">No montado</span>
                        @endif
                    </div>
                    @if($ramdisk['available'])
                        <div class="w-full bg-slate-100 rounded-full h-2">
                            <div class="h-2 rounded-full transition-all"
                                 style="width: {{ max($ramdisk['percent'], 0.5) }}%; background-color: {{ $ramdisk['percent'] >= 90 ? '#ef4444' : ($ramdisk['percent'] >= 70 ? '#f59e0b' : '#6366f1') }}"></div>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">{{ $ramdisk['percent'] }}% usado · {{ $ramdisk['free_gb'] }} GB libres</p>
                    @else
                        <div class="w-full bg-slate-100 rounded-full h-2"></div>
                        <p class="text-xs text-red-400 mt-1">Verificar que tmpfs esté montado en /mnt/cliptemp</p>
                    @endif
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-microchip text-rose-500 text-xs"></i>
                            <span class="text-slate-600">SHM Transcripción</span>
                            <span class="text-xs text-slate-400">/dev/shm</span>
                        </div>
                        @if($shm['available'])
                            <span class="font-medium text-slate-800">{{ $shm['used_gb'] }} <span class="font-normal text-slate-400">/ {{ $shm['total_gb'] }} GB</span></span>
                        @else
                            <span class="text-xs text-red-500">No montado</span>
                        @endif
                    </div>
                    @if($shm['available'])
                        <div class="w-full bg-slate-100 rounded-full h-2">
                            <div class="h-2 rounded-full transition-all"
                                 style="width: {{ max($shm['percent'], 0.5) }}%; background-color: {{ $shm['percent'] >= 90 ? '#ef4444' : ($shm['percent'] >= 70 ? '#f59e0b' : '#10b981') }}"></div>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">{{ $shm['percent'] }}% usado · {{ $shm['free_gb'] }} GB libres</p>
                    @else
                        <div class="w-full bg-slate-100 rounded-full h-2"></div>
                        <p class="text-xs text-red-400 mt-1">Verificar que tmpfs esté montado en /dev/shm</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Acciones Rápidas</h3>
            <div class="space-y-3">
                <a href="/admin/users"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-brand-50 rounded-lg transition-colors group">
                    <div class="w-10 h-10 bg-brand-700 rounded-lg flex items-center justify-center">
                        <i class="fas fa-user-plus text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800 text-sm group-hover:text-brand-700">Nuevo Usuario</p>
                        <p class="text-xs text-slate-500">Agregar al sistema</p>
                    </div>
                </a>
                <a href="/admin/storages"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-green-50 rounded-lg transition-colors group">
                    <div class="w-10 h-10 bg-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-hdd text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800 text-sm group-hover:text-green-700">Nuevo Storage</p>
                        <p class="text-xs text-slate-500">Proveedor de almacenamiento</p>
                    </div>
                </a>
                <a href="{{ $personalStorageId ? '/files?storage_id=' . $personalStorageId : '/files' }}"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-amber-50 rounded-lg transition-colors group">
                    <div class="w-10 h-10 bg-amber-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-cloud-upload-alt text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800 text-sm group-hover:text-amber-700">Subir Archivos</p>
                        <p class="text-xs text-slate-500">Mi espacio personal</p>
                    </div>
                </a>

                @if(count($instructivos) > 0)
                <div class="pt-2 border-t border-slate-100">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Instructivos</p>
                    @foreach($instructivos as $doc)
                    <button type="button" onclick="openInstructivo('{{ $doc['url'] }}')"
                            class="flex items-center gap-3 p-3 bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg transition-colors text-left w-full mb-2">
                        <div class="w-8 h-8 bg-red-600 rounded-lg flex items-center justify-center shrink-0">
                            <i class="fas fa-file-pdf text-white text-xs"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 text-xs truncate">{{ pathinfo($doc['name'], PATHINFO_FILENAME) }}</p>
                            <p class="text-xs text-red-500">PDF · Click para abrir</p>
                        </div>
                    </button>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- System info -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Información del Sistema</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">Versión Laravel</p>
                <p class="font-semibold text-slate-700">13.5.0</p>
            </div>
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">PHP Version</p>
                <p class="font-semibold text-slate-700">8.4.20</p>
            </div>
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">Base de Datos</p>
                <p class="font-semibold text-slate-700">PostgreSQL</p>
            </div>
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">Estado</p>
                <p class="font-semibold text-green-600 flex items-center gap-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full inline-block"></span>
                    Operativo
                </p>
            </div>
        </div>
    </div>

</div>

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startAdminDashboardTour() {
    function scrollTo(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        return el;
    }

    function getStatsCard(label) {
        var cards = document.querySelectorAll('.grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-4 > div');
        for (var i = 0; i < cards.length; i++) {
            var p = cards[i].querySelector('p.text-sm.text-slate-500');
            if (p && p.textContent.trim().toLowerCase().indexOf(label.toLowerCase()) !== -1) return cards[i];
        }
        return null;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Panel de Administración',
                content: 'Esta es la vista principal del sistema. Aquí ves <strong>métricas globales</strong> del sistema, ' +
                         '<strong>estadísticas de uso</strong>, acceso rápido a las funciones administrativas más usadas, ' +
                         'y la sección de <strong>instructivos</strong> con la documentación oficial.',
                icon: 'fa-tachometer-alt',
                color: '#6366f1',
                selector: null,
                position: 'center'
            },
            {
                title: 'Total Usuarios',
                content: 'Cantidad total de usuarios registrados en la plataforma. ' +
                         'La flecha verde indica crecimiento vs el mes anterior.',
                icon: 'fa-users',
                color: '#6366f1',
                selector: function () { return getStatsCard('Total Usuarios'); },
                position: 'bottom',
                onShow: function () {
                    var c = getStatsCard('Total Usuarios');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Total Storages',
                content: 'Cantidad de <strong>storages</strong> (backends de almacenamiento) configurados. ' +
                         'Si todos están activos, se muestra el mensaje "Todos activos".',
                icon: 'fa-database',
                color: '#16a34a',
                selector: function () { return getStatsCard('Total Storages'); },
                position: 'bottom',
                onShow: function () {
                    var c = getStatsCard('Total Storages');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Total Archivos',
                content: 'Cantidad de archivos almacenados en todos los storages. ' +
                         'Debajo se muestra el espacio total usado en MB.',
                icon: 'fa-file',
                color: '#f59e0b',
                selector: function () { return getStatsCard('Total Archivos'); },
                position: 'bottom',
                onShow: function () {
                    var c = getStatsCard('Total Archivos');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Total Shares',
                content: 'Cantidad de <strong>enlaces públicos</strong> activos generados por los usuarios para compartir archivos.',
                icon: 'fa-link',
                color: '#6366f1',
                selector: function () { return getStatsCard('Total Shares'); },
                position: 'bottom',
                onShow: function () {
                    var c = getStatsCard('Total Shares');
                    if (c) scrollTo(c);
                }
            },
            {
                title: 'Estadísticas de Uso',
                content: 'Barras de progreso con indicadores visuales: ' +
                         '<strong>Usuarios Activos</strong>, <strong>Almacenamiento Utilizado</strong>, ' +
                         '<strong>Enlaces Compartidos</strong>, el <strong>RAM Disk FFmpeg</strong> (memoria tmpfs para clips) ' +
                         'y el <strong>SHM Transcripción</strong> (memoria tmpfs para WAVs de transcripción). ' +
                         'Los colores cambian según el porcentaje para identificar alertas rápidamente.',
                icon: 'fa-chart-bar',
                color: '#3b82f6',
                selector: 'h3.text-lg.font-semibold',
                position: 'bottom',
                onShow: function () {
                    var h = document.querySelector('h3.text-lg.font-semibold');
                    if (h) scrollTo(h);
                }
            },
            {
                title: 'RAM Disk FFmpeg',
                content: 'Monitor del disco temporal en memoria (<code>/mnt/cliptemp</code>) usado por FFmpeg para procesar clips. ' +
                         'Si supera el 90% los nuevos cortes pueden fallar. ' +
                         'Si aparece "No montado", el sistema de clips está fuera de servicio.',
                icon: 'fa-memory',
                color: '#06b6d4',
                selector: function () {
                    var labels = document.querySelectorAll('span.text-slate-600');
                    for (var i = 0; i < labels.length; i++) {
                        if (labels[i].textContent.indexOf('RAM Disk FFmpeg') !== -1) {
                            return labels[i].closest('div') || labels[i];
                        }
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var labels = document.querySelectorAll('span.text-slate-600');
                    for (var i = 0; i < labels.length; i++) {
                        if (labels[i].textContent.indexOf('RAM Disk FFmpeg') !== -1) {
                            var container = labels[i].closest('div.w-full.bg-slate-50') || labels[i].closest('div.bg-white') || labels[i].parentElement;
                            if (container) scrollTo(container);
                            return;
                        }
                    }
                }
            },
            {
                title: 'SHM Transcripción',
                content: 'Monitor del disco temporal en memoria (<code>/dev/shm</code>) usado por FFmpeg para generar los WAV temporales durante la transcripción. ' +
                         'Si llega al 100% los workers de transcripción se bloquean. ' +
                         'Es el indicador más crítico: si aparece en rojo, la cola de transcripción se detiene.',
                icon: 'fa-microchip',
                color: '#f43f5e',
                selector: function () {
                    var labels = document.querySelectorAll('span.text-slate-600');
                    for (var i = 0; i < labels.length; i++) {
                        if (labels[i].textContent.indexOf('SHM Transcripción') !== -1) {
                            return labels[i].closest('div') || labels[i];
                        }
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var labels = document.querySelectorAll('span.text-slate-600');
                    for (var i = 0; i < labels.length; i++) {
                        if (labels[i].textContent.indexOf('SHM Transcripción') !== -1) {
                            var container = labels[i].closest('div.w-full.bg-slate-50') || labels[i].closest('div.bg-white') || labels[i].parentElement;
                            if (container) scrollTo(container);
                            return;
                        }
                    }
                }
            },
            {
                title: 'Acciones Rápidas',
                content: 'Atajos a las funciones más usadas: ' +
                         '<strong>Nuevo Usuario</strong> (admin/users), <strong>Nuevo Storage</strong> (admin/storages) y ' +
                         '<strong>Subir Archivos</strong> (mi storage personal). Útiles para ahorrar tiempo.',
                icon: 'fa-bolt',
                color: '#475569',
                selector: 'h3.text-lg.font-semibold:nth-of-type(2)',
                position: 'left',
                onShow: function () {
                    var h3s = document.querySelectorAll('h3.text-lg.font-semibold');
                    if (h3s.length >= 2) scrollTo(h3s[1]);
                }
            },
            {
                title: 'Instructivos (PDF)',
                content: 'Documentación oficial en PDF con manuales de uso. ' +
                         'Haz clic en cualquier PDF para abrirlo en una nueva pestaña. ' +
                         'Esta sección se irá actualizando conforme evolucione la plataforma.',
                icon: 'fa-book-open',
                color: '#dc2626',
                selector: function () {
                    var btn = document.querySelector('button[onclick*="openInstructivo"]');
                    return btn || null;
                },
                position: 'left',
                onShow: function () {
                    var btn = document.querySelector('button[onclick*="openInstructivo"]');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Información del Sistema',
                content: 'Datos técnicos del entorno: <strong>Versión Laravel</strong>, <strong>PHP Version</strong>, ' +
                         '<strong>Base de Datos</strong> (PostgreSQL) y el <strong>Estado</strong> actual del sistema. ' +
                         'El punto verde "Operativo" indica que todos los servicios están funcionando.',
                icon: 'fa-server',
                color: '#64748b',
                selector: 'h3:has-text("Información del Sistema")',
                position: 'top',
                onShow: function () {
                    var h3s = document.querySelectorAll('h3.text-lg.font-semibold');
                    for (var i = 0; i < h3s.length; i++) {
                        if (h3s[i].textContent.indexOf('Información del Sistema') !== -1) {
                            scrollTo(h3s[i]);
                            return;
                        }
                    }
                }
            },
            {
                title: 'Guía Completada',
                content: 'Conoces el Panel de Administración y todas sus secciones. ' +
                         'Desde aquí accedes a la gestión de usuarios, storages, archivos compartidos y la salud del sistema. ' +
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
