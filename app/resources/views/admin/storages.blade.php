@extends('layouts.app')

@section('title', 'Gestionar Storages - Tcloud')

@section('content')
<div class="p-6" x-data="{
    storages: [],
    showCreateModal: false,
    showEditModal: false,
    showDeleteModal: false,
    editingStorage: null,
    deletingStorage: null,
    testingStorage: null,
    showUsersModal: false,
    usersModalStorage: null,
    usersModalList: [],
    allUsersList: [],
    userSearchQuery: '',
    userSearchSelected: null,
    searchQuery: '',
    filterType: '',
    filterStatus: '',
    sortBy: { column: 'id', direction: 'asc' },
    currentPage: 1,
    perPage: 25,
    newAssignmentPermissions: 'read',
    newAssignmentCanShare: false,
    showEditAssignment: false,
    editingAssignment: null,
    editAssignmentPermissions: 'read',
    editAssignmentCanShare: false,
    get allAssigned() { return this.allUsersList.length > 0 && this.usersModalList.length >= this.allUsersList.length; },
    get filteredUsers() {
        const assignedIds = this.usersModalList.map(a => a.user_id);
        const q = this.userSearchQuery.toLowerCase().trim();
        return this.allUsersList.filter(u =>
            !assignedIds.includes(u.id) &&
            (q === '' || u.username.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))
        );
    },
    get filteredAndSorted() {
        let list = this.storages;
        if (this.filterType) list = list.filter(s => s.type === this.filterType);
        if (this.filterStatus === 'active') list = list.filter(s => s.enabled);
        if (this.filterStatus === 'inactive') list = list.filter(s => !s.enabled);
        if (this.searchQuery.trim()) {
            const q = this.searchQuery.toLowerCase().trim();
            list = list.filter(s =>
                s.name.toLowerCase().includes(q) ||
                s.type.toLowerCase().includes(q) ||
                (s.enabled ? 'activo' : 'inactivo').includes(q)
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
    get paginatedStorages() {
        const start = (this.currentPage - 1) * this.perPage;
        return this.filteredAndSorted.slice(start, start + Number(this.perPage));
    },

    toast: { show: false, message: '', success: true },
    showToast() {
        setTimeout(() => { this.toast.show = false; }, 3500);
    },
    
    async loadStorages() {
        const res = await fetch('/admin/storages', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            this.storages = await res.json();
        } else {
            console.error('Error loading storages');
        }
    },
    
    async createStorage(formData) {
        const data = Object.fromEntries(formData);
        data.config = {};
        if (data.type === 's3') {
            data.config = {
                region: data.region || '',
                version: 'latest',
                credentials: {
                    key: data.s3_key || '',
                    secret: data.s3_secret || ''
                },
                bucket: data.bucket || ''
            };
        }
        delete data.region;
        delete data.s3_key;
        delete data.s3_secret;
        delete data.bucket;
        
        const res = await fetch('/admin/storages', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        if (res.ok) {
            this.showCreateModal = false;
            this.loadStorages();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },
    
    async updateStorage(formData, id) {
        const data = Object.fromEntries(formData);
        if (data.type === 'local') {
            data.config = {};
        }
        
        const res = await fetch('/admin/storages/' + id, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        if (res.ok) {
            this.showEditModal = false;
            this.editingStorage = null;
            this.loadStorages();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },
    
    async deleteStorage(id) {
        const res = await fetch('/admin/storages/' + id, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            }
        });
        if (res.ok) {
            this.showDeleteModal = false;
            this.deletingStorage = null;
            this.loadStorages();
        }
    },
    
    async testStorage(storage) {
        this.testingStorage = storage.id;
        
        const res = await fetch('/admin/storages/' + storage.id + '/test', {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        });
        const result = await res.json();
        this.toast = { show: true, message: storage.name + ': ' + result.message, success: result.success };
        this.showToast();
        this.testingStorage = null;
    },

    async openUsersModal(storage) {
        this.usersModalStorage = storage;
        this.showUsersModal = true;
        this.showEditAssignment = false;
        this.editingAssignment = null;
        this.resetUserSearch();
        await Promise.all([this.loadUsersModal(), this.loadAllUsers()]);
    },

    async loadUsersModal() {
        const res = await fetch('/admin/storages/' + this.usersModalStorage.id + '/users', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            this.usersModalList = await res.json();
        }
    },

    async loadAllUsers() {
        const res = await fetch('/admin/users/search?q=', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            this.allUsersList = await res.json();
        }
    },

    async toggleAssignAll() {
        if (this.allAssigned) {
            if (!confirm('¿Remover todos los usuarios de este storage?')) return;
            const res = await fetch('/admin/storages/' + this.usersModalStorage.id + '/users/all/remove', {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
            });
            if (res.ok) {
                await this.loadUsersModal();
                this.toast = { show: true, message: 'Todos los usuarios removidos', success: true };
                this.showToast();
            }
        } else {
            if (!confirm('¿Asignar todos los usuarios al storage con permisos de lectura?')) return;
            const res = await fetch('/admin/storages/' + this.usersModalStorage.id + '/users/assign-all', {
                method: 'POST',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
            });
            if (res.ok) {
                await this.loadUsersModal();
                this.toast = { show: true, message: 'Todos los usuarios asignados', success: true };
                this.showToast();
            }
        }
    },

    selectUser(user) {
        this.userSearchSelected = user;
        this.userSearchQuery = '';
    },

    resetUserSearch() {
        this.userSearchQuery = '';
        this.userSearchSelected = null;
        this.newAssignmentPermissions = 'read';
        this.newAssignmentCanShare = false;
    },

    async assignUserFromModal() {
        if (!this.userSearchSelected) return;
        const res = await fetch('/admin/storages/' + this.usersModalStorage.id + '/users', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                user_id: this.userSearchSelected.id,
                permissions: this.newAssignmentPermissions,
                can_create_shares: this.newAssignmentCanShare ? 1 : 0
            })
        });
        if (res.ok) {
            this.resetUserSearch();
            await this.loadUsersModal();
            this.toast = { show: true, message: 'Usuario asignado correctamente', success: true };
            this.showToast();
        } else {
            const err = await res.json();
            this.toast = { show: true, message: 'Error: ' + (err.error || JSON.stringify(err)), success: false };
            this.showToast();
        }
    },

    openEditAssignment(assignment) {
        this.editingAssignment = assignment;
        this.editAssignmentPermissions = assignment.permissions;
        this.editAssignmentCanShare = assignment.can_create_shares;
        this.showEditAssignment = true;
    },

    async updateAssignmentFromModal() {
        if (!this.editingAssignment) return;
        const res = await fetch('/admin/storages/' + this.usersModalStorage.id + '/users/' + this.editingAssignment.user_id, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                permissions: this.editAssignmentPermissions,
                can_create_shares: this.editAssignmentCanShare ? 1 : 0
            })
        });
        if (res.ok) {
            this.showEditAssignment = false;
            this.editingAssignment = null;
            await this.loadUsersModal();
            this.toast = { show: true, message: 'Permisos actualizados', success: true };
            this.showToast();
        }
    },

    async removeAssignmentFromModal(userId) {
        if (!confirm('¿Remover este usuario del storage?')) return;
        const res = await fetch('/admin/storages/' + this.usersModalStorage.id + '/users/' + userId, {
            method: 'DELETE',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (res.ok) {
            await this.loadUsersModal();
            this.toast = { show: true, message: 'Usuario removido', success: true };
            this.showToast();
        }
    },
    toggleSort(column) {
        if (this.sortBy.column === column) {
            this.sortBy.direction = this.sortBy.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortBy = { column, direction: 'asc' };
        }
    },
    resetFilters() {
        this.searchQuery = '';
        this.filterType = '';
        this.filterStatus = '';
        this.currentPage = 1;
    }
}" x-init="
    loadStorages();
    $watch('searchQuery', () => { currentPage = 1; });
    $watch('filterType',  () => { currentPage = 1; });
    $watch('filterStatus',() => { currentPage = 1; });
    $watch('perPage',     () => { currentPage = 1; });
">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Gestionar Storages</h1>
        <button @click="showCreateModal = true" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
            Crear Storage
        </button>
    </div>

    <!-- Barra de controles: búsqueda, filtros, paginación -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <div class="flex flex-wrap gap-3 items-center">
            <!-- Búsqueda -->
            <div class="flex-1 min-w-48 relative">
                <input type="text" x-model="searchQuery" placeholder="Buscar storage..."
                       class="w-full border rounded-lg pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                </svg>
            </div>
            <!-- Filtro tipo -->
            <select x-model="filterType" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">Todos los tipos</option>
                <option value="local">Local</option>
                <option value="s3">S3</option>
            </select>
            <!-- Filtro estado -->
            <select x-model="filterStatus" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">Todos los estados</option>
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
            </select>
            <!-- Por página -->
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500 whitespace-nowrap">Por página:</span>
                <select x-model="perPage" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </div>
            <!-- Limpiar filtros -->
            <button x-show="searchQuery || filterType || filterStatus"
                    @click="resetFilters()"
                    class="flex items-center gap-1 text-sm text-red-600 hover:text-red-800 border border-red-200 rounded-lg px-3 py-2 hover:bg-red-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Limpiar
            </button>
        </div>
        <!-- Contador de resultados -->
        <div class="mt-2 text-xs text-gray-500" x-text="
            totalFiltered === storages.length
                ? 'Mostrando ' + totalFiltered + (totalFiltered === 1 ? ' storage' : ' storages')
                : 'Mostrando ' + totalFiltered + ' de ' + storages.length + ' storages'
        "></div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('id')">
                        <div class="flex items-center gap-1">
                            ID
                            <span class="text-gray-400" x-text="sortBy.column === 'id' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span>
                        </div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('name')">
                        <div class="flex items-center gap-1">
                            Nombre
                            <span class="text-gray-400" x-text="sortBy.column === 'name' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span>
                        </div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('type')">
                        <div class="flex items-center gap-1">
                            Tipo
                            <span class="text-gray-400" x-text="sortBy.column === 'type' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span>
                        </div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('files_count')">
                        <div class="flex items-center gap-1">
                            Archivos
                            <span class="text-gray-400" x-text="sortBy.column === 'files_count' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span>
                        </div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer select-none hover:bg-gray-100 transition-colors" @click="toggleSort('enabled')">
                        <div class="flex items-center gap-1">
                            Estado
                            <span class="text-gray-400" x-text="sortBy.column === 'enabled' ? (sortBy.direction === 'asc' ? '↑' : '↓') : '↕'"></span>
                        </div>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <template x-for="storage in paginatedStorages" :key="storage.id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="storage.id"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900" x-text="storage.name"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="storage.type === 'local' ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800'"
                                  x-text="storage.type"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="storage.files_count"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="storage.enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  x-text="storage.enabled ? 'Activo' : 'Inactivo'"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <button @click="openUsersModal(storage)" class="text-green-600 hover:text-green-900 mr-3">Usuarios</button>
                            <button @click="testStorage(storage)" class="text-green-600 hover:text-green-900 mr-3">Probar</button>
                            <button @click="editingStorage = storage; showEditModal = true" class="text-indigo-600 hover:text-indigo-900 mr-3">Editar</button>
                            <button @click="deletingStorage = storage; showDeleteModal = true" class="text-red-600 hover:text-red-900">Eliminar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <!-- Sin resultados de filtro -->
        <div x-show="storages.length > 0 && filteredAndSorted.length === 0" class="text-center py-8 text-gray-500">
            No se encontraron storages con los filtros aplicados.
            <button @click="resetFilters()" class="ml-2 text-indigo-600 hover:underline text-sm">Limpiar filtros</button>
        </div>
        <!-- Sin storages registrados -->
        <div x-show="storages.length === 0" class="text-center py-8 text-gray-500">
            No hay storages configurados.
        </div>

        <!-- Controles de paginación -->
        <div x-show="totalPages > 1" class="flex items-center justify-between px-6 py-4 border-t bg-gray-50">
            <div class="text-sm text-gray-600">
                Página <span x-text="currentPage"></span> de <span x-text="totalPages"></span>
                &mdash; registros <span x-text="((currentPage - 1) * perPage) + 1"></span>–<span x-text="Math.min(currentPage * perPage, totalFiltered)"></span>
                de <span x-text="totalFiltered"></span>
            </div>
            <div class="flex items-center gap-2">
                <button @click="currentPage = Math.max(1, currentPage - 1)"
                        :disabled="currentPage === 1"
                        :class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-200'"
                        class="px-3 py-1.5 border rounded text-sm bg-white transition-colors">
                    ← Anterior
                </button>
                <template x-for="page in Array.from({ length: totalPages }, (_, i) => i + 1)" :key="page">
                    <button @click="currentPage = page"
                            :class="currentPage === page ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white hover:bg-gray-100'"
                            class="px-3 py-1.5 border rounded text-sm transition-colors"
                            x-text="page"></button>
                </template>
                <button @click="currentPage = Math.min(totalPages, currentPage + 1)"
                        :disabled="currentPage === totalPages"
                        :class="currentPage === totalPages ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-200'"
                        class="px-3 py-1.5 border rounded text-sm bg-white transition-colors">
                    Siguiente →
                </button>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showCreateModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showCreateModal = false">
            <h2 class="text-xl font-bold mb-4">Crear Storage</h2>
            <form @submit.prevent="createStorage(new FormData($event.target))">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Nombre</label>
                    <input type="text" name="name" required class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Tipo</label>
                    <select name="type" required class="w-full border p-2 rounded" onchange="document.getElementById('s3_config').style.display = this.value === 's3' ? 'block' : 'none'">
                        <option value="local">Local</option>
                        <option value="s3">S3</option>
                    </select>
                </div>
                <div class="mb-4" id="s3_config" style="display: none;">
                    <label class="block text-sm font-medium mb-1">S3 Region</label>
                    <input type="text" name="region" placeholder="us-east-1" class="w-full border p-2 rounded mb-2">
                    <label class="block text-sm font-medium mb-1">S3 Key</label>
                    <input type="text" name="s3_key" placeholder="AKIA..." class="w-full border p-2 rounded mb-2">
                    <label class="block text-sm font-medium mb-1">S3 Secret</label>
                    <input type="password" name="s3_secret" class="w-full border p-2 rounded mb-2">
                    <label class="block text-sm font-medium mb-1">Bucket</label>
                    <input type="text" name="bucket" class="w-full border p-2 rounded">
                </div>
                <div class="mb-4" id="local_config">
                    <label class="block text-sm font-medium mb-1">Base Path</label>
                    <input type="text" name="base_path" placeholder="/data/storage" class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="enabled" value="1" checked class="mr-2">
                        Habilitado
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Crear</button>
                    <button type="button" @click="showCreateModal = false" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showEditModal = false">
            <h2 class="text-xl font-bold mb-4">Editar Storage</h2>
            <template x-if="editingStorage">
                <form @submit.prevent="updateStorage(new FormData($event.target), editingStorage.id)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Nombre</label>
                        <input type="text" name="name" :value="editingStorage.name" required class="w-full border p-2 rounded">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Tipo</label>
                        <span x-text="editingStorage.type" class="text-gray-600"></span>
                    </div>
                    <template x-if="editingStorage.type === 'local'">
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Base Path</label>
                            <input type="text" name="base_path" :value="editingStorage.base_path" class="w-full border p-2 rounded">
                        </div>
                    </template>
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="enabled" value="1" :checked="editingStorage.enabled" class="mr-2">
                            Habilitado
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>
                        <button type="button" @click="showEditModal = false; editingStorage = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    <div x-cloak x-show="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showDeleteModal = false">
            <h2 class="text-xl font-bold mb-4">Eliminar Storage</h2>
            <template x-if="deletingStorage">
                <div>
                    <p class="mb-4">¿Estás seguro de eliminar el storage <strong x-text="deletingStorage.name"></strong>?</p>
                    <p class="text-red-600 text-sm mb-4">Los archivos no serán eliminados, pero quedarán huérfanos.</p>
                    <div class="flex gap-2">
                        <button @click="deleteStorage(deletingStorage.id)" class="bg-red-600 text-white px-4 py-2 rounded">Eliminar</button>
                        <button @click="showDeleteModal = false; deletingStorage = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div x-cloak x-show="showUsersModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto" @click.away="showUsersModal = false">

            <!-- Header -->
            <div class="flex justify-between items-center mb-5">
                <h2 class="text-xl font-bold text-gray-800">Usuarios del Storage: <span class="text-indigo-600" x-text="usersModalStorage?.name"></span></h2>
                <button @click="showUsersModal = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Chips de usuarios asignados -->
            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-600">Usuarios asignados</span>
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                        <input type="checkbox" :checked="allAssigned" @change="toggleAssignAll()" class="rounded">
                        Todas las personas
                    </label>
                </div>

                <div class="min-h-[56px] max-h-40 overflow-y-auto flex flex-wrap gap-2 p-3 bg-gray-50 rounded-lg border">
                    <template x-for="a in usersModalList" :key="a.user_id">
                        <div class="flex items-center gap-1 pl-2 pr-1 py-1 rounded-full text-xs font-medium cursor-pointer border"
                             :class="{
                                 'full':   'bg-green-100 text-green-800 border-green-200',
                                 'write':  'bg-blue-100 text-blue-800 border-blue-200',
                                 'upload': 'bg-yellow-100 text-yellow-800 border-yellow-200',
                                 'read':   'bg-gray-100 text-gray-700 border-gray-200'
                             }[a.permissions]"
                             @click="openEditAssignment(a)">
                            <span x-text="'@' + a.user_username"></span>
                            <span class="ml-1 px-1 rounded text-xs opacity-60" x-text="a.permissions"></span>
                            <button @click.stop="removeAssignmentFromModal(a.user_id)"
                                    class="ml-1 w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 transition-colors text-current font-bold leading-none">×</button>
                        </div>
                    </template>
                    <span x-show="usersModalList.length === 0" class="text-gray-400 text-sm self-center">Sin usuarios asignados</span>
                </div>
            </div>

            <!-- Edición inline de permisos -->
            <template x-if="showEditAssignment && editingAssignment">
                <div class="border rounded-lg p-4 mb-4 bg-indigo-50 border-indigo-200">
                    <h3 class="text-sm font-bold mb-3 text-indigo-700">Editar permisos: <span x-text="'@' + editingAssignment.user_username"></span></h3>
                    <div class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium mb-1">Permisos</label>
                            <select x-model="editAssignmentPermissions" class="border p-1.5 rounded text-sm">
                                <option value="read">Lectura</option>
                                <option value="write">Escritura</option>
                                <option value="upload">Subida</option>
                                <option value="full">Completo</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center text-sm">
                                <input type="checkbox" x-model="editAssignmentCanShare" class="mr-1.5">
                                Puede compartir
                            </label>
                        </div>
                        <button @click="updateAssignmentFromModal()" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700">Guardar</button>
                        <button @click="showEditAssignment = false; editingAssignment = null" class="bg-gray-300 px-3 py-1.5 rounded text-sm">Cancelar</button>
                    </div>
                </div>
            </template>

            <!-- Asignar nuevo usuario -->
            <div class="border rounded-lg p-4">
                <h3 class="text-sm font-bold mb-3 text-gray-700">Asignar usuario</h3>

                <!-- Campo de filtro -->
                <input type="text" x-model="userSearchQuery"
                       placeholder="Filtrar usuarios..."
                       class="w-full border p-2 rounded text-sm mb-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">

                <!-- Lista de usuarios disponibles -->
                <div class="border rounded-lg overflow-hidden mb-3 max-h-44 overflow-y-auto">
                    <template x-if="filteredUsers.length > 0">
                        <div>
                            <template x-for="user in filteredUsers" :key="user.id">
                                <div @click="selectUser(user)"
                                     class="px-3 py-2 flex items-center justify-between cursor-pointer border-b last:border-b-0 transition-colors"
                                     :class="userSearchSelected && userSearchSelected.id === user.id
                                         ? 'bg-indigo-100 border-indigo-200'
                                         : 'hover:bg-gray-50'">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600"
                                             x-text="user.username.charAt(0).toUpperCase()"></div>
                                        <span class="text-sm font-medium text-gray-800" x-text="'@' + user.username"></span>
                                    </div>
                                    <span class="text-xs text-gray-400" x-text="user.email"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div x-show="filteredUsers.length === 0" class="px-3 py-4 text-sm text-gray-400 text-center">
                        <span x-text="allUsersList.length === 0 ? 'Cargando usuarios...' : 'No hay más usuarios para asignar'"></span>
                    </div>
                </div>

                <!-- Usuario seleccionado + controles -->
                <div class="flex flex-wrap gap-3 items-center">
                    <div class="flex-1 min-w-0">
                        <span x-show="userSearchSelected"
                              class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-100 text-indigo-800 rounded-full text-xs font-medium">
                            <span x-text="userSearchSelected ? '@' + userSearchSelected.username : ''"></span>
                            <button @click="userSearchSelected = null" class="ml-1 hover:text-indigo-600 font-bold">×</button>
                        </span>
                        <span x-show="!userSearchSelected" class="text-xs text-gray-400">Selecciona un usuario de la lista</span>
                    </div>
                    <div>
                        <select x-model="newAssignmentPermissions" class="border p-1.5 rounded text-sm">
                            <option value="read">Lectura</option>
                            <option value="write">Escritura</option>
                            <option value="upload">Subida</option>
                            <option value="full">Completo</option>
                        </select>
                    </div>
                    <label class="flex items-center text-sm text-gray-600">
                        <input type="checkbox" x-model="newAssignmentCanShare" class="mr-1.5">
                        Puede compartir
                    </label>
                    <button @click="assignUserFromModal()"
                            :disabled="!userSearchSelected"
                            :class="userSearchSelected ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-gray-300 cursor-not-allowed'"
                            class="text-white px-4 py-1.5 rounded text-sm font-medium transition-colors">Asignar</button>
                </div>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
         x-transition:leave="transition ease-in duration-200"
         class="fixed bottom-4 right-4 z-50 max-w-sm"
         :class="toast.success ? 'bg-green-500' : 'bg-red-500'">
        <div class="flex items-center px-4 py-3 text-white">
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
