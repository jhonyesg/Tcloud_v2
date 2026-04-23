@extends('layouts.app')

@section('title', 'Compartidos - Tcloud')

@section('content')
<div class="p-6" x-data="sharesApp()" x-init="init()">

    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mis Recursos Compartidos</h1>
            <p class="text-sm text-gray-500 mt-0.5">Gestiona los enlaces de acceso que has generado</p>
        </div>
        <button @click="loadShares()" :disabled="loading"
                class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50 disabled:opacity-50">
            <i :class="loading ? 'fas fa-spinner fa-spin' : 'fas fa-sync-alt'" class="text-xs"></i>
            Actualizar
        </button>
    </div>

    <!-- Stats bar -->
    <div x-show="shares.length > 0" class="grid grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-lg border px-4 py-3">
            <p class="text-xs text-gray-400 mb-0.5">Total</p>
            <p class="text-2xl font-bold text-gray-800" x-text="shares.length"></p>
        </div>
        <div class="bg-white rounded-lg border px-4 py-3">
            <p class="text-xs text-gray-400 mb-0.5">Activos</p>
            <p class="text-2xl font-bold text-green-600" x-text="shares.filter(s => !s.is_expired).length"></p>
        </div>
        <div class="bg-white rounded-lg border px-4 py-3">
            <p class="text-xs text-gray-400 mb-0.5">Expirados</p>
            <p class="text-2xl font-bold text-red-500" x-text="shares.filter(s => s.is_expired).length"></p>
        </div>
        <div class="bg-white rounded-lg border px-4 py-3">
            <p class="text-xs text-gray-400 mb-0.5">Total accesos</p>
            <p class="text-2xl font-bold text-indigo-600" x-text="shares.reduce((acc, s) => acc + (s.access_logs_count || 0), 0)"></p>
        </div>
    </div>

    <!-- Main table card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">

        <!-- Toolbar (filter + search) -->
        <div x-show="shares.length > 0" class="px-5 py-3 border-b border-gray-100 flex items-center gap-3 flex-wrap">
            <div class="relative flex-1 min-w-48">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input x-model="searchTerm" type="text" placeholder="Buscar por nombre..."
                       class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <select x-model="filterPermission" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-200 text-gray-600">
                <option value="">Todos los permisos</option>
                <option value="read">Solo lectura</option>
                <option value="write">Escritura</option>
                <option value="upload">Subida</option>
                <option value="full">Acceso completo</option>
            </select>
            <select x-model="filterStatus" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-200 text-gray-600">
                <option value="">Todos los estados</option>
                <option value="active">Activos</option>
                <option value="expired">Expirados</option>
            </select>
            <span class="text-xs text-gray-400 ml-auto" x-text="filteredShares.length + ' resultado' + (filteredShares.length !== 1 ? 's' : '')"></span>
        </div>

        <!-- Loading skeleton -->
        <div x-show="loading" class="p-8 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl text-indigo-400 mb-3 block"></i>
            <p class="text-sm">Cargando recursos compartidos...</p>
        </div>

        <!-- Empty state -->
        <div x-show="!loading && shares.length === 0" class="py-16 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
                <i class="fas fa-share-alt text-2xl text-gray-400"></i>
            </div>
            <p class="font-medium text-gray-600">No tienes recursos compartidos</p>
            <p class="text-sm text-gray-400 mt-1">Ve al módulo de Archivos, abre un recurso y genera un enlace compartido.</p>
        </div>

        <!-- No results after filter -->
        <div x-show="!loading && shares.length > 0 && filteredShares.length === 0" class="py-10 text-center text-gray-400 text-sm">
            <i class="fas fa-filter mb-2 block text-lg"></i>
            Ningún resultado con los filtros aplicados.
        </div>

        <!-- Table -->
        <div x-show="!loading && filteredShares.length > 0" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase tracking-wide">
                        <th class="px-5 py-3 text-left">Recurso</th>
                        <th class="px-4 py-3 text-left">Enlace</th>
                        <th class="px-4 py-3 text-left">Permiso</th>
                        <th class="px-4 py-3 text-left">Estado</th>
                        <th class="px-4 py-3 text-left">Expira</th>
                        <th class="px-4 py-3 text-center">Accesos</th>
                        <th class="px-4 py-3 text-left">Creado</th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="share in filteredShares" :key="share.id">
                        <tr class="hover:bg-gray-50 transition-colors group"
                            :class="share.is_expired ? 'opacity-70' : ''">

                            <!-- Resource name + icon -->
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span class="text-lg flex-shrink-0" x-html="fileIcon(share.file)"></span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 truncate max-w-[180px]"
                                           :title="share.file?.name || 'Recurso eliminado'"
                                           x-text="share.file?.name || 'Recurso eliminado'"></p>
                                        <p class="text-xs text-gray-400 truncate max-w-[180px]"
                                           :title="share.file?.path || ''"
                                           x-text="share.file?.path ? filePath(share.file.path) : ''"></p>
                                    </div>
                                    <span x-show="share.has_password" title="Protegido con contraseña"
                                          class="text-amber-400 flex-shrink-0"><i class="fas fa-lock text-xs"></i></span>
                                </div>
                            </td>

                            <!-- Link -->
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-1.5">
                                    <code class="text-xs text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono truncate max-w-[120px]"
                                          :title="share.public_url"
                                          x-text="'/s/' + share.token.slice(0,10) + '...'"></code>
                                    <button @click="copyLink(share)"
                                            class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-indigo-600 transition-all"
                                            title="Copiar enlace">
                                        <i class="fas fa-copy text-xs"></i>
                                    </button>
                                </div>
                            </td>

                            <!-- Permission badge -->
                            <td class="px-4 py-3.5">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="{
                                          'bg-gray-100 text-gray-700':   share.permissions === 'read',
                                          'bg-blue-100 text-blue-700':   share.permissions === 'write',
                                          'bg-amber-100 text-amber-700': share.permissions === 'upload',
                                          'bg-green-100 text-green-700': share.permissions === 'full'
                                      }">
                                    <i :class="{
                                        'fas fa-eye':        share.permissions === 'read',
                                        'fas fa-pen':        share.permissions === 'write',
                                        'fas fa-upload':     share.permissions === 'upload',
                                        'fas fa-check-double': share.permissions === 'full'
                                    }" class="text-xs"></i>
                                    <span x-text="permLabel(share.permissions)"></span>
                                </span>
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3.5">
                                <span class="inline-flex items-center gap-1 text-xs font-medium"
                                      :class="share.is_expired ? 'text-red-500' : 'text-green-600'">
                                    <span class="w-1.5 h-1.5 rounded-full inline-block flex-shrink-0"
                                          :class="share.is_expired ? 'bg-red-400' : 'bg-green-400'"></span>
                                    <span x-text="share.is_expired ? 'Expirado' : 'Activo'"></span>
                                </span>
                            </td>

                            <!-- Expiry date -->
                            <td class="px-4 py-3.5 text-xs"
                                :class="share.is_expired ? 'text-red-400' : 'text-gray-500'">
                                <span x-show="!share.expires_at" class="text-gray-400">Sin vencimiento</span>
                                <span x-show="share.expires_at" x-text="formatDate(share.expires_at)"></span>
                            </td>

                            <!-- Access count -->
                            <td class="px-4 py-3.5 text-center">
                                <span class="inline-flex items-center gap-1 text-xs text-gray-600">
                                    <i class="fas fa-eye text-gray-300"></i>
                                    <span x-text="share.access_logs_count || 0"></span>
                                </span>
                            </td>

                            <!-- Created date -->
                            <td class="px-4 py-3.5 text-xs text-gray-400" x-text="formatDate(share.created_at)"></td>

                            <!-- Actions -->
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="copyLink(share)"
                                            class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 text-xs flex items-center gap-1"
                                            title="Copiar enlace público">
                                        <i class="fas fa-link"></i>
                                        Copiar
                                    </button>
                                    <button @click="openLink(share)"
                                            class="px-2.5 py-1 rounded-lg bg-gray-50 text-gray-600 hover:bg-gray-100 text-xs"
                                            title="Abrir enlace en nueva pestaña">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                    <button @click="confirmDelete(share)"
                                            class="px-2.5 py-1 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 text-xs"
                                            title="Revocar enlace">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Delete confirm modal -->
    <div x-cloak x-show="deleteModal.show"
         x-transition:enter="transition ease-out duration-150"
         x-transition:leave="transition ease-in duration-100"
         class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm mx-4">
            <div class="flex items-start gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-trash-alt text-red-500"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800">Revocar enlace</h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        ¿Eliminar el enlace compartido de
                        <strong x-text="deleteModal.shareName"></strong>?
                        Quien tenga el link ya no podrá acceder.
                    </p>
                </div>
            </div>
            <div class="flex gap-2 justify-end">
                <button @click="deleteModal.show = false"
                        class="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-gray-600">
                    Cancelar
                </button>
                <button @click="deleteShare()" :disabled="deleteModal.loading"
                        class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 flex items-center gap-1.5">
                    <i x-show="deleteModal.loading" class="fas fa-spinner fa-spin text-xs"></i>
                    Revocar enlace
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show"
         x-transition:enter="transition ease-out duration-200 transform"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-end="opacity-0"
         class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 flex items-center gap-2.5 px-4 py-2.5 rounded-xl shadow-lg text-sm font-medium text-white"
         :class="toast.success ? 'bg-green-600' : 'bg-red-600'">
        <i :class="toast.success ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i>
        <span x-text="toast.message"></span>
    </div>
