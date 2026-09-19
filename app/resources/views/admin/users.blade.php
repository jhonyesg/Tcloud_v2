@extends('layouts.app')

@section('title', 'Gestionar Usuarios - Tcloud')

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="{
    users: [],
    showCreateModal: false,
    showEditModal: false,
    showDeleteModal: false,
    editingUser: null,
    deletingUser: null,
    deletingUserId: null,
    toast: null,
    searchQuery: '',
    sortBy: { column: 'id', direction: 'asc' },
    currentPage: 1,
    perPage: 25,
    get filteredAndSorted() {
        let list = this.users;
        if (this.searchQuery.trim()) {
            const q = this.searchQuery.toLowerCase().trim();
            list = list.filter(u =>
                (u.email || '').toLowerCase().includes(q) ||
                (u.username || '').toLowerCase().includes(q)
            );
        }
        const col = this.sortBy.column;
        const dir = this.sortBy.direction === 'asc' ? 1 : -1;
        return [...list].sort((a, b) => {
            let va = a[col] ?? '';
            let vb = b[col] ?? '';
            if (typeof va === 'boolean') { va = va ? 1 : 0; }
            if (typeof vb === 'boolean') { vb = vb ? 1 : 0; }
            if (typeof va === 'string') va = va.toLowerCase();
            if (typeof vb === 'string') vb = vb.toLowerCase();
            return va < vb ? -dir : va > vb ? dir : 0;
        });
    },
    get totalFiltered() { return this.filteredAndSorted.length; },
    get totalPages() { return Math.max(1, Math.ceil(this.filteredAndSorted.length / this.perPage)); },
    get paginatedUsers() {
        const start = (this.currentPage - 1) * this.perPage;
        return this.filteredAndSorted.slice(start, start + Number(this.perPage));
    },

    async loadUsers() {
        const res = await apiFetch('/admin/users', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            const data = await res.json();
            const userList = data.data || data;
            this.users = Array.isArray(userList) ? userList.filter(u => u && u.id) : [];
        }
    },
    
    async createUser(formData) {
        const res = await apiFetch('/admin/users', {
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
            this.showCreateModal = false;
            this.loadUsers();
        } else {
            const err = await res.json();
            this.showToast('Error: ' + JSON.stringify(err), 'error');
        }
    },

    async updateUser(formData, id) {
        const res = await apiFetch('/admin/users/' + id, {
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
            this.editingUser = null;
            this.loadUsers();
        } else {
            const err = await res.json();
            this.showToast('Error: ' + JSON.stringify(err), 'error');
        }
    },

    async deleteUser(id) {
        this.deletingUserId = id;
        try {
            const res = await apiFetch('/admin/users/' + id, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                }
            });
            if (res.ok) {
                this.showDeleteModal = false;
                this.deletingUser = null;
                await this.loadUsers();
                this.showToast('Usuario eliminado correctamente', 'success');
            } else {
                const err = await res.json().catch(() => ({}));
                this.showToast('Error al eliminar el usuario: ' + (err.error || err.message || 'Error desconocido'), 'error');
            }
        } finally {
            this.deletingUserId = null;
        }
    },
    
    formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    async toggleMediaEditor(user) {
        const res = await apiFetch('/admin/users/' + user.id + '/toggle-media-editor', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            }
        });
        if (res.ok) {
            const data = await res.json();
            user.media_editor_enabled = data.media_editor_enabled;
        }
    },

    showToast(msg, type) {
        this.toast = { msg, type };
        setTimeout(() => this.toast = null, 16000);
    },

    toggleSort(column) {
        if (this.sortBy.column === column) {
            this.sortBy.direction = this.sortBy.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortBy = { column, direction: 'asc' };
        }
    }
}" x-init="
    loadUsers();
    $watch('searchQuery', () => { currentPage = 1; });
    $watch('perPage',     () => { currentPage = 1; });
