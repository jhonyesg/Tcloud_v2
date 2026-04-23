<?php $__env->startSection('title', $file->is_folder ? 'Carpeta Compartida' : 'Archivo Compartido - Tcloud'); ?>

<?php $__env->startSection('content'); ?>
<?php
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
?>

<script type="application/json" id="files-data"><?php echo $filesJsonRaw; ?></script>

<div id="share-app"
     x-data="shareModal()">

<script>
function shareModal() {
    return {
        showModal: false,
        deleteUrl: '',
        deleteFileId: null,
        deleteFileName: '',
        currentIndex: 0,
        files: [],
        folderContents: [],
        token: '<?php echo e($share->token); ?>',
        videoLoading: false,
        pdfFullscreen: false,

        init() {
            window._shareApp = this;
            const el = document.getElementById('files-data');
            if (el) {
                try {
                    this.files = JSON.parse(el.textContent);
                } catch(e) {}
            }
            this.loadFolderContents();
        },

        _setDeleteModalVisible(visible) {
            const modal = document.getElementById('delete-confirm-modal');
            if (modal) modal.style.display = visible ? 'flex' : 'none';
        },

        loadFolderContents() {
            const container = document.querySelector('#folder-contents');
            if (container) {
                const items = container.querySelectorAll('[data-file-id]');
                this.folderContents = Array.from(items).map(item => ({
                    id: item.getAttribute('data-file-id'),
                    name: item.querySelector('.font-medium')?.textContent || ''
                }));
            }
        },
        
        openPreview(index) {
            this.setCurrentIndex(index);
            this.showModal = true;
        },
        
        setCurrentIndex(index) {
            const video = this.$refs.videoplayer;
            const audio = this.$refs.audioplayer;
            const pdf = this.$refs.pdfviewer;
            if (video) { video.pause(); video.src = ''; }
            if (audio) { audio.pause(); audio.src = ''; }
            if (pdf) { pdf.src = ''; }
            this.currentIndex = index;
            this.$nextTick(() => {
                if (this.currentFile) {
                    if (this.isVideo(this.currentFile.mime_type)) {
                        const v = this.$refs.videoplayer;
                        if (v) { v.src = this.getFileUrl(this.currentFile); v.load(); }
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
            if (video) { video.pause(); video.src = ''; }
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
            this._processNextUpload();
        },

        async _processNextUpload() {
            if (!this._uploadQueue || this._uploadQueue.length === 0) {
                await this.refreshFolderContents();
                document.getElementById('upload-progress').classList.add('hidden');
                document.getElementById('upload-select').classList.remove('hidden');
                const bar = document.getElementById('upload-bar');
                bar.classList.remove('bg-red-500');
                bar.classList.add('bg-green-600');
                if (this._uploadDone > 0) {
                    this.showNotification(
                        this._uploadDone === 1 ? '¡Archivo subido!' : `¡${this._uploadDone} archivos subidos!`,
                        'success'
                    );
                }
                return;
            }

            const file = this._uploadQueue.shift();
            const existing = this.folderContents.find(f => f.name.trim() === file.name.trim());

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
                formData.append('parent_id', '<?php echo e($file->id); ?>');
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
                        this.folderContents.push({ id: null, name: file.name });
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
            return fetch(window.location.href, { headers: { 'Accept': 'text/html' } })
                .then(r => r.text())
                .then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const newContents = doc.querySelector('#folder-contents');
                    if (newContents) {
                        document.querySelector('#folder-contents').innerHTML = newContents.innerHTML;
                        this.loadFolderContents();
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
                    const item = this.folderContents.find(f => f.id == fileId);
                    if (item) item.name = newName;
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
            const fileElement = document.querySelector(`[data-file-id="${fileId}"]`);
            if (fileElement) {
                fileElement.remove();
            }
            this.files = this.files.filter(f => f.id != fileId);
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
</script>

    <div class="min-h-screen bg-[#03153C] flex items-center justify-center p-4">
        <div class="w-full max-w-6xl">
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">
                <div class="bg-[#0A1F4D] p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                                <?php if($file->is_folder): ?>
                                    <svg class="w-6 h-6 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                    </svg>
                                <?php else: ?>
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                    </svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h1 class="text-xl font-bold text-white"><?php echo e($file->name); ?></h1>
                                <p class="text-blue-100 text-sm">
                                    <?php if($file->is_folder): ?>
                                        Carpeta compartida
                                    <?php endif; ?>
                                    por <?php echo e($share->creator->email ?? 'Usuario'); ?>

                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 bg-white/20 rounded-full text-white text-sm font-medium">
                                <?php echo e(ucfirst($share->permissions)); ?>

                            </span>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <?php if($file->is_folder): ?>
                        
                        
                        
                        <?php if(isset($breadcrumbs) && count($breadcrumbs) > 0): ?>
                        <nav class="flex items-center gap-2 text-sm mb-4 pb-3 border-b border-slate-200">
                            <?php $__currentLoopData = $breadcrumbs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $crumb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if($index > 0): ?>
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                <?php endif; ?>
                                <?php if($crumb->id === $file->id): ?>
                                    <span class="text-slate-800 font-medium"><?php echo e($crumb->name); ?></span>
                                <?php else: ?>
                                    <a href="<?php echo e(route('share.folder', ['token' => $share->token, 'folder_id' => $crumb->id])); ?>" class="text-blue-600 hover:text-blue-700">
                                        <?php echo e($crumb->name); ?>

                                    </a>
                                <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </nav>
                        <?php endif; ?>

                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-slate-800">Contenido</h2>
                            <?php if(in_array($share->permissions, ['write', 'upload', 'full'])): ?>
                                <button onclick="document.getElementById('upload-section').classList.toggle('hidden')" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                    </svg>
                                    Subir archivo
                                </button>
                            <?php endif; ?>
                        </div>

                        
                        <?php if(in_array($share->permissions, ['write', 'upload', 'full'])): ?>
                            <div id="upload-section" class="hidden mb-6 border-2 border-dashed border-slate-300 rounded-xl p-6 text-center transition-colors"
                                 @dragenter.prevent="$el.classList.add('border-green-400','bg-green-50')"
                                 @dragover.prevent="$el.classList.add('border-green-400','bg-green-50')"
                                 @dragleave.prevent="$el.classList.remove('border-green-400','bg-green-50')"
                                 @drop.prevent="$el.classList.remove('border-green-400','bg-green-50'); handleDrop($event)">
                                <form action="<?php echo e(route('share.upload', ['token' => $share->token])); ?>" method="POST" enctype="multipart/form-data" id="upload-form" @submit.prevent="handleUpload">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="parent_id" value="<?php echo e($file->id); ?>">
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
                        <?php endif; ?>

                        
                        <?php if($share->permissions === 'full'): ?>
                            <div class="mb-4 flex items-center justify-between">
                                <form action="<?php echo e(route('share.create-folder', ['token' => $share->token])); ?>" method="POST" class="flex gap-2">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="parent_id" value="<?php echo e($file->id); ?>">
                                    <input type="text" name="name" placeholder="Nueva carpeta" class="border border-slate-300 px-3 py-2 rounded-lg text-sm" required>
                                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                        Crear carpeta
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                        
                        <div id="folder-contents" class="space-y-2">
                            <?php $previewableIndex = 0; ?>
                            <?php $__currentLoopData = $folderContents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php
                                    $isPreviewable = false;
                                    $mime = $item->mime_type ?? '';
                                    if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/') || $mime === 'application/pdf') {
                                        $isPreviewable = true;
                                    }
                                ?>
                                <div class="flex items-center justify-between bg-slate-50 hover:bg-slate-100 rounded-lg p-4 transition-colors <?php echo e($item->is_folder ? 'cursor-pointer' : ''); ?>" <?php if($item->is_folder): ?> onclick="window.location='<?php echo e(route('share.folder', ['token' => $share->token, 'folder_id' => $item->id])); ?>'" <?php endif; ?> data-file-id="<?php echo e($item->id); ?>">
                                    <div class="flex items-center gap-3">
                                        <?php if($item->is_folder): ?>
                                            <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                                                <svg class="w-6 h-6 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <span class="font-medium text-slate-700 hover:text-blue-600"><?php echo e($item->name); ?></span>
                                                <p class="text-xs text-slate-500">Carpeta</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-10 h-10 bg-slate-200 rounded-lg flex items-center justify-center">
                                                <?php if(str_starts_with($mime, 'video/')): ?>
                                                    <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                <?php elseif(str_starts_with($mime, 'audio/')): ?>
                                                    <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
                                                    </svg>
                                                <?php elseif($mime === 'application/pdf'): ?>
                                                    <svg class="w-6 h-6 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                                    </svg>
                                                <?php elseif(str_starts_with($mime, 'image/')): ?>
                                                    <svg class="w-6 h-6 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                <?php else: ?>
                                                    <svg class="w-6 h-6 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                    </svg>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="font-medium text-slate-700"><?php echo e($item->name); ?></p>
                                                <p class="text-xs text-slate-500"><?php echo e(number_format($item->size / 1024, 1)); ?> KB</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <?php if(!$item->is_folder && $isPreviewable): ?>
                                            <?php $currentIndex = $previewableIndex++; ?>
                                            <button @click="openPreview(<?php echo e($currentIndex); ?>)" class="p-2 bg-green-100 hover:bg-green-200 text-green-600 rounded-lg transition-colors" title="Ver">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                            </button>
                                        <?php endif; ?>
                                        <?php if(!$item->is_folder): ?>
                                            <a href="<?php echo e(route('share.file-download', ['token' => $share->token, 'file_id' => $item->id])); ?>" class="p-2 bg-blue-100 hover:bg-blue-200 text-blue-600 rounded-lg transition-colors" title="Descargar">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if(in_array($share->permissions, ['write', 'full']) && !$item->is_folder): ?>
                                            <form action="<?php echo e(route('share.rename', ['token' => $share->token, 'file_id' => $item->id])); ?>" method="POST" class="flex items-center gap-1" @submit.prevent="renameFile($event, <?php echo e($item->id); ?>)">
                                                <?php echo csrf_field(); ?>
                                                <input type="text" name="name" value="<?php echo e($item->name); ?>" class="border border-slate-300 px-2 py-1 rounded text-sm w-32">
                                                <button type="submit" class="p-2 bg-amber-100 hover:bg-amber-200 text-amber-600 rounded-lg transition-colors" title="Renombrar">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                                                    </svg>
                                                </button>
                                            </form>
                                            <button @click="confirmDelete('<?php echo e(route('share.delete', ['token' => $share->token, 'file_id' => $item->id])); ?>', <?php echo e($item->id); ?>, '<?php echo e($item->name); ?>')" type="button" class="p-2 bg-red-100 hover:bg-red-200 text-red-600 rounded-lg transition-colors" title="Eliminar">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                            <?php if($folderContents->isEmpty()): ?>
                                <div class="text-center py-12 text-slate-500">
                                    <svg class="w-16 h-16 mx-auto mb-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                    </svg>
                                    <p>Esta carpeta está vacía</p>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Tamaño</p>
                                <p class="font-semibold text-slate-800"><?php echo e(number_format($file->size / 1024 / 1024, 2)); ?> MB</p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Tipo</p>
                                <p class="font-semibold text-slate-800"><?php echo e($mimeType); ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Fecha</p>
                                <p class="font-semibold text-slate-800"><?php echo e($file->created_at->format('d/m/Y')); ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 text-center">
                                <p class="text-slate-500 text-sm">Estado</p>
                                <p class="font-semibold text-green-600">Activo</p>
                            </div>
                        </div>

                        <?php
                            $isPreviewable = str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/') || $mimeType === 'application/pdf';
                        ?>

                        <?php if($isPreviewable): ?>
                            <div class="bg-slate-100 rounded-2xl p-4 mb-6 min-h-[300px] max-h-[500px] flex items-center justify-center">
                                <?php if(str_starts_with($mimeType, 'image/')): ?>
                                    <img src="<?php echo e($fileUrl); ?>" alt="<?php echo e($file->name); ?>" class="max-w-full max-h-[450px] object-contain rounded-lg cursor-pointer" @click="openPreview(0)">
                                <?php elseif(str_starts_with($mimeType, 'video/')): ?>
                                    <video controls class="max-w-full max-h-[450px] rounded-lg">
                                        <source src="<?php echo e($fileUrl); ?>" type="<?php echo e($mimeType); ?>">
                                        Tu navegador no soporta reproducción de video.
                                    </video>
                                <?php elseif(str_starts_with($mimeType, 'audio/')): ?>
                                    <div class="text-center">
                                        <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                            <svg class="w-10 h-10 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
                                            </svg>
                                        </div>
                                        <audio controls class="w-full max-w-md">
                                            <source src="<?php echo e($fileUrl); ?>" type="<?php echo e($mimeType); ?>">
                                            Tu navegador no soporta reproducción de audio.
                                        </audio>
                                    </div>
                                <?php elseif($mimeType === 'application/pdf'): ?>
                                    <iframe src="<?php echo e($fileUrl); ?>" class="w-full h-[450px] rounded-lg"></iframe>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
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
                        <?php endif; ?>

                        <div class="flex flex-wrap gap-3 justify-center">
                            <a href="<?php echo e($fileUrl); ?>" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-medium transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Descargar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    
    <div x-show="showModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 bg-black/95 flex"
         @keydown.escape.window="closePreview()">

        
        <button @click="closePreview()" class="absolute top-4 right-4 z-50 p-3 bg-white/10 hover:bg-white/20 rounded-full text-white transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        
        <div class="w-72 bg-slate-900/80 border-r border-white/10 overflow-y-auto">
            <div class="p-4 border-b border-white/10">
                <p class="text-white font-medium" x-text="files.length + ' archivos'"></p>
            </div>
            <div class="divide-y divide-white/5">
                <template x-for="(file, index) in files" :key="file.id">
                    <button
                        @click="setCurrentIndex(index)"
                        class="w-full p-3 text-left hover:bg-white/10 transition-colors flex items-center gap-3"
                        :class="currentIndex === index ? 'bg-white/20' : ''">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                             :class="{
                                 'bg-rose-500/20': isVideo(file.mime_type),
                                 'bg-purple-500/20': isAudio(file.mime_type),
                                 'bg-cyan-500/20': isImage(file.mime_type),
                                 'bg-red-500/20': isPdf(file.mime_type)
                             }">
                            <template x-if="isVideo(file.mime_type)">
                                <svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                </svg>
                            </template>
                            <template x-if="isAudio(file.mime_type)">
                                <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
                                </svg>
                            </template>
                            <template x-if="isImage(file.mime_type)">
                                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </template>
                            <template x-if="isPdf(file.mime_type)">
                                <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                </svg>
                            </template>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white text-sm font-medium truncate" x-text="file.name"></p>
                            <p class="text-white/40 text-xs" x-text="file.mime_type"></p>
                        </div>
                        <div x-show="currentIndex === index" class="w-2 h-2 bg-blue-400 rounded-full"></div>
                    </button>
                </template>
            </div>
        </div>

        
        <div class="flex-1 flex items-center justify-center p-4" @click.stop>
            <div class="w-full h-full" x-show="currentFile">
                
                <div x-show="currentFile && isImage(currentFile.mime_type)" class="flex items-center justify-center h-full">
                    <img :src="currentFile ? getFileUrl(currentFile) : ''" :alt="currentFile ? currentFile.name : ''" class="max-w-full max-h-[85vh] object-contain mx-auto rounded-lg">
                </div>

                
                <div x-show="currentFile && isVideo(currentFile.mime_type)" class="flex items-center justify-center h-full">
                    <video
                        x-ref="videoplayer"
                        controls
                        preload="none"
                        style="background: #000;"
                        class="max-w-full max-h-[80vh] mx-auto rounded-lg">
                    </video>
                </div>

                
                <div x-show="currentFile && isAudio(currentFile.mime_type)" class="bg-slate-800 rounded-2xl p-12 max-w-2xl mx-auto">
                    <div class="w-24 h-24 bg-purple-600 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
                        </svg>
                    </div>
                    <p class="text-white text-center text-xl font-medium mb-6" x-text="currentFile ? currentFile.name : ''"></p>
                    <audio x-ref="audioplayer" controls class="w-full"></audio>
                </div>

                
                <div x-show="currentFile && isPdf(currentFile.mime_type)" class="flex flex-col h-full w-full" x-data="{ pdfLoading: true }" @click.stop>
                    <div class="flex items-center justify-between mb-3 bg-slate-800 rounded-lg p-2 shrink-0">
                        <div class="text-white font-medium">
                            PDF Viewer
                        </div>
                        <button @click="togglePdfFullscreen()" class="p-2 bg-white/10 hover:bg-white/20 text-white rounded transition-colors" :title="pdfFullscreen ? 'Exit fullscreen' : 'Fullscreen'">
                            <svg x-show="!pdfFullscreen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                            </svg>
                            <svg x-show="pdfFullscreen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="flex-1 w-full bg-slate-200 rounded-lg overflow-hidden relative min-h-0" :class="pdfFullscreen ? 'fixed inset-0 z-50 bg-black' : ''">
                        <div x-show="pdfLoading" class="absolute inset-0 flex items-center justify-center bg-slate-200 z-10">
                            <div class="text-slate-500">Cargando PDF...</div>
                        </div>
                        <iframe x-ref="pdfviewer" class="w-full h-full" :src="currentFile ? getFileUrl(currentFile) : ''" @load="pdfLoading = false"></iframe>
                    </div>
                </div>
            </div>
        </div>


        <div class="absolute bottom-0 left-72 right-0 bg-gradient-to-t from-black/80 to-transparent p-6">
            <div class="flex items-center justify-between">
                <div class="text-white">
                    <p class="text-xl font-medium" x-text="currentFile ? currentFile.name : ''"></p>
                    <p class="text-white/60 text-sm mt-1" x-text="currentFile ? (currentIndex + 1) + ' / ' + files.length : ''"></p>
                </div>
                <div class="flex gap-3">
                    <a :href="currentFile ? getFileUrl(currentFile) : '#'" class="flex items-center gap-2 bg-white/20 hover:bg-white/30 text-white px-5 py-2.5 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Descargar
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>


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
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/shares/public.blade.php ENDPATH**/ ?>