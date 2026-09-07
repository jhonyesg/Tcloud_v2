@extends('layouts.app')

@section('title', 'Papelera de reciclaje')

@section('content')
<div x-data="papeleraApp()" x-init="loadItems()" class="px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                <i class="fas fa-trash-can text-brand-500 mr-2"></i>Papelera de reciclaje
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Los elementos se eliminan automáticamente después de
                <span class="font-semibold">{{ (int) config('trash.retention_days', 15) }} días</span>.
            </p>
        </div>
        <div class="flex gap-2">
            <button type="button"
                    @click="confirming = 'empty'"
                    :disabled="items.length === 0"
                    class="px-4 py-2 bg-red-500 hover:bg-red-600 disabled:bg-slate-300 text-white text-sm font-medium rounded-lg transition-colors">
                <i class="fas fa-trash mr-1"></i> Vaciar papelera
            </button>
        </div>
    </div>

    {{-- Panel colapsable: cómo funciona la papelera (patrón igual a ia/api-transcriptor) --}}
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <button type="button"
                @click="showHelp = !showHelp"
                :aria-expanded="showHelp ? 'true' : 'false'"
                aria-controls="papelera-how-it-works"
                class="w-full flex items-center justify-between px-5 py-3 text-left hover:bg-slate-50 transition-colors">
            <span class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <i class="fas fa-circle-info text-brand-500"></i>
                ¿Cómo funciona la papelera?
            </span>
            <i class="fas fa-chevron-down text-xs text-slate-400 transition-transform"
               :class="showHelp ? 'rotate-180' : ''"></i>
        </button>
        <div id="papelera-how-it-works"
             x-show="showHelp"
             x-transition
             class="border-t border-slate-100">
            <div class="px-5 py-5 text-sm text-slate-600 grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Bloque 1: Cuando borras un archivo --}}
                <div class="space-y-2">
                    <p class="font-medium text-slate-700">
                        <i class="fas fa-arrow-right text-brand-400 mr-1"></i>Cuando borras un archivo
                    </p>
                    <ul class="list-disc list-inside space-y-1 text-xs text-slate-500">
                        <li>El archivo <b>no se mueve de disco</b>: sigue en su carpeta original.</li>
                        <li>Solo se marcan flags en la fila de BD:
                            <span class="font-mono bg-slate-100 px-1 rounded">is_trashed=true</span>,
                            <span class="font-mono bg-slate-100 px-1 rounded">deleted_at=fecha</span>,
                            <span class="font-mono bg-slate-100 px-1 rounded">parent_id=NULL</span>,
                            <span class="font-mono bg-slate-100 px-1 rounded">original_parent_id</span>.
                        </li>
                        <li>Si es carpeta, la marca se aplica recursivamente a todos los hijos.</li>
                    </ul>
                    <p class="text-xs text-slate-500 mt-2 bg-slate-50 border border-slate-200 rounded-md p-2">
                        <i class="fas fa-circle-info text-slate-400 mr-1"></i>
                        <b>No hay duplicidad en BD.</b> Es la misma fila con un flag. El listado del
                        explorador y el sync del storage filtran por
                        <span class="font-mono bg-slate-100 px-1 rounded">is_trashed=false</span>,
                        así que un archivo en papelera nunca aparece dos veces.
                    </p>
                </div>

                {{-- Bloque 2: Cuándo se borra solo --}}
                <div class="space-y-2">
                    <p class="font-medium text-slate-700">
                        <i class="fas fa-clock text-brand-400 mr-1"></i>Cuándo se borra definitivamente
                    </p>
                    <ul class="list-disc list-inside space-y-1 text-xs text-slate-500">
                        <li>El comando
                            <span class="font-mono bg-slate-100 px-1 rounded">php artisan trash:purge</span>
                            corre diario (scheduler a las 03:17).
                        </li>
                        <li>Borra items con
                            <span class="font-mono bg-slate-100 px-1 rounded">deleted_at</span>
                            más viejo que
                            <span class="font-semibold">{{ (int) config('trash.retention_days', 15) }} días</span>
                            (configurable).
                        </li>
                        <li>Si la cantidad de candidatos supera el 50% del total, la purga
                            <b>aborta con log</b>
                            <span class="font-mono bg-slate-100 px-1 rounded">papelera.purge.aborted_mass_delete</span>
                            — protección anti borrado masivo.
                        </li>
                        <li>Si el item tiene transcripciones, shares o jobs de edición activos,
                            <b>no se borra</b>: queda en papelera hasta que liberes esas dependencias.
                        </li>
                    </ul>
                </div>

                {{-- Bloque 3: Restaurar vs Eliminar definitivamente --}}
                <div class="space-y-2">
                    <p class="font-medium text-slate-700">
                        <i class="fas fa-arrows-rotate text-brand-400 mr-1"></i>Restaurar vs eliminar
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="border border-slate-200 rounded-md p-3 bg-slate-50">
                            <p class="text-xs font-semibold text-brand-700 mb-1">
                                <i class="fas fa-undo mr-1"></i>Restaurar
                            </p>
                            <p class="text-xs text-slate-500">
                                Vuelve a su carpeta original. Si no existe, va al root.
                                Si ya hay un archivo con el mismo nombre allí, se le agrega el sufijo
                                <span class="font-mono bg-white px-1 rounded">-restored-&lt;timestamp&gt;</span>.
                            </p>
                        </div>
                        <div class="border border-slate-200 rounded-md p-3 bg-slate-50">
                            <p class="text-xs font-semibold text-red-700 mb-1">
                                <i class="fas fa-trash mr-1"></i>Eliminar definitivamente
                            </p>
                            <p class="text-xs text-slate-500">
                                Borra la fila y el archivo en disco. <b>No se puede</b> si el item
                                tiene shares, transcripciones o jobs activos — el botón se deshabilita.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Bloque 4: Espacio y links públicos --}}
                <div class="space-y-2">
                    <p class="font-medium text-slate-700">
                        <i class="fas fa-hard-drive text-brand-400 mr-1"></i>Espacio y links públicos
                    </p>
                    <ul class="list-disc list-inside space-y-1 text-xs text-slate-500">
                        <li>Mientras esté en papelera, el archivo <b>sigue contando en tu cuota</b>
                            de storage personal. Solo se libera cuando se purga automáticamente o
                            lo eliminas definitivamente.</li>
                        <li>Los links públicos (<span class="font-mono bg-slate-100 px-1 rounded">/s/&lt;token&gt;</span>)
                            de un archivo en papelera devuelven
                            <span class="font-mono bg-slate-100 px-1 rounded">410 Gone</span>
                            al visitante: el archivo ya no está disponible aunque el link siga existiendo.
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat tiles -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded-lg border border-slate-200 px-4 py-3">
            <div class="text-2xl font-semibold text-slate-800 tabular-nums" x-text="stats.total"></div>
            <div class="text-xs text-slate-500 mt-0.5">En papelera</div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 px-4 py-3"
             :class="stats.urgent > 0 ? 'border-amber-300' : ''">
            <div class="text-2xl font-semibold tabular-nums"
                 :class="stats.urgent > 0 ? 'text-amber-600' : 'text-slate-800'"
                 x-text="stats.urgent"></div>
            <div class="text-xs text-slate-500 mt-0.5">Por expirar pronto</div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 px-4 py-3">
            <div class="text-2xl font-semibold text-slate-800 tabular-nums" x-text="formatSize(stats.size_bytes)"></div>
            <div class="text-xs text-slate-500 mt-0.5">A liberar al purgar</div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 px-4 py-3">
            <div class="text-2xl font-semibold text-slate-800 tabular-nums" x-text="daysUntilPurge()"></div>
            <div class="text-xs text-slate-500 mt-0.5">Próxima purga</div>
        </div>
    </div>

    <!-- Banner urgente -->
    <div x-show="stats.urgent > 0"
         x-transition.opacity
         class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-3">
        <i class="fas fa-exclamation-triangle text-amber-500"></i>
        <span class="text-sm text-amber-800">
            Tienes <b x-text="stats.urgent"></b>
            archivo<span x-show="stats.urgent > 1">s</span>
            que se borrarán en menos de 3 días.
            <a href="#" @click.prevent="filter = 'urgent'" class="underline font-medium">Ver cuáles</a>
        </span>
    </div>

    <!-- Filtros -->
    <div class="flex flex-wrap gap-2 mb-4" x-show="!isLoading">
        <button type="button" @click="filter = 'all'"
                :class="filter === 'all' ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-slate-600 border-slate-200'"
                class="px-3 py-1.5 text-xs font-medium border rounded-md transition-colors">
            Todos (<span x-text="items.length"></span>)
        </button>
        <button type="button" @click="filter = 'urgent'"
                :class="filter === 'urgent' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-slate-600 border-slate-200'"
                class="px-3 py-1.5 text-xs font-medium border rounded-md transition-colors">
            Por expirar (<span x-text="countByFilter('urgent')"></span>)
        </button>
        <button type="button" @click="filter = 'critical'"
                :class="filter === 'critical' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-slate-600 border-slate-200'"
                class="px-3 py-1.5 text-xs font-medium border rounded-md transition-colors">
            Críticos (<span x-text="countByFilter('critical')"></span>)
        </button>
    </div>

    <div x-show="isLoading" class="text-center py-12 text-slate-500">
        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
        <p>Cargando papelera...</p>
    </div>

    <div x-show="!isLoading && items.length === 0" class="text-center py-16 bg-slate-50 rounded-lg border border-slate-200">
        <i class="fas fa-trash-can text-5xl text-slate-300 mb-4"></i>
        <p class="text-slate-600 font-medium">La papelera está vacía</p>
        <p class="text-sm text-slate-400 mt-1">Los archivos eliminados aparecerán aquí y se borrarán automáticamente.</p>
    </div>

    <div x-show="!isLoading && items.length > 0" class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
        <!-- Desktop: tabla -->
        <div class="hidden sm:block overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600">Nombre</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600">Eliminado</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600">Días restantes</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600">Ubicación original</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="item in filteredItems()" :key="item.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <i :class="item.is_folder ? 'fas fa-folder text-amber-500' : 'fas fa-file text-slate-400'"></i>
                                    <span class="text-sm font-medium text-slate-800" x-text="item.name"></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600" x-text="formatDate(item.deleted_at)"></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold tabular-nums w-12 text-right"
                                          :class="progressColorClass(item.days_remaining)"
                                          x-text="item.days_remaining + 'd'"></span>
                                    <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden min-w-[60px]">
                                        <div class="h-full rounded-full transition-all"
                                             :class="progressBarClass(item.days_remaining)"
                                             :style="`width: ${progressPct(item.days_remaining)}%`"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 font-mono" x-text="item.path || '/'"></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-2 justify-end">
                                    <button type="button"
                                            @click="confirmAction('restore', item)"
                                            class="px-3 py-1.5 bg-brand-500 hover:bg-brand-600 text-white text-xs font-medium rounded transition-colors">
                                        <i class="fas fa-undo mr-1"></i> Restaurar
                                    </button>
                                    <button type="button"
                                            @click="confirmAction('hardDelete', item)"
                                            class="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white text-xs font-medium rounded transition-colors">
                                        <i class="fas fa-trash mr-1"></i> Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Móvil: cards -->
        <div class="sm:hidden divide-y divide-slate-100">
            <template x-for="item in filteredItems()" :key="item.id">
                <div class="p-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0 flex-1">
                            <i :class="item.is_folder ? 'fas fa-folder text-amber-500' : 'fas fa-file text-slate-400'"></i>
                            <span class="text-sm font-medium text-slate-800 truncate" x-text="item.name"></span>
                        </div>
                        <span class="text-sm font-semibold tabular-nums"
                              :class="progressColorClass(item.days_remaining)"
                              x-text="item.days_remaining + 'd'"></span>
                    </div>
                    <div class="mt-2 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all"
                             :class="progressBarClass(item.days_remaining)"
                             :style="`width: ${progressPct(item.days_remaining)}%`"></div>
                    </div>
                    <div class="mt-1.5 text-xs text-slate-500 font-mono truncate" x-text="item.path || '/'"></div>
                    <div class="mt-2 flex gap-2 justify-end">
                        <button type="button"
                                @click="confirmAction('restore', item)"
                                class="px-3 py-1 bg-brand-500 hover:bg-brand-600 text-white text-xs font-medium rounded">
                            Restaurar
                        </button>
                        <button type="button"
                                @click="confirmAction('hardDelete', item)"
                                class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white text-xs font-medium rounded">
                            Eliminar
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Modal de confirmación -->
    <div x-show="confirming !== null" x-transition.opacity
         class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4"
         @click.self="confirming = null">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-2"
                x-text="confirming === 'empty' ? '¿Vaciar toda la papelera?' : (confirming === 'restore' ? '¿Restaurar?' : '¿Eliminar definitivamente?')"></h3>
            <p class="text-sm text-slate-600 mb-4"
               x-text="confirming === 'empty' ? 'Se borrarán permanentemente todos los elementos de tu papelera. Los archivos con transcripciones o compartidos se conservarán.' : (confirming === 'restore' ? 'El elemento volverá a su ubicación original. Si el padre ya no existe, irá al root.' : 'Esta acción no se puede deshacer.')"></p>
            <p x-show="pendingItem" class="text-sm font-mono bg-slate-50 p-2 rounded mb-4" x-text="pendingItem?.name"></p>
            <div class="flex gap-2 justify-end">
                <button type="button" @click="confirming = null" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded">Cancelar</button>
                <button type="button"
                        @click="executeConfirm()"
                        :disabled="isExecuting"
                        class="px-4 py-2 bg-red-500 hover:bg-red-600 disabled:bg-slate-300 text-white text-sm font-medium rounded">
                    <span x-show="!isExecuting">Confirmar</span>
                    <span x-show="isExecuting"><i class="fas fa-spinner fa-spin mr-1"></i> Procesando...</span>
                </button>
            </div>
        </div>
    </div>

    <div x-show="toast" x-transition
         class="fixed bottom-6 right-6 bg-slate-800 text-white px-5 py-3 rounded-lg shadow-lg z-50"
         x-text="toast"></div>
