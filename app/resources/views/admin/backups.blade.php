@extends('layouts.app')

@section('title', 'Backup de produccion - Tcloud')

@section('content')
<div class="p-6" x-data="backupAdmin()" x-init="init()">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Backup de produccion</h1>
            <p class="text-slate-500 mt-0.5">
                Declara que carpeta del arbol de produccion corresponde a cada storage de origen.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="refresh()"
                    class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-rotate" :class="loading ? 'fa-spin' : ''"></i>
                <span>Actualizar</span>
            </button>
            <button @click="openCreate()"
                    class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-link"></i>
                <span>Asignar carpeta</span>
            </button>
        </div>
    </div>

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Aviso: montaje caido -->
    <div x-show="mountDown" x-cloak
         class="mb-4 flex items-start gap-3 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800">
        <i class="fas fa-triangle-exclamation mt-0.5"></i>
        <div class="text-sm">
            <p class="font-semibold">El montaje de red del arbol de backup esta caido.</p>
            <p class="mt-0.5">No se puede leer el arbol ni verificar carpetas. El export NFS esta en modo <code>hard</code>: un acceso al disco bloquea las peticiones.</p>
        </div>
    </div>

    <!-- Cobertura -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Carpetas del arbol</p>
            <p class="mt-2 text-3xl font-bold text-slate-800" x-text="folders.length"></p>
            <p class="mt-1 text-xs text-slate-500 truncate" x-text="rootLabel"></p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Con origen declarado</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600" x-text="mappedFolders"></p>
            <p class="mt-1 text-xs text-slate-500" x-text="orphanFolders.length + ' sin origen (huérfanas)'"></p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Storages de origen</p>
            <p class="mt-2 text-3xl font-bold text-slate-800" x-text="rows.length"></p>
            <p class="mt-1 text-xs text-slate-500" x-text="unmappedSources + ' sin backup asignado'"></p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Vínculos inválidos</p>
            <p class="mt-2 text-3xl font-bold" :class="brokenSources > 0 ? 'text-red-600' : 'text-slate-800'"
               x-text="brokenSources"></p>
            <p class="mt-1 text-xs text-slate-500">Destino borrado o no local</p>
        </div>
    </div>

    <!-- Carpetas huerfanas -->
    <div x-show="orphanFolders.length > 0" x-cloak class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200">
        <button @click="orphansOpen = !orphansOpen" class="w-full flex items-center justify-between px-5 py-4 text-left">
            <div>
                <h2 class="text-sm font-semibold text-slate-800">Carpetas del árbol sin storage de origen</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Existen en el arbol de produccion pero ningun storage de origen las reclama. El cliente no puede pedirlas.
                </p>
            </div>
            <i class="fas fa-chevron-down text-slate-400 transition-transform" :class="orphansOpen ? 'rotate-180' : ''"></i>
        </button>
        <div x-show="orphansOpen" class="px-5 pb-4 border-t border-slate-100">
            <div class="flex flex-wrap gap-2 pt-3">
                <template x-for="folder in orphanFolders" :key="folder">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-medium">
                        <i class="fas fa-folder-open text-[10px]"></i>
                        <span x-text="folder"></span>
                        <template x-if="suggestionFor(folder)">
                            <span class="text-amber-600/80" x-text="'→ ' + suggestionFor(folder)"></span>
                        </template>
                    </span>
                </template>
            </div>
            <p class="mt-3 text-xs text-slate-400">
                La flecha es una <strong>sugerencia</strong> por nombre compartido: no escribe nada. Declara el vinculo a mano.
            </p>
        </div>
    </div>

    <!-- Listado de storages -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[220px]">
                <input type="text" x-model="search" placeholder="Buscar storage o ruta..."
                       class="w-full border border-slate-300 rounded-lg pl-9 pr-4 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-sm"></i>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" x-model="onlyUnmapped" class="rounded border-slate-300">
                Solo sin mapear
            </label>
            <span class="text-xs text-slate-400" x-text="filtered().length + ' de ' + rows.length"></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Storage de origen</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Carpeta de backup</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Árbol destino</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Estado</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in filtered()" :key="row.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-slate-800" x-text="row.name"></p>
                                <p class="text-xs text-slate-400 truncate max-w-sm" x-text="row.base_path"></p>
                            </td>
                            <td class="px-4 py-3">
                                <template x-if="row.folder">
                                    <code class="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded" x-text="row.folder"></code>
                                </template>
                                <template x-if="!row.folder">
                                    <span class="text-xs text-slate-400">— sin asignar —</span>
                                </template>
                                <template x-if="row.state === 'unmapped' && row.suggestion">
                                    <p class="mt-1 text-[11px] text-slate-400" x-text="'sugerencia: ' + row.suggestion"></p>
                                </template>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell">
                                <span class="text-xs text-slate-600" x-text="row.destination || '—'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs font-medium"
                                      :class="row.state === 'mapped' ? 'bg-emerald-50 text-emerald-700' : (row.state === 'broken' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500')">
                                    <i class="fas text-[10px]"
                                       :class="row.state === 'mapped' ? 'fa-check-circle' : (row.state === 'broken' ? 'fa-triangle-exclamation' : 'fa-minus-circle')"></i>
                                    <span x-text="row.state === 'mapped' ? 'Mapeado' : (row.state === 'broken' ? 'Vínculo inválido' : 'Sin mapear')"></span>
                                </span>
                                <p x-show="row.state === 'broken' && row.reason" class="mt-1 text-[11px] text-red-600" x-text="row.reason"></p>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button @click="openEdit(row)"
                                        class="px-3 py-1.5 bg-brand-50 hover:bg-brand-100 text-brand-700 text-xs font-medium rounded-lg transition-colors">
                                    <i class="fas fa-pen mr-1"></i><span x-text="row.state === 'mapped' ? 'Cambiar' : 'Asignar'"></span>
                                </button>
                                <button @click="remove(row)"
                                        x-show="row.state !== 'unmapped'"
                                        class="ml-1 px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-medium rounded-lg transition-colors">
                                    <i class="fas fa-unlink mr-1"></i>Quitar
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="filtered().length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-slate-400">
                            Ningún storage coincide con el filtro.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div x-cloak x-show="showForm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto" @click.away="showForm = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1"
                    x-text="form.source_storage_id ? 'Cambiar carpeta de backup' : 'Asignar carpeta de backup'"></h2>
                <p class="text-sm text-slate-500 mb-5">El vinculo NO se deduce de nombres: se declara aqui.</p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Storage de origen</label>
                        <select x-model.number="form.source_storage_id" @change="loadFolders()"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                            <option value="">— elige un storage —</option>
                            <template x-for="row in rows" :key="row.id">
                                <option :value="row.id" x-text="row.name + (row.folder ? '  →  ' + row.folder : '')"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Árbol de backup</label>
                        <select x-model.number="form.backup_storage_id" @change="loadFolders()"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                            <option value="">— elige el árbol destino —</option>
                            <template x-for="dest in destinations" :key="dest.id">
                                <option :value="dest.id" x-text="dest.name + '  (' + dest.base_path + ')'"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Carpeta</label>
                        <select x-model="form.backup_folder_name" :disabled="!form.backup_storage_id"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none disabled:bg-slate-50 disabled:text-slate-400">
                            <option value="">— elige la carpeta del árbol —</option>
                            <template x-for="folder in folders" :key="folder.name">
                                <option :value="folder.name"
                                        x-text="folder.name + '  (' + folder.days + ' días' + (folder.last_day ? ', último ' + folder.last_day : '') + ')' + (folder.mapped_to ? '  · ya asignada a ' + folder.mapped_to.name : '')"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-slate-400" x-show="folders.length === 0 && form.backup_storage_id">
                            No se pudieron leer las carpetas del árbol.
                        </p>
                    </div>

                    <div x-show="error" x-cloak class="p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
                        <i class="fas fa-circle-exclamation mr-1.5"></i><span x-text="error"></span>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="save()" :disabled="saving"
                            class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-save mr-1.5" x-show="!saving"></i>
                        <i class="fas fa-spinner fa-spin mr-1.5" x-show="saving"></i>
                        Guardar
                    </button>
                    <button @click="showForm = false"
                            class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
