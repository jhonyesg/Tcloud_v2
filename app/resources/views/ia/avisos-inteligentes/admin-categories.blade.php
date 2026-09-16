@extends('layouts.app')

@section('title', 'Categorías base - Avisos Inteligentes')

@section('content')
<div class="p-6" x-data="adminCategories()" x-init="init()">
    <div class="mb-4">
        <a href="/ia/avisos-inteligentes" class="text-sm text-brand-600 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Avisos Inteligentes
        </a>
    </div>

    <div class="mb-6 flex items-start justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Categorías base de keywords</h1>
            <p class="text-slate-500 mt-0.5 text-sm">
                Conjunto administrado por ti y mostrado como sugerencia a todos los clientes.
                Las keywords asignadas a estas categorías siguen matcheando por texto igual que antes;
                la categoría es solo una etiqueta visual para que el cliente organice y filtre su lista.
            </p>
        </div>
        <button @click="openCreate()" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">
            <i class="fas fa-plus mr-1.5"></i>Nueva categoría admin
        </button>
    </div>

    <div x-show="error" x-cloak class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="error"></div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Nombre</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Slug</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Color</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Clientes / keywords</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="c in categories" :key="c.id">
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-3 h-3 rounded-full" :style="'background:' + c.color_hex"></span>
                                <span class="text-sm font-medium text-slate-800" x-text="c.name"></span>
                            </div>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell text-sm text-slate-500 font-mono" x-text="c.slug"></td>
                        <td class="px-4 py-3 text-sm text-slate-600 font-mono" x-text="c.color_hex"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-600">
                            <span x-text="c.users_with_keywords"></span> cliente(s) ·
                            <span x-text="c.keywords_total"></span> keyword(s)
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1.5">
                                <button @click="openEdit(c)" class="text-xs px-2.5 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 rounded">Editar</button>
                                <button @click="askDelete(c)" class="text-xs px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr x-show="categories.length === 0">
                    <td colspan="5" class="px-4 py-12 text-center text-slate-400 text-sm">No hay categorías base definidas todavía.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Modal crear/editar --}}
    <div x-show="modalOpen" x-cloak class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4" @keydown.escape.window="modalOpen = false">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6" @click.outside="modalOpen = false">
            <h3 class="text-lg font-semibold text-slate-800 mb-4" x-text="editingId ? 'Editar categoría admin' : 'Nueva categoría admin'"></h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Nombre</label>
                    <input type="text" x-model="form.name" maxlength="80" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" x-model="form.color_hex" class="h-10 w-16 border border-slate-300 rounded cursor-pointer">
                        <input type="text" x-model="form.color_hex" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$" class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                </div>
                <div x-show="formError" x-cloak class="px-3 py-2 bg-red-50 border border-red-200 rounded text-sm text-red-700" x-text="formError"></div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
                <button @click="modalOpen = false" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg text-sm">Cancelar</button>
                <button @click="save()" :disabled="!form.name.trim() || saving" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                    <span x-text="editingId ? 'Guardar cambios' : 'Crear'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- Modal borrar --}}
    <div x-show="deleteOpen" x-cloak class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4" @keydown.escape.window="deleteOpen = false">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6" @click.outside="deleteOpen = false">
            <h3 class="text-lg font-semibold text-slate-800 mb-2">Eliminar categoría admin</h3>
            <p class="text-sm text-slate-600 mb-3">
                Vas a eliminar <strong x-text="toDelete?.name"></strong>.
            </p>
            <p x-show="toDelete && toDelete.keywords_total > 0" class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-3">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Esta categoría tiene <strong x-text="toDelete?.keywords_total"></strong> keyword(s) asignada(s) en <strong x-text="toDelete?.users_with_keywords"></strong> cliente(s).
                Si confirmas, esas keywords pasarán a "Sin categoría" y los clientes las verán aparecer en ese filtro hasta que las recategoricen.
            </p>
            <p x-show="toDelete && (!toDelete.keywords_total || toDelete.keywords_total === 0)" class="text-sm text-slate-500 mb-3">Esta categoría no tiene keywords asignadas.</p>
            <div class="flex justify-end gap-2 mt-4">
                <button @click="deleteOpen = false" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg text-sm">Cancelar</button>
                <button @click="confirmDelete()" :disabled="deleting" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                    <span x-text="toDelete?.keywords_total > 0 ? 'Sí, desasignar y eliminar' : 'Eliminar'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function adminCategories() {
    return {
        categories: [],
        modalOpen: false,
        deleteOpen: false,
        editingId: null,
        toDelete: null,
        saving: false,
        deleting: false,
        form: { name: '', color_hex: '#4654a8' },
        formError: '',
        error: '',
        csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        async init() {
            await this.load();
        },
        async load() {
            try {
                const res = await fetch('/ia/avisos-inteligentes/admin/categories', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const d = await res.json();
                    this.categories = (d.categories || []).map(c => ({
                        ...c,
                        id: Number(c.id),
                        users_with_keywords: Number(c.users_with_keywords || 0),
                        keywords_total: Number(c.keywords_total || 0),
                    }));
                    this.error = '';
                }
            } catch (e) {
                this.error = 'No se pudo cargar la lista.';
            }
        },
        openCreate() {
            this.editingId = null;
            this.form = { name: '', color_hex: '#4654a8' };
            this.formError = '';
            this.modalOpen = true;
        },
        openEdit(c) {
            this.editingId = c.id;
            this.form = { name: c.name, color_hex: c.color_hex };
            this.formError = '';
            this.modalOpen = true;
        },
        async save() {
            if (!this.form.name.trim()) return;
            this.saving = true;
            this.formError = '';
            const url = this.editingId
                ? '/ia/avisos-inteligentes/admin/categories/' + this.editingId
                : '/ia/avisos-inteligentes/admin/categories';
            const method = this.editingId ? 'PATCH' : 'POST';
            try {
                const res = await fetch(url, {
                    method, credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify(this.form),
                });
                const d = await res.json().catch(() => ({}));
                if (res.ok) {
                    this.modalOpen = false;
                    await this.load();
                } else {
                    this.formError = d.error || 'No se pudo guardar.';
                }
            } catch (e) {
                this.formError = 'Error de red.';
            } finally {
                this.saving = false;
            }
        },
        askDelete(c) {
            this.toDelete = c;
            this.deleteOpen = true;
        },
        async confirmDelete() {
            if (!this.toDelete) return;
            this.deleting = true;
            try {
                const res = await fetch('/ia/avisos-inteligentes/admin/categories/' + this.toDelete.id + '?confirm=true', {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                });
                const d = await res.json().catch(() => ({}));
                if (res.ok || res.status === 204) {
                    this.deleteOpen = false;
                    this.toDelete = null;
                    await this.load();
                } else if (res.status === 409 && d.error === 'confirm_required') {
                    // si llegamos aquí sin ?confirm, reintentamos con confirm
                    const res2 = await fetch('/ia/avisos-inteligentes/admin/categories/' + this.toDelete.id + '?confirm=true', {
                        method: 'DELETE', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (res2.ok) {
                        this.deleteOpen = false;
                        this.toDelete = null;
                        await this.load();
                    } else {
                        this.error = d.message || 'No se pudo eliminar.';
                    }
                } else {
                    this.error = d.message || d.error || 'No se pudo eliminar.';
                }
            } catch (e) {
                this.error = 'Error de red.';
            } finally {
                this.deleting = false;
            }
        },
    };
}
</script>
@endpush
@endsection