</div>

<script>
function sharesApp() {
    return {
        shares: [],
        loading: false,
        searchTerm: '',
        filterPermission: '',
        filterStatus: '',
        deleteModal: { show: false, shareId: null, shareName: '', loading: false },
        toast: { show: false, message: '', success: true, _timer: null },

        init() {
            this.loadShares();
        },

        async loadShares() {
            this.loading = true;
            try {
                const res = await fetch('/shares', {
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) throw new Error('Error ' + res.status);
                this.shares = await res.json();
            } catch (e) {
                this.showToast(false, 'Error al cargar compartidos: ' + e.message);
            } finally {
                this.loading = false;
            }
        },

        get filteredShares() {
            return this.shares.filter(s => {
                const name = (s.file?.name || '').toLowerCase();
                const term = this.searchTerm.toLowerCase();
                const matchSearch = !term || name.includes(term);
                const matchPerm   = !this.filterPermission || s.permissions === this.filterPermission;
                const matchStatus = !this.filterStatus ||
                    (this.filterStatus === 'active'  && !s.is_expired) ||
                    (this.filterStatus === 'expired' &&  s.is_expired);
                return matchSearch && matchPerm && matchStatus;
            });
        },

        copyLink(share) {
            const url = share.public_url || (window.location.origin + '/s/' + share.token);
            navigator.clipboard.writeText(url)
                .then(() => this.showToast(true, '¡Enlace copiado al portapapeles!'))
                .catch(() => this.showToast(false, 'No se pudo copiar el enlace'));
        },

        openLink(share) {
            const url = share.public_url || (window.location.origin + '/s/' + share.token);
            window.open(url, '_blank', 'noopener');
        },

        confirmDelete(share) {
            this.deleteModal = {
                show: true,
                shareId: share.id,
                shareName: share.file?.name || 'este recurso',
                loading: false
            };
        },

        async deleteShare() {
            this.deleteModal.loading = true;
            try {
                const res = await fetch('/shares/' + this.deleteModal.shareId, {
                    method: 'DELETE',
                    credentials: 'include',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                    }
                });
                if (!res.ok) throw new Error('Error ' + res.status);
                this.shares = this.shares.filter(s => s.id !== this.deleteModal.shareId);
                this.deleteModal.show = false;
                this.showToast(true, 'Enlace revocado correctamente');
            } catch (e) {
                this.showToast(false, 'Error al eliminar: ' + e.message);
            } finally {
                this.deleteModal.loading = false;
            }
        },

        showToast(success, message) {
            clearTimeout(this.toast._timer);
            this.toast = { show: true, success, message, _timer: null };
            this.toast._timer = setTimeout(() => { this.toast.show = false; }, 3500);
        },

        permLabel(perm) {
            return { read: 'Lectura', write: 'Escritura', upload: 'Subida', full: 'Completo' }[perm] || perm;
        },

        formatDate(raw) {
            if (!raw) return '';
            const d = new Date(raw);
            return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
        },

        filePath(path) {
            if (!path) return '';
            const parts = path.split('/').filter(Boolean);
            if (parts.length <= 1) return '/';
            return '/' + parts.slice(0, -1).join('/');
        },

        fileIcon(file) {
            if (!file) return '<i class="fas fa-question text-gray-300"></i>';
            if (file.is_folder) return '<i class="fas fa-folder text-yellow-400"></i>';
            const mime = file.mime_type || '';
            if (mime.startsWith('image/'))       return '<i class="fas fa-file-image text-blue-400"></i>';
            if (mime.startsWith('video/'))       return '<i class="fas fa-file-video text-purple-400"></i>';
            if (mime.startsWith('audio/'))       return '<i class="fas fa-file-audio text-pink-400"></i>';
            if (mime === 'application/pdf')      return '<i class="fas fa-file-pdf text-red-400"></i>';
            if (mime.includes('zip') || mime.includes('compressed') || mime.includes('archive'))
                                                 return '<i class="fas fa-file-archive text-orange-400"></i>';
            if (mime.includes('word') || mime.includes('document'))
                                                 return '<i class="fas fa-file-word text-blue-600"></i>';
            if (mime.includes('sheet') || mime.includes('excel'))
                                                 return '<i class="fas fa-file-excel text-green-600"></i>';
            if (mime.includes('presentation') || mime.includes('powerpoint'))
                                                 return '<i class="fas fa-file-powerpoint text-orange-600"></i>';
            if (mime.startsWith('text/'))        return '<i class="fas fa-file-alt text-gray-500"></i>';
            return '<i class="fas fa-file text-gray-400"></i>';
        }
    };
}
</script>
@endsection
