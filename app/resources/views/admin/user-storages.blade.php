@extends('layouts.app')

@section('title', 'Asignar Storages - ' . $targetUser->email . ' - Tcloud')

@section('content')
<div class="p-6" x-data="{
    targetUser: null,
    allStorages: [],
    userStorages: [],
    showAssignModal: false,
    showEditModal: false,
    editingAssignment: null,
    removingStorageKey: null,
    toast: { show: false, message: '', success: true },

    showToast() {
        setTimeout(() => { this.toast.show = false; }, 3500);
    },

    async loadData() {
        const userId = {{ $targetUser->id }};
        const res = await apiFetch('/admin/users/' + userId + '/storages', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (!res.ok) {
            console.error('Error loading storages');
            return;
        }
        const data = await res.json();
        this.userStorages = Array.isArray(data) ? data : [];
    },

    async assignStorage(formData) {
        const userId = {{ $targetUser->id }};
        const res = await apiFetch('/admin/users/' + userId + '/storages', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(Object.fromEntries(formData))
        });
        if (res.ok) {
            this.showAssignModal = false;
            await this.loadData();
            this.toast = { show: true, message: 'Storage asignado correctamente', success: true };
        } else {
            const err = await res.json().catch(() => ({}));
            this.toast = { show: true, message: err.error || err.message || 'Error al asignar el storage', success: false };
        }
        this.showToast();
    },

    async updateAssignment(formData, storageId) {
        const userId = {{ $targetUser->id }};
        const res = await apiFetch('/admin/users/' + userId + '/storages/' + storageId, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(Object.fromEntries(formData))
        });
        if (res.ok) {
            this.showEditModal = false;
            this.editingAssignment = null;
            await this.loadData();
            this.toast = { show: true, message: 'Permisos actualizados', success: true };
        } else {
            const err = await res.json().catch(() => ({}));
            this.toast = { show: true, message: err.error || err.message || 'Error al actualizar permisos', success: false };
        }
        this.showToast();
    },

    async removeAssignment(storageId) {
        if (!confirm('¿Estás seguro de remover este storage?')) return;
        const userId = {{ $targetUser->id }};
        this.removingStorageKey = userId + '-' + storageId;
        try {
            const res = await apiFetch('/admin/users/' + userId + '/storages/' + storageId, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                }
            });
            if (res.ok) {
                await this.loadData();
                this.toast = { show: true, message: 'Storage removido del usuario', success: true };
            } else {
                const err = await res.json().catch(() => ({}));
                this.toast = { show: true, message: err.error || err.message || 'Error al remover el storage', success: false };
            }
            this.showToast();
        } finally {
            this.removingStorageKey = null;
        }
    }
}" x-init="loadData()">
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-4">
            <a href="/admin/users" class="text-brand-600 hover:text-brand-700 font-medium text-sm">← Volver a Usuarios</a>
            <h1 class="text-lg sm:text-2xl font-bold text-slate-800">Storages de: {{ $targetUser->email }}</h1>
        </div>
        <button @click="showAssignModal = true" class="bg-brand-600 text-white px-3 sm:px-4 py-2 rounded-lg hover:bg-brand-700 text-sm font-medium transition-colors flex items-center gap-2">
            <i class="fas fa-plus text-xs"></i> Asignar Storage
        </button>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Storage</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Permisos</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Puede Compartir</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asignado el</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <template x-for="assignment in (userStorages || [])" :key="assignment.storage_provider_id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900" x-text="assignment.storage_provider.name"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="assignment.storage_provider.type === 'local' ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800'"
                                  x-text="assignment.storage_provider.type"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="{
                                      'full': 'bg-green-100 text-green-800',
                                      'write': 'bg-blue-100 text-blue-800',
                                      'upload': 'bg-yellow-100 text-yellow-800',
                                      'read': 'bg-gray-100 text-gray-800'
                                  }[assignment.permissions]"
                                  x-text="assignment.permissions"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="assignment.can_create_shares ? 'Sí' : 'No'"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"
                            x-text="new Date(assignment.assigned_at).toLocaleDateString()"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <button @click="editingAssignment = assignment; showEditModal = true"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button @click="removeAssignment(assignment.storage_provider_id)"
                                        :disabled="removingStorageKey === {{ $targetUser->id }} + '-' + assignment.storage_provider_id"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-red-100 bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <span x-show="removingStorageKey !== {{ $targetUser->id }} + '-' + assignment.storage_provider_id"><i class="fas fa-database"></i> Remover</span>
                                    <span x-show="removingStorageKey === {{ $targetUser->id }} + '-' + assignment.storage_provider_id" class="inline-flex items-center gap-1">
                                        <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Removiendo...
                                    </span>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="(userStorages || []).length === 0" class="text-center py-8 text-gray-500">
            Este usuario no tiene storages asignados.
        </div>
    </div>

    <div x-cloak x-show="showAssignModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showAssignModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Asignar Storage</h2>
                <form @submit.prevent="assignStorage(new FormData($event.target))">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Storage</label>
                            <select name="storage_provider_id" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                                <option value="">Seleccionar storage...</option>
                                <template x-for="storage in allStorages" :key="storage.id">
                                    <option :value="storage.id" x-text="storage.name + ' (' + storage.type + ')'"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Permisos</label>
                            <select name="permissions" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                                <option value="read">Lectura</option>
                                <option value="write">Escritura</option>
                                <option value="upload">Subida</option>
                                <option value="full">Completo</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="can_create_shares" value="1" class="rounded text-brand-600 focus:ring-brand-500">
                                Puede crear shares públicos
                            </label>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="submit" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Asignar</button>
                        <button type="button" @click="showAssignModal = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showEditModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Editar Permisos</h2>
                <template x-if="editingAssignment">
                    <form @submit.prevent="updateAssignment(new FormData($event.target), editingAssignment.storage_provider_id)">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Storage</label>
                                <span x-text="editingAssignment.storage_provider.name" class="text-sm text-slate-800 font-medium"></span>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Permisos</label>
                                <select name="permissions" :value="editingAssignment.permissions" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                                    <option value="read">Lectura</option>
                                    <option value="write">Escritura</option>
                                    <option value="upload">Subida</option>
                                    <option value="full">Completo</option>
                                </select>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="can_create_shares" value="1" :checked="editingAssignment.can_create_shares" class="rounded text-brand-600 focus:ring-brand-500">
                                    Puede crear shares públicos
                                </label>
                            </div>
                        </div>
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Guardar</button>
                            <button type="button" @click="showEditModal = false; editingAssignment = null" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
         x-transition:leave="transition ease-in duration-200"
         class="fixed bottom-4 right-4 z-50 max-w-sm"
         :class="toast.success ? 'bg-green-500' : 'bg-red-500'">
        <div class="flex items-center px-4 py-3 text-white rounded-lg shadow-lg">
            <svg x-show="toast.success" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <svg x-show="!toast.success" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            <span x-text="toast.message"></span>
            <button @click="toast.show = false" class="ml-4 text-white hover:text-gray-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
</div>
@endsection