">
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Barra de controles: búsqueda + perPage + limpiar -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <div class="flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-48 relative">
                <input type="text" x-model="searchQuery" placeholder="Buscar usuario (email o username)..."
                       class="w-full border rounded-lg pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                </svg>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500 whitespace-nowrap">Por página:</span>
                <select x-model="perPage" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="250">250</option>
                    <option value="500">500</option>
                </select>
            </div>
            <button x-show="searchQuery"
                    @click="searchQuery = ''"
                    class="flex items-center gap-1 text-sm text-red-600 hover:text-red-800 border border-red-200 rounded-lg px-3 py-2 hover:bg-red-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Limpiar
            </button>
        </div>
        <div class="mt-2 text-xs text-gray-500" x-text="
            totalFiltered === users.length
                ? 'Mostrando ' + totalFiltered + (totalFiltered === 1 ? ' usuario' : ' usuarios')
                : 'Mostrando ' + totalFiltered + ' de ' + users.length + ' usuarios'
        "></div>
    </div>

    <div class="flex justify-between items-center mb-4 sm:mb-6">
        <h1 class="text-lg sm:text-2xl font-bold text-gray-800">Gestionar Usuarios</h1>
        <div class="flex items-center gap-2">
            <button onclick="startUsersTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
                <i class="fas fa-map-marked-alt"></i>
                <span class="hidden sm:inline">Guía</span>
            </button>
            <button @click="showCreateModal = true" class="bg-blue-600 text-white px-3 sm:px-4 py-2 rounded hover:bg-blue-700 text-sm">
                <span class="hidden sm:inline">Crear Usuario</span><span class="sm:hidden">Crear</span>
            </button>
        </div>
    </div>

    {{-- Vista móvil: tarjetas --}}
    <div class="sm:hidden space-y-3">
        <template x-for="user in (users || [])" :key="user.id">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800 text-sm truncate" x-text="user.username || user.email"></p>
                        <p class="text-xs text-slate-400 truncate" x-text="user.email" x-show="user.username"></p>
                    </div>
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full flex-shrink-0"
                          :class="user.role === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800'"
                          x-text="user.role"></span>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-500 mb-3">
                    <span><i class="fas fa-hdd text-slate-300 mr-1"></i><span x-text="user.personal_quota_bytes === 0 ? 'Ilimitado' : formatBytes(user.personal_quota_bytes)"></span></span>
                    <span><i class="fas fa-chart-pie text-slate-300 mr-1"></i><span x-text="formatBytes(user.personal_used_bytes)"></span></span>
                </div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs text-slate-500">Editor medios:</span>
                    <template x-if="user.role === 'admin'">
                        <span class="text-xs text-gray-400">Siempre activo</span>
                    </template>
                    <template x-if="user.role !== 'admin'">
                        <button @click="toggleMediaEditor(user)"
                            :class="user.media_editor_enabled ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-600'"
                            class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
                            x-text="user.media_editor_enabled ? 'Activo' : 'Inactivo'">
                        </button>
                    </template>
                </div>
                <div class="flex gap-2">
                    <a :href="'/admin/users/' + user.id + '/storages'"
                       class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-green-50 text-green-700 active:bg-green-100 text-xs font-medium border border-green-100">
                        <i class="fas fa-database text-xs"></i> Storages
                    </a>
                    <button @click="editingUser = user; showEditModal = true"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 active:bg-indigo-100 text-xs font-medium border border-indigo-100">
                        <i class="fas fa-edit text-xs"></i> Editar
                    </button>
                    <button @click="deletingUser = user; showDeleteModal = true"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-red-50 text-red-700 active:bg-red-100 text-xs font-medium border border-red-100">
                        <i class="fas fa-trash text-xs"></i> Eliminar
                    </button>
                </div>
            </div>
        </template>
        <div x-show="users.length > 0 && filteredAndSorted.length === 0"
             class="bg-white rounded-xl border border-slate-200 text-center py-8 text-gray-500 text-sm">
            No se encontraron usuarios con la búsqueda aplicada.
            <button @click="searchQuery = ''" class="ml-1 text-indigo-600">Limpiar filtros</button>
        </div>
        <div x-show="users.length === 0" class="bg-white rounded-xl border border-slate-200 text-center py-8 text-gray-500 text-sm">
            No hay usuarios registrados.
        </div>
    </div>

    {{-- Vista escritorio: tabla --}}
    <div class="hidden sm:block bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('id')">
                        <div class="flex items-center gap-1">ID <span class="text-gray-400" x-text="sortBy.column === 'id' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('email')">
                        <div class="flex items-center gap-1">Email <span class="text-gray-400" x-text="sortBy.column === 'email' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('username')">
                        <div class="flex items-center gap-1">Username <span class="text-gray-400" x-text="sortBy.column === 'username' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('role')">
                        <div class="flex items-center gap-1">Rol <span class="text-gray-400" x-text="sortBy.column === 'role' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('personal_quota_bytes')">
                        <div class="flex items-center gap-1">Quota <span class="text-gray-400" x-text="sortBy.column === 'personal_quota_bytes' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('personal_used_bytes')">
                        <div class="flex items-center gap-1">Usado <span class="text-gray-400" x-text="sortBy.column === 'personal_used_bytes' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('media_editor_enabled')">
                        <div class="flex items-center gap-1">Editor Medios <span class="text-gray-400" x-text="sortBy.column === 'media_editor_enabled' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span></div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
        <template x-for="user in paginatedUsers" :key="user.id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="user.id"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="user.email"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="user.username || '—'"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="user.role === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800'"
                                  x-text="user.role"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"
                            x-text="user.personal_quota_bytes === 0 ? 'Ilimitado' : formatBytes(user.personal_quota_bytes)"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"
                            x-text="formatBytes(user.personal_used_bytes)"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <template x-if="user.role === 'admin'">
                                <span class="text-xs text-gray-400">Siempre activo</span>
                            </template>
                            <template x-if="user.role !== 'admin'">
                                <button @click="toggleMediaEditor(user)"
                                    :class="user.media_editor_enabled ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-600'"
                                    class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
                                    x-text="user.media_editor_enabled ? 'Activo' : 'Inactivo'">
                                </button>
                            </template>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a :href="'/admin/users/' + user.id + '/storages'" class="text-green-600 hover:text-green-900 mr-3">Storages</a>
                            <button @click="editingUser = user; showEditModal = true" class="text-indigo-600 hover:text-indigo-900 mr-3">Editar</button>
                            <button @click="deletingUser = user; showDeleteModal = true" class="text-red-600 hover:text-red-900">Eliminar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        </div>{{-- /overflow-x-auto --}}
        <div x-show="users.length > 0 && filteredAndSorted.length === 0" class="text-center py-8 text-gray-500">
            No se encontraron usuarios con la búsqueda aplicada.
            <button @click="searchQuery = ''" class="ml-2 text-indigo-600 hover:underline text-sm">Limpiar filtros</button>
        </div>
        <div x-show="users.length === 0" class="text-center py-8 text-gray-500">
            No hay usuarios registrados.
        </div>
    </div>

    <div x-cloak x-show="showCreateModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showCreateModal = false">
            <h2 class="text-xl font-bold mb-4">Crear Usuario</h2>
            <form @submit.prevent="createUser(new FormData($event.target))">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" required class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Username <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input type="text" name="username" class="w-full border p-2 rounded" placeholder="ej. jsuarez">
                </div>
                <div class="mb-4" x-data="{ showCreatePw: false }">
                    <label class="block text-sm font-medium mb-1">Contraseña</label>
                    <div class="relative">
                        <input :type="showCreatePw ? 'text' : 'password'" name="password" required class="w-full border p-2 rounded pr-10">
                        <button type="button" @click="showCreatePw = !showCreatePw"
                                :aria-label="showCreatePw ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                :title="showCreatePw ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-700">
                            <i class="fa-solid" :class="showCreatePw ? 'fa-eye-slash' : 'fa-eye'"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Rol</label>
                    <select name="role" required class="w-full border p-2 rounded">
                        <option value="user">Usuario</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Quota (bytes, 0 = ilimitado)</label>
                    <input type="number" name="personal_quota_bytes" value="0" min="0" class="w-full border p-2 rounded">
                </div>
                <div class="mb-4 flex items-center gap-2">
                    <input type="checkbox" name="send_email" value="1" id="send_email" checked class="rounded text-blue-600 focus:ring-blue-500">
                    <label for="send_email" class="text-sm text-gray-700">Enviar correo de bienvenida al crear usuario</label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Crear</button>
                    <button type="button" @click="showCreateModal = false" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md max-h-[90vh] overflow-y-auto" @click.away="showEditModal = false">
            <h2 class="text-xl font-bold mb-4">Editar Usuario</h2>
            <template x-if="editingUser">
                <form @submit.prevent="updateUser(new FormData($event.target), editingUser.id)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Email</label>
                        <input type="email" name="email" :value="editingUser.email" required class="w-full border p-2 rounded">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Username <span class="text-gray-400 font-normal">(opcional)</span></label>
                        <input type="text" name="username" :value="editingUser.username || ''" class="w-full border p-2 rounded" placeholder="ej. jsuarez">
                    </div>
                    <div class="mb-4" x-data="{ showEditPw: false }">
                        <label class="block text-sm font-medium mb-1">Nueva Contraseña <span class="text-gray-400 font-normal">(dejar vacío para no cambiar)</span></label>
                        <div class="relative">
                            <input :type="showEditPw ? 'text' : 'password'" name="password" class="w-full border p-2 rounded pr-10">
                            <button type="button" @click="showEditPw = !showEditPw"
                                    :aria-label="showEditPw ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                    :title="showEditPw ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                    class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-700">
                                <i class="fa-solid" :class="showEditPw ? 'fa-eye-slash' : 'fa-eye'"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Rol</label>
                        <select name="role" class="w-full border p-2 rounded"
                                x-init="$el.value = editingUser.role">
                            <option value="user">Usuario</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Quota (bytes, 0 = ilimitado)</label>
                        <input type="number" name="personal_quota_bytes" :value="editingUser.personal_quota_bytes" min="0" class="w-full border p-2 rounded">
                    </div>
                    <div class="mb-4 border-t pt-4 mt-2">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Control de Sesiones</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium mb-1">Máx. sesiones simultáneas</label>
                                <input type="number" name="max_sessions" :value="editingUser.max_sessions ?? 6" min="0" class="w-full border p-2 rounded text-sm">
                                <p class="text-xs text-gray-400 mt-0.5">0 = sin límite</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Duración de sesión (min)</label>
                                <input type="number" name="session_lifetime_minutes" :value="editingUser.session_lifetime_minutes ?? ''" min="0" placeholder="vacío = global" class="w-full border p-2 rounded text-sm">
                                <p class="text-xs text-gray-400 mt-0.5">0 = sin expiración, vacío = global</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>
                        <button type="button" @click="showEditModal = false; editingUser = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    <div x-cloak x-show="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showDeleteModal = false">
            <h2 class="text-xl font-bold mb-4">Eliminar Usuario</h2>
            <template x-if="deletingUser">
                <div>
                    <p class="mb-4">¿Estás seguro de eliminar al usuario <strong x-text="deletingUser.email"></strong>?</p>
                    <p class="text-red-600 text-sm mb-4">Esta acción eliminará todos sus archivos, storages asignados y shares.</p>
                    <div class="flex gap-2">
                        <button @click="deleteUser(deletingUser.id)"
                                :disabled="deletingUserId === deletingUser.id"
                                class="bg-red-600 text-white px-4 py-2 rounded disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2">
                            <span x-show="deletingUserId !== deletingUser.id">Eliminar</span>
                            <span x-show="deletingUserId === deletingUser.id" class="inline-flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Eliminando...
                            </span>
                        </button>
                        <button @click="showDeleteModal = false; deletingUser = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startUsersTour() {
    function getAlpine() {
        var allData = document.querySelectorAll('[x-data]');
        for (var i = 0; i < allData.length; i++) {
            var el = allData[i];
            if (el._x_dataStack && el._x_dataStack[0]) {
                var data = el._x_dataStack[0];
                if (data.users !== undefined && data.showCreateModal !== undefined) return data;
            }
        }
        var first = document.querySelector('[x-data]');
        return first ? (first._x_dataStack ? first._x_dataStack[0] : null) : null;
    }

    var alpine = getAlpine();
    if (alpine) {
        alpine.showCreateModal = false;
        alpine.showEditModal = false;
        alpine.showDeleteModal = false;
    }

    function scrollTo(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        return el;
    }

    function getFirstRow() {
        return document.querySelector('table tbody tr:first-child') || null;
    }

    function getCrearUsuarioBtn() {
        var btns = document.querySelectorAll('button[onclick*="startUsersTour"]');
        if (btns.length > 0) {
            var prev = btns[0].previousElementSibling;
            if (prev && prev.tagName === 'BUTTON') return prev;
        }
        return document.querySelector('button.bg-blue-600') || null;
    }

    function getLink(text) {
        var row = getFirstRow();
        if (!row) return null;
        var links = row.querySelectorAll('td:last-child a');
        for (var i = 0; i < links.length; i++) {
            if (links[i].textContent.trim().toLowerCase().indexOf(text.toLowerCase()) !== -1) return links[i];
        }
        return null;
    }

    function getBtn(text) {
        var row = getFirstRow();
        if (!row) return null;
        var btns = row.querySelectorAll('td:last-child button');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].textContent.trim().toLowerCase().indexOf(text.toLowerCase()) !== -1) return btns[i];
        }
        return null;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Gestionar Usuarios',
                content: 'Aquí puedes crear, editar y eliminar usuarios de la plataforma. ' +
                         'También puedes asignar quotas de almacenamiento, controlar sesiones y habilitar el Editor de Medios por usuario.',
                icon: 'fa-users-cog',
                color: '#6366f1',
                selector: null,
                position: 'center'
            },
            {
                title: 'Crear Usuario',
                content: 'Haz clic en el botón azul <strong>Crear Usuario</strong> para abrir el modal. ' +
                         'Define email, username (opcional), contraseña, rol (usuario o administrador) y quota personalizada. ' +
                         'Si activas "Enviar correo de bienvenida", el usuario recibe un email con sus credenciales.',
                icon: 'fa-user-plus',
                color: '#2563eb',
                selector: function () { return getCrearUsuarioBtn(); },
                position: 'left',
                onShow: function () {
                    var btn = getCrearUsuarioBtn();
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Encabezados de la Tabla',
                content: 'Columnas: <strong>ID</strong>, <strong>Email</strong>, <strong>Username</strong>, <strong>Rol</strong> ' +
                         '(<span style="color:#7c3aed">admin</span> o <span style="color:#16a34a">user</span>), ' +
                         '<strong>Quota</strong>, <strong>Usado</strong>, <strong>Editor Medios</strong> y <strong>Acciones</strong>.',
                icon: 'fa-columns',
                color: '#3b82f6',
                selector: 'table thead',
                position: 'bottom',
                onShow: function () {
                    scrollTo('table thead');
                }
            },
            {
                title: 'Columna: ID',
                content: 'Identificador numérico único del usuario. Asignado automáticamente al crear.',
                icon: 'fa-hashtag',
                color: '#475569',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 0) return cells[0];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[0]);
                }
            },
            {
                title: 'Columna: Email',
                content: 'Correo electrónico del usuario. Se usa para login y recuperación de contraseña. ' +
                         'Es obligatorio y debe ser único en la plataforma.',
                icon: 'fa-envelope',
                color: '#1e293b',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 1) return cells[1];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[1]);
                }
            },
            {
                title: 'Columna: Username',
                content: 'Nombre de usuario opcional (alias). Aparece en listados y junto al email. ' +
                         'Si está vacío, se muestra <code>—</code> y se usa solo el email como identificador.',
                icon: 'fa-user',
                color: '#1e293b',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 2) return cells[2];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[2]);
                }
            },
            {
                title: 'Columna: Rol',
                content: 'Badge con el rol del usuario: ' +
                         '<span style="color:#7c3aed"><strong>admin</strong></span> (administrador, acceso completo) o ' +
                         '<span style="color:#16a34a"><strong>user</strong></span> (usuario estándar). ' +
                         'Los admins no pueden ser desactivados del Editor de Medios.',
                icon: 'fa-shield-alt',
                color: '#7c3aed',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 3) return cells[3];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[3]);
                }
            },
            {
                title: 'Columna: Quota',
                content: 'Espacio máximo de almacenamiento personal permitido para el usuario. ' +
                         '<strong>0 = ilimitado</strong>. Se muestra en formato legible (KB, MB, GB, TB).',
                icon: 'fa-hdd',
                color: '#3b82f6',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 4) return cells[4];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[4]);
                }
            },
            {
                title: 'Columna: Usado',
                content: 'Espacio actualmente consumido por los archivos personales del usuario. ' +
                         'Si supera la quota, no podrá subir más archivos hasta liberar espacio.',
                icon: 'fa-chart-pie',
                color: '#f59e0b',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 5) return cells[5];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[5]);
                }
            },
            {
                title: 'Columna: Editor Medios',
                content: 'Botón que activa/desactiva el acceso al Editor de Medios para este usuario. ' +
                         '<strong style="color:#4f46e5">Activo</strong> (índigo) o <strong style="color:#94a3b8">Inactivo</strong> (gris). ' +
                         'Los admins siempre tienen "Siempre activo" (no aparece el botón).',
                icon: 'fa-film',
                color: '#7c3aed',
                selector: function () {
                    var row = getFirstRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 6) return cells[6];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstRow();
                    if (row) scrollTo(row.querySelectorAll('td')[6]);
                }
            },
            {
                title: 'Acción: Storages',
                content: 'Link <strong style="color:#16a34a">Storages</strong> (verde): abre la página de asignación de storages ' +
                         'donde puedes ver a qué storages tiene acceso el usuario, con qué permisos y si puede compartir.',
                icon: 'fa-database',
                color: '#16a34a',
                selector: function () { return getLink('Storages'); },
                position: 'left',
                onShow: function () {
                    var l = getLink('Storages');
                    if (l) scrollTo(l);
                }
            },
            {
                title: 'Acción: Editar',
                content: 'Link <strong style="color:#4f46e5">Editar</strong> (índigo): modifica email, username, contraseña, ' +
                         'rol y quota del usuario. ' +
                         'También puedes configurar límites de sesiones simultáneas y duración.',
                icon: 'fa-edit',
                color: '#4f46e5',
                selector: function () { return getLink('Editar'); },
                position: 'left',
                onShow: function () {
                    var l = getLink('Editar');
                    if (l) scrollTo(l);
                }
            },
            {
                title: 'Acción: Eliminar',
                content: 'Link <strong style="color:#dc2626">Eliminar</strong> (rojo): elimina el usuario. ' +
                         '<span style="color:#dc2626"><strong>Precaución:</strong> esta acción borra permanentemente ' +
                         'todos sus archivos, storages asignados y enlaces compartidos. Te pedirá confirmación.</span>',
                icon: 'fa-trash-alt',
                color: '#dc2626',
                selector: function () { return getLink('Eliminar'); },
                position: 'left',
                onShow: function () {
                    var l = getLink('Eliminar');
                    if (l) scrollTo(l);
                }
            },
            {
                title: 'Guía Completada',
                content: 'Conoces el módulo de Usuarios: ' +
                         '<strong>crear</strong>, <strong>editar</strong>, <strong>asignar storages</strong>, ' +
                         '<strong>activar Editor de Medios</strong> y <strong>eliminar</strong>. ' +
                         'Repite esta guía cuando quieras con el botón morado.',
                icon: 'fa-check-circle',
                color: '#16a34a',
                selector: null,
                position: 'center'
            }
        ]
    });
}
</script>
@endsection
