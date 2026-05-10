@extends('layouts.app')

@section('title', 'Archivos - Tcloud')

@section('content')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('fileManager', () => ({
    files: [],
    availableStorages: [],
    currentFolder: null,
    currentFolderName: null,
    currentStorage: null,
    currentStorageName: null,
    currentStoragePermission: 'read',
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
    viewerOpen: false,
    currentViewerFile: null,
    sortField: 'name',
    sortDir: 'asc',
    uploadQueue: [],
    dragOverMain: false,
    dragDepth: 0,
    imgScale: 1,
    imgRotation: 0,
    imgPanX: 0,
    imgPanY: 0,
    imgDragging: false,
    imgDragStart: { x: 0, y: 0 },
    imgSaving: false,
    viewerIndex: null,
    viewerFiles: [],
    renamingFileId: null,
    renamingFileName: '',
deleteConfirmFile: null,
        ready: false,
        searchQuery: '',
        searchMode: false,
        searchTimer: null,
        canUseMediaEditor: false,
        showClipModal: false,
        clipFile: null,
        clipProcessing: false,
        clipError: '',
        clipDuration: 0,
        clipCurrentTime: 0,
        clipPlaying: false,
        clipReady: false,
        clipSelStart: null,
        clipSelEnd: null,
        clipDragging: false,
        clipDragType: null,
        clipSequence: [],
        clipShowAddFile: false,
        clipAddFileList: [],
        clipAddFileLoading: false,
        clipUndoStack: [],
        clipPreviewing: false,
        clipPreviewUrl: '',
        clipPreviewMime: '',
        showClipHistory: false,
        clipHistory: [],
        clipHistoryLoading: false,

        async init() {
            await Promise.all([
                this.loadStorages(),
                fetch('/auth/me', { credentials: 'include', headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(d => { if (d) this.canUseMediaEditor = !!d.can_use_media_editor; })
            ]);
            await this.restoreNavState();
            this.ready = true;
            this.$watch('searchQuery', (val) => {
                clearTimeout(this.searchTimer);
                if (val.length >= 2) {
                    this.searchTimer = setTimeout(() => this.searchFiles(), 350);
                } else if (val.length === 0) {
                    this.clearSearch();
                }
            });
        },

    canUpload() {
        return ['write', 'upload', 'full'].includes(this.currentStoragePermission);
    },

    canCreateFolders() {
        return ['write', 'full'].includes(this.currentStoragePermission);
    },

    saveNavState() {
        localStorage.setItem('tcloud_files_nav', JSON.stringify({
            storageId: this.currentStorage,
            storageName: this.currentStorageName,
            storagePermission: this.currentStoragePermission,
            folderId: this.currentFolder,
            folderName: this.currentFolderName,
            breadcrumbs: this.breadcrumbs,
            viewMode: this.viewMode
        }));
    },

    clearNavState() {
        localStorage.removeItem('tcloud_files_nav');
    },

    async restoreNavState() {
        try {
            const saved = localStorage.getItem('tcloud_files_nav');
            if (!saved) return;
            const state = JSON.parse(saved);
            if (!state.storageId) return;
            this.currentStorage = state.storageId;
            this.currentStorageName = state.storageName;
            const storage = this.availableStorages.find(s => s.id === state.storageId);
            this.currentStoragePermission = storage ? storage.permissions : 'read';
            this.currentFolder = state.folderId || null;
            this.currentFolderName = state.folderName || null;
            this.breadcrumbs = state.breadcrumbs || [];
            this.viewMode = 'files';
            this.loadFiles();
        } catch (e) {
            this.clearNavState();
        }
    },

    setFilesViewMode(mode) {
        this.filesViewMode = mode;
        localStorage.setItem('files_view_mode', mode);
        this.saveNavState();
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
            if (this.availableStorages.length === 1) {
                this.enterStorage(this.availableStorages[0].id, this.availableStorages[0].name);
            }
        }
    },

    enterStorage(storageId, storageName) {
        this.currentStorage = storageId;
        this.currentStorageName = storageName;
        const storage = this.availableStorages.find(s => s.id === storageId);
        this.currentStoragePermission = storage ? storage.permissions : 'read';
        this.currentFolder = null;
        this.currentFolderName = null;
        this.breadcrumbs = [];
        this.viewMode = 'files';
        this.loadFiles();
        this.saveNavState();
    },

    navigateToRoot() {
        this.currentStorage = null;
        this.currentStorageName = null;
        this.currentFolder = null;
        this.currentFolderName = null;
        this.breadcrumbs = [];
        this.viewMode = 'storages';
        this.files = [];
        this.clearNavState();
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
        this.saveNavState();
    },

    navigateToBreadcrumb(breadcrumb) {
        const index = this.breadcrumbs.indexOf(breadcrumb);
        if (index > -1) {
            this.breadcrumbs = this.breadcrumbs.slice(0, index);
        }
        this.currentFolder = breadcrumb.id;
        this.currentFolderName = breadcrumb.name === 'Raíz' ? null : breadcrumb.name;
        this.loadFiles();
        this.saveNavState();
    },

    uploadErrorMessage(status) {
        const map = { 409: 'Ya existe un archivo con ese nombre', 413: 'Cuota de almacenamiento excedida', 403: 'Sin permisos de escritura en este storage', 422: 'Debes estar dentro de un storage para subir' };
        return map[status] || 'Error al subir el archivo (' + status + ')';
    },

    uploadFile(file, queueIndex) {
        return new Promise((resolve) => {
            if (!this.currentStorage) {
                this.uploadQueue[queueIndex].error = 'Debes estar dentro de un storage para subir';
                resolve();
                return;
            }
            const formData = new FormData();
            formData.append('file', file);
            formData.append('parent_id', this.currentFolder || '');
            formData.append('storage_id', this.currentStorage);
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    this.uploadQueue[queueIndex].progress = Math.round((e.loaded / e.total) * 100);
                }
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    this.uploadQueue[queueIndex].progress = 100;
                    this.uploadQueue[queueIndex].done = true;
                    this.loadFiles();
                } else {
                    this.uploadQueue[queueIndex].error = this.uploadErrorMessage(xhr.status);
                }
                resolve();
            };
            xhr.onerror = () => {
                this.uploadQueue[queueIndex].error = 'Error de red al subir el archivo';
                resolve();
            };
            xhr.open('POST', '/files/upload');
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.withCredentials = true;
            xhr.send(formData);
        });
    },

    async uploadFiles(fileList) {
        if (!fileList || fileList.length === 0) return;
        const files = Array.from(fileList);
        const startIndex = this.uploadQueue.length;
        files.forEach(f => this.uploadQueue.push({ name: f.name, progress: 0, error: null, done: false }));
        this.showUploadModal = true;
        for (let i = 0; i < files.length; i++) {
            await this.uploadFile(files[i], startIndex + i);
        }
    },

    resetImgTransform() {
        this.imgScale = 1;
        this.imgRotation = 0;
        this.imgPanX = 0;
        this.imgPanY = 0;
        this.imgDragging = false;
    },

    async saveImgRotation() {
        if (!this.currentViewerFile) return;
        const norm = ((this.imgRotation % 360) + 360) % 360;
        if (norm === 0) return;
        this.imgSaving = true;
        try {
            const res = await fetch('/files/' + this.currentViewerFile.id + '/rotate', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ degrees: norm }),
            });
            if (res.ok) {
                this.imgRotation = 0;
                this.imgPanX = 0;
                this.imgPanY = 0;
                // Forzar recarga de la imagen añadiendo timestamp para romper caché
                this.currentViewerFile = { ...this.currentViewerFile, _v: Date.now() };
            } else {
                const data = await res.json();
                alert('Error al guardar: ' + (data.error || res.status));
            }
        } finally {
            this.imgSaving = false;
        }
    },

    imgViewerStyle() {
        const norm = ((this.imgRotation % 360) + 360) % 360;
        const transposed = norm === 90 || norm === 270;
        const maxW = transposed ? 'calc(100vh - 120px)' : '100%';
        const maxH = transposed ? '100vw' : 'calc(100vh - 120px)';
        const tr = `translate(${this.imgPanX}px, ${this.imgPanY}px) rotate(${this.imgRotation}deg) scale(${this.imgScale})`;
        const transition = this.imgDragging ? 'none' : 'transform 0.15s ease';
        return `transform: ${tr}; transform-origin: center; transition: ${transition}; max-width: ${maxW}; max-height: ${maxH};`;
    },

    zoomImg(delta) {
        this.imgScale = Math.min(5, Math.max(0.2, this.imgScale + delta));
    },

    rotateImg(deg) {
        this.imgRotation = this.imgRotation + deg;
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

    isVideo(mime) {
        return mime && mime.startsWith('video/');
    },

    isAudio(mime) {
        return mime && mime.startsWith('audio/');
    },

    isImage(mime) {
        return mime && mime.startsWith('image/');
    },

    isPdf(mime) {
        return mime === 'application/pdf';
    },

    getViewerUrl(file) {
        return '/media/' + file.id + '/preview' + (file._v ? '?v=' + file._v : '');
    },

    openViewer(file) {
        if (file.is_folder) return;
        this.resetImgTransform();
        if (!this.viewerOpen) {
            this.viewerFiles = this.sortedFiles().filter(f => !f.is_folder);
        }
        this.viewerIndex = this.viewerFiles.findIndex(f => f.id === file.id);
        this.currentViewerFile = file;
        this.viewerOpen = true;
        const url = this.getViewerUrl(file);
        const mime = file.mime_type || '';
        this.$nextTick(() => {
            if (this.isVideo(mime) && this.$refs.videoplayer) {
                this.$refs.videoplayer.src = url;
                this.$refs.videoplayer.load();
            } else if (this.isAudio(mime) && this.$refs.audioplayer) {
                this.$refs.audioplayer.src = url;
                this.$refs.audioplayer.load();
            }
        });
    },

    closeViewer() {
        if (this.$refs.videoplayer) {
            this.$refs.videoplayer.pause();
            this.$refs.videoplayer.src = '';
        }
        if (this.$refs.audioplayer) {
            this.$refs.audioplayer.pause();
            this.$refs.audioplayer.src = '';
        }
        this.viewerOpen = false;
        this.currentViewerFile = null;
        this.viewerIndex = null;
        this.viewerFiles = [];
    },

    startRename(file) {
        this.renamingFileId = file.id;
        this.renamingFileName = file.name;
        this.$nextTick(() => {
            const input = document.getElementById('rename-input-' + file.id);
            if (input) { input.focus(); input.select(); }
        });
    },

    async saveRename(file) {
        const newName = this.renamingFileName.trim();
        this.renamingFileId = null;
        if (!newName || newName === file.name) return;
        const res = await fetch('/files/' + file.id, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ name: newName })
        });
        if (res.ok) {
            const updated = await res.json();
            const idx = this.files.findIndex(f => f.id === file.id);
            if (idx !== -1) this.files[idx] = { ...this.files[idx], name: updated.name };
        }
    },

    deleteFile(file) {
        this.deleteConfirmFile = file;
    },

    async executeDeleteFile() {
        const file = this.deleteConfirmFile;
        this.deleteConfirmFile = null;
        if (!file) return;
        const res = await fetch('/files/' + file.id, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        if (res.ok) {
            this.files = this.files.filter(f => f.id !== file.id);
        }
    },

    viewerNext() {
        if (this.viewerIndex !== null && this.viewerIndex < this.viewerFiles.length - 1) {
            this.openViewer(this.viewerFiles[this.viewerIndex + 1]);
        }
    },

    viewerPrev() {
        if (this.viewerIndex !== null && this.viewerIndex > 0) {
            this.openViewer(this.viewerFiles[this.viewerIndex - 1]);
        }
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

    sortFiles(field) {
        if (this.sortField === field) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortField = field;
            this.sortDir = 'asc';
        }
    },

    sortedFiles() {
        const arr = [...this.files];
        const field = this.sortField;
        const dir = this.sortDir === 'asc' ? 1 : -1;
        return arr.sort((a, b) => {
            if (a.is_folder && !b.is_folder) return -1;
            if (!a.is_folder && b.is_folder) return 1;
            if (field === 'name') {
                const na = (a.name || '').toLowerCase();
                const nb = (b.name || '').toLowerCase();
                return na < nb ? -dir : na > nb ? dir : 0;
            }
            if (field === 'size') {
                return ((a.size || 0) - (b.size || 0)) * dir;
            }
            if (field === 'date') {
                const da = a.created_at ? new Date(a.created_at).getTime() : 0;
                const db = b.created_at ? new Date(b.created_at).getTime() : 0;
                return (da - db) * dir;
            }
            return 0;
        });
    },

    formatDate(dateString) {
        if (!dateString) return 'Nunca';
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },

    truncateUrl(url, maxLength = 30) {
        if (!url) return '';
        if (url.length <= maxLength) return url;
        return url.substring(0, maxLength) + '...';
    },

    async searchFiles() {
        if (!this.currentStorage || this.searchQuery.length < 2) return;
        let url = '/files?q=' + encodeURIComponent(this.searchQuery) + '&storage_id=' + this.currentStorage;
        const res = await fetch(url, {
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            this.files = await res.json();
            this.searchMode = true;
        }
    },

    clearSearch() {
        clearTimeout(this.searchTimer);
        this.searchQuery = '';
        this.searchMode = false;
        this.loadFiles();
    },

    isClippable(file) {
        if (file.is_folder || !this.canUseMediaEditor) return false;
        const ext = (file.name || '').split('.').pop().toLowerCase();
        if (!['mp4', 'mp3', 'm4a'].includes(ext)) return false;
        const storage = this.availableStorages.find(s => s.id === this.currentStorage);
        return storage && storage.type === 'local';
    },

    openClipEditor(file) {
        this.clipFile = file;
        this.clipError = '';
        this.clipProcessing = false;
        this.clipDuration = 0;
        this.clipCurrentTime = 0;
        this.clipPlaying = false;
        this.clipReady = false;
        this.clipSelStart = null;
        this.clipSelEnd = null;
        this.clipDragging = false;
        this.clipDragType = null;
        this.clipSequence = [];
        this.clipShowAddFile = false;
        this.clipAddFileList = [];
        this.showClipModal = true;
        this.$nextTick(() => this.initClipPlayer(file));
    },

    closeClipModal() {
        const el = document.getElementById('clip-media-el');
        if (el) { try { el.pause(); el.removeAttribute('src'); el.load(); } catch(e) {} }
        this.clipPlaying = false;
        this.clipDragging = false;
        this.showClipModal = false;
    },

    initClipPlayer(file) {
        const el = document.getElementById('clip-media-el');
        if (!el) return;
        const self = this;
        el.onloadedmetadata = () => {
            self.clipDuration = el.duration;
            self.clipReady = true;
            if (el.tagName === 'VIDEO') el.currentTime = 0.5;
        };
        el.ontimeupdate  = () => { self.clipCurrentTime = el.currentTime; };
        el.onplay        = () => { self.clipPlaying = true; };
        el.onpause       = () => { self.clipPlaying = false; };
        el.onended       = () => { self.clipPlaying = false; };
        el.onerror       = () => { self.clipError = 'Error cargando el archivo de medios.'; };
        el.src = '/media/' + file.id + '/preview';
        el.load();
    },

    clipTogglePlay() {
        const el = document.getElementById('clip-media-el');
        if (!el || !this.clipReady) return;
        if (el.paused) el.play(); else el.pause();
    },

    clipPlaySelection() {
        if (this.clipSelStart === null || !this.clipReady) return;
        const el = document.getElementById('clip-media-el');
        if (!el) return;
        const start = Math.min(this.clipSelStart, this.clipSelEnd ?? this.clipSelStart);
        const end   = Math.max(this.clipSelStart, this.clipSelEnd ?? this.clipSelStart);
        el.currentTime = start;
        el.play();
        const stop = () => { if (el.currentTime >= end) { el.pause(); el.removeEventListener('timeupdate', stop); } };
        el.addEventListener('timeupdate', stop);
    },

    // ── Timeline interaction ───────────────────────────────────────
    _clipTlPct(e) {
        const tl = this.$refs.clipTimeline;
        if (!tl || !this.clipDuration) return 0;
        const rect = tl.getBoundingClientRect();
        return Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    },

    clipTimelineMd(e) {
        if (!this.clipReady || !this.clipDuration) return;
        const pct = this._clipTlPct(e);
        const t   = pct * this.clipDuration;
        // Check proximity to existing handle (within ~10px)
        if (this.clipSelStart !== null && this.clipSelEnd !== null) {
            const tl = this.$refs.clipTimeline;
            const w  = tl ? tl.getBoundingClientRect().width : 1;
            if (Math.abs((this.clipSelStart / this.clipDuration) - pct) * w < 12) {
                this.clipDragging = true; this.clipDragType = 'sel-start'; return;
            }
            if (Math.abs((this.clipSelEnd / this.clipDuration) - pct) * w < 12) {
                this.clipDragging = true; this.clipDragType = 'sel-end'; return;
            }
        }
        // New selection
        this.clipDragging  = true;
        this.clipDragType  = 'new-sel';
        this.clipSelStart  = t;
        this.clipSelEnd    = t;
        const el = document.getElementById('clip-media-el');
        if (el) el.currentTime = t;
    },

    clipHandleMd(type, e) {
        e.stopPropagation();
        this.clipDragging = true;
        this.clipDragType = type === 'start' ? 'sel-start' : 'sel-end';
    },

    clipTimelineMm(e) {
        if (!this.clipDragging || !this.clipDuration) return;
        const t = this._clipTlPct(e) * this.clipDuration;
        if (this.clipDragType === 'new-sel') {
            if (t >= this.clipSelStart) { this.clipSelEnd = t; }
            else { this.clipSelEnd = this.clipSelStart; this.clipSelStart = t; }
        } else if (this.clipDragType === 'sel-start') {
            this.clipSelStart = Math.min(t, this.clipSelEnd - 0.05);
        } else if (this.clipDragType === 'sel-end') {
            this.clipSelEnd = Math.max(t, this.clipSelStart + 0.05);
        }
    },

    clipTimelineMu() {
        this.clipDragging = false;
        this.clipDragType = null;
        // snap: seek player to selection start
        if (this.clipSelStart !== null) {
            const el = document.getElementById('clip-media-el');
            if (el) el.currentTime = Math.min(this.clipSelStart, this.clipSelEnd ?? this.clipSelStart);
        }
    },

    clipSelStyle() {
        if (this.clipSelStart === null || this.clipSelEnd === null || !this.clipDuration) return 'display:none';
        const l = (Math.min(this.clipSelStart, this.clipSelEnd) / this.clipDuration * 100).toFixed(3);
        const w = (Math.abs(this.clipSelEnd - this.clipSelStart) / this.clipDuration * 100).toFixed(3);
        return `left:${l}%; width:${w}%`;
    },

    clipTimelineTicks() {
        if (!this.clipDuration || this.clipDuration <= 0) return [];
        const d = this.clipDuration;
        let iv = d < 60 ? 5 : d < 180 ? 15 : d < 600 ? 30 : d < 3600 ? 300 : 600;
        const ticks = [];
        for (let t = 0; t <= d; t += iv) {
            const m = Math.floor(t / 60), s = Math.floor(t % 60);
            ticks.push({ t, pct: (t / d * 100).toFixed(2), label: m + ':' + String(s).padStart(2,'0') });
        }
        return ticks;
    },

    // ── Sequence management ───────────────────────────────────────
    clipAddToSequence() {
        if (this.clipSelStart === null || this.clipSelEnd === null) return;
        const start = +Math.min(this.clipSelStart, this.clipSelEnd).toFixed(3);
        const end   = +Math.max(this.clipSelStart, this.clipSelEnd).toFixed(3);
        if (end - start < 0.05) return;
        this.clipPushUndo();
        this.clipSequence.push({ id: Date.now() + Math.random(), type: 'segment',
            fileId: this.clipFile.id, fileName: this.clipFile.name, start, end });
        this.clipSelStart = null;
        this.clipSelEnd   = null;
    },

    clipRemoveSeq(idx) { this.clipPushUndo(); this.clipSequence.splice(idx, 1); },

    clipMoveSeq(idx, dir) {
        const ni = idx + dir;
        if (ni < 0 || ni >= this.clipSequence.length) return;
        this.clipPushUndo();
        [this.clipSequence[idx], this.clipSequence[ni]] = [this.clipSequence[ni], this.clipSequence[idx]];
    },

    clipSeqTotalDuration() {
        return this.clipSequence.reduce((s, item) =>
            s + (item.type === 'segment' ? (item.end - item.start) : (item.duration || 0)), 0);
    },

    async clipLoadAddFiles() {
        this.clipAddFileLoading = true;
        try {
            const res  = await fetch('/files?storage=' + this.currentStorage + '&per_page=200',
                { credentials: 'include', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            const ok   = ['mp4', 'mp3', 'm4a'];
            this.clipAddFileList = (data.files || data.data || []).filter(f => {
                if (f.is_folder || f.id === this.clipFile.id) return false;
                return ok.includes((f.name || '').split('.').pop().toLowerCase());
            });
        } catch(e) { this.clipAddFileList = []; }
        finally    { this.clipAddFileLoading = false; }
    },

    clipAddFileToSeq(file, position) {
        const item = { id: Date.now() + Math.random(), type: 'full',
            fileId: file.id, fileName: file.name, duration: 0 };
        if (position === 'start') this.clipSequence.unshift(item);
        else this.clipSequence.push(item);
        this.clipShowAddFile = false;
    },

    // ── Shared helpers ────────────────────────────────────────────
    formatClipTime(s) {
        if (!s && s !== 0) return '0:00';
        const h  = Math.floor(s / 3600);
        const m  = Math.floor((s % 3600) / 60);
        const sc = Math.floor(s % 60);
        const ms = Math.floor((s % 1) * 10);
        if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(sc).padStart(2,'0')}`;
        return `${m}:${String(sc).padStart(2,'0')}.${ms}`;
    },

    async generateClip() {
        this.clipError = '';
        if (this.clipSequence.length === 0) {
            this.clipError = 'Agrega al menos un segmento a la secuencia.';
            return;
        }
        this.clipProcessing = true;
        const el = document.getElementById('clip-media-el');
        if (el) el.pause();
        try {
            const sequence = this.clipSequence.map(item =>
                item.type === 'segment'
                    ? { fileId: item.fileId, start: item.start, end: item.end }
                    : { fileId: item.fileId });
            const res = await fetch('/files/' + this.clipFile.id + '/clip', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ sequence }),
            });
            if (!res.ok) {
                const data = await res.json();
                this.clipError = data.error || 'Error al generar el corte.';
                return;
            }
            const blob = await res.blob();
            const ext      = (this.clipFile.name || '').split('.').pop().toLowerCase();
            const filename = this.clipFile.name.replace(/\.[^.]+$/, '') + '_corte.' + ext;
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename;
            document.body.appendChild(a); a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            this.closeClipModal();
        } catch (e) {
            this.clipError = 'Error de red: ' + e.message;
        } finally {
            this.clipProcessing = false;
        }
    },

    // ── Preview before export ──────────────────────────────────────
    async clipPreview() {
        this.clipError = '';
        this.clipPreviewing = true;
        this.clipPreviewUrl = '';
        const el = document.getElementById('clip-media-el');
        if (el) el.pause();
        try {
            const sequence = this.clipSequence.map(item =>
                item.type === 'segment'
                    ? { fileId: item.fileId, start: item.start, end: item.end }
                    : { fileId: item.fileId });
            const res = await fetch('/files/' + this.clipFile.id + '/clip?preview=1', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ sequence }),
            });
            if (!res.ok) {
                const data = await res.json();
                this.clipError = data.error || 'Error al generar preview.';
                return;
            }
            const data = await res.json();
            this.clipPreviewUrl = data.preview_url;
            this.clipPreviewMime = data.mime || 'video/mp4';
        } catch (e) {
            this.clipError = 'Error de red: ' + e.message;
        } finally {
            this.clipPreviewing = false;
        }
    },

    clipClosePreview() {
        this.clipPreviewUrl = '';
        this.clipPreviewMime = '';
    },

    // ── Undo / Revert ─────────────────────────────────────────────
    clipPushUndo() {
        this.clipUndoStack.push(JSON.stringify(this.clipSequence));
        if (this.clipUndoStack.length > 30) this.clipUndoStack.shift();
    },

    clipUndo() {
        if (this.clipUndoStack.length === 0) return;
        this.clipSequence = JSON.parse(this.clipUndoStack.pop());
    },

    // ── History ────────────────────────────────────────────────────
    async clipLoadHistory() {
        this.clipHistoryLoading = true;
        this.showClipHistory = true;
        this.clipHistory = [];
        try {
            const res = await fetch('/media-clip/history', {
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (res.ok) this.clipHistory = await res.json();
        } catch (e) { /* ignore */ }
        finally { this.clipHistoryLoading = false; }
    },

    async clipReclip(jobId) {
        try {
            const res = await fetch('/media-clip/' + jobId + '/reclip', {
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) return;
            const data = await res.json();
            const sourceFile = (this.files || []).find(f => f.id === data.source_file_id);
            if (sourceFile) {
                this.clipFile = sourceFile;
                this.clipSequence = [];
                this.clipUndoStack = [];
                for (const seg of (data.segments || [])) {
                    if (seg.fileId && seg.start !== undefined && seg.end !== undefined) {
                        const segFile = (this.files || []).find(f => f.id === seg.fileId) || sourceFile;
                        this.clipSequence.push({
                            id: Date.now() + Math.random(),
                            type: 'segment',
                            fileId: seg.fileId || data.source_file_id,
                            fileName: segFile.name || data.source_file_name,
                            start: seg.start,
                            end: seg.end
                        });
                    }
                }
                this.showClipHistory = false;
                this.showClipModal = true;
                this.$nextTick(() => this.initClipPlayer(sourceFile));
            }
        } catch (e) { /* ignore */ }
    }
    }));
});
</script>
<div class="min-h-screen bg-slate-100" x-data="fileManager()" x-init="init()" x-show="ready" x-cloak>
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
                    <div class="flex items-center gap-2">
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
                        <button @click="navigateToRoot()" class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                            </svg>
                            Volver a Storages
                        </button>
                    </div>
                </template>
                <button @click="showNewFolderModal = true" x-show="viewMode === 'files' && canCreateFolders()" class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                    </svg>
                    Nueva Carpeta
                </button>
                <button @click="uploadQueue = []; showUploadModal = true" x-show="viewMode === 'files' && canUpload()" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    Subir Archivo
                </button>
            </div>
        </div>
    </header>

    <main class="p-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden relative"
             @dragenter.prevent="if(viewMode === 'files' && canUpload()) { dragDepth++; dragOverMain = true; }"
             @dragleave.prevent="dragDepth--; if(dragDepth <= 0) { dragDepth = 0; dragOverMain = false; }"
             @dragover.prevent
             @drop.prevent="dragDepth = 0; dragOverMain = false; if(viewMode === 'files' && canUpload()) uploadFiles($event.dataTransfer.files)">
            <div x-show="dragOverMain && viewMode === 'files' && canUpload()"
                 class="absolute inset-0 z-40 bg-blue-500/20 border-4 border-dashed border-blue-500 rounded-xl flex items-center justify-center pointer-events-none">
                <div class="bg-white rounded-xl px-8 py-6 shadow-xl text-center">
                    <svg class="w-12 h-12 text-blue-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-blue-700 font-semibold text-lg">Suelta para subir</p>
                    <p class="text-blue-500 text-sm mt-1" x-text="currentFolderName ? 'En: ' + currentFolderName : 'En: ' + currentStorageName"></p>
                </div>
            </div>
            <div class="p-4 border-b border-slate-200 bg-slate-50" x-show="viewMode === 'files'">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <!-- Breadcrumb normal -->
                    <nav x-show="!searchMode" class="flex items-center gap-2 text-sm flex-1 min-w-0">
                        <button @click="navigateToRoot()" class="flex items-center gap-1 text-blue-600 hover:text-blue-700 font-medium whitespace-nowrap">
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
                    <!-- Indicador de modo búsqueda -->
                    <div x-show="searchMode" class="flex items-center gap-2 flex-1 min-w-0">
                        <svg class="w-4 h-4 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                        </svg>
                        <span class="text-sm text-slate-600">Resultados para:</span>
                        <span class="text-sm font-semibold text-blue-700 truncate" x-text="'«' + searchQuery + '»'"></span>
                        <span class="text-xs text-slate-400" x-text="'(' + files.length + ' resultado' + (files.length !== 1 ? 's' : '') + ')'"></span>
                        <button @click="clearSearch()" class="ml-1 flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 hover:underline whitespace-nowrap">
                            ← Volver a carpeta
                        </button>
                    </div>
                    <!-- Cuadro de búsqueda -->
                    <div class="relative w-72 flex-shrink-0">
                        <input type="text" x-model="searchQuery"
                               :disabled="!currentStorage"
                               placeholder="Buscar archivos..."
                               :class="!currentStorage ? 'opacity-50 cursor-not-allowed bg-slate-100' : 'bg-white'"
                               class="w-full border border-slate-300 rounded-lg pl-9 pr-8 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
                        <svg class="absolute left-3 top-2.5 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                        </svg>
                        <button x-show="searchQuery" @click="clearSearch()"
                                class="absolute right-2.5 top-2 text-slate-400 hover:text-slate-700 text-lg leading-none font-medium">×</button>
                    </div>
                </div>
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
                    <template x-for="file in sortedFiles()" :key="file.id">
                        <div class="group bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 rounded-xl p-4 cursor-pointer transition-all">
                            <div class="flex flex-col items-center text-center" @click="file.is_folder ? navigateToFolder(file.id, file.name) : openViewer(file)">
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
                                <p x-show="renamingFileId !== file.id" class="font-medium text-slate-700 text-sm truncate w-full" x-text="file.name" :title="file.name"></p>
                                <input x-show="renamingFileId === file.id"
                                       :id="'rename-input-' + file.id"
                                       x-model="renamingFileName"
                                       @click.stop
                                       @keydown.enter.stop="saveRename(file)"
                                       @keydown.escape.stop="renamingFileId = null"
                                       @blur="saveRename(file)"
                                       class="border border-blue-400 px-2 py-0.5 rounded text-sm w-full text-center font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <p class="text-xs text-slate-400 mt-1" x-text="file.is_folder ? 'Carpeta' : formatSize(file.size)"></p>
                            </div>
                            <div class="flex items-center justify-center mt-3 opacity-0 group-hover:opacity-100 transition-opacity gap-1">
                                <button @click.stop="openDetailModal(file)" class="p-2 bg-white hover:bg-indigo-100 rounded-lg shadow-sm transition-colors" title="Compartir">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                    </svg>
                                </button>
                                <button x-show="isClippable(file)" @click.stop="openClipEditor(file)" class="p-2 bg-white hover:bg-violet-100 rounded-lg shadow-sm transition-colors" title="Editor de corte">
                                    <svg class="w-4 h-4 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z"/>
                                    </svg>
                                </button>
                                <button @click.stop="startRename(file)" class="p-2 bg-white hover:bg-amber-100 rounded-lg shadow-sm transition-colors" title="Renombrar">
                                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button @click.stop="deleteFile(file)" class="p-2 bg-white hover:bg-red-100 rounded-lg shadow-sm transition-colors" title="Eliminar">
                                    <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
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
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:bg-slate-100" @click="sortFiles('name')">
                                    <span class="flex items-center gap-1">
                                        Nombre
                                        <span x-show="sortField === 'name'" x-text="sortDir === 'asc' ? '↑' : '↓'" class="text-blue-500"></span>
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell cursor-pointer select-none hover:bg-slate-100" @click="sortFiles('size')">
                                    <span class="flex items-center gap-1">
                                        Tamaño
                                        <span x-show="sortField === 'size'" x-text="sortDir === 'asc' ? '↑' : '↓'" class="text-blue-500"></span>
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell cursor-pointer select-none hover:bg-slate-100" @click="sortFiles('date')">
                                    <span class="flex items-center gap-1">
                                        Fecha
                                        <span x-show="sortField === 'date'" x-text="sortDir === 'asc' ? '↑' : '↓'" class="text-blue-500"></span>
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <template x-for="file in sortedFiles()" :key="file.id">
                                <tr class="hover:bg-slate-50 cursor-pointer" @click="file.is_folder ? navigateToFolder(file.id, file.name) : openViewer(file)">
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
                                            <span x-show="renamingFileId !== file.id" class="font-medium text-slate-700 truncate" x-text="file.name"></span>
                                            <input x-show="renamingFileId === file.id"
                                                   :id="'rename-input-' + file.id"
                                                   x-model="renamingFileName"
                                                   @click.stop
                                                   @keydown.enter.stop="saveRename(file)"
                                                   @keydown.escape.stop="renamingFileId = null"
                                                   @blur="saveRename(file)"
                                                   class="border border-blue-400 px-2 py-0.5 rounded text-sm w-40 font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 text-sm hidden md:table-cell" x-text="file.is_folder ? '-' : formatSize(file.size)"></td>
                                    <td class="px-4 py-3 text-slate-500 text-sm hidden lg:table-cell" x-text="formatDate(file.created_at)"></td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button @click.stop="openDetailModal(file)" class="p-2 hover:bg-slate-200 rounded-lg transition-colors" title="Compartir">
                                                <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                                </svg>
                                            </button>
                                            <button x-show="isClippable(file)" @click.stop="openClipEditor(file)" class="p-2 bg-violet-100 hover:bg-violet-200 text-violet-600 rounded-lg transition-colors" title="Editor de corte">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z"/>
                                                </svg>
                                            </button>
                                            <button @click.stop="startRename(file)" class="p-2 bg-amber-100 hover:bg-amber-200 text-amber-600 rounded-lg transition-colors" title="Renombrar">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                            </button>
                                            <button @click.stop="deleteFile(file)" class="p-2 bg-red-100 hover:bg-red-200 text-red-600 rounded-lg transition-colors" title="Eliminar">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </div>
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

                <!-- Sin resultados de búsqueda -->
                <div x-show="viewMode === 'files' && searchMode && files.length === 0" class="text-center py-16">
                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-slate-700 mb-2">No se encontraron archivos</h3>
                    <p class="text-slate-500 mb-4">No hay resultados para <span class="font-semibold" x-text="'«' + searchQuery + '»'"></span></p>
                    <button @click="clearSearch()" class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                        ← Volver a carpeta
                    </button>
                </div>
                <!-- Carpeta vacía (sin búsqueda activa) -->
                <div x-show="viewMode === 'files' && !searchMode && files.length === 0" class="text-center py-16">
                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-slate-700 mb-2">No hay archivos</h3>
                    <p class="text-slate-500 mb-4" x-show="canUpload()">Sube un archivo o crea una carpeta para comenzar</p>
                    <p class="text-slate-500 mb-4" x-show="!canUpload()">No hay archivos en este storage</p>
                    <button @click="uploadQueue = []; showUploadModal = true" x-show="canUpload()" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        Subir Archivo
                    </button>
                </div>
            </div>
        </div>
    </main>

    <div x-cloak x-show="showUploadModal && canUpload()" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" x-transition>
        <div class="bg-white rounded-xl p-6 w-full max-w-lg shadow-xl">
            <h2 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Subir Archivos
            </h2>

            <div x-show="uploadQueue.length === 0">
                <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center hover:border-blue-400 transition-colors"
                     x-data="{ dragging: false }"
                     :class="dragging ? 'border-blue-500 bg-blue-50' : ''"
                     @dragover.prevent="dragging = true"
                     @dragleave.prevent="dragging = false"
                     @drop.prevent="dragging = false; uploadFiles($event.dataTransfer.files)">
                    <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-slate-600 mb-4">Arrastra archivos aquí o</p>
                    <label class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg cursor-pointer transition-colors">
                        Seleccionar
                        <input type="file" class="hidden" multiple @change="uploadFiles($event.target.files)">
                    </label>
                </div>
                <button @click="showUploadModal = false" class="mt-4 w-full bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg transition-colors">
                    Cancelar
                </button>
            </div>

            <div x-show="uploadQueue.length > 0">
                <div class="space-y-3 max-h-72 overflow-y-auto mb-4">
                    <template x-for="(item, i) in uploadQueue" :key="i">
                        <div class="bg-slate-50 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-sm font-medium text-slate-700 truncate max-w-xs" x-text="item.name"></span>
                                <span x-show="item.done" class="text-green-600 text-xs font-semibold flex-shrink-0 ml-2">✓ Listo</span>
                                <span x-show="item.error" class="text-red-500 text-xs font-semibold flex-shrink-0 ml-2">✗ Error</span>
                                <span x-show="!item.done && !item.error" class="text-slate-400 text-xs flex-shrink-0 ml-2" x-text="item.progress + '%'"></span>
                            </div>
                            <div x-show="!item.error" class="w-full bg-slate-200 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full transition-all duration-200"
                                     :class="item.done ? 'bg-green-500' : 'bg-blue-500'"
                                     :style="'width: ' + item.progress + '%'"></div>
                            </div>
                            <p x-show="item.error" class="text-red-500 text-xs mt-1" x-text="item.error"></p>
                        </div>
                    </template>
                </div>
                <div class="flex gap-3">
                    <label class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg cursor-pointer transition-colors text-sm">
                        Agregar más
                        <input type="file" class="hidden" multiple @change="uploadFiles($event.target.files)">
                    </label>
                    <button @click="showUploadModal = false; uploadQueue = []"
                            :disabled="!uploadQueue.every(f => f.done || f.error)"
                            :class="uploadQueue.every(f => f.done || f.error) ? 'bg-blue-600 hover:bg-blue-700 text-white cursor-pointer' : 'bg-slate-200 text-slate-400 cursor-not-allowed'"
                            class="flex-1 px-4 py-2 rounded-lg transition-colors text-sm font-medium">
                        Cerrar
                    </button>
                </div>
            </div>
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

    <div x-cloak x-show="viewerOpen" class="fixed inset-0 bg-black z-50 flex flex-col" x-transition @keydown.escape.window="closeViewer()" @keydown.arrowleft.window="viewerOpen && viewerPrev()" @keydown.arrowright.window="viewerOpen && viewerNext()">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-700 flex-shrink-0 bg-slate-900">
            <div class="flex items-center gap-3 min-w-0 mr-4">
                <span class="text-white font-medium truncate" x-text="currentViewerFile ? currentViewerFile.name : ''"></span>
                <span x-show="viewerFiles.length > 1"
                      class="text-slate-400 text-sm flex-shrink-0"
                      x-text="viewerIndex !== null ? (viewerIndex + 1) + ' / ' + viewerFiles.length : ''"></span>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <a x-show="currentViewerFile" :href="currentViewerFile ? '/files/' + currentViewerFile.id + '/download' : '#'"
                   class="flex items-center gap-1.5 text-slate-300 hover:text-white text-sm transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar
                </a>
                <button @click="closeViewer()" class="text-slate-400 hover:text-white transition-colors" title="Cerrar (Esc)">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
        <div x-show="currentViewerFile && isImage(currentViewerFile.mime_type)"
             class="flex items-center justify-center gap-3 px-5 py-2 border-b border-slate-700 flex-shrink-0 bg-slate-900/80">
            <button @click="rotateImg(-90)" title="Rotar izquierda"
                    class="p-1.5 text-slate-300 hover:text-white hover:bg-slate-700 rounded-lg transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                </svg>
            </button>
            <button @click="rotateImg(90)" title="Rotar derecha"
                    class="p-1.5 text-slate-300 hover:text-white hover:bg-slate-700 rounded-lg transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a8 8 0 00-8 8v2m18-10l-6 6m6-6l-6-6"/>
                </svg>
            </button>
            <div class="w-px h-5 bg-slate-600"></div>
            <button @click="zoomImg(-0.25)" title="Zoom −"
                    class="p-1.5 text-slate-300 hover:text-white hover:bg-slate-700 rounded-lg transition-colors text-lg font-bold leading-none w-8 text-center">−</button>
            <span class="text-slate-400 text-xs w-10 text-center" x-text="Math.round(imgScale * 100) + '%'"></span>
            <button @click="zoomImg(0.25)" title="Zoom +"
                    class="p-1.5 text-slate-300 hover:text-white hover:bg-slate-700 rounded-lg transition-colors text-lg font-bold leading-none w-8 text-center">+</button>
            <div class="w-px h-5 bg-slate-600"></div>
            <button @click="resetImgTransform()" title="Restablecer"
                    class="px-2 py-1 text-slate-300 hover:text-white hover:bg-slate-700 rounded-lg transition-colors text-xs font-medium">
                Reset
            </button>
            <template x-if="((imgRotation % 360) + 360) % 360 !== 0">
                <div class="flex items-center gap-2 ml-2">
                    <div class="w-px h-5 bg-slate-600"></div>
                    <button @click="saveImgRotation()"
                            :disabled="imgSaving"
                            class="flex items-center gap-1.5 px-3 py-1 bg-blue-600 hover:bg-blue-500 disabled:bg-blue-800 disabled:opacity-60 text-white rounded-lg transition-colors text-xs font-medium">
                        <template x-if="!imgSaving">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                            </svg>
                        </template>
                        <template x-if="imgSaving">
                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="imgSaving ? 'Guardando…' : 'Guardar rotación'"></span>
                    </button>
                </div>
            </template>
        </div>
        <div class="flex-1 overflow-hidden relative flex items-center justify-center select-none bg-black"
             @mousemove="if(imgDragging) { imgPanX += $event.movementX; imgPanY += $event.movementY; }"
             @mouseup="imgDragging = false"
             @mouseleave="imgDragging = false">
            <!-- Nav arrow: prev -->
            <button x-show="viewerIndex > 0"
                    @click.stop="viewerPrev()"
                    class="absolute left-3 top-1/2 -translate-y-1/2 z-20 bg-black/50 hover:bg-black/80 text-white rounded-full w-10 h-10 flex items-center justify-center transition-colors"
                    title="Anterior">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <!-- Nav arrow: next -->
            <button x-show="viewerIndex !== null && viewerIndex < viewerFiles.length - 1"
                    @click.stop="viewerNext()"
                    class="absolute right-3 top-1/2 -translate-y-1/2 z-20 bg-black/50 hover:bg-black/80 text-white rounded-full w-10 h-10 flex items-center justify-center transition-colors"
                    title="Siguiente">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
            <div x-show="currentViewerFile && isVideo(currentViewerFile.mime_type)" class="w-full h-full flex items-center justify-center p-4">
                <video x-ref="videoplayer" controls preload="auto" class="max-w-full max-h-full rounded-lg bg-black"></video>
            </div>
            <div x-show="currentViewerFile && isAudio(currentViewerFile.mime_type)" class="w-full flex flex-col items-center gap-6 py-16">
                <div class="w-24 h-24 bg-purple-900/50 rounded-full flex items-center justify-center">
                    <svg class="w-12 h-12 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                    </svg>
                </div>
                <audio x-ref="audioplayer" controls preload="auto" class="w-full max-w-lg"></audio>
            </div>
            <div x-show="currentViewerFile && isImage(currentViewerFile.mime_type)"
                 class="w-full h-full flex items-center justify-center overflow-hidden">
                <img :src="currentViewerFile && isImage(currentViewerFile.mime_type) ? getViewerUrl(currentViewerFile) : ''"
                     :alt="currentViewerFile ? currentViewerFile.name : ''"
                     :style="imgViewerStyle()"
                     :class="imgScale > 1 ? (imgDragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-default'"
                     class="object-contain"
                     @wheel.prevent="zoomImg($event.deltaY < 0 ? 0.2 : -0.2)"
                     @mousedown.prevent="if(imgScale > 1) { imgDragging = true; }">
            </div>
            <div x-show="currentViewerFile && isPdf(currentViewerFile.mime_type)" class="w-full h-full p-4">
                <iframe x-ref="pdfviewer"
                        :src="currentViewerFile && isPdf(currentViewerFile.mime_type) ? getViewerUrl(currentViewerFile) : ''"
                        class="w-full h-full rounded border-0"
                        title="PDF Viewer">
                </iframe>
            </div>
            <div x-show="currentViewerFile && !isVideo(currentViewerFile.mime_type) && !isAudio(currentViewerFile.mime_type) && !isImage(currentViewerFile.mime_type) && !isPdf(currentViewerFile.mime_type)"
                 class="text-center py-12">
                <div class="w-20 h-20 bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="text-slate-400 mb-4">Vista previa no disponible para este tipo de archivo</p>
                <a x-show="currentViewerFile" :href="currentViewerFile ? '/files/' + currentViewerFile.id + '/download' : '#'"
                   class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar archivo
                </a>
            </div>
        </div>
    </div>

    {{-- Delete confirmation modal --}}
    <div x-show="deleteConfirmFile !== null"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] bg-black/60 flex items-center justify-center p-4"
         @keydown.escape.window="deleteConfirmFile = null">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6" @click.stop>
            <div class="flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mx-auto mb-4">
                <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <h3 class="text-xl font-bold text-slate-800 text-center mb-2">¿Eliminar?</h3>
            <p class="text-slate-500 text-center mb-6">
                ¿Estás seguro de eliminar
                <span class="font-medium text-slate-700" x-text="deleteConfirmFile ? deleteConfirmFile.name : ''"></span>?
                Esta acción no se puede deshacer.
            </p>
            <div class="flex gap-3">
                <button @click="deleteConfirmFile = null" class="flex-1 px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors">
                    Cancelar
                </button>
                <button @click="executeDeleteFile()" class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-xl transition-colors">
                    Eliminar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Editor de Corte -->
    <div x-cloak x-show="showClipModal"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[70] bg-black/80 flex items-center justify-center p-3"
         @keydown.escape.window="closeClipModal()">
        <div class="w-full max-w-5xl flex flex-col rounded-2xl overflow-hidden shadow-2xl"
             style="background:#12122a; max-height:96vh;" @click.stop>

            <!-- Header -->
            <div class="flex items-center justify-between px-5 py-3 border-b flex-shrink-0" style="border-color:rgba(255,255,255,0.08);">
                <button @click="closeClipModal()" class="flex items-center gap-2 text-sm text-white/50 hover:text-white/80 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Volver
                </button>
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243-4.243 3 3 0 004.243-4.243z"/>
                    </svg>
                    <p class="text-sm font-medium text-white/70 truncate max-w-xs" x-text="clipFile ? clipFile.name : ''"></p>
                </div>
                <div class="flex items-center gap-1">
                    <button @click="clipLoadHistory()" title="Historial de cortes"
                            class="text-white/40 hover:text-amber-400 p-1.5 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>
                    <button @click="closeClipModal()" class="text-white/40 hover:text-white/70 p-1 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Body -->
            <div class="flex flex-1 overflow-hidden min-h-0">

                <!-- ── Left: player + timeline ── -->
                <div class="flex-1 flex flex-col min-w-0 overflow-hidden" style="background:#0b0b1a;">

                    <!-- Single media element (video tag works for audio too) -->
                    <!-- Visible as video only when the file is video -->
                    <div class="relative bg-black flex-shrink-0"
                         :style="clipFile && clipFile.mime_type && clipFile.mime_type.startsWith('video/') ? 'max-height:210px;min-height:80px;' : 'height:0;overflow:hidden;'">
                        <video id="clip-media-el" class="w-full h-full object-contain" style="max-height:210px;"
                               preload="metadata" playsinline></video>
                    </div>

                    <!-- Audio file info card (visual only) -->
                    <div x-show="clipFile && clipFile.mime_type && !clipFile.mime_type.startsWith('video/')"
                         class="flex-shrink-0 flex items-center gap-4 px-6 py-5" style="background:#0a0a18;">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0"
                             style="background:rgba(124,58,237,0.18);">
                            <svg class="w-7 h-7 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-white/60 text-sm font-medium" x-text="clipFile ? clipFile.name : ''"></p>
                            <p class="text-white/30 text-xs mt-0.5"
                               x-text="clipReady ? formatClipTime(clipDuration) + ' de duración' : 'Cargando...'"></p>
                        </div>
                    </div>

                    <!-- Timeline area -->
                    <div class="px-4 pt-4 pb-2 flex-shrink-0">

                        <!-- Loading -->
                        <div x-show="!clipReady" class="flex items-center justify-center gap-3 py-8">
                            <svg class="w-5 h-5 animate-spin text-violet-400" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-white/40 text-sm">Cargando archivo...</p>
                        </div>

                        <div x-show="clipReady">
                            <!-- Selection info bar -->
                            <div class="flex items-center gap-2 mb-2 min-h-[24px]">
                                <template x-if="clipSelStart !== null && clipSelEnd !== null && Math.abs(clipSelEnd - clipSelStart) > 0.05">
                                    <div class="flex items-center gap-2 flex-1">
                                        <span class="text-xs font-mono text-amber-300"
                                              x-text="formatClipTime(Math.min(clipSelStart,clipSelEnd)) + ' → ' + formatClipTime(Math.max(clipSelStart,clipSelEnd))"></span>
                                        <span class="text-xs text-white/30"
                                              x-text="'(' + formatClipTime(Math.abs(clipSelEnd-clipSelStart)) + ')'"></span>
                                        <button @click="clipAddToSequence()"
                                                class="ml-auto flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg font-medium transition-colors"
                                                style="background:rgba(251,191,36,0.15); color:rgba(251,191,36,0.9);"
                                                onmouseover="this.style.background='rgba(251,191,36,0.25)'"
                                                onmouseout="this.style.background='rgba(251,191,36,0.15)'">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                            </svg>
                                            Agregar a secuencia
                                        </button>
                                    </div>
                                </template>
                                <template x-if="clipSelStart === null || clipSelEnd === null || Math.abs(clipSelEnd - clipSelStart) <= 0.05">
                                    <p class="text-xs text-white/25">Arrastra sobre el timeline para marcar un segmento</p>
                                </template>
                            </div>

                            <!-- The timeline bar -->
                            <div class="relative rounded-xl overflow-hidden select-none"
                                 style="height:60px; background:linear-gradient(180deg,#1c1c38 0%,#131325 100%); cursor:crosshair;"
                                 x-ref="clipTimeline"
                                 @mousedown="clipTimelineMd($event)"
                                 @mousemove.window="clipTimelineMm($event)"
                                 @mouseup.window="clipTimelineMu()">

                                <!-- Purple: already-added segments from this file -->
                                <template x-for="item in clipSequence" :key="item.id">
                                    <div x-show="item.type === 'segment' && item.fileId === (clipFile ? clipFile.id : -1)"
                                         class="absolute top-1.5 bottom-5 rounded pointer-events-none"
                                         style="background:rgba(124,58,237,0.35);"
                                         :style="'left:' + (item.start/clipDuration*100).toFixed(2) + '%; width:' + ((item.end-item.start)/clipDuration*100).toFixed(2) + '%'">
                                    </div>
                                </template>

                                <!-- Amber: current selection -->
                                <template x-if="clipSelStart !== null && clipSelEnd !== null && clipDuration > 0">
                                    <div class="absolute top-1.5 bottom-5 rounded"
                                         style="background:rgba(251,191,36,0.18); border:1px solid rgba(251,191,36,0.65);"
                                         :style="clipSelStyle()">
                                        <!-- Left handle -->
                                        <div class="absolute left-0 top-0 bottom-0 w-3 flex items-center justify-center cursor-ew-resize z-10"
                                             style="background:rgba(251,191,36,0.85); border-radius:3px 0 0 3px;"
                                             @mousedown.stop="clipHandleMd('start', $event)">
                                            <div class="flex gap-px"><div class="w-px h-3" style="background:rgba(0,0,0,0.4)"></div><div class="w-px h-3" style="background:rgba(0,0,0,0.4)"></div></div>
                                        </div>
                                        <!-- Right handle -->
                                        <div class="absolute right-0 top-0 bottom-0 w-3 flex items-center justify-center cursor-ew-resize z-10"
                                             style="background:rgba(251,191,36,0.85); border-radius:0 3px 3px 0;"
                                             @mousedown.stop="clipHandleMd('end', $event)">
                                            <div class="flex gap-px"><div class="w-px h-3" style="background:rgba(0,0,0,0.4)"></div><div class="w-px h-3" style="background:rgba(0,0,0,0.4)"></div></div>
                                        </div>
                                    </div>
                                </template>

                                <!-- Playhead -->
                                <div class="absolute top-0 bottom-5 w-px pointer-events-none z-20"
                                     style="background:rgba(255,255,255,0.9); box-shadow:0 0 3px rgba(255,255,255,0.4);"
                                     :style="'left:' + (clipDuration > 0 ? (clipCurrentTime/clipDuration*100).toFixed(3) : 0) + '%'">
                                    <div class="absolute -top-0 left-1/2 -translate-x-1/2 w-2.5 h-2.5 bg-white rounded-sm" style="clip-path:polygon(0 0,100% 0,50% 100%)"></div>
                                </div>

                                <!-- Time ruler -->
                                <div class="absolute bottom-0 left-0 right-0 h-5 pointer-events-none"
                                     style="border-top:1px solid rgba(255,255,255,0.06);">
                                    <template x-for="tick in clipTimelineTicks()" :key="tick.t">
                                        <div class="absolute bottom-0 flex flex-col items-center" :style="'left:' + tick.pct + '%'">
                                            <div class="w-px h-2" style="background:rgba(255,255,255,0.12)"></div>
                                            <span class="text-white/30 leading-none" style="font-size:9px; transform:translateX(-50%);" x-text="tick.label"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Playback controls -->
                    <div class="flex items-center gap-3 px-4 py-3 border-t flex-shrink-0" style="border-color:rgba(255,255,255,0.07);">
                        <button @click="clipTogglePlay()" :disabled="!clipReady"
                                class="w-9 h-9 rounded-full flex items-center justify-center transition-colors flex-shrink-0"
                                :class="clipReady ? 'bg-violet-600 hover:bg-violet-500' : 'bg-white/10 cursor-not-allowed'">
                            <svg x-show="!clipPlaying" class="w-4 h-4 text-white ml-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M6.3 2.841A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                            </svg>
                            <svg x-show="clipPlaying" class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                        <div class="font-mono text-sm">
                            <span class="text-white" x-text="formatClipTime(clipCurrentTime)"></span>
                            <span class="text-white/30"> / </span>
                            <span class="text-white/50" x-text="formatClipTime(clipDuration)"></span>
                        </div>
                        <button x-show="clipSelStart !== null && clipSelEnd !== null && Math.abs(clipSelEnd - clipSelStart) > 0.05"
                                @click="clipPlaySelection()" :disabled="!clipReady"
                                class="ml-auto flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg transition-colors"
                                style="background:rgba(251,191,36,0.1); color:rgba(251,191,36,0.75);"
                                onmouseover="this.style.background='rgba(251,191,36,0.18)'"
                                onmouseout="this.style.background='rgba(251,191,36,0.1)'">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M6.3 2.841A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                            </svg>
                            Ver selección
                        </button>
                    </div>
                </div>

                <!-- ── Right: sequence ── -->
                <div class="w-72 flex-shrink-0 flex flex-col border-l" style="border-color:rgba(255,255,255,0.08); background:#12122a;">

                    <!-- Header -->
                    <div class="px-5 pt-4 pb-3 border-b flex-shrink-0 flex items-center justify-between" style="border-color:rgba(255,255,255,0.08);">
                        <p class="text-xs text-white/40 uppercase tracking-wider font-medium">Secuencia</p>
                        <span x-show="clipSequence.length > 0"
                              class="text-xs text-white/25 font-mono"
                              x-text="clipSequence.length + (clipSequence.length === 1 ? ' clip' : ' clips')"></span>
                    </div>

                    <!-- Sequence list -->
                    <div class="flex-1 overflow-y-auto px-3 py-3 space-y-1.5">

                        <!-- Empty state -->
                        <div x-show="clipSequence.length === 0"
                             class="flex flex-col items-center justify-center py-12 text-center">
                            <div class="w-12 h-12 rounded-2xl mb-3 flex items-center justify-center"
                                 style="background:rgba(124,58,237,0.1);">
                                <svg class="w-6 h-6 text-violet-400/35" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/>
                                </svg>
                            </div>
                            <p class="text-xs text-white/25 leading-relaxed">Marca un segmento en el<br>timeline y agrégalo aquí</p>
                        </div>

                        <!-- Items -->
                        <template x-for="(item, i) in clipSequence" :key="item.id">
                            <div class="rounded-xl px-3 py-2.5 group"
                                 :style="item.type === 'segment'
                                    ? 'background:rgba(251,191,36,0.07);border:1px solid rgba(251,191,36,0.18)'
                                    : 'background:rgba(124,58,237,0.1);border:1px solid rgba(124,58,237,0.25)'">
                                <div class="flex items-start gap-2">
                                    <span class="text-xs font-bold mt-0.5 flex-shrink-0 tabular-nums"
                                          :class="item.type === 'segment' ? 'text-amber-400/60' : 'text-violet-400/60'"
                                          x-text="(i+1) + '.'"></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs text-white/55 truncate" x-text="item.fileName"></p>
                                        <template x-if="item.type === 'segment'">
                                            <p class="font-mono text-xs text-amber-300/80 mt-0.5"
                                               x-text="formatClipTime(item.start) + ' → ' + formatClipTime(item.end)"></p>
                                        </template>
                                        <template x-if="item.type === 'full'">
                                            <p class="text-xs text-violet-300/60 mt-0.5">Archivo completo</p>
                                        </template>
                                        <p x-show="item.type === 'segment'"
                                           class="text-xs text-white/25 mt-0.5"
                                           x-text="formatClipTime(item.end - item.start)"></p>
                                    </div>
                                    <!-- Order + remove controls -->
                                    <div class="flex flex-col gap-0.5 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="clipMoveSeq(i, -1)" :disabled="i === 0"
                                                class="p-0.5 rounded text-white/30 hover:text-white/70 disabled:opacity-20 disabled:cursor-not-allowed transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        </button>
                                        <button @click="clipMoveSeq(i, 1)" :disabled="i === clipSequence.length - 1"
                                                class="p-0.5 rounded text-white/30 hover:text-white/70 disabled:opacity-20 disabled:cursor-not-allowed transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                        <button @click="clipRemoveSeq(i)"
                                                class="p-0.5 rounded text-white/20 hover:text-red-400 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Add other file panel -->
                    <div x-show="clipShowAddFile"
                         class="border-t overflow-y-auto flex-shrink-0" style="border-color:rgba(255,255,255,0.08); max-height:180px;">
                        <div class="px-3 pt-2 pb-2">
                            <p class="text-xs text-white/35 mb-1.5">Archivos compatibles en este storage</p>
                            <div x-show="clipAddFileLoading" class="text-center py-4 text-xs text-white/30">Cargando...</div>
                            <div x-show="!clipAddFileLoading && clipAddFileList.length === 0"
                                 class="text-center py-4 text-xs text-white/25">Sin otros archivos mp4 / mp3 / m4a</div>
                            <template x-for="f in clipAddFileList" :key="f.id">
                                <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg group"
                                     style="cursor:default"
                                     onmouseover="this.style.background='rgba(255,255,255,0.04)'"
                                     onmouseout="this.style.background=''">
                                    <p class="flex-1 text-xs text-white/55 truncate" x-text="f.name"></p>
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="clipAddFileToSeq(f, 'start')"
                                                class="text-xs px-2 py-0.5 rounded transition-colors"
                                                style="background:rgba(124,58,237,0.25); color:rgba(167,139,250,0.9);"
                                                title="Insertar al inicio">↑</button>
                                        <button @click="clipAddFileToSeq(f, 'end')"
                                                class="text-xs px-2 py-0.5 rounded transition-colors"
                                                style="background:rgba(124,58,237,0.25); color:rgba(167,139,250,0.9);"
                                                title="Agregar al final">↓</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="px-4 pb-5 pt-3 border-t flex-shrink-0 space-y-2" style="border-color:rgba(255,255,255,0.08);">

                        <!-- Add current selection -->
                        <button @click="clipAddToSequence()"
                                :disabled="clipSelStart === null || clipSelEnd === null || Math.abs((clipSelEnd||0)-(clipSelStart||0)) < 0.05"
                                class="w-full py-2 rounded-xl border text-xs font-medium transition-colors flex items-center justify-center gap-1.5"
                                :class="(clipSelStart !== null && clipSelEnd !== null && Math.abs(clipSelEnd-clipSelStart) >= 0.05)
                                    ? 'border-amber-500/40 text-amber-300/80 hover:bg-amber-500/10'
                                    : 'border-white/10 text-white/20 cursor-not-allowed'">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Agregar selección
                        </button>

                        <!-- Add other file -->
                        <button @click="clipShowAddFile = !clipShowAddFile; if(clipShowAddFile && clipAddFileList.length === 0) clipLoadAddFiles();"
                                class="w-full py-2 rounded-xl border text-xs font-medium transition-colors flex items-center justify-center gap-1.5"
                                :class="clipShowAddFile
                                    ? 'border-violet-500/50 text-violet-300'
                                    : 'border-white/10 text-white/30 hover:border-violet-500/30 hover:text-violet-300/60'">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                            </svg>
                            <span x-text="clipShowAddFile ? 'Ocultar archivos' : 'Agregar otro archivo'"></span>
                        </button>

                        <!-- Total duration -->
                        <div x-show="clipSequence.length > 0"
                             class="flex justify-between items-center px-1 text-xs">
                            <span class="text-white/30">Duración total</span>
                            <span class="font-mono text-white/55" x-text="formatClipTime(clipSeqTotalDuration())"></span>
                        </div>

                        <!-- Error -->
                        <div x-show="clipError" class="text-xs text-red-400 rounded-xl px-3 py-2"
                             style="background:rgba(239,68,68,0.1);" x-text="clipError"></div>

                        <!-- Export -->
                        <button @click="generateClip()"
                                :disabled="clipProcessing || clipSequence.length === 0"
                                :class="(!clipProcessing && clipSequence.length > 0)
                                    ? 'bg-blue-600 hover:bg-blue-500'
                                    : 'opacity-40 cursor-not-allowed bg-blue-600'"
                                class="w-full flex items-center justify-center gap-2 py-3 rounded-xl font-semibold text-white text-sm transition-colors">
                            <template x-if="clipProcessing">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </template>
                            <span x-text="clipProcessing ? 'Procesando...' : 'Exportar recorte'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection