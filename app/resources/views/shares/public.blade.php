@extends('layouts.app')

@section('title', $file->name . ' — Tcloud')

@php
    $ogTitle       = $file->name;
    $ogUrl         = config('app.url') . '/s/' . $share->token;
    $ogLogoUrl     = config('app.url') . '/logo.png';
    $isImage       = !$file->is_folder && str_starts_with($file->mime_type ?? '', 'image/');
    $ogImageUrl    = $isImage
        ? config('app.url') . '/s/' . $share->token . '/media/' . $file->id . '/preview'
        : $ogLogoUrl;
    if ($file->is_folder) {
        $itemCount  = isset($folderContents) ? $folderContents->count() : 0;
        $ogDesc     = 'Carpeta compartida · ' . $itemCount . ' ' . ($itemCount === 1 ? 'elemento' : 'elementos');
    } else {
        $mime = $file->mime_type ?? '';
        if (str_starts_with($mime, 'image/'))      $ogDesc = 'Imagen compartida en Tcloud';
        elseif (str_starts_with($mime, 'video/'))  $ogDesc = 'Video compartido en Tcloud';
        elseif (str_starts_with($mime, 'audio/'))  $ogDesc = 'Audio compartido en Tcloud';
        elseif ($mime === 'application/pdf')        $ogDesc = 'PDF compartido en Tcloud';
        else                                        $ogDesc = 'Archivo compartido en Tcloud';
    }
@endphp

@section('og_meta')
<meta property="og:type"        content="website">
<meta property="og:title"       content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogDesc }}">
<meta property="og:url"         content="{{ $ogUrl }}">
<meta property="og:image"       content="{{ $ogImageUrl }}">
<meta property="og:site_name"   content="Tcloud">
<meta name="twitter:card"       content="summary_large_image">
<meta name="twitter:title"      content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $ogDesc }}">
<meta name="twitter:image"      content="{{ $ogImageUrl }}">
@endsection

@section('content')
@php
$previewableFiles = $file->is_folder ? collect() : collect([$file]);
if ($file->is_folder && isset($folderContents)) {
    $previewableFiles = $folderContents->filter(function($item) {
        $mime = $item->mime_type ?? '';
        return !$item->is_folder && (
            str_starts_with($mime, 'image/') || 
            str_starts_with($mime, 'video/') || 
            str_starts_with($mime, 'audio/') || 
            $mime === 'application/pdf'
        );
    });
}
$filesJsonRaw = json_encode($previewableFiles->values());
@endphp

<script type="application/json" id="files-data">{!! $filesJsonRaw !!}</script>
@if($file->is_folder && isset($folderContents))
<script type="application/json" id="folder-items-data">{!! json_encode($folderContents->values()) !!}</script>
@endif

<div id="share-app"
     x-data="shareModal()" x-cloak>

