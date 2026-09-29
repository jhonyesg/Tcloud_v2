@extends('layouts.app')

@section('skip_session_manager', '1')

@section('title', 'Vista Previa - ' . ($fileName ?? 'Tcloud'))

@section('content')
<div class="p-6" x-data="previewViewer()">

    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="/files" class="text-blue-600 hover:text-blue-800">← Volver a archivos</a>
            <span class="text-gray-500" x-text="fileName"></span>
        </div>
        <template x-if="isImage">
            <div class="flex gap-2">
                <button @click="zoomOut()" class="p-2 bg-gray-200 rounded hover:bg-gray-300" title="Zoom out">−</button>
                <span x-text="Math.round(zoom * 100) + '%'" class="p-2"></span>
                <button @click="zoomIn()" class="p-2 bg-gray-200 rounded hover:bg-gray-300" title="Zoom in">+</button>
                <button @click="rotate()" class="p-2 bg-gray-200 rounded hover:bg-gray-300" title="Rotar">↻</button>
                <button @click="resetView()" class="p-2 bg-gray-200 rounded hover:bg-gray-300" title="Reset">⌂</button>
            </div>
        </template>
    </div>

    <template x-if="isImage">
        <div class="flex justify-center bg-gray-100 rounded-lg overflow-hidden" style="max-height: 70vh;">
            <img src="/media/{{ $fileId }}/preview"
                 :style="'transform: scale(' + zoom + ') rotate(' + rotation + 'deg)'"
                 class="object-contain max-h-[70vh] transition-transform"
                 @wheel.prevent="zoom += $event.deltaY > 0 ? -0.1 : 0.1">
        </div>
    </template>

    <template x-if="isPdf">
        <div class="bg-gray-100 rounded-lg" style="height: 80vh;">
            <embed src="/media/{{ $fileId }}/preview"
                   type="application/pdf"
                   class="w-full h-full"
                   internalinstanceid="25">
        </div>
    </template>

    <template x-if="isAudio">
        <div class="flex flex-col items-center justify-center bg-gray-100 rounded-lg p-8">
            <div class="mb-4">
                <svg class="w-16 h-16 text-blue-500 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M18 3a1 1 0 00-1.196-.98L15 4.5 11.5 3A1 1 0 0010 3v7.586l-2-2A1 1 0 007 10v8a1 1 0 001.553.831l5-2.5 3.5 2.089a1 1 0 001.447-1.053L11 12.414V10a1 1 0 00-.553-.831l-4-2V3z"/>
                </svg>
            </div>
            <p class="text-lg font-medium mb-4" x-text="fileName"></p>
            <audio controls preload="metadata" class="w-full max-w-lg">
                <source src="/media/{{ $fileId }}/preview" :type="fileMime">
                Tu navegador no soporta el elemento de audio.
            </audio>
        </div>
    </template>

    <template x-if="isVideo">
        <div class="flex flex-col items-center bg-gray-100 rounded-lg p-4">
            <video controls preload="metadata" playsinline webkit-playsinline class="w-full max-w-4xl rounded" style="max-height: 70vh;">
                <source src="/media/{{ $fileId }}/preview" :type="fileMime">
                Tu navegador no soporta el elemento de video.
            </video>
            <p class="mt-4 text-gray-600" x-text="fileName"></p>
        </div>
    </template>

    <template x-if="unsupported">
        <div class="flex flex-col items-center justify-center h-64 bg-gray-100 rounded-lg">
            <svg class="w-16 h-16 text-gray-400 mb-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
            </svg>
            <p class="text-gray-500">Vista previa no disponible para este tipo de archivo.</p>
            <a href="/files/{{ $fileId }}/download" class="mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Descargar archivo
            </a>
        </div>
    </template>
</div>

<script>
function previewViewer() {
    return {
        fileMime: @json($fileMime),
        fileName: @json($fileName),
        zoom: 1,
        rotation: 0,
        get isImage() { return (this.fileMime || '').startsWith('image/'); },
        get isPdf() { return this.fileMime === 'application/pdf'; },
        get isAudio() { return (this.fileMime || '').startsWith('audio/'); },
        get isVideo() { return (this.fileMime || '').startsWith('video/'); },
        get unsupported() {
            const m = this.fileMime || '';
            return !this.isImage && !this.isPdf && !this.isAudio && !this.isVideo;
        },
        zoomIn() { this.zoom = Math.min(this.zoom + 0.25, 4); },
        zoomOut() { this.zoom = Math.max(this.zoom - 0.25, 0.25); },
        resetView() { this.zoom = 1; this.rotation = 0; },
        rotate() { this.rotation = (this.rotation + 90) % 360; }
    };
}
</script>
@endsection
