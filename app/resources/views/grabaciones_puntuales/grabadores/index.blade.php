@extends('layouts.app')

@section('title', 'Grabadores - Tcloud')

@section('content')
<div class="p-6" x-data="{
    grabadores: [],
    allUsers: [],
    loading: true,

    showCreateModal: false,
    showTestModal: false,
    showUsersModal: false,
    showDetailModal: false,
    showDeleteModal: false,

    testResult: null,
    testing: false,

    usersModalGrabador: null,
    usersModalList: [],
    userSearchQuery: '',
    userSearchSelected: null,
    nombreBase: '',
    rutaBase: '',

    detailGrabador: null,
    deletingGrabador: null,

    toast: { show: false, message: '', success: true },

    newGrabador: { nombre: '', tipo: 'radio', ip: '', puerto: '5002', token: '', observaciones: '' },

    async init() {
        await Promise.all([this.loadGrabadores(), this.loadUsers()]);
        this.loading = false;
    },

    async loadGrabadores() {
        const res = await fetch('/grabaciones-puntuales/grabadores', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) this.grabadores = await res.json();
    },

    async loadUsers() {
        const res = await fetch('/grabaciones-puntuales/grabadores/users', {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) this.allUsers = await res.json();
    },

    showToast(message, success = true) {
        this.toast = { show: true, message, success };
        setTimeout(() => { this.toast.show = false; }, 3500);
    },

    async createGrabador() {
        const res = await fetch('/grabaciones-puntuales/grabadores', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(this.newGrabador)
        });
        if (res.ok) {
            this.showCreateModal = false;
            this.newGrabador = { nombre: '', tipo: 'radio', ip: '', puerto: '5002', token: '', observaciones: '' };
            await this.loadGrabadores();
            this.showToast('Grabador creado con 10 canales automáticos');
        } else {
            const err = await res.json();
            this.showToast(err.message || 'Error al crear grabador', false);
        }
    },

    async testGrabador(grabador) {
        this.testing = true;
        this.testResult = null;
        this.showTestModal = true;
        const res = await fetch('/grabaciones-puntuales/grabadores/' + grabador.id + '/probar', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        this.testResult = await res.json();
        this.testResult.grabador_nombre = grabador.nombre;
        this.testResult.grabador_ip = grabador.ip;
        this.testing = false;
    },

    async openUsersModal(grabador) {
        this.usersModalGrabador = grabador;
        this.usersModalList = grabador.usuarios || [];
        this.userSearchSelected = null;
        this.userSearchQuery = '';
        this.nombreBase = '';
        this.rutaBase = '';
        this.showUsersModal = true;
    },

    get filteredUsers() {
        const assignedIds = this.usersModalList.map(u => u.id);
        const q = this.userSearchQuery.toLowerCase().trim();
        return this.allUsers.filter(u =>
            !assignedIds.includes(u.id) &&
            (q === '' || (u.username && u.username.toLowerCase().includes(q)) || u.email.toLowerCase().includes(q))
        );
    },

    async assignUser() {
        if (!this.userSearchSelected || !this.nombreBase || !this.rutaBase) return;
        const res = await fetch('/grabaciones-puntuales/grabadores/' + this.usersModalGrabador.id + '/asignar-usuario', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                user_id: this.userSearchSelected.id,
                nombre_base: this.nombreBase,
                ruta_base: this.rutaBase
            })
        });
        if (res.ok) {
            const data = await res.json();
            if (data.warning) {
                this.showToast(data.warning, false);
            } else {
                this.showToast('Usuario asignado correctamente');
            }
            this.usersModalList = data.usuarios || data;
            this.userSearchSelected = null;
            this.userSearchQuery = '';
            this.nombreBase = '';
            this.rutaBase = '';
            await this.loadGrabadores();
        } else {
            const err = await res.json();
            this.showToast(err.error || 'Error al asignar usuario', false);
        }
    },

    get channelPreview() {
        if (!this.nombreBase) return [];
        return Array.from({ length: 10 }, (_, i) => {
            const num = String(i + 1).padStart(2, '0');
            return { nombre: this.nombreBase + '_' + num, ruta: this.rutaBase + '/' + this.nombreBase + '_' + num };
        });
    },

    async updateLimit(userId, newLimit) {
        const res = await fetch('/grabaciones-puntuales/grabadores/' + this.usersModalGrabador.id + '/actualizar-asignacion/' + userId, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ limite_canales: newLimit })
        });
        if (res.ok) {
            this.usersModalList = await res.json();
            await this.loadGrabadores();
            this.showToast('Límite actualizado');
        }
    },

    async removeUser(userId) {
        if (!confirm('¿Remover este usuario del grabador?')) return;
        const res = await fetch('/grabaciones-puntuales/grabadores/' + this.usersModalGrabador.id + '/remover-usuario/' + userId, {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        if (res.ok) {
            this.usersModalList = await res.json();
            await this.loadGrabadores();
            this.showToast('Usuario removido');
        }
    },

    openDetail(grabador) {
        this.detailGrabador = grabador;
        this.showDetailModal = true;
    },

    async deleteGrabador() {
        if (!this.deletingGrabador) return;
        const res = await fetch('/grabaciones-puntuales/grabadores/' + this.deletingGrabador.id, {
            method: 'DELETE',
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        if (res.ok) {
            this.showDeleteModal = false;
            this.deletingGrabador = null;
            await this.loadGrabadores();
            this.showToast('Grabador eliminado');
        }
    }
}" x-init="init()">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-satellite-dish text-emerald-600"></i>
                </div>
                Grabadores
            </h1>
            <p class="text-sm text-gray-500 mt-1">Administra los dispositivos grabadores y sus canales</p>
        </div>
        <button @click="showCreateModal = true"
                class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl font-medium transition-colors shadow-sm">
            <i class="fas fa-plus text-sm"></i>
            Nuevo Grabador
        </button>
    </div>

    <!-- Loading -->
    <div x-show="loading" class="flex items-center justify-center py-20">
        <i class="fas fa-spinner fa-spin text-3xl text-slate-300"></i>
    </div>

    <!-- Table -->
    <div x-show="!loading" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Nombre</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Tipo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">IP</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Canales</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider">Usuarios</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="g in grabadores" :key="g.id">
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                                     :class="g.tipo === 'tv' ? 'bg-purple-100' : 'bg-emerald-100'">
                                    <i :class="g.tipo === 'tv' ? 'fas fa-tv text-purple-600' : 'fas fa-radio text-emerald-600'" class="text-sm"></i>
                                </div>
                                <span class="font-medium text-slate-800 text-sm" x-text="g.nombre"></span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium"
                                  :class="g.tipo === 'tv' ? 'bg-purple-100 text-purple-700' : 'bg-emerald-100 text-emerald-700'"
                                  x-text="g.tipo === 'tv' ? 'TV' : 'Radio'"></span>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-600 font-mono" x-text="g.ip + ':' + g.puerto"></td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                                  :class="g.activo ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                <span class="w-1.5 h-1.5 rounded-full" :class="g.activo ? 'bg-green-500' : 'bg-red-500'"></span>
                                <span x-text="g.activo ? 'Activo' : 'Inactivo'"></span>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center text-sm font-medium text-slate-700" x-text="g.canales_count"></td>
                        <td class="px-6 py-4 text-center text-sm font-medium text-slate-700" x-text="(g.usuarios || []).length"></td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-1">
                                <button @click="openUsersModal(g)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors" title="Usuarios">
                                    <i class="fas fa-users text-xs"></i>
                                </button>
                                <button @click="testGrabador(g)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 rounded-lg transition-colors" title="Probar conexión">
                                    <i class="fas fa-plug text-xs"></i>
                                </button>
                                <button @click="openDetail(g)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors" title="Ver detalle">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                                <button @click="deletingGrabador = g; showDeleteModal = true" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="!loading && grabadores.length === 0" class="text-center py-16">
            <div class="flex flex-col items-center">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-satellite-dish text-slate-300 text-2xl"></i>
                </div>
                <p class="text-slate-500 font-medium">No hay grabadores registrados</p>
                <p class="text-sm text-slate-400 mt-1">Crea uno nuevo para comenzar</p>
            </div>
        </div>
    </div>

    {{-- ─── CREATE MODAL ─────────────────────────────────────────── --}}
    <div x-cloak x-show="showCreateModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-xl" @click.away="showCreateModal = false">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold text-slate-800">Nuevo Grabador</h2>
                <button @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <form @submit.prevent="createGrabador()">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input type="text" x-model="newGrabador.nombre" required
                               class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none"
                               placeholder="Ej: Radio Siglo">
                        <p class="text-xs text-slate-400 mt-1">Se crearán 10 canales automáticamente: <span x-text="newGrabador.nombre ? newGrabador.nombre + '_01 ... ' + newGrabador.nombre + '_10' : 'nombre_01 ... nombre_10'"></span></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Tipo</label>
                        <div class="flex gap-3">
                            <label class="flex-1 flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition-colors"
                                   :class="newGrabador.tipo === 'radio' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 hover:border-slate-300'">
                                <input type="radio" name="tipo" value="radio" x-model="newGrabador.tipo" class="sr-only">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center"
                                     :class="newGrabador.tipo === 'radio' ? 'bg-emerald-500' : 'bg-slate-200'">
                                    <i class="fas fa-radio text-white text-sm"></i>
                                </div>
                                <span class="font-medium text-sm" :class="newGrabador.tipo === 'radio' ? 'text-emerald-700' : 'text-slate-600'">Radio</span>
                            </label>
                            <label class="flex-1 flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition-colors"
                                   :class="newGrabador.tipo === 'tv' ? 'border-purple-500 bg-purple-50' : 'border-slate-200 hover:border-slate-300'">
                                <input type="radio" name="tipo" value="tv" x-model="newGrabador.tipo" class="sr-only">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center"
                                     :class="newGrabador.tipo === 'tv' ? 'bg-purple-500' : 'bg-slate-200'">
                                    <i class="fas fa-tv text-white text-sm"></i>
                                </div>
                                <span class="font-medium text-sm" :class="newGrabador.tipo === 'tv' ? 'text-purple-700' : 'text-slate-600'">TV</span>
                            </label>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Dirección IP</label>
                            <input type="text" x-model="newGrabador.ip" required
                                   class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm font-mono focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none"
                                   placeholder="192.168.0.118">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Puerto</label>
                            <input type="number" x-model="newGrabador.puerto" required
                                   class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Token <span class="text-slate-400 font-normal">(opcional)</span></label>
                        <input type="text" x-model="newGrabador.token"
                               class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none"
                               placeholder="Si la API requiere autenticación">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Observaciones</label>
                        <textarea x-model="newGrabador.observaciones" rows="2"
                                  class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none resize-none"></textarea>
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="flex-1 flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-xl font-medium transition-colors">
                        <i class="fas fa-save text-sm"></i>
                        Crear Grabador
                    </button>
                    <button type="button" @click="showCreateModal = false" class="px-6 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ─── TEST MODAL ───────────────────────────────────────────── --}}
    <div x-cloak x-show="showTestModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" @click.away="showTestModal = false">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold text-slate-800">Prueba de Conexión</h2>
                <button @click="showTestModal = false" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <div x-show="testing" class="flex flex-col items-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-emerald-500 mb-3"></i>
                <p class="text-slate-500">Probando conexión...</p>
            </div>

            <div x-show="!testing && testResult">
                <div class="flex items-center gap-3 mb-4 p-4 rounded-xl"
                     :class="testResult?.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center"
                         :class="testResult?.success ? 'bg-green-100' : 'bg-red-100'">
                        <i :class="testResult?.success ? 'fas fa-check text-green-600' : 'fas fa-times text-red-600'" class="text-xl"></i>
                    </div>
                    <div>
                        <p class="font-semibold" :class="testResult?.success ? 'text-green-800' : 'text-red-800'" x-text="testResult?.success ? 'Conexión Exitosa' : 'Error de Conexión'"></p>
                        <p class="text-sm" :class="testResult?.success ? 'text-green-600' : 'text-red-600'" x-text="testResult?.grabador_nombre + ' (' + testResult?.grabador_ip + ')'"></p>
                    </div>
                </div>

                <template x-if="testResult?.success">
                    <div class="bg-slate-50 rounded-xl p-4 space-y-2">
                        <p class="text-sm font-medium text-slate-700 mb-2">Estado del grabador:</p>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-500">Canales remotos detectados</span>
                            <span class="font-medium text-slate-800" x-text="testResult?.canales_remotos || 0"></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-500">Base URL</span>
                            <span class="font-mono text-xs text-slate-600" x-text="testResult?.endpoints?.['Base URL']"></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-500">Endpoint /canales</span>
                            <span class="text-green-600 font-medium" x-text="testResult?.endpoints?.['GET /canales']"></span>
                        </div>
                    </div>
                </template>

                <template x-if="!testResult?.success">
                    <div class="bg-red-50 rounded-xl p-4">
                        <p class="text-sm text-red-700" x-text="testResult?.message"></p>
                    </div>
                </template>

                <button @click="showTestModal = false" class="w-full mt-5 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    {{-- ─── USERS MODAL ─────────────────────────────────────────── --}}
    <div x-cloak x-show="showUsersModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto shadow-xl" @click.away="showUsersModal = false">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold text-slate-800">
                    Usuarios: <span class="text-emerald-600" x-text="usersModalGrabador?.nombre"></span>
                </h2>
                <button @click="showUsersModal = false" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <!-- Assigned Users -->
            <div class="mb-5">
                <span class="text-sm font-medium text-slate-600 mb-2 block">Usuarios asignados</span>
                <div class="min-h-[56px] max-h-40 overflow-y-auto flex flex-wrap gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
                    <template x-for="u in usersModalList" :key="u.id">
                        <div class="flex items-center gap-1 pl-2 pr-1 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 border border-emerald-200">
                            <span x-text="u.username || u.email"></span>
                            <span class="ml-1 px-1 rounded text-xs opacity-60" x-text="(u.pivot?.limite_canales || '?') + ' ch'"></span>
                            <button @click="removeUser(u.id)" class="ml-1 w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 transition-colors text-current font-bold leading-none">×</button>
                        </div>
                    </template>
                    <span x-show="usersModalList.length === 0" class="text-slate-400 text-sm self-center">Sin usuarios asignados</span>
                </div>
            </div>

            <!-- Assign New User -->
            <div class="border border-slate-200 rounded-xl p-4">
                <h3 class="text-sm font-bold text-slate-700 mb-3">Asignar usuario</h3>
                <input type="text" x-model="userSearchQuery" placeholder="Filtrar usuarios..."
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm mb-2 focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none">

                <div class="border border-slate-200 rounded-xl overflow-hidden mb-3 max-h-44 overflow-y-auto">
                    <template x-if="filteredUsers.length > 0">
                        <div>
                            <template x-for="user in filteredUsers" :key="user.id">
                                <div @click="userSearchSelected = user; userSearchQuery = ''"
                                     class="px-3 py-2 flex items-center justify-between cursor-pointer border-b last:border-b-0 transition-colors"
                                     :class="userSearchSelected?.id === user.id ? 'bg-emerald-50 border-emerald-200' : 'hover:bg-slate-50'">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-emerald-100 flex items-center justify-center text-xs font-bold text-emerald-600"
                                             x-text="(user.username || user.email).charAt(0).toUpperCase()"></div>
                                        <span class="text-sm font-medium text-slate-800" x-text="user.username || user.email"></span>
                                    </div>
                                    <span class="text-xs text-slate-400" x-text="user.email"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div x-show="filteredUsers.length === 0" class="px-3 py-4 text-sm text-slate-400 text-center">
                        <span x-text="allUsers.length === 0 ? 'Cargando...' : 'No hay más usuarios para asignar'"></span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 items-start">
                    <div class="flex-1 min-w-0">
                        <span x-show="userSearchSelected" class="inline-flex items-center gap-1 px-2 py-1 bg-emerald-100 text-emerald-800 rounded-full text-xs font-medium">
                            <span x-text="userSearchSelected ? (userSearchSelected.username || userSearchSelected.email) : ''"></span>
                            <button @click="userSearchSelected = null" class="ml-1 hover:text-emerald-600 font-bold">×</button>
                        </span>
                        <span x-show="!userSearchSelected" class="text-xs text-slate-400">Selecciona un usuario</span>
                    </div>
                </div>
            </div>

            <!-- Nombre base y Ruta base -->
            <div class="border border-slate-200 rounded-xl p-4 mt-3 bg-emerald-50">
                <h3 class="text-sm font-bold text-slate-700 mb-3">Configuración de canales</h3>
                <div class="grid grid-cols-2 gap-4 mb-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Nombre base</label>
                        <input type="text" x-model="nombreBase" placeholder="Ej: Siglo"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none"
                               pattern="[a-zA-Z0-9_]+">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Ruta base</label>
                        <input type="text" x-model="rutaBase" placeholder="Ej: /disco1/grabaciones"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none">
                    </div>
                </div>

                <!-- Preview de canales a crear -->
                <div x-show="nombreBase && rutaBase" class="bg-white rounded-xl border border-slate-200 p-3">
                    <p class="text-xs font-medium text-slate-500 mb-2">Se crearán 10 canales:</p>
                    <div class="grid grid-cols-2 gap-1 text-xs font-mono">
                        <template x-for="ch in channelPreview" :key="ch.nombre">
                            <div class="flex justify-between px-2 py-1 bg-slate-50 rounded">
                                <span class="text-emerald-700 font-medium" x-text="ch.nombre"></span>
                                <span class="text-slate-400 truncate max-w-[150px]" x-text="ch.ruta" :title="ch.ruta"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <button @click="assignUser()"
                        :disabled="!userSearchSelected || !nombreBase || !rutaBase"
                        :class="(userSearchSelected && nombreBase && rutaBase) ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-slate-300 cursor-not-allowed'"
                        class="mt-3 w-full text-white px-4 py-2.5 rounded-xl text-sm font-medium transition-colors">
                    Asignar y crear 10 canales
                </button>
            </div>
        </div>
    </div>

    {{-- ─── DETAIL MODAL ─────────────────────────────────────────── --}}
    <div x-cloak x-show="showDetailModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto shadow-xl" @click.away="showDetailModal = false">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold text-slate-800">
                    <span x-text="detailGrabador?.nombre"></span>
                </h2>
                <button @click="showDetailModal = false" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <!-- Info Cards -->
            <div class="grid grid-cols-3 gap-3 mb-5">
                <div class="bg-slate-50 rounded-xl p-3 text-center">
                    <p class="text-xs text-slate-500 mb-1">Tipo</p>
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                          :class="detailGrabador?.tipo === 'tv' ? 'bg-purple-100 text-purple-700' : 'bg-emerald-100 text-emerald-700'"
                          x-text="detailGrabador?.tipo === 'tv' ? 'TV' : 'Radio'"></span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 text-center">
                    <p class="text-xs text-slate-500 mb-1">Canales</p>
                    <p class="text-lg font-bold text-slate-800" x-text="detailGrabador?.canales_count"></p>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 text-center">
                    <p class="text-xs text-slate-500 mb-1">Usuarios</p>
                    <p class="text-lg font-bold text-slate-800" x-text="(detailGrabador?.usuarios || []).length"></p>
                </div>
            </div>

            <div class="bg-slate-50 rounded-xl p-4 mb-5">
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div><span class="text-slate-500">IP:</span> <span class="font-mono font-medium text-slate-700" x-text="detailGrabador?.ip"></span></div>
                    <div><span class="text-slate-500">Puerto:</span> <span class="font-medium text-slate-700" x-text="detailGrabador?.puerto"></span></div>
                    <div class="col-span-2"><span class="text-slate-500">Base URL:</span> <span class="font-mono text-xs text-slate-600" x-text="detailGrabador?.base_url"></span></div>
                </div>
            </div>

            <!-- Channels List -->
            <div>
                <h3 class="text-sm font-bold text-slate-700 mb-3">Canales</h3>
                <div class="bg-slate-50 rounded-xl border border-slate-200 overflow-hidden max-h-60 overflow-y-auto">
                    <table class="w-full">
                        <thead class="bg-slate-100 sticky top-0">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-slate-500">Slot</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-slate-500">Usuario</th>
                                <th class="px-4 py-2 text-center text-xs font-medium text-slate-500">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="c in (detailGrabador?.canales || [])" :key="c.id">
                                <tr class="hover:bg-white/50">
                                    <td class="px-4 py-2 text-sm font-medium text-slate-700" x-text="c.slot_nombre"></td>
                                    <td class="px-4 py-2 text-sm text-slate-500" x-text="c.usuario?.username || 'Sin asignar'"></td>
                                    <td class="px-4 py-2 text-center">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                              :class="c.activo ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                            <span class="w-1 h-1 rounded-full" :class="c.activo ? 'bg-green-500' : 'bg-red-500'"></span>
                                            <span x-text="c.activo ? 'Activo' : 'Inactivo'"></span>
                                        </span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <button @click="showDetailModal = false" class="w-full mt-5 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                Cerrar
            </button>
        </div>
    </div>

    {{-- ─── DELETE MODAL ─────────────────────────────────────────── --}}
    <div x-cloak x-show="showDeleteModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" @click.away="showDeleteModal = false">
            <h2 class="text-xl font-bold text-slate-800 mb-3">Eliminar Grabador</h2>
            <p class="text-slate-600 mb-1">¿Estás seguro de eliminar <strong x-text="deletingGrabador?.nombre"></strong>?</p>
            <p class="text-red-600 text-sm mb-5">Se eliminarán todos sus canales y asignaciones de usuarios.</p>
            <div class="flex gap-3">
                <button @click="deleteGrabador()" class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-xl font-medium transition-colors">
                    Eliminar
                </button>
                <button @click="showDeleteModal = false" class="flex-1 border border-slate-300 text-slate-700 px-4 py-2.5 rounded-xl font-medium hover:bg-slate-50 transition-colors">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    {{-- ─── TOAST ────────────────────────────────────────────────── --}}
    <div x-show="toast.show" x-transition:enter="transition ease-out duration-300" x-transition:leave="transition ease-in duration-200"
         class="fixed bottom-4 right-4 z-50 max-w-sm rounded-xl shadow-lg overflow-hidden"
         :class="toast.success ? 'bg-emerald-600' : 'bg-red-600'">
        <div class="flex items-center px-4 py-3 text-white">
            <i :class="toast.success ? 'fas fa-check-circle' : 'fas fa-exclamation-circle'" class="mr-2"></i>
            <span class="text-sm" x-text="toast.message"></span>
            <button @click="toast.show = false" class="ml-4 hover:opacity-70"><i class="fas fa-times"></i></button>
        </div>
    </div>
</div>
@endsection
