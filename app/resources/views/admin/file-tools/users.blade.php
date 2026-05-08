@extends('layouts.app')

@section('title', 'Asignar Plugins a Usuario - Tcloud')

@section('content')
<div class="p-6" x-data="userFileTools()" x-cloak>
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Plugins por Usuario</h1>
            <p class="text-sm text-gray-500 mt-0.5">Asigna plugins de herramientas a usuarios específicos</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
        <div class="p-4 border-b border-gray-100">
            <div class="flex items-center gap-4">
                <select x-model="selectedUser" @change="loadUserPlugins()" class="flex-1 border rounded-lg px-3 py-2">
                    <option value="">Seleccionar usuario...</option>
                    <template x-for="user in users" :key="user.id">
                        <option :value="user.id" x-text="user.email"></option>
                    </template>
                </select>
                <button @click="showAssignModal()" :disabled="!selectedUser" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm disabled:opacity-50">
                    <i class="fas fa-plus mr-1"></i>
                    Asignar Plugin
                </button>
            </div>
        </div>

        <template x-if="selectedUser">
            <div class="p-4">
                <h3 class="font-medium text-gray-800 mb-3">Plugins asignados a este usuario:</h3>
                <template x-if="userPlugins.length > 0">
                    <table class="w-full text-sm mb-4">
                        <thead>
                            <tr class="border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase">
                                <th class="text-left py-2 px-3">Plugin</th>
                                <th class="text-left py-2 px-3">Tipo</th>
                                <th class="text-left py-2 px-3">Expira</th>
                                <th class="text-left py-2 px-3">Estado</th>
                                <th class="text-right py-2 px-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="up in userPlugins" :key="up.id">
                                <tr class="border-b border-gray-50">
                                    <td class="py-2 px-3 font-medium" x-text="up.plugin?.name || 'N/A'"></td>
                                    <td class="py-2 px-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700" x-text="up.plugin?.type"></span>
                                    </td>
                                    <td class="py-2 px-3 text-gray-500" x-text="up.expires_at ? new Date(up.expires_at).toLocaleDateString() : 'Sin expiración'"></td>
                                    <td class="py-2 px-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-medium" :class="up.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="up.is_active ? 'Activo' : 'Inactivo'"></span>
                                    </td>
                                    <td class="py-2 px-3 text-right">
                                        <button @click="revokePlugin(up)" class="text-red-600 hover:text-red-800 text-sm">Revocar</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
                <div x-show="userPlugins.length === 0" class="text-center py-8 text-gray-500">
                    <p>Este usuario no tiene plugins asignados</p>
                </div>
            </div>
        </template>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-medium text-gray-800">Plugins disponibles en el sistema</h3>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase tracking-wide">
                    <th class="px-5 py-3 text-left">Plugin</th>
                    <th class="px-4 py-3 text-left">Tipo</th>
                    <th class="px-4 py-3 text-left">MIMEs</th>
                    <th class="px-4 py-3 text-left">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <template x-for="plugin in allPlugins" :key="plugin.id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3.5 font-medium text-gray-800" x-text="plugin.name"></td>
                        <td class="px-4 py-3.5">
                            <span class="px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-700" x-text="plugin.type"></span>
                        </td>
                        <td class="px-4 py-3.5 text-xs text-gray-500" x-text="(plugin.supported_mimes || []).join(', ')"></td>
                        <td class="px-4 py-3.5">
                            <span class="px-2 py-1 rounded text-xs font-medium" :class="plugin.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="plugin.is_active ? 'Activo' : 'Inactivo'"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Assign Modal -->
    <div x-show="assignModal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4" @click.away="assignModal.open = false">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold">Asignar Plugin</h3>
                <button @click="assignModal.open = false" class="text-gray-500 hover:text-gray-700 text-xl">&times;</button>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Plugin <span class="text-red-500">*</span></label>
                    <select x-model="assignModal.pluginId" class="w-full border rounded px-3 py-2">
                        <option value="">Seleccionar...</option>
                        <template x-for="plugin in availablePluginsForUser" :key="plugin.id">
                            <option :value="plugin.id" x-text="plugin.name + ' (' + plugin.type + ')'"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de expiración (opcional)</label>
                    <input type="date" x-model="assignModal.expiresAt" class="w-full border rounded px-3 py-2" min="">
                    <p class="text-xs text-gray-500 mt-1">Dejar vacío para sin expiración</p>
                </div>
            </div>
            <div class="flex justify-end gap-3 p-4 border-t">
                <button @click="assignModal.open = false" class="px-4 py-2 border rounded hover:bg-gray-50 text-sm">Cancelar</button>
                <button @click="assignPlugin()" :disabled="assignModal.saving || !assignModal.pluginId" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm disabled:opacity-50">
                    <span x-show="!assignModal.saving">Asignar</span>
                    <span x-show="assignModal.saving">Asignando...</span>
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
function userFileTools() {
    return {
        users: [],
        allPlugins: [],
        selectedUser: '',
        userPlugins: [],
        assignModal: { open: false, pluginId: '', expiresAt: '', saving: false },
        toast: { show: false, message: '', success: true },

        init() {
            this.loadUsers();
            this.loadAllPlugins();
        },

        async loadUsers() {
            try {
                const res = await fetch('/admin/users');
                const data = await res.json();
                this.users = data.data || [];
            } catch (e) {
                console.error('Error loading users:', e);
            }
        },

        async loadAllPlugins() {
            try {
                const res = await fetch('/admin/file-tools/plugins');
                const data = await res.json();
                this.allPlugins = data.data || [];
            } catch (e) {
                console.error('Error loading plugins:', e);
            }
        },

        async loadUserPlugins() {
            if (!this.selectedUser) {
                this.userPlugins = [];
                return;
            }
            try {
                const res = await fetch(`/admin/file-tools/user/${this.selectedUser}/plugins`);
                const data = await res.json();
                this.userPlugins = data.data || [];
            } catch (e) {
                this.showToast(false, 'Error al cargar plugins del usuario');
            }
        },

        get availablePluginsForUser() {
            const assignedIds = this.userPlugins.map(up => up.plugin?.id);
            return this.allPlugins.filter(p => p.is_active && !assignedIds.includes(p.id));
        },

        showAssignModal() {
            this.assignModal = { open: true, pluginId: '', expiresAt: '', saving: false };
        },

        async assignPlugin() {
            if (!this.assignModal.pluginId) return;
            this.assignModal.saving = true;
            try {
                const payload = { plugin_id: this.assignModal.pluginId };
                if (this.assignModal.expiresAt) {
                    payload.expires_at = this.assignModal.expiresAt;
                }
                const res = await fetch(`/admin/file-tools/user/${this.selectedUser}/plugins`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(payload)
                });
                if (res.ok) {
                    this.showToast(true, 'Plugin asignado');
                    this.assignModal.open = false;
                    await this.loadUserPlugins();
                } else {
                    const data = await res.json();
                    this.showToast(false, data.message || 'Error al asignar');
                }
            } catch (e) {
                this.showToast(false, 'Error: ' + e.message);
            }
            this.assignModal.saving = false;
        },

        async revokePlugin(userPlugin) {
            if (!confirm('¿Revocar este plugin?')) return;
            try {
                const res = await fetch(`/admin/file-tools/user/${this.selectedUser}/plugins/${userPlugin.plugin_id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
                if (res.ok) {
                    this.showToast(true, 'Plugin revocado');
                    await this.loadUserPlugins();
                } else {
                    this.showToast(false, 'Error al revocar');
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