</div>

<script>
function papeleraApp() {
    return {
        items: [],
        isLoading: true,
        isExecuting: false,
        confirming: null,
        pendingItem: null,
        showHelp: false,
        toast: '',
        filter: 'all',
        stats: {
            total: 0,
            urgent: 0,
            critical: 0,
            size_bytes: 0,
            next_purge_date: null,
        },

        async loadItems() {
            this.isLoading = true;
            try {
                const res = await fetch('/papelera?page=1', {
                    credentials: 'include',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    }
                });
                if (!res.ok) {
                    this.showToast('Error al cargar la papelera (' + res.status + ').');
                    this.items = [];
                    return;
                }
                const data = await res.json();
                this.items = Array.isArray(data?.items) ? data.items : [];
                if (data?.stats && typeof data.stats === 'object') {
                    this.stats = { ...this.stats, ...data.stats };
                }
            } catch (e) {
                this.showToast('Error de red al cargar la papelera.');
                this.items = [];
            } finally {
                this.isLoading = false;
            }
        },

        progressPct(days) {
            const retention = 15;
            const pct = (Math.max(0, days) / retention) * 100;
            return Math.max(2, Math.min(100, pct));
        },

        progressBarClass(days) {
            const pct = this.progressPct(days);
            if (pct > 30) return 'bg-brand-500';
            if (pct > 10) return 'bg-amber-500';
            return 'bg-red-500';
        },

        progressColorClass(days) {
            const pct = this.progressPct(days);
            if (pct > 30) return 'text-slate-700';
            if (pct > 10) return 'text-amber-600';
            return 'text-red-600';
        },

        filteredItems() {
            if (this.filter === 'all') return this.items;
            if (this.filter === 'urgent') return this.items.filter(i => i.days_remaining <= 3);
            if (this.filter === 'critical') return this.items.filter(i => i.days_remaining <= 1);
            return this.items;
        },

        countByFilter(filter) {
            if (filter === 'urgent') return this.items.filter(i => i.days_remaining <= 3).length;
            if (filter === 'critical') return this.items.filter(i => i.days_remaining <= 1).length;
            return this.items.length;
        },

        formatSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            const val = bytes / Math.pow(1024, i);
            return val.toFixed(val < 10 ? 1 : 0) + ' ' + units[i];
        },

        daysUntilPurge() {
            if (!this.stats.next_purge_date) return '—';
            const next = new Date(this.stats.next_purge_date);
            const now = new Date();
            const diffDays = Math.round((next - now) / (1000 * 60 * 60 * 24));
            if (diffDays <= 0) return 'hoy';
            if (diffDays === 1) return 'mañana';
            return 'en ' + diffDays + ' días';
        },

        confirmAction(action, item) {
            this.confirming = action;
            this.pendingItem = item;
        },

        async executeConfirm() {
            if (this.isExecuting) return;
            this.isExecuting = true;

            try {
                if (this.confirming === 'empty') {
                    const res = await fetch('/papelera/empty', {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    });
                    if (res.ok) {
                        const data = await res.json();
                        this.showToast(`Papelera vaciada (${data.deleted ?? 0} elementos).`);
                        await this.loadItems();
                    } else {
                        this.showToast('Error al vaciar la papelera (' + res.status + ').');
                    }
                } else if (this.confirming === 'restore' && this.pendingItem) {
                    const res = await fetch('/papelera/' + this.pendingItem.id + '/restore', {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    });
                    if (res.ok) {
                        this.showToast('Elemento restaurado.');
                        await this.loadItems();
                    } else {
                        this.showToast('Error al restaurar (' + res.status + ').');
                    }
                } else if (this.confirming === 'hardDelete' && this.pendingItem) {
                    const res = await fetch('/papelera/' + this.pendingItem.id, {
                        method: 'DELETE',
                        credentials: 'include',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    });
                    if (res.ok) {
                        this.showToast('Eliminado definitivamente.');
                        await this.loadItems();
                    } else if (res.status === 409) {
                        this.showToast('No se puede eliminar: el elemento tiene transcripciones o compartidos activos.');
                    } else {
                        this.showToast('Error al eliminar (' + res.status + ').');
                    }
                }
            } catch (e) {
                this.showToast('Error de red.');
            } finally {
                this.isExecuting = false;
                this.confirming = null;
                this.pendingItem = null;
            }
        },

        formatDate(iso) {
            if (!iso) return '-';
            try {
                const d = new Date(iso);
                return d.toLocaleDateString('es-CO', { year: 'numeric', month: 'short', day: 'numeric' });
            } catch (_) {
                return iso;
            }
        },

        showToast(msg) {
            this.toast = msg;
            setTimeout(() => { this.toast = ''; }, 3500);
        },
    };
}
</script>
@endsection
