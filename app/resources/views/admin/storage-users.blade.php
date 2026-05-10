@extends('layouts.app')

@section('title', 'Usuarios del Storage - ' . $storage->name . ' - Tcloud')

@section('content')
<div class="p-6" x-data="{
    userStorages: [],
    storageId: {{ $storage->id }},
    showAssignModal: false,
    showEditModal: false,
    editingAssignment: null,
    allUsers: [],
    selectedUsers: [],
    userSearchQuery: '',
    userSearchResults: [],
    userSearchOpen: false,

    async loadUsers() {
        const res = await fetch('/admin/storages/' + this.storageId + '/users', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            this.userStorages = await res.json();
        }
    },

    async loadAllUsers() {
        const res = await fetch('/admin/users/search?q=', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            this.allUsers = await res.json();
        }
    },

    filterUsers(query) {
        const q = (query || '').toLowerCase();
        return this.allUsers.filter(u => {
            if (this.selectedUsers.find(s => s.id === u.id)) return false;
            if (!q) return true;
            return (u.username && u.username.toLowerCase().includes(q)) || 
                   (u.email && u.email.toLowerCase().includes(q));
        }).slice(0, 20);
    },

    addSelectedUser(user) {
        if (!this.selectedUsers.find(u => u.id === user.id)) {
            this.selectedUsers.push(user);
        }
        this.userSearchQuery = '';
        this.userSearchResults = [];
        this.userSearchOpen = false;
    },

    removeSelectedUser(userId) {
        this.selectedUsers = this.selectedUsers.filter(u => u.id !== userId);
    },

    async assignSelectedUsers() {
        if (this.selectedUsers.length === 0) { alert('Selecciona al menos un usuario'); return; }
        const form = document.getElementById('assign-form');
        const formData = new FormData(form);
        const permissions = formData.get('permissions');
        const canCreateShares = formData.get('can_create_shares') === '1';

        for (const user of this.selectedUsers) {
            const res = await fetch('/admin/storages/' + this.storageId + '/users', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    user_id: user.id,
                    permissions: permissions,
                    can_create_shares: canCreateShares
                })
            });
            if (!res.ok) {
                const err = await res.json();
                alert('Error assigning ' + user.username + ': ' + JSON.stringify(err));
            }
        }
        this.showAssignModal = false;
        this.selectedUsers = [];
        await this.loadUsers();
    },

    async updateAssignment(formData, userId) {
        const res = await fetch('/admin/storages/' + this.storageId + '/users/' + userId, {
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
            await this.loadUsers();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },

    async removeAssignment(userId) {
        if (!confirm('¿Estás seguro de remover este usuario?')) return;
        const res = await fetch('/admin/storages/' + this.storageId + '/users/' + userId, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            }
        });
        if (res.ok) {
            await this.loadUsers();
        }
    }
}" x-init="loadUsers(); loadAllUsers()">
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-4">
            <a href="/admin/storages" class="text-blue-600 hover:text-blue-800">← Volver a Storages</a>
            <h1 class="text-2xl font-bold text-gray-800">Usuarios del Storage: {{ $storage->name }}</h1>
        </div>
        <button @click="showAssignModal = true" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
            Asignar Usuario
        </button>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Permisos</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Puede Compartir</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asignado el</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <template x-for="assignment in userStorages" :key="assignment.user_id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900" x-text="'@' + assignment.user_username"></div>
                            <div class="text-xs text-gray-500" x-text="assignment.user_email"></div>
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
                            <button @click="removeAssignment(assignment.user_id)" class="text-red-600 hover:text-red-900">Remover</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="userStorages.length === 0" class="text-center py-8 text-gray-500">
            Este storage no tiene usuarios asignados.
        </div>
    </div>

    <div x-cloak x-show="showAssignModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showAssignModal = false; userSearchOpen = false">
            <h2 class="text-xl font-bold mb-4">Asignar Usuarios</h2>
            <form id="assign-form" @submit.prevent="assignSelectedUsers()">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Buscar y seleccionar usuarios</label>
                    <div class="relative">
                        <!-- Selected users as tags -->
                        <div x-show="selectedUsers.length > 0" class="flex flex-wrap gap-2 mb-2">
                            <template x-for="user in selectedUsers" :key="user.id">
                                <span class="inline-flex items-center gap-1 bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                    <span x-text="'@' + user.username"></span>
                                    <button type="button" @click="removeSelectedUser(user.id)" class="hover:text-blue-600 focus:outline-none">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </span>
                            </template>
                        </div>
                        <!-- Search input -->
                        <input type="text" x-model="userSearchQuery"
                               @input="userSearchResults = filterUsers(userSearchQuery); userSearchOpen = true"
                               @focus="userSearchResults = filterUsers(userSearchQuery); userSearchOpen = true"
                               @click.away="userSearchOpen = false"
                               placeholder="Escribe para buscar..."
                               class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               autocomplete="off">
                        <!-- Dropdown -->
                        <div x-show="userSearchOpen && userSearchResults.length > 0"
                             class="absolute z-10 mt-1 w-full bg-white border rounded-lg shadow-lg max-h-48 overflow-y-auto">
                            <template x-for="user in userSearchResults" :key="user.id">
                                <div @click="addSelectedUser(user)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer flex justify-between items-center border-b border-gray-100 last:border-0">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                            <span class="text-xs font-medium text-blue-600" x-text="user.username?.charAt(0)?.toUpperCase()"></span>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900" x-text="'@' + user.username"></div>
                                            <div class="text-xs text-gray-500" x-text="user.email"></div>
                                        </div>
                                    </div>
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                    </svg>
                                </div>
                            </template>
                        </div>
                        <!-- Empty state -->
                        <div x-show="userSearchOpen && userSearchQuery.length >= 1 && userSearchResults.length === 0"
                             class="absolute z-10 mt-1 w-full bg-white border rounded-lg shadow-lg px-3 py-4 text-center">
                            <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            <p class="text-sm text-gray-500">Sin resultados para "<span x-text="userSearchQuery"></span>"</p>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Selecciona múltiples usuarios y asígnalos todos de una vez</p>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Permisos (para todos los seleccionados)</label>
                    <select name="permissions" required class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="read">Lectura</option>
                        <option value="write">Escritura</option>
                        <option value="upload">Subida</option>
                        <option value="full">Completo</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="can_create_shares" value="1" class="mr-2 rounded text-blue-600 focus:ring-blue-500">
                        Puede crear shares públicos
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" :disabled="selectedUsers.length === 0" 
                            :class="selectedUsers.length === 0 ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700'"
                            class="text-white px-4 py-2 rounded transition-colors">
                        Asignar <span x-show="selectedUsers.length > 0" x-text="'(' + selectedUsers.length + ')'"></span>
                    </button>
                    <button type="button" @click="showAssignModal = false; selectedUsers = []" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400 transition-colors">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showEditModal = false">
            <h2 class="text-xl font-bold mb-4">Editar Permisos</h2>
            <template x-if="editingAssignment">
                <form @submit.prevent="updateAssignment(new FormData($event.target), editingAssignment.user_id)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Usuario</label>
                        <span x-text="'@' + editingAssignment.user_username" class="text-gray-600 font-medium"></span>
                        <span x-text="editingAssignment.user_email" class="text-gray-400 text-xs ml-1"></span>
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
