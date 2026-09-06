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
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Nombre</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Eliminado</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Días restantes</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Ubicación original</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="item in items" :key="item.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <i :class="item.is_folder ? 'fas fa-folder text-amber-500' : 'fas fa-file text-slate-400'"></i>
                                    <span class="text-sm font-medium text-slate-800" x-text="item.name"></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600" x-text="formatDate(item.deleted_at)"></td>
                            <td class="px-4 py-3">
                                <span class="text-sm font-semibold"
                                      :class="item.is_urgent ? 'text-red-600' : 'text-slate-700'"
                                      x-text="item.days_remaining + ' días'"></span>
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
                                        <i class="fas fa-trash mr-1"></i> Eliminar definitivamente
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
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
        toast: '',

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
            } catch (e) {
                this.showToast('Error de red al cargar la papelera.');
                this.items = [];
            } finally {
                this.isLoading = false;
            }
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
