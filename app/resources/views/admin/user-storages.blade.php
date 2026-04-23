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

    async loadData() {
        const userId = {{ $targetUser->id }};
        const res = await fetch('/admin/users/' + userId + '/storages', {
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
        const res = await fetch('/admin/users/' + userId + '/storages', {
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
            this.loadData();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },

    async updateAssignment(formData, storageId) {
        const userId = {{ $targetUser->id }};
        const res = await fetch('/admin/users/' + userId + '/storages/' + storageId, {
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
            this.loadData();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },

    async removeAssignment(storageId) {
        if (!confirm('¿Estás seguro de remover este storage?')) return;
        const userId = {{ $targetUser->id }};
        const res = await fetch('/admin/users/' + userId + '/storages/' + storageId, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            }
        });
        if (res.ok) {
            this.loadData();
        }
    }
}" x-init="loadData()">
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-4">
            <a href="/admin/users" class="text-blue-600 hover:text-blue-800">← Volver a Usuarios</a>
            <h1 class="text-2xl font-bold text-gray-800">Storages de: {{ $targetUser->email }}</h1>
        </div>
        <button @click="showAssignModal = true" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
            Asignar Storage
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
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <button @click="editingAssignment = assignment; showEditModal = true" class="text-indigo-600 hover:text-indigo-900 mr-3">Editar</button>
                            <button @click="removeAssignment(assignment.storage_provider_id)" class="text-red-600 hover:text-red-900">Remover</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="(userStorages || []).length === 0" class="text-center py-8 text-gray-500">
            Este usuario no tiene storages asignados.
        </div>
    </div>

    <div x-cloak x-show="showAssignModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showAssignModal = false">
            <h2 class="text-xl font-bold mb-4">Asignar Storage</h2>
            <form @submit.prevent="assignStorage(new FormData($event.target))">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Storage</label>
                    <select name="storage_provider_id" required class="w-full border p-2 rounded">
                        <option value="">Seleccionar storage...</option>
                        <template x-for="storage in allStorages" :key="storage.id">
                            <option :value="storage.id" x-text="storage.name + ' (' + storage.type + ')'"></option>
                        </template>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Permisos</label>
                    <select name="permissions" required class="w-full border p-2 rounded">
                        <option value="read">Lectura</option>
                        <option value="write">Escritura</option>
                        <option value="upload">Subida</option>
                        <option value="full">Completo</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="can_create_shares" value="1" class="mr-2">
                        Puede crear shares públicos
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Asignar</button>
                    <button type="button" @click="showAssignModal = false" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showEditModal = false">
            <h2 class="text-xl font-bold mb-4">Editar Permisos</h2>
            <template x-if="editingAssignment">
                <form @submit.prevent="updateAssignment(new FormData($event.target), editingAssignment.storage_provider_id)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Storage</label>
                        <span x-text="editingAssignment.storage_provider.name" class="text-gray-600"></span>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Permisos</label>
                        <select name="permissions" :value="editingAssignment.permissions" required class="w-full border p-2 rounded">
                            <option value="read">Lectura</option>
                            <option value="write">Escritura</option>
                            <option value="upload">Subida</option>
                            <option value="full">Completo</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="can_create_shares" value="1" :checked="editingAssignment.can_create_shares" class="mr-2">
                            Puede crear shares públicos
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>
                        <button type="button" @click="showEditModal = false; editingAssignment = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
