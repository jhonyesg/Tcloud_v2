<?php $__env->startSection('title', 'Archivos - Tcloud'); ?>

<?php $__env->startSection('content'); ?>
<div class="min-h-screen bg-slate-100" x-data="{
    files: [],
    availableStorages: [],
    currentFolder: null,
    currentFolderName: null,
    currentStorage: null,
    currentStorageName: null,
    viewMode: 'storages',
    filesViewMode: localStorage.getItem('files_view_mode') || 'grid',
    selectedFiles: [],
    showUploadModal: false,
    showDetailModal: false,
    showNewFolderModal: false,
    selectedFile: null,
    breadcrumbs: [],
    fileShares: [],
    editingShareId: null,
    editingShareData: {},
    shareForm: {
        permissions: 'read',
        password: '',
        expires_at: ''
    },
    shareFeedback: { type: '', message: '' },

    async init() {
        await this.loadStorages();
    },

    setFilesViewMode(mode) {
        this.filesViewMode = mode;
        localStorage.setItem('files_view_mode', mode);
    },

    getFileIcon(file) {
        if (file.is_folder) {
            return { icon: 'folder', color: 'amber', bg: 'bg-amber-100' };
        }

        const mime = file.mime_type || '';
        const name = file.name || '';
        const ext = name.split('.').pop()?.toLowerCase() || '';

        if (mime.startsWith('video/') || ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm'].includes(ext)) {
            return { icon: 'video', color: 'rose', bg: 'bg-rose-100' };
        }
        if (mime.startsWith('audio/') || ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'].includes(ext)) {
            return { icon: 'music', color: 'purple', bg: 'bg-purple-100' };
        }
        if (mime === 'application/pdf' || ext === 'pdf') {
            return { icon: 'pdf', color: 'red', bg: 'bg-red-100' };
        }
        if (mime.includes('word') || ['doc', 'docx'].includes(ext)) {
            return { icon: 'word', color: 'blue', bg: 'bg-blue-100' };
        }
        if (mime.includes('spreadsheet') || ['xls', 'xlsx', 'csv'].includes(ext)) {
            return { icon: 'excel', color: 'green', bg: 'bg-green-100' };
        }
        if (mime.includes('presentation') || ['ppt', 'pptx'].includes(ext)) {
            return { icon: 'ppt', color: 'orange', bg: 'bg-orange-100' };
        }
        if (mime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'].includes(ext)) {
            return { icon: 'image', color: 'cyan', bg: 'bg-cyan-100' };
        }
        if (mime.includes('zip') || mime.includes('rar') || mime.includes('7z') || ['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
            return { icon: 'archive', color: 'amber', bg: 'bg-amber-100' };
        }
        if (mime.startsWith('text/') || ['js', 'json', 'html', 'css', 'xml', 'php', 'py', 'java', 'c', 'cpp'].includes(ext)) {
            return { icon: 'code', color: 'slate', bg: 'bg-slate-100' };
        }

        return { icon: 'file', color: 'gray', bg: 'bg-slate-100' };
    },

    async loadStorages() {
        const res = await fetch('/user/storages', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            const data = await res.json();
            this.availableStorages = data.storages || [];
        }
    },

    enterStorage(storageId, storageName) {
        this.currentStorage = storageId;
        this.currentStorageName = storageName;
        this.currentFolder = null;
        this.currentFolderName = null;
        this.breadcrumbs = [];
        this.viewMode = 'files';
        this.loadFiles();
    },

    navigateToRoot() {
        this.currentStorage = null;
        this.currentStorageName = null;
        this.currentFolder = null;
        this.currentFolderName = null;
        this.breadcrumbs = [];
        this.viewMode = 'storages';
        this.files = [];
    },

    loadFiles() {
        let url = '/files?';
        if (this.currentFolder) url += 'parent_id=' + this.currentFolder;
        if (this.currentStorage) url += '&storage_id=' + this.currentStorage;

        fetch(url, {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(res => res.ok && res.json()).then(data => {
            this.files = data || [];
        });
    },

    navigateToFolder(folderId, folderName) {
        if (this.currentFolder !== null) {
            this.breadcrumbs.push({ id: this.currentFolder, name: this.currentFolderName || 'Raíz' });
        }
        this.currentFolder = folderId;
        this.currentFolderName = folderName;
        this.loadFiles();
    },

    navigateToBreadcrumb(breadcrumb) {
        const index = this.breadcrumbs.indexOf(breadcrumb);
        if (index > -1) {
            this.breadcrumbs = this.breadcrumbs.slice(0, index);
        }
        this.currentFolder = breadcrumb.id;
        this.currentFolderName = breadcrumb.name === 'Raíz' ? null : breadcrumb.name;
        this.loadFiles();
    },

    async uploadFile(file) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('parent_id', this.currentFolder || '');
        formData.append('storage_id', this.currentStorage || '');

        const res = await fetch('/files/upload', {
            method: 'POST',
            credentials: 'include',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (res.ok) {
            this.showUploadModal = false;
            this.loadFiles();
        }
    },

    async createFolder(name) {
        const res = await fetch('/files', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                name: name,
                parent_id: this.currentFolder,
                storage_id: this.currentStorage,
                is_folder: true
            })
        });

        if (res.ok) {
            this.showNewFolderModal = false;
            this.loadFiles();
        }
    },

    async openDetailModal(file) {
        this.selectedFile = file;
        this.showDetailModal = true;
        this.fileShares = [];
        this.editingShareId = null;
        this.shareForm = { permissions: 'read', password: '', expires_at: '' };
        this.shareFeedback = { type: '', message: '' };
        await this.loadFileShares(file.id);
    },

    async loadFileShares(fileId) {
        const res = await fetch('/shares?file_id=' + fileId, {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            const data = await res.json();
            this.fileShares = Array.isArray(data) ? data : (data.shares || []);
        }
    },

    async generateShareLink() {
        this.shareFeedback = { type: '', message: '' };
        const res = await fetch('/shares', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                file_id: this.selectedFile.id,
                permissions: this.shareForm.permissions,
                password: this.shareForm.password || null,
                expires_at: this.shareForm.expires_at || null
            })
        });

        if (res.ok) {
            const newShare = await res.json();
            this.fileShares.push(newShare);
            this.shareForm = { permissions: 'read', password: '', expires_at: '' };
            this.shareFeedback = { type: 'success', message: 'Enlace generado correctamente' };
            setTimeout(() => this.shareFeedback = { type: '', message: '' }, 3000);
        } else {
            const error = await res.json();
            this.shareFeedback = { type: 'error', message: error.error || 'Error al generar enlace' };
        }
    },

    copyShareLink(token) {
        const url = window.location.origin + '/s/' + token;
        navigator.clipboard.writeText(url).then(() => {
            this.shareFeedback = { type: 'success', message: 'Enlace copiado al portapapeles' };
            setTimeout(() => this.shareFeedback = { type: '', message: '' }, 3000);
        });
    },

    async deleteShareLink(shareId) {
        if (!confirm('¿Eliminar este enlace?')) return;

        const res = await fetch('/shares/' + shareId, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (res.ok) {
            this.fileShares = this.fileShares.filter(s => s.id !== shareId);
            this.shareFeedback = { type: 'success', message: 'Enlace eliminado' };
            setTimeout(() => this.shareFeedback = { type: '', message: '' }, 3000);
        }
    },

    editShareLink(share) {
        this.editingShareId = share.id;
        this.editingShareData = {
            permissions: share.permissions,
            expires_at: share.expires_at ? share.expires_at.slice(0, 16) : ''
        };
    },

    async saveShareLink(shareId) {
        const res = await fetch('/shares/' + shareId, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                permissions: this.editingShareData.permissions,
                expires_at: this.editingShareData.expires_at || null
            })
        });

        if (res.ok) {
            const updated = await res.json();
            const index = this.fileShares.findIndex(s => s.id === shareId);
            if (index !== -1) {
                this.fileShares[index] = { ...this.fileShares[index], ...updated };
            }
            this.editingShareId = null;
            this.editingShareData = {};
            this.shareFeedback = { type: 'success', message: 'Enlace actualizado' };
            setTimeout(() => this.shareFeedback = { type: '', message: '' }, 3000);
        }
    },

    cancelEditShareLink() {
        this.editingShareId = null;
        this.editingShareData = {};
    },

    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    formatDate(dateString) {
        if (!dateString) return 'Nunca';
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },

    truncateUrl(url, maxLength = 30) {
        if (!url) return '';
        if (url.length <= maxLength) return url;
        return url.substring(0, maxLength) + '...';
    }
}" x-init="init()">
    <header class="bg-white shadow-sm border-b border-slate-200">
        <div class="px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-[#2451B8] rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-slate-800">Mis Archivos</h1>
                    <p class="text-xs text-slate-500" x-text="viewMode === 'storages' ? 'Selecciona un storage' : currentStorageName"></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <template x-if="viewMode === 'storages'">
                    <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg">
                        <button @click="setFilesViewMode('grid')" class="p-2 rounded-lg transition-colors" :class="filesViewMode === 'grid' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-200'">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                            </svg>
                        </button>
                        <button @click="setFilesViewMode('list')" class="p-2 rounded-lg transition-colors" :class="filesViewMode === 'list' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-200'">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                            </svg>
                        </button>
                    </div>
                </template>
                <template x-if="viewMode === 'files'">
                    <button @click="navigateToRoot()" class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                        </svg>
                        Volver a Storages
                    </button>
                </template>
                <button @click="showNewFolderModal = true" x-show="viewMode === 'files'" class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                    </svg>
                    Nueva Carpeta
                </button>
                <button @click="showUploadModal = true" x-show="viewMode === 'files'" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    Subir Archivo
                </button>
            </div>
        </div>
    </header>

    <main class="p-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-4 border-b border-slate-200 bg-slate-50" x-show="viewMode === 'files'">
                <nav class="flex items-center gap-2 text-sm">
                    <button @click="navigateToRoot()" class="flex items-center gap-1 text-blue-600 hover:text-blue-700 font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        Raíz
                    </button>
                    <template x-for="(crumb, index) in breadcrumbs" :key="index">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <button @click="navigateToBreadcrumb(crumb)" class="text-blue-600 hover:text-blue-700" x-text="crumb.name"></button>
                        </div>
                    </template>
                    <template x-if="currentFolderName">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span class="text-slate-600 font-medium" x-text="currentFolderName"></span>
                        </div>
                    </template>
                </nav>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4" x-show="viewMode === 'storages' && availableStorages.length > 0 && filesViewMode === 'grid'">
                    <template x-for="storage in availableStorages" :key="storage.id">
                        <div @click="enterStorage(storage.id, storage.name)" class="group bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 rounded-xl p-4 cursor-pointer transition-all">
                            <div class="flex flex-col items-center text-center">
                                <div class="w-16 h-16 bg-indigo-100 rounded-xl flex items-center justify-center mb-3">
                                    <svg class="w-10 h-10 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                                    </svg>
                                </div>
                                <p class="font-medium text-slate-700 text-sm truncate w-full" x-text="storage.name" :title="storage.name"></p>
                                <p class="text-xs text-slate-400 mt-1" x-text="storage.permissions"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="overflow-x-auto" x-show="viewMode === 'storages' && availableStorages.length > 0 && filesViewMode === 'list'">
                    <table class="w-full">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Nombre</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Permisos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <template x-for="storage in availableStorages" :key="storage.id">
                                <tr @click="enterStorage(storage.id, storage.name)" class="hover:bg-slate-50 cursor-pointer">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                <svg class="w-6 h-6 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                                                </svg>
                                            </div>
                                            <span class="font-medium text-slate-700" x-text="storage.name"></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 text-sm" x-text="storage.permissions"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4" x-show="viewMode === 'files' && files.length > 0 && filesViewMode === 'grid'">
                    <template x-for="file in files" :key="file.id">
                        <div class="group bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 rounded-xl p-4 cursor-pointer transition-all">
                            <div class="flex flex-col items-center text-center" @click="file.is_folder && navigateToFolder(file.id, file.name)">
                                <div class="w-16 h-16 rounded-xl flex items-center justify-center mb-3" :class="getFileIcon(file).bg">
                                    <template x-if="getFileIcon(file).icon === 'folder'">
                                        <svg class="w-10 h-10 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'video'">
                                        <svg class="w-10 h-10 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'music'">
                                        <svg class="w-10 h-10 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'pdf'">
                                        <svg class="w-10 h-10 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'word'">
                                        <svg class="w-10 h-10 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'excel'">
                                        <svg class="w-10 h-10 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'ppt'">
                                        <svg class="w-10 h-10 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'image'">
                                        <svg class="w-10 h-10 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'archive'">
                                        <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'code'">
                                        <svg class="w-10 h-10 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                        </svg>
                                    </template>
                                    <template x-if="getFileIcon(file).icon === 'file'">
                                        <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                        </svg>
                                    </template>
                                </div>
                                <p class="font-medium text-slate-700 text-sm truncate w-full" x-text="file.name" :title="file.name"></p>
                                <p class="text-xs text-slate-400 mt-1" x-text="file.is_folder ? 'Carpeta' : formatSize(file.size)"></p>
                            </div>
                            <div class="flex items-center justify-center mt-3 opacity-0 group-hover:opacity-100 transition-opacity gap-1">
                                <button @click="openDetailModal(file)" class="p-2 bg-white hover:bg-indigo-100 rounded-lg shadow-sm transition-colors" title="Compartir">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="overflow-x-auto" x-show="viewMode === 'files' && files.length > 0 && filesViewMode === 'list'">
                    <table class="w-full">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Nombre</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell">Tamaño</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell">Fecha</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <template x-for="file in files" :key="file.id">
                                <tr class="hover:bg-slate-50 cursor-pointer" @click="file.is_folder && navigateToFolder(file.id, file.name)">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" :class="getFileIcon(file).bg">
                                                <template x-if="getFileIcon(file).icon === 'folder'">
                                                    <svg class="w-6 h-6 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'video'">
                                                    <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'music'">
                                                    <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'pdf'">
                                                    <svg class="w-6 h-6 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'word'">
                                                    <svg class="w-6 h-6 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'excel'">
                                                    <svg class="w-6 h-6 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'ppt'">
                                                    <svg class="w-6 h-6 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'image'">
                                                    <svg class="w-6 h-6 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'archive'">
                                                    <svg class="w-6 h-6 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'code'">
                                                    <svg class="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                                    </svg>
                                                </template>
                                                <template x-if="getFileIcon(file).icon === 'file'">
                                                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                    </svg>
                                                </template>
                                            </div>
                                            <span class="font-medium text-slate-700 truncate" x-text="file.name"></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 text-sm hidden md:table-cell" x-text="file.is_folder ? '-' : formatSize(file.size)"></td>
                                    <td class="px-4 py-3 text-slate-500 text-sm hidden lg:table-cell" x-text="formatDate(file.created_at)"></td>
                                    <td class="px-4 py-3 text-right">
                                        <button @click.stop="openDetailModal(file)" class="p-2 hover:bg-slate-200 rounded-lg transition-colors" title="Compartir">
                                            <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div x-show="viewMode === 'storages' && availableStorages.length === 0" class="text-center py-16">
                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-slate-700 mb-2">No tienes storages asignados</h3>
                    <p class="text-slate-500">Contacta al administrador para que te asigne storages.</p>
                </div>

                <div x-show="viewMode === 'files' && files.length === 0" class="text-center py-16">
                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-slate-700 mb-2">No hay archivos</h3>
                    <p class="text-slate-500 mb-4">Sube un archivo o crea una carpeta para comenzar</p>
                    <button @click="showUploadModal = true" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        Subir Archivo
                    </button>
                </div>
            </div>
        </div>
    </main>

    <div x-cloak x-show="showUploadModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" x-transition>
        <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-xl" @click.away="showUploadModal = false">
            <h2 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Subir Archivo
            </h2>
            <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center hover:border-blue-400 transition-colors"
                 x-data="{ dragging: false }"
                 :class="dragging ? 'border-blue-500 bg-blue-50' : ''"
                 @dragover.prevent="dragging = true"
                 @dragleave.prevent="dragging = false"
                 @drop.prevent="dragging = false; uploadFile($event.dataTransfer.files[0])">
                <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                <p class="text-slate-600 mb-4">Arrastra archivos aquí o</p>
                <label class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg cursor-pointer transition-colors">
                    Seleccionar
                    <input type="file" class="hidden" @change="uploadFile($event.target.files[0])">
                </label>
            </div>
            <button @click="showUploadModal = false" class="mt-4 w-full bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                Cancelar
            </button>
        </div>
    </div>

    <div x-cloak x-show="showNewFolderModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" x-transition>
        <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-xl" @click.away="showNewFolderModal = false">
            <h2 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                <svg class="w-6 h-6 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                </svg>
                Nueva Carpeta
            </h2>
            <input type="text" placeholder="Nombre de la carpeta" x-ref="folderName" class="w-full border border-slate-300 px-4 py-3 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none mb-4">
            <div class="flex gap-3">
                <button @click="createFolder($refs.folderName.value)" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    Crear
                </button>
                <button @click="showNewFolderModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showDetailModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" x-transition>
        <div class="bg-white rounded-xl p-6 w-full max-w-lg shadow-xl max-h-[90vh] overflow-y-auto" @click.away="showDetailModal = false">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                    </svg>
                    <span x-text="selectedFile && selectedFile.is_folder ? 'Detalle de la Carpeta' : 'Detalle del Archivo'"></span>
                </h2>
                <button @click="showDetailModal = false" class="text-slate-400 hover:text-slate-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <template x-if="selectedFile">
                <div>
                    <div class="bg-slate-50 rounded-lg p-4 mb-4">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center" :class="getFileIcon(selectedFile).bg">
                                <svg class="w-6 h-6" :class="getFileIcon(selectedFile).icon === 'folder' ? 'text-amber-500' : 'text-slate-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-800" x-text="selectedFile.name"></p>
                                <p class="text-sm text-slate-500" x-text="selectedFile.is_folder ? 'Carpeta' : formatSize(selectedFile.size)"></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-slate-500">Nombre:</p>
                                <p class="font-medium text-slate-700" x-text="selectedFile.name"></p>
                            </div>
                            <div x-show="!selectedFile.is_folder">
                                <p class="text-slate-500">Tamaño:</p>
                                <p class="font-medium text-slate-700" x-text="formatSize(selectedFile.size)"></p>
                            </div>
                            <div>
                                <p class="text-slate-500">Fecha de creación:</p>
                                <p class="font-medium text-slate-700" x-text="selectedFile.created_at ? formatDate(selectedFile.created_at) : 'N/A'"></p>
                            </div>
                            <div x-show="!selectedFile.is_folder">
                                <p class="text-slate-500">Tipo:</p>
                                <p class="font-medium text-slate-700" x-text="selectedFile.mime_type || 'Archivo'"></p>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2" x-show="!selectedFile.is_folder">
                            <a :href="'/files/' + selectedFile.id + '/download'" class="flex-1 flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-colors text-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Descargar
                            </a>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 pt-4">
                        <h3 class="font-semibold text-slate-800 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                            </svg>
                            Enlaces Compartidos
                        </h3>

                        <div x-show="shareFeedback.message" class="mb-3 p-2 rounded-lg text-sm" :class="shareFeedback.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="shareFeedback.message"></div>

                        <div class="mb-4 bg-purple-50 rounded-lg p-3">
                            <h4 class="text-sm font-medium text-purple-800 mb-2">Generar nuevo enlace</h4>
                            <div class="grid grid-cols-2 gap-2 mb-2">
                                <select x-model="shareForm.permissions" class="w-full border border-purple-200 px-3 py-1.5 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                                    <option value="read">Lectura</option>
                                    <option value="write">Escritura</option>
                                    <option value="upload">Subida</option>
                                    <option value="full">Completo</option>
                                </select>
                                <input type="datetime-local" x-model="shareForm.expires_at" class="w-full border border-purple-200 px-3 py-1.5 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none" placeholder="Expira (opcional)">
                            </div>
                            <input type="password" x-model="shareForm.password" class="w-full border border-purple-200 px-3 py-1.5 rounded-lg text-sm mb-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none" placeholder="Contraseña (opcional)">
                            <button @click="generateShareLink()" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-lg text-sm transition-colors flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                                Generar Enlace
                            </button>
                        </div>

                        <div x-show="fileShares.length === 0" class="text-center py-6 text-slate-500 text-sm">
                            No hay enlaces compartidos para este archivo
                        </div>

                        <div class="space-y-2" x-show="fileShares.length > 0">
                            <template x-for="share in fileShares" :key="share.id">
                                <div class="border border-slate-200 rounded-lg p-3">
                                    <template x-if="editingShareId !== share.id">
                                        <div>
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="px-2 py-0.5 rounded text-xs" :class="{
                                                    'bg-gray-100 text-gray-800': share.permissions === 'read',
                                                    'bg-blue-100 text-blue-800': share.permissions === 'write',
                                                    'bg-yellow-100 text-yellow-800': share.permissions === 'upload',
                                                    'bg-green-100 text-green-800': share.permissions === 'full'
                                                }" x-text="share.permissions"></span>
                                                <span class="text-xs text-slate-500" x-text="formatDate(share.created_at)"></span>
                                            </div>
                                            <p class="text-sm text-slate-600 mb-2 truncate" :title="window.location.origin + '/s/' + share.token" x-text="truncateUrl(window.location.origin + '/s/' + share.token, 40)"></p>
                                            <div class="flex gap-2">
                                                <button @click="copyShareLink(share.token)" class="flex-1 flex items-center justify-center gap-1 bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded text-xs transition-colors">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                                    </svg>
                                                    Copiar
                                                </button>
                                                <button @click="editShareLink(share)" class="flex-1 flex items-center justify-center gap-1 bg-amber-100 hover:bg-amber-200 text-amber-700 px-2 py-1 rounded text-xs transition-colors">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                    </svg>
                                                    Editar
                                                </button>
                                                <button @click="deleteShareLink(share.id)" class="flex items-center justify-center gap-1 bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded text-xs transition-colors">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="editingShareId === share.id">
                                        <div>
                                            <div class="grid grid-cols-2 gap-2 mb-2">
                                                <select x-model="editingShareData.permissions" class="w-full border border-slate-300 px-2 py-1 rounded text-sm">
                                                    <option value="read">Lectura</option>
                                                    <option value="write">Escritura</option>
                                                    <option value="upload">Subida</option>
                                                    <option value="full">Completo</option>
                                                </select>
                                                <input type="datetime-local" x-model="editingShareData.expires_at" class="w-full border border-slate-300 px-2 py-1 rounded text-sm">
                                            </div>
                                            <div class="flex gap-2">
                                                <button @click="saveShareLink(share.id)" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs transition-colors">
                                                    Guardar
                                                </button>
                                                <button @click="cancelEditShareLink()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1 rounded text-xs transition-colors">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/files/index.blade.php ENDPATH**/ ?>