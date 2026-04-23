@extends('layouts.app')

@section('title', 'Vista Previa - ' . $file->name)

@section('content')
<div class="min-h-screen bg-[#03153C] flex items-center justify-center p-4">
    <div class="w-full max-w-4xl">
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
                <a href="{{ $previewUrl }}" class="flex items-center gap-2 bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar
                </a>
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
</div>
@endsection