<script>
function shareModal() {
        return {
                showModal: false,
                deleteUrl: '',
                deleteFileId: null,
                deleteFileName: '',
                currentIndex: 0,
                files: [],
                token: '{{ $share->token }}',
                videoLoading: false,
                pdfFullscreen: false,
                sortField: 'name',
                sortDir: 'asc',
                shareViewMode: localStorage.getItem('share_view_mode') || 'list',
                folderItems: [],
                sharePermissions: '{{ $share->permissions }}',
                shareRenamingId: null,
                shareRenamingName: '',
                imgScale: 1,
                imgRotation: 0,
                imgPanX: 0,
                imgPanY: 0,
                imgDragging: false,
                imgDragStart: { x: 0, y: 0 },

        init() {
            window._shareApp = this;
            const el = document.getElementById('files-data');
            if (el) {
                try {
                    this.files = JSON.parse(el.textContent);
                } catch(e) {}
            }
            const folderEl = document.getElementById('folder-items-data');
            if (folderEl) {
                try { this.folderItems = JSON.parse(folderEl.textContent); } catch(e) {}
            }
            const uploadMsg = sessionStorage.getItem('upload_notification');
            if (uploadMsg) {
                sessionStorage.removeItem('upload_notification');
                this.$nextTick(() => this.showNotification(uploadMsg, 'success'));
            }
            // Clean ?refresh=1 from URL bar without reloading
            if (new URLSearchParams(window.location.search).has('refresh')) {
                const clean = new URL(window.location.href);
                clean.searchParams.delete('refresh');
                history.replaceState(null, '', clean.toString());
            }
        },

        _setDeleteModalVisible(visible) {
            const modal = document.getElementById('delete-confirm-modal');
            if (modal) modal.style.display = visible ? 'flex' : 'none';
        },

        setShareViewMode(mode) {
            this.shareViewMode = mode;
            localStorage.setItem('share_view_mode', mode);
        },

        sortFolderFiles(field) {
            if (this.sortField === field) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortField = field;
                this.sortDir = 'asc';
            }
        },

        sortedFolderItems() {
            const arr = [...this.folderItems];
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
                    const da = a.file_modified_at ? new Date(a.file_modified_at).getTime() : 0;
                    const db = b.file_modified_at ? new Date(b.file_modified_at).getTime() : 0;
                    return (da - db) * dir;
                }
                return 0;
            });
        },

        formatSize(bytes) {
            const n = Number(bytes);
            if (!n && n !== 0) return '—';
            if (n === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.min(Math.floor(Math.log(n) / Math.log(k)), sizes.length - 1);
            return parseFloat((n / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        formatDate(dateString) {
            if (!dateString) return '—';
            const date = new Date(dateString);
            return date.toLocaleDateString('es-ES', { year: 'numeric', month: 'short', day: 'numeric' });
        },

        getPreviewIndex(item) {
            if (item.is_folder) return -1;
            return this.files.findIndex(f => f.id === item.id);
        },

        isItemPreviewable(item) {
            if (item.is_folder) return false;
            const mime = item.mime_type || '';
            return mime.startsWith('image/') || mime.startsWith('video/') || mime.startsWith('audio/') || mime === 'application/pdf';
        },

        canRename() {
            return ['write', 'full'].includes(this.sharePermissions);
        },

        canDelete() {
            return ['write', 'full'].includes(this.sharePermissions);
        },

        openPreview(index) {
            this.setCurrentIndex(index);
            this.showModal = true;
        },
        
        resetImgTransform() {
            this.imgScale = 1;
            this.imgRotation = 0;
            this.imgPanX = 0;
            this.imgPanY = 0;
            this.imgDragging = false;
        },

        zoomImg(delta) {
            this.imgScale = Math.min(5, Math.max(0.2, this.imgScale + delta));
        },

        rotateImg(deg) {
            this.imgRotation += deg;
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

                setCurrentIndex(index) {
            this.resetImgTransform();
            const video = this.$refs.videoplayer;
            const audio = this.$refs.audioplayer;
            const pdf = this.$refs.pdfviewer;
            if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
            if (audio) { audio.pause(); audio.src = ''; }
            if (pdf) { pdf.src = ''; }
            this.currentIndex = index;
            this.$nextTick(() => {
                if (this.currentFile) {
                    if (this.isVideo(this.currentFile.mime_type)) {
                        const v = this.$refs.videoplayer;
                        if (v) {
                            v.src = this.getFileUrl(this.currentFile);
                            v.load();
                        }
                    } else if (this.isAudio(this.currentFile.mime_type)) {
                        const a = this.$refs.audioplayer;
                        if (a) { a.src = this.getFileUrl(this.currentFile); a.load(); }
                    } else if (this.isPdf(this.currentFile.mime_type)) {
                        const p = this.$refs.pdfviewer;
                        if (p) { p.src = this.getFileUrl(this.currentFile); }
                    }
                }
            });
        },
        
        updateVideoSource() {
            const video = this.$refs.videoplayer;
            if (!video) return;

            video.pause();
            video.removeAttribute('src');
            video.load();

            if (this.currentFile && this.isVideo(this.currentFile.mime_type)) {
                this.$nextTick(() => {
                    const v = this.$refs.videoplayer;
                    if (v && this.currentFile) {
                        v.src = this.getFileUrl(this.currentFile);
                        v.load();
                    }
                });
            }
        },
        
        closePreview() {
            const video = this.$refs.videoplayer;
            const audio = this.$refs.audioplayer;
            const pdf = this.$refs.pdfviewer;
            if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
            if (audio) { audio.pause(); audio.src = ''; }
            if (pdf) { pdf.src = ''; }
            this.showModal = false;
        },
        
        handleFileSelect(event) {
            const files = Array.from(event.target.files || []);
            event.target.value = '';
            if (files.length) this.startUploadQueue(files);
        },

        handleUpload() {
            const files = Array.from(document.getElementById('file-input').files || []);
            if (files.length) this.startUploadQueue(files);
        },

        handleDrop(event) {
            const files = Array.from(event.dataTransfer?.files || []);
            if (files.length) this.startUploadQueue(files);
        },

        startUploadQueue(files) {
            this._uploadQueue = [...files];
            this._uploadTotal = files.length;
            this._uploadDone = 0;
            this._uploadSuccess = 0;
            this._processNextUpload();
        },

        async _processNextUpload() {
            if (!this._uploadQueue || this._uploadQueue.length === 0) {
                if (this._uploadSuccess > 0) {
                    const msg = this._uploadSuccess === 1 ? '¡Archivo subido!' : `¡${this._uploadSuccess} archivos subidos!`;
                    sessionStorage.setItem('upload_notification', msg);
                    setTimeout(() => location.reload(), 800);
                } else {
                    document.getElementById('upload-progress').classList.add('hidden');
                    document.getElementById('upload-select').classList.remove('hidden');
                    const bar = document.getElementById('upload-bar');
                    bar.classList.remove('bg-red-500');
                    bar.classList.add('bg-green-600');
                }
                return;
            }

            const file = this._uploadQueue.shift();
            const existing = this.folderItems.find(f => f.name.trim() === file.name.trim());

            if (existing) {
                const action = await this._askReplace(file.name);
                if (action === 'skip') {
                    this._processNextUpload();
                    return;
                }
            }

            await this._uploadFileAsync(file, !!existing);
            this._uploadDone++;
            this._processNextUpload();
        },

        _askReplace(fileName) {
            return new Promise(resolve => {
                const modal = document.getElementById('replace-confirm-modal');
                const nameEl = document.getElementById('replace-file-name');
                if (nameEl) nameEl.textContent = fileName;
                if (modal) modal.style.display = 'flex';
                this._replaceResolve = resolve;
            });
        },

        resolveReplace(action) {
            const modal = document.getElementById('replace-confirm-modal');
            if (modal) modal.style.display = 'none';
            if (this._replaceResolve) {
                this._replaceResolve(action);
                this._replaceResolve = null;
            }
        },

        _uploadFileAsync(file, replace) {
            return new Promise(resolve => {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('parent_id', '{{ $file->id }}');
                formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                if (replace) formData.append('replace', '1');

                const current = (this._uploadTotal - (this._uploadQueue?.length ?? 0));
                const total = this._uploadTotal;
                const progressDiv = document.getElementById('upload-progress');
                const progressBar = document.getElementById('upload-bar');
                const statusText = document.getElementById('upload-status');
                const selectDiv = document.getElementById('upload-select');

                progressDiv.classList.remove('hidden');
                selectDiv.classList.add('hidden');
                progressBar.classList.remove('bg-red-500');
                progressBar.classList.add('bg-green-600');
                progressBar.style.width = '0%';
                statusText.textContent = `(${current}/${total}) ${file.name}... 0%`;

                const xhr = new XMLHttpRequest();

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        progressBar.style.width = percent + '%';
                        statusText.textContent = `(${current}/${total}) ${file.name}... ${percent}%`;
                    }
                });

                xhr.onload = () => {
                    if (xhr.status !== 201) {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            this.showNotification('Error: ' + (res.error || 'Fallo al subir'), 'error');
                        } catch {
                            this.showNotification(`Error al subir ${file.name}`, 'error');
                        }
                        progressBar.classList.remove('bg-green-600');
                        progressBar.classList.add('bg-red-500');
                    } else {
                        this.folderItems.push({ id: null, name: file.name, is_folder: false, size: 0, mime_type: null, file_modified_at: null });
                        this._uploadSuccess = (this._uploadSuccess || 0) + 1;
                    }
                    resolve();
                };

                xhr.onerror = () => {
                    this.showNotification(`Error de conexión al subir ${file.name}`, 'error');
                    resolve();
                };

                xhr.open('POST', '/s/' + this.token + '/upload');
                xhr.send(formData);
            });
        },

        refreshFolderContents() {
            return fetch('/s/' + this.token + '?refresh=1', { headers: { 'Accept': 'text/html' } })
                .then(r => r.text())
                .then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const el = doc.getElementById('folder-items-data');
                    if (el) {
                        try { this.folderItems = JSON.parse(el.textContent); } catch(e) {}
                    }
                });
        },
        
        showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-xl shadow-lg text-white font-medium transition-all transform translate-x-0`;
            notification.classList.add(type === 'success' ? 'bg-green-600' : 'bg-red-600');
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        },
        
        shareStartRename(id, name) {
            this.shareRenamingId = id;
            this.shareRenamingName = name;
            this.$nextTick(() => {
                const input = document.getElementById('share-rename-' + id);
                if (input) { input.focus(); input.select(); }
            });
        },

        async shareSaveRename(id, url) {
            const newName = this.shareRenamingName.trim();
            this.shareRenamingId = null;
            if (!newName) return;
            const formData = new FormData();
            formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            formData.append('name', newName);
            const r = await fetch(url, { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
            if (r.ok) {
                const idx = this.folderItems.findIndex(f => f.id == id);
                if (idx !== -1) this.folderItems[idx] = { ...this.folderItems[idx], name: newName };
                this.showNotification('¡Nombre actualizado!', 'success');
            }
        },

        renameFile(event, fileId) {
            const form = event.target;
            const input = form.querySelector('input[name="name"]');
            const newName = input ? input.value.trim() : '';
            if (!newName) return;

            const formData = new FormData();
            formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            formData.append('name', newName);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' }
            }).then(r => {
                if (r.ok) {
                    const row = form.closest('[data-file-id]');
                    if (row) {
                        const nameEl = row.querySelector('p.font-medium');
                        if (nameEl) nameEl.textContent = newName;
                    }
                    const idx = this.folderItems.findIndex(f => f.id == fileId);
                    if (idx !== -1) this.folderItems[idx] = { ...this.folderItems[idx], name: newName };
                    this.showNotification('¡Nombre actualizado!', 'success');
                } else {
                    r.json().then(d => this.showNotification('Error: ' + (d.error || 'No se pudo renombrar'), 'error'));
                }
            }).catch(() => this.showNotification('Error de conexión', 'error'));
        },

        confirmDelete(url, fileId, fileName) {
            this.deleteUrl = url;
            this.deleteFileId = fileId;
            this.deleteFileName = fileName;
            const nameEl = document.getElementById('delete-file-name');
            if (nameEl) nameEl.textContent = fileName;
            this._setDeleteModalVisible(true);
        },

        cancelDelete() {
            this._setDeleteModalVisible(false);
        },

        executeDelete() {
            if (!this.deleteUrl || !this.deleteFileId) return;

            const formData = new FormData();
            formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || '');

            fetch(this.deleteUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            }).then(response => {
                if (response.ok) {
                    this._setDeleteModalVisible(false);
                    this.showNotification('¡Archivo eliminado!', 'success');
                    this.removeFileFromUI(this.deleteFileId);
                } else {
                    this._setDeleteModalVisible(false);
                    this.showNotification('Error al eliminar', 'error');
                }
            }).catch(() => {
                this._setDeleteModalVisible(false);
                this.showNotification('Error de conexión', 'error');
            });
        },

        removeFileFromUI(fileId) {
            this.files = this.files.filter(f => f.id != fileId);
            this.folderItems = this.folderItems.filter(f => f.id != fileId);
        },
        
        next() {
            if (this.currentIndex < this.files.length - 1) {
                this.currentIndex++;
            }
        },
        
        prev() {
            if (this.currentIndex > 0) {
                this.currentIndex--;
            }
        },
        
        get currentFile() {
            return this.files[this.currentIndex] || null;
        },
        
        isImage(mime) {
            return mime && mime.startsWith('image/');
        },
        
        isVideo(mime) {
            return mime && (mime.startsWith('video/') || mime === 'video/x-matroska');
        },
        
        isAudio(mime) {
            return mime && mime.startsWith('audio/');
        },
        
        isPdf(mime) {
            return mime === 'application/pdf';
        },
        
        getFileUrl(file) {
            return '/s/' + this.token + '/media/' + file.id + '/preview';
        },

        togglePdfFullscreen() {
            this.pdfFullscreen = !this.pdfFullscreen;
            this.$nextTick(() => {
                const iframe = document.querySelector('[x-ref="pdfviewer"]');
                if (this.pdfFullscreen && iframe) {
                    iframe.parentElement.classList.add('!fixed', '!inset-0', '!z-50', '!bg-black');
                    iframe.classList.add('!h-screen');
                } else if (iframe) {
                    iframe.parentElement.classList.remove('!fixed', '!inset-0', '!z-50', '!bg-black');
                    iframe.classList.remove('!h-screen');
                }
            });
        }
    };
}

function refreshFolder() {
    const btn = document.getElementById('refresh-btn');
    const icon = document.getElementById('refresh-icon');
    const label = document.getElementById('refresh-label');
    if (btn) btn.disabled = true;
    if (label) label.textContent = 'Actualizando...';
    if (icon) icon.classList.add('animate-spin');

    const url = new URL(window.location.href);
    url.searchParams.set('refresh', '1');
    sessionStorage.setItem('upload_notification', '¡Contenido actualizado!');
    window.location.href = url.toString();
}
</script>

    <div class="min-h-screen bg-[#03153C] flex flex-col">
        <div class="w-full flex-1 flex flex-col">
            <div class="bg-white flex-1 flex flex-col">
                <div class="bg-[#0A1F4D] p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                                @if($file->is_folder)
                                    <svg class="w-6 h-6 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                    </svg>
                                @else
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                    </svg>
                                @endif
                            </div>
                            <div>
                                <h1 class="text-xl font-bold text-white">{{ $file->name }}</h1>
                                <p class="text-blue-100 text-sm">
                                    @if($file->is_folder)
                                        Carpeta compartida
                                    @endif
                                    por {{ $share->creator->email ?? 'Usuario' }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 bg-white/20 rounded-full text-white text-sm font-medium">
                                {{ ucfirst($share->permissions) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    @if($file->is_folder)
                        {{-- FOLDER VIEW --}}
                        
                        {{-- Breadcrumbs --}}
                        @if(isset($breadcrumbs) && count($breadcrumbs) > 0)
                        <nav class="flex items-center gap-2 text-sm mb-4 pb-3 border-b border-slate-200">
                            @foreach($breadcrumbs as $index => $crumb)
                                @if($index > 0)
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                @endif
                                @if($crumb->id === $file->id)
                                    <span class="text-slate-800 font-medium">{{ $crumb->name }}</span>
                                @else
                                    <a href="{{ route('share.folder', ['token' => $share->token, 'folder_id' => $crumb->id]) }}" class="text-blue-600 hover:text-blue-700">
                                        {{ $crumb->name }}
                                    </a>
                                @endif
                            @endforeach
                        </nav>
                        @endif

                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-slate-800">Contenido</h2>
                            <div class="flex items-center gap-2">
                                <button id="refresh-btn" onclick="refreshFolder()" title="Actualizar contenido" class="flex items-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-sm transition-colors">
                                    <svg id="refresh-icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    <span id="refresh-label">Actualizar</span>
                                </button>
                                <div class="flex items-center bg-slate-100 rounded-lg p-1">
                                    <button @click="setShareViewMode('grid')" class="p-1.5 rounded-md transition-colors" :class="shareViewMode === 'grid' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                                    </button>
                                    <button @click="setShareViewMode('list')" class="p-1.5 rounded-md transition-colors" :class="shareViewMode === 'list' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                                    </button>
                                </div>
                                @if(in_array($share->permissions, ['write', 'upload', 'full']))
                                    <button onclick="document.getElementById('upload-section').classList.toggle('hidden')" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                        </svg>
                                        Subir archivo
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- Upload Section --}}
                        @if(in_array($share->permissions, ['write', 'upload', 'full']))
                            <div id="upload-section" class="hidden mb-6 border-2 border-dashed border-slate-300 rounded-xl p-6 text-center transition-colors"
                                 @dragenter.prevent="$el.classList.add('border-green-400','bg-green-50')"
                                 @dragover.prevent="$el.classList.add('border-green-400','bg-green-50')"
                                 @dragleave.prevent="$el.classList.remove('border-green-400','bg-green-50')"
                                 @drop.prevent="$el.classList.remove('border-green-400','bg-green-50'); handleDrop($event)">
                                <form action="{{ route('share.upload', ['token' => $share->token]) }}" method="POST" enctype="multipart/form-data" id="upload-form" @submit.prevent="handleUpload">
                                    @csrf
                                    <input type="hidden" name="parent_id" value="{{ $file->id }}">
                                    <div id="upload-progress" class="hidden mb-4">
                                        <div class="w-full bg-slate-200 rounded-full h-2.5">
                                            <div id="upload-bar" class="bg-green-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                        <p class="text-sm text-slate-500 mt-2" id="upload-status">Subiendo... 0%</p>
                                    </div>
                                    <div id="upload-select">
                                        <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                        </svg>
                                        <p class="text-slate-600 mb-4">Arrastra archivos aquí o</p>
                                        <label class="inline-block bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg cursor-pointer transition-colors">
                                            Seleccionar archivo
                                            <input type="file" name="file" class="hidden" multiple @change="handleFileSelect" id="file-input">
                                        </label>
                                    </div>
                                </form>
                            </div>
                        @endif

                        {{-- Create Folder (full permission only) --}}
                        @if($share->permissions === 'full')
                            <div class="mb-4 flex items-center justify-between">
                                <form action="{{ route('share.create-folder', ['token' => $share->token]) }}" method="POST" class="flex gap-2">
                                    @csrf
                                    <input type="hidden" name="parent_id" value="{{ $file->id }}">
                                    <input type="text" name="name" placeholder="Nueva carpeta" class="border border-slate-300 px-3 py-2 rounded-lg text-sm" required>
                                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                        Crear carpeta
                                    </button>
                                </form>
                            </div>
                        @endif

                        {{-- Folder Contents LIST VIEW --}}
                        <div x-show="shareViewMode === 'list'">
                        <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:bg-slate-100" @click="sortFolderFiles('name')">
                                        <span class="flex items-center gap-1">Nombre <span x-show="sortField === 'name'" x-text="sortDir === 'asc' ? '↑' : '↓'" class="text-blue-500"></span></span>
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell cursor-pointer select-none hover:bg-slate-100" @click="sortFolderFiles('size')">
                                        <span class="flex items-center gap-1">Tamaño <span x-show="sortField === 'size'" x-text="sortDir === 'asc' ? '↑' : '↓'" class="text-blue-500"></span></span>
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell cursor-pointer select-none hover:bg-slate-100" @click="sortFolderFiles('date')">
                                        <span class="flex items-center gap-1">Fecha <span x-show="sortField === 'date'" x-text="sortDir === 'asc' ? '↑' : '↓'" class="text-blue-500"></span></span>
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200" id="folder-contents">
                                <template x-for="item in sortedFolderItems()" :key="item.id">
                                    <tr class="transition-colors hover:bg-slate-50"
                                        :class="(item.is_folder || isItemPreviewable(item)) ? 'cursor-pointer' : ''"
                                        @click="item.is_folder ? (window.location='/s/'+token+'/folder/'+item.id) : (getPreviewIndex(item) >= 0 ? openPreview(getPreviewIndex(item)) : null)">
                                        {{-- Name --}}
                                        <td class="px-4 py-3 min-w-0">
                                            <div class="flex items-center gap-3 min-w-0">
                                                <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                                                     :class="item.is_folder ? 'bg-amber-100' : (item.mime_type && item.mime_type.startsWith('video/')) ? 'bg-rose-100' : (item.mime_type && item.mime_type.startsWith('audio/')) ? 'bg-purple-100' : (item.mime_type && item.mime_type.startsWith('image/')) ? 'bg-cyan-100' : item.mime_type === 'application/pdf' ? 'bg-red-100' : 'bg-slate-200'">
                                                    <svg x-show="item.is_folder" class="w-6 h-6 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                                    <svg x-show="!item.is_folder && item.mime_type && item.mime_type.startsWith('video/')" class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <svg x-show="!item.is_folder && item.mime_type && item.mime_type.startsWith('audio/')" class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/></svg>
                                                    <svg x-show="!item.is_folder && item.mime_type === 'application/pdf'" class="w-6 h-6 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                                                    <svg x-show="!item.is_folder && item.mime_type && item.mime_type.startsWith('image/')" class="w-6 h-6 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                    <svg x-show="!item.is_folder && (!item.mime_type || (!item.mime_type.startsWith('video/') && !item.mime_type.startsWith('audio/') && !item.mime_type.startsWith('image/') && item.mime_type !== 'application/pdf'))" class="w-6 h-6 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                                </div>
                                                <div class="min-w-0">
                                                    <span x-show="shareRenamingId !== item.id" class="share-file-name font-medium text-slate-700 truncate block" x-text="item.name"></span>
                                                    <input x-show="shareRenamingId === item.id"
                                                           :id="'share-rename-' + item.id"
                                                           x-model="shareRenamingName"
                                                           @click.stop
                                                           @keydown.enter.stop="shareSaveRename(item.id, '/s/'+token+'/rename/'+item.id)"
                                                           @keydown.escape.stop="shareRenamingId = null"
                                                           @blur="shareSaveRename(item.id, '/s/'+token+'/rename/'+item.id)"
                                                           class="border border-blue-400 px-2 py-0.5 rounded text-sm w-40 font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                            </div>
                                        </td>
                                        {{-- Size (hidden on mobile) --}}
                                        <td class="px-4 py-3 text-slate-500 text-sm hidden md:table-cell" x-text="item.is_folder ? '-' : formatSize(item.size)"></td>
                                        {{-- Date (hidden on tablet) --}}
                                        <td class="px-4 py-3 text-slate-500 text-sm hidden lg:table-cell" x-text="item.file_modified_at ? formatDate(item.file_modified_at) : '—'"></td>
                                        {{-- Actions --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                <a x-show="!item.is_folder" :href="'/s/'+token+'/download/'+item.id" download @click.stop class="p-2 bg-blue-100 hover:bg-blue-200 text-blue-600 rounded-lg transition-colors" title="Descargar">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                </a>
                                                <button x-show="canRename() && !item.is_folder" @click.stop="shareStartRename(item.id, item.name)" class="p-2 bg-amber-100 hover:bg-amber-200 text-amber-600 rounded-lg transition-colors" title="Renombrar">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                </button>
                                                <button x-show="canDelete() && !item.is_folder" @click.stop="confirmDelete('/s/'+token+'/delete/'+item.id, item.id, item.name)" type="button" class="p-2 bg-red-100 hover:bg-red-200 text-red-600 rounded-lg transition-colors" title="Eliminar">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <template x-if="folderItems.length === 0">
                                    <tr><td colspan="4" class="text-center py-12 text-slate-500">
                                        <svg class="w-16 h-16 mx-auto mb-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        <p>Esta carpeta está vacía</p>
                                    </td></tr>
                                </template>
                            </tbody>
                        </table>
                        </div>
                        </div>{{-- end list view wrapper --}}

                        {{-- GRID VIEW --}}
                        <div x-show="shareViewMode === 'grid'">
                            <div x-show="folderItems.length === 0" class="text-center py-12 text-slate-500">
                                <svg class="w-16 h-16 mx-auto mb-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                </svg>
                                <p>Esta carpeta está vacía</p>
                            </div>
                            <div x-show="folderItems.length > 0" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                                <template x-for="item in sortedFolderItems()" :key="item.id">
                                    <div class="group bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 rounded-xl p-4 cursor-pointer transition-all"
                                         @click="item.is_folder ? (window.location='/s/'+token+'/folder/'+item.id) : (getPreviewIndex(item) >= 0 ? openPreview(getPreviewIndex(item)) : null)">
                                        <div class="flex flex-col items-center text-center">
                                            <div class="w-16 h-16 rounded-xl flex items-center justify-center mb-3"
                                                 :class="item.is_folder ? 'bg-amber-100' : (item.mime_type && item.mime_type.startsWith('video/')) ? 'bg-rose-100' : (item.mime_type && item.mime_type.startsWith('audio/')) ? 'bg-purple-100' : (item.mime_type && item.mime_type.startsWith('image/')) ? 'bg-cyan-100' : item.mime_type === 'application/pdf' ? 'bg-red-100' : 'bg-slate-200'">
                                                <svg x-show="item.is_folder" class="w-10 h-10 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                                <svg x-show="!item.is_folder && item.mime_type && item.mime_type.startsWith('video/')" class="w-10 h-10 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                <svg x-show="!item.is_folder && item.mime_type && item.mime_type.startsWith('audio/')" class="w-10 h-10 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/></svg>
                                                <svg x-show="!item.is_folder && item.mime_type === 'application/pdf'" class="w-10 h-10 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                                                <svg x-show="!item.is_folder && item.mime_type && item.mime_type.startsWith('image/')" class="w-10 h-10 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <svg x-show="!item.is_folder && (!item.mime_type || (!item.mime_type.startsWith('video/') && !item.mime_type.startsWith('audio/') && !item.mime_type.startsWith('image/') && item.mime_type !== 'application/pdf'))" class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                            </div>
                                            <p class="font-medium text-slate-700 text-sm truncate w-full" x-text="item.name" :title="item.name"></p>
                                            <p class="text-xs text-slate-400 mt-1" x-text="item.is_folder ? 'Carpeta' : formatSize(item.size)"></p>
                                            <p class="text-xs text-slate-400" x-show="!item.is_folder && item.file_modified_at" x-text="formatDate(item.file_modified_at)"></p>
                                        </div>
                                        <div x-show="!item.is_folder" class="flex items-center justify-center mt-3 opacity-0 group-hover:opacity-100 transition-opacity gap-1">
                                            <button x-show="isItemPreviewable(item)" @click.stop="openPreview(getPreviewIndex(item))" class="p-2 bg-white hover:bg-green-100 rounded-lg shadow-sm transition-colors" title="Ver">
                                                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                            <a :href="'/s/'+token+'/download/'+item.id" download @click.stop class="p-2 bg-white hover:bg-blue-100 rounded-lg shadow-sm transition-colors" title="Descargar">
                                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </a>
                                            <button x-show="canDelete()" @click.stop="confirmDelete('/s/'+token+'/delete/'+item.id, item.id, item.name)" class="p-2 bg-white hover:bg-red-100 rounded-lg shadow-sm transition-colors" title="Eliminar">
                                                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>{{-- end grid view wrapper --}}

                    @else
                        {{-- FILE VIEW (single file shared, not folder) --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Tamaño</p>
                                <p class="font-semibold text-slate-800">{{ number_format($file->size / 1024 / 1024, 2) }} MB</p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Tipo</p>
                                <p class="font-semibold text-slate-800">{{ $mimeType }}</p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Fecha</p>
                                <p class="font-semibold text-slate-800">{{ $file->created_at->format('d/m/Y') }}</p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Estado</p>
                                <p class="font-semibold text-green-600">Activo</p>
                            </div>
                        </div>

                        @php
                            $isPreviewable = str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/') || $mimeType === 'application/pdf';
                        @endphp

                        @if($isPreviewable)
                            <div class="rounded-2xl overflow-hidden mb-6">
                                @if(str_starts_with($mimeType, 'image/'))
                                    <div class="bg-slate-900 flex items-center justify-center rounded-2xl" style="min-height:300px; max-height:75vh">
                                        <img src="{{ $fileUrl }}" alt="{{ $file->name }}"
                                             class="object-contain rounded-2xl cursor-pointer"
                                             style="max-width:100%; max-height:75vh; width:auto; height:auto"
                                             @click="openPreview(0)">
                                    </div>
                                @elseif(str_starts_with($mimeType, 'video/'))
                                    <div class="bg-black flex items-center justify-center rounded-2xl" style="min-height:300px; max-height:75vh">
                                        <video controls playsinline
                                               class="rounded-2xl block"
                                               style="max-width:100%; max-height:75vh; width:auto; height:auto">
                                            <source src="{{ $fileUrl }}" type="{{ $mimeType }}">
                                            Tu navegador no soporta reproducción de video.
                                        </video>
                                    </div>
                                @elseif(str_starts_with($mimeType, 'audio/'))
                                    <div class="bg-gradient-to-br from-purple-950 to-slate-900 rounded-2xl flex flex-col items-center justify-center gap-6 py-16 px-8">
                                        <div class="w-24 h-24 bg-purple-500/20 rounded-full flex items-center justify-center ring-4 ring-purple-500/30">
                                            <svg class="w-12 h-12 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
                                            </svg>
                                        </div>
                                        <p class="text-purple-200 font-medium text-lg text-center truncate max-w-full">{{ $file->name }}</p>
                                        <audio controls class="w-full" style="max-width:700px">
                                            <source src="{{ $fileUrl }}" type="{{ $mimeType }}">
                                            Tu navegador no soporta reproducción de audio.
                                        </audio>
                                    </div>
                                @elseif($mimeType === 'application/pdf')
                                    <iframe src="{{ $fileUrl }}"
                                            class="w-full rounded-2xl border-0"
                                            style="height:80vh"></iframe>
                                @endif
                            </div>
                        @else
                            <div class="bg-slate-100 rounded-2xl p-8 mb-6 flex items-center justify-center">
                                <div class="text-center">
                                    <div class="w-20 h-20 bg-slate-200 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <svg class="w-10 h-10 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    <p class="text-slate-600">Vista previa no disponible</p>
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-wrap gap-3 justify-center">
                            <a href="{{ route('share.download', ['token' => $share->token]) }}" download class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-medium transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Descargar
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- PREVIEW MODAL --}}
    <div x-show="showModal"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black z-50 flex flex-col"
         @keydown.escape.window="closePreview()"
         @keydown.arrowleft.window="showModal && currentIndex > 0 && setCurrentIndex(currentIndex - 1)"
         @keydown.arrowright.window="showModal && currentIndex < files.length - 1 && setCurrentIndex(currentIndex + 1)">

        {{-- Header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-700 flex-shrink-0 bg-slate-900">
            <div class="flex items-center gap-3 min-w-0 mr-4">
                <span class="text-white font-medium truncate" x-text="currentFile ? currentFile.name : ''"></span>
                <span x-show="files.length > 1"
                      class="text-slate-400 text-sm flex-shrink-0"
                      x-text="(currentIndex + 1) + ' / ' + files.length"></span>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <a x-show="currentFile" :href="currentFile ? getFileUrl(currentFile) : '#'"
                   class="flex items-center gap-1.5 text-slate-300 hover:text-white text-sm transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar
                </a>
                <button @click="closePreview()" class="text-slate-400 hover:text-white transition-colors" title="Cerrar (Esc)">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Image toolbar --}}
        <div x-show="currentFile && isImage(currentFile.mime_type)"
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
        </div>

        {{-- Content area --}}
        <div class="flex-1 overflow-hidden relative flex items-center justify-center select-none bg-black"
             @mousemove="if(imgDragging) { imgPanX += $event.movementX; imgPanY += $event.movementY; }"
             @mouseup="imgDragging = false"
             @mouseleave="imgDragging = false">

            {{-- Nav arrow: prev --}}
            <button x-show="currentIndex > 0"
                    @click.stop="setCurrentIndex(currentIndex - 1)"
                    class="absolute left-3 top-1/2 -translate-y-1/2 z-20 bg-black/50 hover:bg-black/80 text-white rounded-full w-10 h-10 flex items-center justify-center transition-colors"
                    title="Anterior">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            {{-- Nav arrow: next --}}
            <button x-show="currentIndex < files.length - 1"
                    @click.stop="setCurrentIndex(currentIndex + 1)"
                    class="absolute right-3 top-1/2 -translate-y-1/2 z-20 bg-black/50 hover:bg-black/80 text-white rounded-full w-10 h-10 flex items-center justify-center transition-colors"
                    title="Siguiente">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            {{-- Video --}}
            <div x-show="currentFile && isVideo(currentFile.mime_type)" class="w-full h-full flex items-center justify-center p-4">
                <video x-ref="videoplayer" controls preload="auto" playsinline class="max-w-full max-h-full rounded-lg bg-black"></video>
            </div>

            {{-- Audio --}}
            <div x-show="currentFile && isAudio(currentFile.mime_type)" class="w-full flex flex-col items-center gap-6 py-16">
                <div class="w-24 h-24 bg-purple-900/50 rounded-full flex items-center justify-center">
                    <svg class="w-12 h-12 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                    </svg>
                </div>
                <audio x-ref="audioplayer" controls preload="auto" class="w-full max-w-lg"></audio>
            </div>

            {{-- Image --}}
            <div x-show="currentFile && isImage(currentFile.mime_type)"
                 class="w-full h-full flex items-center justify-center overflow-hidden">
                <img :src="currentFile && isImage(currentFile.mime_type) ? getFileUrl(currentFile) : ''"
                     :alt="currentFile ? currentFile.name : ''"
                     :style="imgViewerStyle()"
                     :class="imgScale > 1 ? (imgDragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-default'"
                     class="object-contain"
                     @wheel.prevent="zoomImg($event.deltaY < 0 ? 0.2 : -0.2)"
                     @mousedown.prevent="if(imgScale > 1) { imgDragging = true; }">
            </div>

            {{-- PDF --}}
            <div x-show="currentFile && isPdf(currentFile.mime_type)" class="w-full h-full p-4">
                <iframe x-ref="pdfviewer"
                        :src="currentFile && isPdf(currentFile.mime_type) ? getFileUrl(currentFile) : ''"
                        class="w-full h-full rounded border-0"
                        title="PDF Viewer">
                </iframe>
            </div>

            {{-- Unknown --}}
            <div x-show="currentFile && !isVideo(currentFile.mime_type) && !isAudio(currentFile.mime_type) && !isImage(currentFile.mime_type) && !isPdf(currentFile.mime_type)"
                 class="text-center py-12">
                <div class="w-20 h-20 bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="text-slate-400 mb-4">Vista previa no disponible para este tipo de archivo</p>
                <a x-show="currentFile" :href="currentFile ? getFileUrl(currentFile) : '#'"
                   class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar archivo
                </a>
            </div>
        </div>

    </div>
</div>

{{-- DELETE CONFIRMATION MODAL (outside preview modal to avoid display:none parent hiding it) --}}
<div id="delete-confirm-modal"
     class="fixed inset-0 z-[60] bg-black/60 items-center justify-center p-4"
     style="display:none"
     onkeydown="if(event.key==='Escape') window._shareApp && window._shareApp.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6" onclick="event.stopPropagation()">
        <div class="flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 class="text-xl font-bold text-slate-800 text-center mb-2">¿Eliminar archivo?</h3>
        <p class="text-slate-500 text-center mb-6">¿Estás seguro de eliminar <span class="font-medium text-slate-700" id="delete-file-name"></span>? Esta acción no se puede deshacer.</p>
        <div class="flex gap-3">
            <button onclick="window._shareApp && window._shareApp.cancelDelete()" class="flex-1 px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors">
                Cancelar
            </button>
            <button onclick="window._shareApp && window._shareApp.executeDelete()" class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-xl transition-colors">
                Eliminar
            </button>
        </div>
    </div>
</div>
{{-- REPLACE CONFIRMATION MODAL --}}
<div id="replace-confirm-modal"
     class="fixed inset-0 z-[60] bg-black/60 items-center justify-center p-4"
     style="display:none">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6" onclick="event.stopPropagation()">
        <div class="flex items-center justify-center w-16 h-16 bg-amber-100 rounded-full mx-auto mb-4">
            <svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
        </div>
        <h3 class="text-xl font-bold text-slate-800 text-center mb-2">Archivo ya existe</h3>
        <p class="text-slate-500 text-center mb-6">
            <span class="font-medium text-slate-700" id="replace-file-name"></span>
            ya existe en esta carpeta. ¿Qué deseas hacer?
        </p>
        <div class="flex gap-3">
            <button onclick="window._shareApp && window._shareApp.resolveReplace('skip')" class="flex-1 px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors">
                Omitir
            </button>
            <button onclick="window._shareApp && window._shareApp.resolveReplace('replace')" class="flex-1 px-4 py-3 bg-amber-500 hover:bg-amber-600 text-white font-medium rounded-xl transition-colors">
                Reemplazar
            </button>
        </div>
    </div>
</div>
@endsection