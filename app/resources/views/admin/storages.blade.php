@extends('layouts.app')

@section('title', 'Gestionar Storages - Tcloud')

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="{
    storages: [],
    showCreateModal: false,
    showEditModal: false,
    showDeleteModal: false,
    editingStorage: null,
    newStorageType: 'local',
    deletingStorage: null,
    deletingStorageId: null,
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
    removingUserAssignmentKey: null,
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
        const res = await apiFetch('/admin/storages', {
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
        
        const res = await apiFetch('/admin/storages', {
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
            this.newStorageType = 'local';
            await this.loadStorages();
            this.toast = { show: true, message: 'Storage creado correctamente', success: true };
            this.showToast();
        } else {
            const err = await res.json().catch(() => ({}));
            this.toast = { show: true, message: err.error || err.message || 'Error al crear el storage', success: false };
            this.showToast();
        }
    },

    async updateStorage(formData, id) {
        const data = Object.fromEntries(formData);
        if (data.type === 'local') {
            data.config = {};
        }
        
        const res = await apiFetch('/admin/storages/' + id, {
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
            await this.loadStorages();
            this.toast = { show: true, message: 'Storage actualizado correctamente', success: true };
            this.showToast();
        } else {
            const err = await res.json().catch(() => ({}));
            this.toast = { show: true, message: err.error || err.message || 'Error al actualizar el storage', success: false };
            this.showToast();
        }
    },
    
    async deleteStorage(id) {
        this.deletingStorageId = id;
        try {
            const res = await apiFetch('/admin/storages/' + id, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                }
            });
            if (res.ok) {
                this.showDeleteModal = false;
                this.deletingStorage = null;
                await this.loadStorages();
                this.toast = { show: true, message: 'Storage eliminado correctamente', success: true };
            } else {
                const err = await res.json().catch(() => ({}));
                this.toast = { show: true, message: err.error || 'Error al eliminar el storage', success: false };
            }
            this.showToast();
        } finally {
            this.deletingStorageId = null;
        }
    },
    
    async testStorage(storage) {
        this.testingStorage = storage.id;

        const res = await apiFetch('/admin/storages/' + storage.id + '/test', {
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

    async reconcileStorage(storage) {
        this.toast = { show: true, message: 'Despachando reconciliación para ' + storage.name + '...', success: true };
        this.showToast();

        const res = await apiFetch('/admin/storages/' + storage.id + '/reconcile', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
            }
        });
        const result = await res.json().catch(() => ({ success: false, message: 'Sin respuesta del servidor' }));

        if (!res.ok) {
            this.toast = { show: true, message: result.message || 'Error al despachar', success: false };
        } else {
            this.toast = { show: true, message: result.message || 'Reconciliación disparada', success: true };
        }
        this.showToast();
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
        const res = await apiFetch('/admin/storages/' + this.usersModalStorage.id + '/users', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            this.usersModalList = await res.json();
        }
    },

    async loadAllUsers() {
        const res = await apiFetch('/admin/users/search?q=', {
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
            const res = await apiFetch('/admin/storages/' + this.usersModalStorage.id + '/users/all/remove', {
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
            const res = await apiFetch('/admin/storages/' + this.usersModalStorage.id + '/users/assign-all', {
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
        const res = await apiFetch('/admin/storages/' + this.usersModalStorage.id + '/users', {
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
        const res = await apiFetch('/admin/storages/' + this.usersModalStorage.id + '/users/' + this.editingAssignment.user_id, {
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
        const key = this.usersModalStorage.id + '-' + userId;
        this.removingUserAssignmentKey = key;
        try {
            const res = await apiFetch('/admin/storages/' + this.usersModalStorage.id + '/users/' + userId, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
            });
            if (res.ok) {
                await this.loadUsersModal();
                this.toast = { show: true, message: 'Usuario removido', success: true };
            } else {
                const err = await res.json().catch(() => ({}));
                this.toast = { show: true, message: err.error || 'Error al desvincular el usuario', success: false };
                await this.loadUsersModal();
            }
            this.showToast();
        } finally {
            this.removingUserAssignmentKey = null;
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
    <div class="flex justify-between items-center mb-4 sm:mb-6">
        <h1 class="text-lg sm:text-2xl font-bold text-gray-800">Gestionar Storages</h1>
        <div class="flex items-center gap-2">
            <button onclick="startStoragesTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
                <i class="fas fa-map-marked-alt"></i>
                <span class="hidden sm:inline">Guía</span>
            </button>
            <button @click="showCreateModal = true" class="bg-green-600 text-white px-3 sm:px-4 py-2 rounded hover:bg-green-700 text-sm">
                <span class="hidden sm:inline">Crear Storage</span><span class="sm:hidden">Crear</span>
            </button>
        </div>
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
                    <option value="100">100</option>
                    <option value="250">250</option>
                    <option value="500">500</option>
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

    {{-- Vista móvil: tarjetas --}}
    <div class="sm:hidden space-y-3">
        <template x-for="storage in paginatedStorages" :key="storage.id">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800 text-sm truncate" x-text="storage.name"></p>
                        <p class="text-xs text-slate-400" x-text="'ID: ' + storage.id + ' · ' + storage.files_count + ' archivos'"></p>
                    </div>
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full"
                              :class="storage.type === 'local' ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800'"
                              x-text="storage.type"></span>
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full"
                              :class="(storage.kind || 'local') === 'external' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                              x-text="(storage.kind || 'local') === 'external' ? 'Red' : 'Disco'"></span>
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full"
                              :class="storage.enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                              x-text="storage.enabled ? 'Activo' : 'Inactivo'"></span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <button @click="openUsersModal(storage)"
                            class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-green-50 text-green-700 active:bg-green-100 text-xs font-medium border border-green-100">
                        <i class="fas fa-users text-xs"></i> Usuarios
                    </button>
                    <button @click="testStorage(storage)" :disabled="testingStorage === storage.id"
                            class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-slate-50 text-slate-700 active:bg-slate-100 text-xs font-medium border border-slate-200 disabled:opacity-50">
                        <i class="fas fa-plug text-xs"></i> Probar
                    </button>
                    <button @click="editingStorage = storage; showEditModal = true"
                            class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 active:bg-indigo-100 text-xs font-medium border border-indigo-100">
                        <i class="fas fa-edit text-xs"></i> Editar
                    </button>
                    <button @click="deletingStorage = storage; showDeleteModal = true"
                            class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-red-50 text-red-700 active:bg-red-100 text-xs font-medium border border-red-100">
                        <i class="fas fa-trash text-xs"></i> Eliminar
                    </button>
                </div>
            </div>
        </template>
        <div x-show="storages.length > 0 && filteredAndSorted.length === 0"
             class="bg-white rounded-xl border border-slate-200 text-center py-8 text-gray-500 text-sm">
            Sin resultados. <button @click="resetFilters()" class="ml-1 text-indigo-600">Limpiar filtros</button>
        </div>
        <div x-show="storages.length === 0" class="bg-white rounded-xl border border-slate-200 text-center py-8 text-gray-500 text-sm">
            No hay storages configurados.
        </div>
    </div>

    {{-- Vista escritorio: tabla --}}
    <div class="hidden sm:block bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
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
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="(storage.kind || 'local') === 'external' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                                  x-text="(storage.kind || 'local') === 'external' ? 'Red' : 'Disco'"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="storage.files_count"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="storage.enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  x-text="storage.enabled ? 'Activo' : 'Inactivo'"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <button @click="openUsersModal(storage)"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-green-100 bg-green-50 text-green-700 hover:bg-green-100 transition-colors">
                                    <i class="fas fa-users"></i> Usuarios
                                </button>
                                <button @click="testStorage(storage)"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition-colors">
                                    <i class="fas fa-plug"></i> Probar
                                </button>
                                <button @click="reconcileStorage(storage)"
                                        x-show="(storage.kind || 'local') === 'external'"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors">
                                    <i class="fas fa-rotate"></i> Re-verificar
                                </button>
                                <button @click="editingStorage = storage; showEditModal = true"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button @click="deletingStorage = storage; showDeleteModal = true"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-red-100 bg-red-50 text-red-700 hover:bg-red-100 transition-colors">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
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
        </div>{{-- /overflow-x-auto --}}
    </div>{{-- /hidden sm:block --}}

    <div x-cloak x-show="showCreateModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showCreateModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Crear Storage</h2>
                <form @submit.prevent="createStorage(new FormData($event.target))">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Nombre</label>
                            <input type="text" name="name" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Tipo</label>
                            <select name="type" x-model="newStorageType" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                                <option value="local">Local</option>
                                <option value="s3">S3</option>
                            </select>
                        </div>
                        <div x-show="newStorageType === 's3'" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">S3 Region</label>
                                <input type="text" name="region" placeholder="us-east-1" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">S3 Key</label>
                                <input type="text" name="s3_key" placeholder="AKIA..." class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">S3 Secret</label>
                                <input type="password" name="s3_secret" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Bucket</label>
                                <input type="text" name="bucket" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none font-mono">
                            </div>
                        </div>
                        <div x-show="newStorageType === 'local'">
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Base Path</label>
                            <input type="text" name="base_path" placeholder="/data/storage" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none font-mono">
                        </div>
                        <div>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="enabled" value="1" checked class="rounded text-brand-600 focus:ring-brand-500">
                                Habilitado
                            </label>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="submit" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Crear</button>
                        <button type="button" @click="showCreateModal = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showEditModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Editar Storage</h2>
                <template x-if="editingStorage">
                    <form @submit.prevent="updateStorage(new FormData($event.target), editingStorage.id)">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Nombre</label>
                                <input type="text" name="name" :value="editingStorage.name" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Tipo</label>
                                <span x-text="editingStorage.type" class="text-sm text-slate-600 font-mono"></span>
                            </div>
                            <template x-if="editingStorage.type === 'local'">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Base Path</label>
                                    <input type="text" name="base_path" :value="editingStorage.base_path" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none font-mono">
                                </div>
                            </template>
                            <div>
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="enabled" value="1" :checked="editingStorage.enabled" class="rounded text-brand-600 focus:ring-brand-500">
                                    Habilitado
                                </label>
                            </div>
                        </div>
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Guardar</button>
                            <button type="button" @click="showEditModal = false; editingStorage = null" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showDeleteModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showDeleteModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Eliminar Storage</h2>
                <template x-if="deletingStorage">
                    <div>
                        <p class="mb-4 text-sm text-slate-600">¿Estás seguro de eliminar el storage <strong class="text-slate-800" x-text="deletingStorage.name"></strong>?</p>
                        <p class="text-red-600 text-sm font-medium mb-1">Esta acción es irreversible. Se eliminarán permanentemente:</p>
                        <ul class="text-red-600 text-sm mb-5 list-disc list-inside">
                            <li x-text="(deletingStorage.files_count || 0) + ' archivo(s) asociado(s)'"></li>
                            <li>Todas las asignaciones de usuarios de este storage</li>
                        </ul>
                        <div class="flex gap-3">
                            <button @click="deleteStorage(deletingStorage.id)"
                                    :disabled="deletingStorageId === deletingStorage.id"
                                    class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2 transition-colors">
                                <span x-show="deletingStorageId !== deletingStorage.id">Eliminar</span>
                                <span x-show="deletingStorageId === deletingStorage.id" class="inline-flex items-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Eliminando...
                                </span>
                            </button>
                            <button @click="showDeleteModal = false; deletingStorage = null" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showUsersModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto shadow-2xl" @click.away="showUsersModal = false">

            <!-- Header -->
            <div class="flex justify-between items-center mb-5 p-6 pb-0">
                <h2 class="text-lg font-bold text-slate-800">Usuarios del Storage: <span class="text-brand-600" x-text="usersModalStorage?.name"></span></h2>
                <button @click="showUsersModal = false" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="p-6 pt-2">
            <!-- Chips de usuarios asignados -->
            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">Usuarios asignados</span>
                    <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none">
                        <input type="checkbox" :checked="allAssigned" @change="toggleAssignAll()" class="rounded text-brand-600 focus:ring-brand-500">
                        Todas las personas
                    </label>
                </div>

                <div class="min-h-[56px] max-h-40 overflow-y-auto flex flex-wrap gap-2 p-3 bg-slate-50 rounded-lg border border-slate-200">
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
                                    :disabled="removingUserAssignmentKey === usersModalStorage.id + '-' + a.user_id"
                                    class="ml-1 w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 transition-colors text-current font-bold leading-none disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="removingUserAssignmentKey !== usersModalStorage.id + '-' + a.user_id">×</span>
                                <svg x-show="removingUserAssignmentKey === usersModalStorage.id + '-' + a.user_id" class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </button>
                        </div>
                    </template>
                    <span x-show="usersModalList.length === 0" class="text-gray-400 text-sm self-center">Sin usuarios asignados</span>
                </div>
            </div>

            <!-- Edición inline de permisos -->
            <template x-if="showEditAssignment && editingAssignment">
                <div class="border rounded-lg p-4 mb-4 bg-brand-50 border-brand-200">
                    <h3 class="text-xs font-bold mb-3 text-brand-700 uppercase tracking-wide">Editar permisos: <span x-text="'@' + editingAssignment.user_username"></span></h3>
                    <div class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1 uppercase tracking-wide">Permisos</label>
                            <select x-model="editAssignmentPermissions" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                                <option value="read">Lectura</option>
                                <option value="write">Escritura</option>
                                <option value="upload">Subida</option>
                                <option value="full">Completo</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" x-model="editAssignmentCanShare" class="rounded text-brand-600 focus:ring-brand-500">
                                Puede compartir
                            </label>
                        </div>
                        <button @click="updateAssignmentFromModal()" class="py-1.5 px-4 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium transition-colors">Guardar</button>
                        <button @click="showEditAssignment = false; editingAssignment = null" class="py-1.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition-colors">Cancelar</button>
                    </div>
                </div>
            </template>

            <!-- Asignar nuevo usuario -->
            <div class="border border-slate-200 rounded-lg p-4">
                <h3 class="text-xs font-bold mb-3 text-slate-600 uppercase tracking-wide">Asignar usuario</h3>

                <!-- Campo de filtro -->
                <input type="text" x-model="userSearchQuery"
                       placeholder="Filtrar usuarios..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">

                <!-- Lista de usuarios disponibles -->
                <div class="border border-slate-200 rounded-lg overflow-hidden mb-3 max-h-44 overflow-y-auto">
                    <template x-if="filteredUsers.length > 0">
                        <div>
                            <template x-for="user in filteredUsers" :key="user.id">
                                <div @click="selectUser(user)"
                                     class="px-3 py-2 flex items-center justify-between cursor-pointer border-b border-slate-100 last:border-b-0 transition-colors"
                                     :class="userSearchSelected && userSearchSelected.id === user.id
                                         ? 'bg-brand-50 border-brand-200'
                                         : 'hover:bg-slate-50'">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center text-xs font-bold text-brand-700"
                                             x-text="user.username.charAt(0).toUpperCase()"></div>
                                        <span class="text-sm font-medium text-slate-800" x-text="'@' + user.username"></span>
                                    </div>
                                    <span class="text-xs text-slate-400" x-text="user.email"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div x-show="filteredUsers.length === 0" class="px-3 py-4 text-sm text-slate-400 text-center">
                        <span x-text="allUsersList.length === 0 ? 'Cargando usuarios...' : 'No hay más usuarios para asignar'"></span>
                    </div>
                </div>

                <!-- Usuario seleccionado + controles -->
                <div class="flex flex-wrap gap-3 items-center">
                    <div class="flex-1 min-w-0">
                        <span x-show="userSearchSelected"
                              class="inline-flex items-center gap-1 px-2 py-1 bg-brand-100 text-brand-700 rounded-full text-xs font-medium">
                            <span x-text="userSearchSelected ? '@' + userSearchSelected.username : ''"></span>
                            <button @click="userSearchSelected = null" class="ml-1 hover:text-brand-600 font-bold">×</button>
                        </span>
                        <span x-show="!userSearchSelected" class="text-xs text-slate-400">Selecciona un usuario de la lista</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1 uppercase tracking-wide">Permisos</label>
                        <select x-model="newAssignmentPermissions" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            <option value="read">Lectura</option>
                            <option value="write">Escritura</option>
                            <option value="upload">Subida</option>
                            <option value="full">Completo</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" x-model="newAssignmentCanShare" class="rounded text-brand-600 focus:ring-brand-500">
                        Puede compartir
                    </label>
                    <button @click="assignUserFromModal()"
                            :disabled="!userSearchSelected"
                            class="py-2 px-4 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-brand-600 transition-colors">Asignar</button>
                </div>
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

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startStoragesTour() {
    // Obtener Alpine dinámicamente
    function getAlpine() {
        var allData = document.querySelectorAll('[x-data]');
        for (var i = 0; i < allData.length; i++) {
            var el = allData[i];
            if (el._x_dataStack && el._x_dataStack[0]) {
                var data = el._x_dataStack[0];
                if (data.storages !== undefined) return data;
            }
        }
        var first = document.querySelector('[x-data]');
        return first ? (first._x_dataStack ? first._x_dataStack[0] : null) : null;
    }

    // Cerrar modales abiertos
    var alpine = getAlpine();
    if (alpine) {
        alpine.showCreateModal = false;
        alpine.showEditModal = false;
        alpine.showDeleteModal = false;
        alpine.showUsersModal = false;
        alpine.showEditAssignment = false;
        alpine.editingAssignment = null;
    }

    // Helper: scroll suave a un elemento
    function scrollTo(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        return el;
    }

    // Helper: obtener la primera fila de storage si existe
    function getFirstStorageRow() {
        return document.querySelector('table tbody tr:first-child') ||
               document.querySelector('.sm\\:hidden .bg-white.rounded-xl') ||
               null;
    }

    // Helper: obtener el primer botón de acción de un tipo en la primera fila
    function getActionButton(text) {
        var row = getFirstStorageRow();
        if (!row) return null;
        var btns = row.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].textContent.trim().toLowerCase().indexOf(text.toLowerCase()) !== -1) return btns[i];
        }
        return null;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Gestionar Storages',
                content: 'Los storages son los backends de almacenamiento donde residen los archivos de los usuarios. ' +
                         'Soportan dos tipos: <strong style="color:#2563eb">Local</strong> (disco del servidor) y <strong style="color:#ea580c">S3</strong> (AWS S3 compatible). ' +
                         'Aquí puedes crearlos, editar su configuración, asignar usuarios y verificar conectividad.',
                icon: 'fa-hdd',
                color: '#6366f1',
                selector: null,
                position: 'center'
            },
            {
                title: 'Barra de Filtros',
                content: 'Cuatro controles para acotar la lista: ' +
                         '<ul style="margin:6px 0 0 16px;padding:0;">' +
                         '<li><strong>Búsqueda</strong> por nombre, tipo o estado</li>' +
                         '<li>Filtro de tipo (<strong>Local/S3</strong>)</li>' +
                         '<li>Filtro de estado (<strong>Activo/Inactivo</strong>)</li>' +
                         '<li>Resultados por página (10, 25, 50, 100, 250, 500)</li>' +
                         '</ul>',
                icon: 'fa-filter',
                color: '#3b82f6',
                selector: '.bg-white.rounded-lg.shadow.p-4',
                position: 'bottom',
                onShow: function () {
                    scrollTo('.bg-white.rounded-lg.shadow.p-4');
                }
            },
            {
                title: 'Encabezados de la Tabla',
                content: 'Las columnas disponibles para ordenar (clic en cualquier encabezado): ' +
                         '<strong>ID</strong>, <strong>Nombre</strong>, <strong>Tipo</strong>, <strong>Archivos</strong>, <strong>Estado</strong> y <strong>Acciones</strong>. ' +
                         'La flecha junto al nombre indica la columna activa (↑ ascendente, ↓ descendente).',
                icon: 'fa-columns',
                color: '#2563eb',
                selector: 'table thead',
                position: 'bottom',
                onShow: function () {
                    scrollTo('table thead');
                }
            },
            {
                title: 'Columna: ID',
                content: 'Identificador numérico único del storage en la base de datos. ' +
                         'Se asigna automáticamente al crear. Útil para referencias técnicas o scripts.',
                icon: 'fa-hashtag',
                color: '#475569',
                selector: function () {
                    var row = getFirstStorageRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 0) return cells[0];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstStorageRow();
                    if (row) scrollTo(row.querySelectorAll('td')[0]);
                }
            },
            {
                title: 'Columna: Nombre',
                content: 'Nombre legible del storage. ' +
                         'Se muestra a los usuarios en sus listados de storages disponibles, así que usa un nombre descriptivo (ej: "Compartido Marketing", "Archivos Legales").',
                icon: 'fa-tag',
                color: '#1e293b',
                selector: function () {
                    var row = getFirstStorageRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 1) return cells[1];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstStorageRow();
                    if (row) scrollTo(row.querySelectorAll('td')[1]);
                }
            },
            {
                title: 'Columna: Tipo',
                content: 'Badge con el tipo de backend: ' +
                         '<span style="color:#2563eb"><strong>local</strong></span> (disco del servidor) o ' +
                         '<span style="color:#ea580c"><strong>s3</strong></span> (AWS S3 o compatible). ' +
                         'Determina qué campos aparecen al editar (ruta base vs credenciales S3).',
                icon: 'fa-server',
                color: '#2563eb',
                selector: function () {
                    var row = getFirstStorageRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 2) return cells[2];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstStorageRow();
                    if (row) scrollTo(row.querySelectorAll('td')[2]);
                }
            },
            {
                title: 'Columna: Archivos',
                content: 'Cantidad de archivos físicos asociados a este storage. ' +
                         'Sirve para identificar storages muy usados antes de hacer tareas de mantenimiento.',
                icon: 'fa-file',
                color: '#3b82f6',
                selector: function () {
                    var row = getFirstStorageRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 3) return cells[3];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstStorageRow();
                    if (row) scrollTo(row.querySelectorAll('td')[3]);
                }
            },
            {
                title: 'Columna: Estado',
                content: 'Badge del estado actual: ' +
                         '<span style="color:#16a34a"><strong>Activo</strong></span> (verde, disponible para usuarios) o ' +
                         '<span style="color:#dc2626"><strong>Inactivo</strong></span> (rojo, deshabilitado). ' +
                         'Los storages inactivos no son visibles para los usuarios, pero sus archivos se conservan.',
                icon: 'fa-circle',
                color: '#16a34a',
                selector: function () {
                    var row = getFirstStorageRow();
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells.length > 4) return cells[4];
                    }
                    return null;
                },
                position: 'bottom',
                onShow: function () {
                    var row = getFirstStorageRow();
                    if (row) scrollTo(row.querySelectorAll('td')[4]);
                }
            },
            {
                title: 'Acción: Usuarios',
                content: 'Haz clic en <strong style="color:#16a34a">Usuarios</strong> para abrir un modal donde puedes: ' +
                         '<ul style="margin:6px 0 0 16px;padding:0;">' +
                         '<li>Ver quién tiene acceso con chips de color (<span style="color:#16a34a">verde=Completo</span>, <span style="color:#2563eb">azul=Escritura</span>, <span style="color:#f59e0b">ámbar=Subida</span>, <span style="color:#64748b">gris=Lectura</span>)</li>' +
                         '<li>Asignar nuevos usuarios con permisos específicos</li>' +
                         '<li>Editar permisos de usuarios existentes (incluido si pueden compartir)</li>' +
                         '<li>Remover usuarios individualmente o todos a la vez</li>' +
                         '</ul>',
                icon: 'fa-users',
                color: '#16a34a',
                selector: function () {
                    var btn = getActionButton('Usuarios');
                    if (btn) return btn;
                    var row = getFirstStorageRow();
                    if (row) {
                        var btns = row.querySelectorAll('button');
                        if (btns.length > 0) return btns[0];
                    }
                    return null;
                },
                position: 'left',
                onShow: function () {
                    var btn = getActionButton('Usuarios');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Acción: Probar',
                content: 'El botón <strong style="color:#64748b">Probar</strong> verifica que el storage responda correctamente. ' +
                         '<ul style="margin:6px 0 0 16px;padding:0;">' +
                         '<li>Para <strong>Local</strong>: comprueba que la ruta exista y sea escribible.</li>' +
                         '<li>Para <strong>S3</strong>: valida credenciales, región y acceso al bucket.</li>' +
                         '</ul>' +
                         'Útil para detectar problemas antes de que los usuarios los reporten.',
                icon: 'fa-plug',
                color: '#64748b',
                selector: function () {
                    var btn = getActionButton('Probar');
                    if (btn) return btn;
                    var row = getFirstStorageRow();
                    if (row) {
                        var btns = row.querySelectorAll('button');
                        if (btns.length > 1) return btns[1];
                    }
                    return null;
                },
                position: 'left',
                onShow: function () {
                    var btn = getActionButton('Probar');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Acción: Editar',
                content: 'Haz clic en <strong style="color:#4f46e5">Editar</strong> para abrir un modal con: ' +
                         '<ul style="margin:6px 0 0 16px;padding:0;">' +
                         '<li>Cambio de nombre</li>' +
                         '<li>Modificación de ruta base (local) o credenciales S3</li>' +
                         '<li>Casilla para activar/desactivar el storage</li>' +
                         '</ul>' +
                         '<span style="color:#dc2626"><strong>Nota:</strong> desactivar un storage impide que los usuarios vean sus archivos, pero no los elimina.</span>',
                icon: 'fa-edit',
                color: '#4f46e5',
                selector: function () {
                    var btn = getActionButton('Editar');
                    if (btn) return btn;
                    var row = getFirstStorageRow();
                    if (row) {
                        var btns = row.querySelectorAll('button');
                        if (btns.length > 2) return btns[2];
                    }
                    return null;
                },
                position: 'left',
                onShow: function () {
                    var btn = getActionButton('Editar');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Acción: Eliminar',
                content: '<strong style="color:#dc2626">Eliminar</strong> abre un modal de confirmación que muestra ' +
                         'cuántos archivos se verán afectados. ' +
                         '<span style="color:#dc2626"><strong>Advertencia:</strong> esta acción es irreversible. ' +
                         'Los archivos físicos en disco NO se borran, pero desaparecen de la interfaz de usuarios.</span>',
                icon: 'fa-trash-alt',
                color: '#dc2626',
                selector: function () {
                    var btn = getActionButton('Eliminar');
                    if (btn) return btn;
                    var row = getFirstStorageRow();
                    if (row) {
                        var btns = row.querySelectorAll('button');
                        if (btns.length > 3) return btns[3];
                    }
                    return null;
                },
                position: 'left',
                onShow: function () {
                    var btn = getActionButton('Eliminar');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Crear Nuevo Storage',
                content: 'Haz clic en el botón verde <strong>Crear Storage</strong> para abrir el modal de creación. ' +
                         'Define nombre, tipo (<strong>Local</strong> requiere ruta base; <strong>S3</strong> requiere región, key, secret y bucket) y estado inicial. ' +
                         'Tras crearlo, <strong>asigna usuarios</strong> en el modal de Usuarios para que puedan acceder.',
                icon: 'fa-plus-circle',
                color: '#16a34a',
                selector: 'button[onclick="startStoragesTour()"] + button',
                position: 'bottom',
                onShow: function () {
                    var btn = document.querySelector('button[onclick="startStoragesTour()"] + button');
                    if (btn) scrollTo(btn);
                }
            },
            {
                title: 'Paginación',
                content: 'Aparece automáticamente cuando hay más resultados que el límite por página. ' +
                         'Muestra el rango de registros actual (ej: "1–25 de 47") y botones numerados para navegar. ' +
                         'Si solo hay una página, la paginación se oculta.',
                icon: 'fa-list-ol',
                color: '#64748b',
                selector: function () {
                    return document.querySelector('.flex.items-center.justify-between.px-6.py-4.border-t') ||
                           document.querySelector('.flex.justify-between') || null;
                },
                position: 'top',
                onShow: function () {
                    var p = document.querySelector('.flex.items-center.justify-between.px-6.py-4.border-t');
                    if (p) scrollTo(p);
                }
            },
            {
                title: 'Notificaciones Toast',
                content: 'En la esquina inferior derecha aparecen mensajes de confirmación o error tras realizar acciones (crear, editar, eliminar, probar). ' +
                         '<strong style="color:#16a34a">Verde</strong> = éxito, <strong style="color:#dc2626">rojo</strong> = error. ' +
                         'Desaparecen automáticamente tras 3.5 segundos.',
                icon: 'fa-bell',
                color: '#f59e0b',
                selector: function () {
                    return document.querySelector('[x-show="toast.show"]') || null;
                },
                position: 'center'
            },
            {
                title: 'Guía Completada',
                content: 'Conoces todo lo necesario para gestionar Storages: ' +
                         '<strong>crear</strong>, <strong>editar</strong>, <strong>probar</strong>, <strong>asignar usuarios</strong> y <strong>eliminar</strong>. ' +
                         'Recuerda siempre <strong>asignar usuarios</strong> tras crear un storage, ' +
                         '<strong>probar la conexión</strong> antes de notificar problemas, ' +
                         'y <strong>tener cuidado al eliminar</strong>. ' +
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
