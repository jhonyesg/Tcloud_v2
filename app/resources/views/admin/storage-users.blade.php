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
    removingStorageUserKey: null,
    toast: { show: false, message: '', success: true },

    showToast() {
        setTimeout(() => { this.toast.show = false; }, 3500);
    },

    async loadUsers() {
        const res = await apiFetch('/admin/storages/' + this.storageId + '/users', {
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
        const res = await apiFetch('/admin/users/search?q=', {
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
        if (this.selectedUsers.length === 0) {
            this.toast = { show: true, message: 'Selecciona al menos un usuario', success: false };
            this.showToast();
            return;
        }
        const form = document.getElementById('assign-form');
        const formData = new FormData(form);
        const permissions = formData.get('permissions');
        const canCreateShares = formData.get('can_create_shares') === '1';
        let failed = [];

        for (const user of this.selectedUsers) {
            const res = await apiFetch('/admin/storages/' + this.storageId + '/users', {
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
                failed.push('@' + user.username);
            }
        }
        this.showAssignModal = false;
        this.selectedUsers = [];
        await this.loadUsers();
        if (failed.length > 0) {
            this.toast = { show: true, message: 'Error al asignar: ' + failed.join(', '), success: false };
        } else {
            this.toast = { show: true, message: 'Usuario(s) asignado(s) correctamente', success: true };
        }
        this.showToast();
    },

    async updateAssignment(formData, userId) {
        const res = await apiFetch('/admin/storages/' + this.storageId + '/users/' + userId, {
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
            this.toast = { show: true, message: 'Permisos actualizados', success: true };
        } else {
            const err = await res.json().catch(() => ({}));
            this.toast = { show: true, message: err.error || err.message || 'Error al actualizar permisos', success: false };
        }
        this.showToast();
    },

    async removeAssignment(userId) {
        if (!confirm('¿Estás seguro de remover este usuario?')) return;
        this.removingStorageUserKey = this.storageId + '-' + userId;
        try {
            const res = await apiFetch('/admin/storages/' + this.storageId + '/users/' + userId, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                }
            });
            if (res.ok) {
                await this.loadUsers();
                this.toast = { show: true, message: 'Usuario removido del storage', success: true };
            } else {
                const err = await res.json().catch(() => ({}));
                this.toast = { show: true, message: err.error || err.message || 'Error al remover el usuario', success: false };
            }
            this.showToast();
        } finally {
            this.removingStorageUserKey = null;
        }
    }
}" x-init="loadUsers(); loadAllUsers()">
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-4">
            <a href="/admin/storages" class="text-brand-600 hover:text-brand-700 font-medium text-sm">← Volver a Storages</a>
            <h1 class="text-lg sm:text-2xl font-bold text-slate-800">Usuarios del Storage: {{ $storage->name }}</h1>
        </div>
        <button @click="showAssignModal = true" class="bg-brand-600 text-white px-3 sm:px-4 py-2 rounded-lg hover:bg-brand-700 text-sm font-medium transition-colors flex items-center gap-2">
            <i class="fas fa-user-plus text-xs"></i> Asignar Usuario
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
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <button @click="editingAssignment = assignment; showEditModal = true"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button @click="removeAssignment(assignment.user_id)"
                                        :disabled="removingStorageUserKey === storageId + '-' + assignment.user_id"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-red-100 bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <span x-show="removingStorageUserKey !== storageId + '-' + assignment.user_id"><i class="fas fa-user-minus"></i> Remover</span>
                                    <span x-show="removingStorageUserKey === storageId + '-' + assignment.user_id" class="inline-flex items-center gap-1">
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
        <div x-show="userStorages.length === 0" class="text-center py-8 text-gray-500">
            Este storage no tiene usuarios asignados.
        </div>
    </div>

    <div x-cloak x-show="showAssignModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showAssignModal = false; userSearchOpen = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Asignar Usuarios</h2>
                <form id="assign-form" @submit.prevent="assignSelectedUsers()">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Buscar y seleccionar usuarios</label>
                            <div class="relative">
                                <!-- Selected users as tags -->
                                <div x-show="selectedUsers.length > 0" class="flex flex-wrap gap-2 mb-2">
                                    <template x-for="user in selectedUsers" :key="user.id">
                                        <span class="inline-flex items-center gap-1 bg-brand-100 text-brand-700 text-sm px-3 py-1 rounded-full">
                                            <span x-text="'@' + user.username"></span>
                                            <button type="button" @click="removeSelectedUser(user.id)" class="hover:text-brand-600 focus:outline-none">
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
                                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none"
                                       autocomplete="off">
                                <!-- Dropdown -->
                                <div x-show="userSearchOpen && userSearchResults.length > 0"
                                     class="absolute z-10 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                    <template x-for="user in userSearchResults" :key="user.id">
                                        <div @click="addSelectedUser(user)" class="px-3 py-2 hover:bg-brand-50 cursor-pointer flex justify-between items-center border-b border-slate-100 last:border-0">
                                            <div class="flex items-center gap-2">
                                                <div class="w-8 h-8 bg-brand-100 rounded-full flex items-center justify-center">
                                                    <span class="text-xs font-medium text-brand-700" x-text="user.username?.charAt(0)?.toUpperCase()"></span>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-medium text-slate-900" x-text="'@' + user.username"></div>
                                                    <div class="text-xs text-slate-500" x-text="user.email"></div>
                                                </div>
                                            </div>
                                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                            </svg>
                                        </div>
                                    </template>
                                </div>
                                <!-- Empty state -->
                                <div x-show="userSearchOpen && userSearchQuery.length >= 1 && userSearchResults.length === 0"
                                     class="absolute z-10 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-lg px-3 py-4 text-center">
                                    <svg class="w-10 h-10 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    <p class="text-sm text-slate-500">Sin resultados para "<span x-text="userSearchQuery"></span>"</p>
                                </div>
                            </div>
                            <p class="text-xs text-slate-500 mt-1">Selecciona múltiples usuarios y asígnalos todos de una vez</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Permisos (para todos los seleccionados)</label>
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
                        <button type="submit" :disabled="selectedUsers.length === 0"
                                class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-brand-600 transition-colors">
                            Asignar <span x-show="selectedUsers.length > 0" x-text="'(' + selectedUsers.length + ')'"></span>
                        </button>
                        <button type="button" @click="showAssignModal = false; selectedUsers = []" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
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
                    <form @submit.prevent="updateAssignment(new FormData($event.target), editingAssignment.user_id)">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Usuario</label>
                                <span x-text="'@' + editingAssignment.user_username" class="text-sm text-slate-800 font-medium"></span>
                                <span x-text="editingAssignment.user_email" class="text-xs text-slate-400 ml-1"></span>
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
