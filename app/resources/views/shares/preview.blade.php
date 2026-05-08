@extends('layouts.app')

@section('title', 'Vista Previa - ' . $file->name)

@php
$availableTools = $availableTools ?? collect();
@endphp

@section('content')
<div class="min-h-screen bg-[#03153C] p-4" x-data="sharePreview()" x-cloak>
    <div class="w-full max-w-4xl mx-auto">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-[#0A1F4D] p-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="{{ route('share.folder', ['token' => $share->token, 'folder_id' => $file->parent_id ?? $share->file_id]) }}" class="text-white hover:text-blue-200">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                    <h1 class="text-lg font-bold text-white">{{ $file->name }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    @if($availableTools->count() > 0)
                        <button @click="openToolModal()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            Herramientas
                        </button>
                    @endif
                    <a href="{{ $previewUrl }}" class="flex items-center gap-2 bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Descargar
                    </a>
                </div>
            </div>

            <div class="p-6 bg-slate-100">
                @if(str_starts_with($mimeType, 'image/'))
                    <div class="flex justify-center bg-white rounded-xl overflow-hidden">
                        <img src="{{ $previewUrl }}" alt="{{ $file->name }}" class="max-w-full object-contain" style="max-height: 70vh;">
                    </div>

                @elseif(str_starts_with($mimeType, 'video/'))
                    <div class="flex flex-col items-center bg-white rounded-xl p-4">
                        <video controls class="w-full max-w-4xl rounded-lg" style="max-height: 70vh;">
                            <source src="{{ $previewUrl }}" type="{{ $mimeType }}">
                            Tu navegador no soporta reproducción de video.
                        </video>
                    </div>

                @elseif(str_starts_with($mimeType, 'audio/'))
                    <div class="flex flex-col items-center justify-center bg-white rounded-xl p-8">
                        <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
                            </svg>
                        </div>
                        <p class="text-lg font-medium mb-4 text-slate-800">{{ $file->name }}</p>
                        <audio controls class="w-full max-w-lg">
                            <source src="{{ $previewUrl }}" type="{{ $mimeType }}">
                            Tu navegador no soporta reproducción de audio.
                        </audio>
                    </div>

                @elseif($mimeType === 'application/pdf')
                    <div class="bg-white rounded-xl overflow-hidden" style="height: 80vh;">
                        <iframe src="{{ $previewUrl }}" class="w-full h-full" style="min-height: 80vh;"></iframe>
                    </div>

                @else
                    <div class="flex flex-col items-center justify-center bg-white rounded-xl p-12">
                        <svg class="w-20 h-20 text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <p class="text-slate-600 mb-4">Vista previa no disponible para este tipo de archivo</p>
                        <a href="{{ $previewUrl }}" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-medium transition-colors">
                            Descargar archivo
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if($availableTools->count() > 0)
    <div x-show="toolModalOpen" x-transition class="fixed inset-0 bg-black/90 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
            <div class="bg-[#0A1F4D] p-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">Herramientas disponibles</h2>
                <button @click="toolModalOpen = false" class="text-white hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-2 gap-3">
                    @foreach($availableTools as $tool)
                    <button @click="launchTool('{{ $tool->slug }}')" class="flex flex-col items-center gap-2 p-4 bg-slate-50 hover:bg-slate-100 rounded-xl transition-colors">
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </div>
                        <span class="font-medium text-slate-700">{{ $tool->name }}</span>
                        <span class="text-xs text-slate-500">{{ ucfirst($tool->type) }}</span>
                    </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endif

    <div x-show="pluginLoading" x-transition class="fixed inset-0 bg-black/90 z-50 flex items-center justify-center">
        <div class="text-center text-white">
            <svg class="animate-spin h-12 w-12 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p>Cargando herramienta...</p>
        </div>
    </div>
</div>

<script>
function sharePreview() {
    return {
        toolModalOpen: false,
        pluginLoading: false,
        currentTool: null,
        fileMime: '{{ $mimeType }}',
        fileData: { id: {{ $file->id }}, name: '{{ $file->name }}', size: {{ $file->size ?? 0 }}, mime_type: '{{ $mimeType }}' },

        openToolModal() {
            this.toolModalOpen = true;
        },

        async launchTool(slug) {
            this.toolModalOpen = false;
            this.pluginLoading = true;
            this.currentTool = slug;

            try {
                const res = await fetch('/file-tools/default?mime=' + encodeURIComponent(this.fileMime));
                if (res.ok) {
                    const data = await res.json();
                    const tool = (data.data || []).find(t => t.slug === slug);
                    if (tool) {
                        await this.loadPluginResources(tool);
                        this.initializePlugin(tool);
                    }
                }
            } catch (e) {
                console.error('Error loading tool:', e);
            }

            this.pluginLoading = false;
        },

        async loadPluginResources(tool) {
            const resources = tool.resources || {};

            for (const js of resources.js || []) {
                const existing = document.querySelector('script[src="' + js + '"]');
                if (!existing) {
                    await new Promise(function(resolve, reject) {
                        const script = document.createElement('script');
                        script.src = js;
                        script.onload = function() { resolve(); };
                        script.onerror = function() { reject(new Error('Failed to load: ' + js)); };
                        document.head.appendChild(script);
                    });
                }
            }

            for (const css of resources.css || []) {
                const existing = document.querySelector('link[href="' + css + '"]');
                if (!existing) {
                    await new Promise(function(resolve, reject) {
                        const link = document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = css;
                        link.onload = function() { resolve(); };
                        link.onerror = function() { reject(new Error('Failed to load: ' + css)); };
                        document.head.appendChild(link);
                    });
                }
            }
        },

        initializePlugin(tool) {
            const initFn = window[tool.slug + '_init'];
            if (typeof initFn === 'function') {
                const container = document.createElement('div');
                container.className = 'fixed inset-0 z-50 bg-black flex items-center justify-center';
                container.id = 'plugin-container';
                document.body.appendChild(container);

                initFn({
                    file: this.fileData,
                    container: container,
                    config: tool.config || {}
                });
            } else {
                alert('Plugin no implementado correctamente');
            }
        }
    }
}
</script>
@endsection