function backupAdmin() {
    return {
        rows: @json($rows),
        destinations: @json($destinations),
        folders: @json($folders),
        orphans: @json($coverage['folders_orphan']),
        suggestions: @json($coverage['suggestions']),
        root: @json($tree['root']),
        mountDown: @json($tree['mount_down']),

        search: '',
        onlyUnmapped: false,
        orphansOpen: false,
        showForm: false,
        saving: false,
        loading: false,
        toast: null,
        error: '',
        form: { source_storage_id: '', backup_storage_id: '', backup_folder_name: '' },

        init() {
            this.rootId = this.root ? this.root.id : '';
        },

        get rootLabel() {
            return this.root ? this.root.name : 'sin árbol declarado';
        },

        get mappedFolders() {
            return this.folders.filter(f => f.mapped_to).length;
        },

        get orphanFolders() {
            return this.orphans;
        },

        get unmappedSources() {
            return this.rows.filter(r => r.state === 'unmapped').length;
        },

        get brokenSources() {
            return this.rows.filter(r => r.state === 'broken').length;
        },

        filtered() {
            const q = this.search.trim().toLowerCase();
            return this.rows.filter(r => {
                if (this.onlyUnmapped && r.state !== 'unmapped') return false;
                if (!q) return true;
                return r.name.toLowerCase().includes(q)
                    || (r.base_path || '').toLowerCase().includes(q)
                    || (r.folder || '').toLowerCase().includes(q);
            });
        },

        suggestionFor(folder) {
            const s = this.suggestions[folder];
            return s ? s.name : null;
        },

        showToast(msg, type) {
            this.toast = { msg, type };
            setTimeout(() => { this.toast = null; }, 4000);
        },

        openCreate() {
            this.form = { source_storage_id: '', backup_storage_id: this.rootId || '', backup_folder_name: '' };
            this.error = '';
            this.showForm = true;
            this.loadFolders();
        },

        openEdit(row) {
            this.form = {
                source_storage_id: row.id,
                backup_storage_id: this.rootId || '',
                backup_folder_name: row.folder || '',
            };
            this.error = '';
            this.showForm = true;
            this.loadFolders();
        },

        async loadFolders() {
            this.folders = [];
            this.error = '';
            if (!this.form.backup_storage_id) return;

            this.loading = true;
            try {
                const res = await apiFetch(`/admin/backups/folders?root=${this.form.backup_storage_id}`, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.error = data.message || 'No se pudo leer el árbol de backup.';
                    return;
                }
                this.folders = data.folders;
                this.root = data.root;
            } catch (e) {
                this.error = 'No se pudo leer el árbol de backup.';
            } finally {
                this.loading = false;
            }
        },

        async save() {
            this.error = '';
            if (!this.form.source_storage_id || !this.form.backup_storage_id || !this.form.backup_folder_name) {
                this.error = 'Elige el storage de origen, el árbol y la carpeta.';
                return;
            }

            this.saving = true;
            try {
                const res = await apiFetch('/admin/backups', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify(this.form),
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    this.showForm = false;
                    this.showToast(data.message || 'Vínculo declarado', 'success');
                    window.location.reload();
                } else {
                    this.error = data.message || 'No se pudo declarar el vínculo.';
                }
            } catch (e) {
                this.error = 'No se pudo declarar el vínculo.';
            } finally {
                this.saving = false;
            }
        },

        async remove(row) {
            if (!confirm(`¿Quitar el vínculo de backup de "${row.name}"?`)) return;

            const res = await apiFetch(`/admin/backups/${row.id}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                this.showToast(data.message || 'Vínculo eliminado', 'success');
                window.location.reload();
            } else {
                this.showToast(data.message || 'No se pudo eliminar el vínculo.', 'error');
            }
        },

        async refresh() {
            this.loading = true;
            try {
                const res = await apiFetch(`/admin/backups/folders?root=${this.rootId || ''}&fresh=1`, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    this.folders = data.folders;
                    this.showToast('Árbol de backup actualizado', 'success');
                } else {
                    this.showToast(data.message || 'No se pudo actualizar', 'error');
                }
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush
