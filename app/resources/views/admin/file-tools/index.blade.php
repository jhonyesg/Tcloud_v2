@extends('layouts.app')

@section('title', 'Herramientas de Archivo - Tcloud')

@section('content')
<div class="p-6" x-data="fileToolsAdmin()">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Herramientas de Archivo</h1>
            <p class="text-sm text-gray-500 mt-0.5">Gestiona los plugins de herramientas disponibles</p>
        </div>
        <button @click="openCreateModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Nuevo Plugin
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg border p-4">
            <p class="text-xs text-gray-400 mb-1">Total Plugins</p>
            <p class="text-2xl font-bold text-gray-800" x-text="plugins.length"></p>
        </div>
        <div class="bg-white rounded-lg border p-4">
            <p class="text-xs text-gray-400 mb-1">Activos</p>
            <p class="text-2xl font-bold text-green-600" x-text="plugins.filter(p => p.is_active).length"></p>
        </div>
        <div class="bg-white rounded-lg border p-4">
            <p class="text-xs text-gray-400 mb-1">Inactivos</p>
            <p class="text-2xl font-bold text-red-500" x-text="plugins.filter(p => !p.is_active).length"></p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase tracking-wide">
                    <th class="px-5 py-3 text-left">Nombre</th>
                    <th class="px-4 py-3 text-left">Slug</th>
                    <th class="px-4 py-3 text-left">Tipo</th>
                    <th class="px-4 py-3 text-left">MIMEs Soportados</th>
                    <th class="px-4 py-3 text-left">Estado</th>
                    <th class="px-4 py-3 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <template x-for="plugin in plugins" :key="plugin.id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3.5 font-medium text-gray-800" x-text="plugin.name"></td>
                        <td class="px-4 py-3.5 text-gray-500 font-mono text-xs" x-text="plugin.slug"></td>
                        <td class="px-4 py-3.5">
                            <span class="px-2 py-1 rounded text-xs font-medium" :class="{
                                'bg-blue-100 text-blue-700': plugin.type === 'viewer',
                                'bg-green-100 text-green-700': plugin.type === 'editor',
                                'bg-purple-100 text-purple-700': plugin.type === 'player'
                            }" x-text="plugin.type"></span>
                        </td>
                        <td class="px-4 py-3.5 text-xs text-gray-500" x-text="(plugin.supported_mimes || []).join(', ')"></td>
                        <td class="px-4 py-3.5">
                            <span class="px-2 py-1 rounded text-xs font-medium" :class="plugin.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="plugin.is_active ? 'Activo' : 'Inactivo'"></span>
                        </td>
                        <td class="px-4 py-3.5 text-right">
                            <button @click="openEditModal(plugin)" class="text-blue-600 hover:text-blue-800 text-sm mr-3">Editar</button>
                            <button @click="deletePlugin(plugin)" class="text-red-600 hover:text-red-800 text-sm">Eliminar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="plugins.length === 0" class="py-12 text-center text-gray-500">
            <i class="fas fa-plug text-3xl mb-3 text-gray-300"></i>
            <p>No hay plugins configurados</p>
        </div>
    </div>

    <!-- Create/Edit Modal -->
    <div x-show="modal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4" @click.away="closeModal()">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold" x-text="modal.isEdit ? 'Editar Plugin' : 'Nuevo Plugin'"></h3>
                <button @click="closeModal()" class="text-gray-500 hover:text-gray-700 text-xl">&times;</button>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                    <input type="text" x-model="modal.data.name" class="w-full border rounded px-3 py-2" :class="{'border-red-500': modal.errors.name}">
                    <p x-show="modal.errors.name" class="text-red-500 text-xs mt-1" x-text="modal.errors.name"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug <span class="text-red-500">*</span></label>
                    <input type="text" x-model="modal.data.slug" class="w-full border rounded px-3 py-2 font-mono" :class="{'border-red-500': modal.errors.slug}" :disabled="modal.isEdit" placeholder="mi-plugin">
                    <p x-show="modal.errors.slug" class="text-red-500 text-xs mt-1" x-text="modal.errors.slug"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo <span class="text-red-500">*</span></label>
                    <select x-model="modal.data.type" class="w-full border rounded px-3 py-2" :class="{'border-red-500': modal.errors.type}">
                        <option value="">Seleccionar...</option>
                        <option value="viewer">Visor</option>
                        <option value="editor">Editor</option>
                        <option value="player">Reproductor</option>
                    </select>
                    <p x-show="modal.errors.type" class="text-red-500 text-xs mt-1" x-text="modal.errors.type"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">MIMEs Soportados <span class="text-red-500">*</span></label>
                    <input type="text" x-model="modal.data.mimesInput" class="w-full border rounded px-3 py-2" placeholder="application/pdf, image/png (separados por coma)">
                    <p class="text-xs text-gray-500 mt-1">Separados por coma, ej: application/pdf, image/png</p>
                    <p x-show="modal.errors.supported_mimes" class="text-red-500 text-xs mt-1" x-text="modal.errors.supported_mimes"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Recursos JS</label>
                    <input type="text" x-model="modal.data.jsInput" class="w-full border rounded px-3 py-2" placeholder="/plugins/mi-plugin/viewer.js">
                    <p class="text-xs text-gray-500 mt-1">Rutas desde public/, separadas por coma</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Recursos CSS</label>
                    <input type="text" x-model="modal.data.cssInput" class="w-full border rounded px-3 py-2" placeholder="/plugins/mi-plugin/viewer.css">
                    <p class="text-xs text-gray-500 mt-1">Rutas desde public/, separadas por coma</p>
                </div>
                <div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" x-model="modal.data.is_active" class="rounded">
                        <span class="text-sm font-medium text-gray-700">Activo</span>
                    </label>
                </div>
                <div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" x-model="modal.data.is_default" class="rounded">
                        <span class="text-sm font-medium text-gray-700">Default (disponible para todos)</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1">Los plugins default se muestran a usuarios sin plugins asignados explícitamente</p>
                </div>
            </div>
            <div class="flex justify-end gap-3 p-4 border-t">
                <button @click="closeModal()" class="px-4 py-2 border rounded hover:bg-gray-50 text-sm">Cancelar</button>
                <button @click="savePlugin()" :disabled="modal.saving" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm disabled:opacity-50">
                    <span x-show="!modal.saving" x-text="modal.isEdit ? 'Actualizar' : 'Crear'"></span>
                    <span x-show="modal.saving">Guardando...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show" x-transition class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 flex items-center gap-2.5 px-4 py-2.5 rounded-xl shadow-lg text-sm font-medium text-white" :class="toast.success ? 'bg-green-600' : 'bg-red-600'">
        <i :class="toast.success ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i>
        <span x-text="toast.message"></span>
    </div>
</div>
@endsection

@push('scripts')
<script>
function fileToolsAdmin() {
    return {
        plugins: [],
        modal: {
            open: false,
            isEdit: false,
            saving: false,
            errors: {},
            data: { name: '', slug: '', type: '', mimesInput: '', jsInput: '', cssInput: '', is_active: true, is_default: false }
        },
        toast: { show: false, message: '', success: true },

        init() {
            this.loadPlugins();
        },

        async loadPlugins() {
            try {
                const res = await fetch('/admin/file-tools/plugins');
                const data = await res.json();
                this.plugins = data.data || [];
            } catch (e) {
                this.showToast(false, 'Error al cargar plugins');
            }
        },

        openCreateModal() {
            this.modal.isEdit = false;
            this.modal.errors = {};
            this.modal.data = { name: '', slug: '', type: '', mimesInput: '', jsInput: '', cssInput: '', is_active: true };
            this.modal.open = true;
        },

        openEditModal(plugin) {
            this.modal.isEdit = true;
            this.modal.errors = {};
            this.modal.data = {
                id: plugin.id,
                name: plugin.name,
                slug: plugin.slug,
                type: plugin.type,
                mimesInput: (plugin.supported_mimes || []).join(', '),
                jsInput: (plugin.resources?.js || []).join(', '),
                cssInput: (plugin.resources?.css || []).join(', '),
                is_active: plugin.is_active,
                is_default: plugin.is_default || false
            };
            this.modal.open = true;
        },

        closeModal() {
            this.modal.open = false;
        },

        validateModal() {
            this.modal.errors = {};
            if (!this.modal.data.name) this.modal.errors.name = 'El nombre es obligatorio';
            if (!this.modal.data.slug) this.modal.errors.slug = 'El slug es obligatorio';
            if (!this.modal.data.type) this.modal.errors.type = 'El tipo es obligatorio';
            if (!this.modal.data.mimesInput) this.modal.errors.supported_mimes = 'Los MIMEs son obligatorios';
            return Object.keys(this.modal.errors).length === 0;
        },

        async savePlugin() {
            if (!this.validateModal()) return;
            this.modal.saving = true;

            const mimes = this.modal.data.mimesInput.split(',').map(s => s.trim()).filter(Boolean);
            const jsFiles = this.modal.data.jsInput.split(',').map(s => s.trim()).filter(Boolean);
            const cssFiles = this.modal.data.cssInput.split(',').map(s => s.trim()).filter(Boolean);

            const payload = {
                name: this.modal.data.name,
                type: this.modal.data.type,
                supported_mimes: mimes,
                resources: { js: jsFiles, css: cssFiles },
                is_active: this.modal.data.is_active,
                is_default: this.modal.data.is_default
            };

            if (!this.modal.isEdit) {
                payload.slug = this.modal.data.slug;
            }

            try {
                const url = this.modal.isEdit ? `/admin/file-tools/plugins/${this.modal.data.id}` : '/admin/file-tools/plugins';
                const method = this.modal.isEdit ? 'PUT' : 'POST';
                const res = await fetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok) {
                    this.showToast(true, this.modal.isEdit ? 'Plugin actualizado' : 'Plugin creado');
                    this.closeModal();
                    await this.loadPlugins();
                } else {
                    this.modal.errors = data.errors || {};
                    this.showToast(false, data.message || 'Error al guardar');
                }
            } catch (e) {
                this.showToast(false, 'Error: ' + e.message);
            }
            this.modal.saving = false;
        },

        async deletePlugin(plugin) {
            if (!confirm(`¿Eliminar el plugin "${plugin.name}"?`)) return;
            try {
                const res = await fetch(`/admin/file-tools/plugins/${plugin.id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
                if (res.ok) {
                    this.showToast(true, 'Plugin eliminado');
                    await this.loadPlugins();
                } else {
                    this.showToast(false, 'Error al eliminar');
                }
            } catch (e) {
                this.showToast(false, 'Error: ' + e.message);
            }
        },

        showToast(success, message) {
            this.toast = { show: true, success, message };
            setTimeout(() => { this.toast.show = false; }, 3000);
        }
    }
}
</script>
@endpush
