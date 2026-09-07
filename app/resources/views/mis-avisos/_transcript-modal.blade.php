{{-- Modal de transcripción completa anclada a la mención (mentions-viewer).
    Estado Alpine: transcriptModal. Reproductor sincronizado solo si
    transcriptModal.meta.can_view_file. --}}
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60"
     x-show="transcriptModal.open" x-cloak
     @keydown.escape.window="closeTranscript()">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden">

        {{-- Encabezado --}}
        <div class="px-5 py-3.5 border-b border-slate-200 flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-slate-800 truncate"
                    x-text="transcriptModal.meta?.file_name || 'Transcripción'"></h2>
                <p class="text-xs text-slate-500 mt-0.5 flex items-center gap-2 flex-wrap">
                    <span x-text="transcriptModal.meta?.storage || ''"></span>
                    <template x-if="transcriptModal.meta?.duration_seconds">
                        <span x-text="'· duración ' + hmsLabel(transcriptModal.meta.duration_seconds)"></span>
                    </template>
                    <span x-text="'· ' + (transcriptModal.totalSegments || 0) + ' segmentos'"></span>
                    <template x-if="transcriptModal.hitKeyword">
                        <span class="px-2 py-0.5 bg-brand-50 text-brand-700 rounded"
                              x-text="'mención: ' + transcriptModal.hitKeyword"></span>
                    </template>
                </p>
            </div>
            <button @click="closeTranscript()"
                    class="shrink-0 w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- Carga / error --}}
        <div x-show="transcriptModal.loading" class="py-14 text-center text-slate-400 text-sm">
            <i class="fas fa-circle-notch fa-spin text-2xl mb-2 block"></i> Cargando transcripción…
        </div>
        <div x-show="transcriptModal.error" class="m-5 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700"
             x-text="transcriptModal.error"></div>

        {{-- Búsqueda dentro de la transcripción cargada --}}
        <div x-show="!transcriptModal.loading && !transcriptModal.error" class="px-5 pt-3 pb-2 border-b border-slate-100">
            <div class="relative">
                <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" x-model="transcriptModal.search"
                       placeholder="Buscar palabras dentro de la transcripción…"
                       class="w-full border border-slate-300 rounded-lg pl-8 pr-20 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400"
                      x-show="transcriptModal.search.trim()"
                      x-text="visibleSegments().length + ' de ' + transcriptModal.segments.length + ' seg.'"></span>
            </div>
            <p x-show="transcriptModal.search.trim() && visibleSegments().length === 0" class="pt-2 text-xs text-slate-400">
                Sin coincidencias en los segmentos cargados; navega con el scroll para cargar más.
            </p>
        </div>

        {{-- Cuerpo: reproductor + segmentos --}}
        <div x-show="!transcriptModal.loading && !transcriptModal.error" class="flex-1 overflow-hidden flex flex-col">

            {{-- Reproductor sincronizado (requiere permiso de archivo) --}}
            <div x-show="transcriptModal.meta?.can_view_file && mediaKind() !== 'none'" class="border-b border-slate-200 bg-slate-50 px-5 py-3">
                <template x-if="mediaKind() === 'video'">
                    <video x-ref="player" controls preload="metadata" playsinline class="w-full max-h-48 rounded bg-black"
                           :src="'/media/' + transcriptModal.meta.file_id + '/preview'"
                           @timeupdate="onPlayerTime($event)"
                           @loadedmetadata="onPlayerLoaded($event)"></video>
                </template>
                <template x-if="mediaKind() === 'audio'">
                    <audio x-ref="player" controls preload="metadata" class="w-full"
                           :src="'/media/' + transcriptModal.meta.file_id + '/preview'"
                           @timeupdate="onPlayerTime($event)"
                           @loadedmetadata="onPlayerLoaded($event)"></audio>
                </template>
            </div>

            {{-- Lista de segmentos con ventana incremental --}}
            <div class="flex-1 overflow-y-auto" x-ref="segList" @scroll="onSegmentsScroll($event)">
                <div x-show="transcriptModal.loadingBefore" class="py-3 text-center text-xs text-slate-400">
                    <i class="fas fa-circle-notch fa-spin mr-1"></i> Cargando segmentos anteriores…
                </div>
                <template x-for="seg in visibleSegments()" :key="seg.id">
                    <div :id="'seg-' + seg.id" @click="seekToSegment(seg)"
                         class="px-5 py-2.5 text-sm border-l-4 cursor-pointer transition-colors"
                         :class="seg.id === transcriptModal.anchorSegmentId
                            ? 'border-brand-600 bg-brand-50'
                            : (seg.segment_index === transcriptModal.activeIndex
                                ? 'border-amber-400 bg-amber-50/70'
                                : 'border-transparent hover:bg-slate-50')">
                        <span class="text-[11px] font-mono text-slate-400 mr-2 select-none"
                              x-text="hmsLabel(seg.start_seconds)"></span>
                        <span class="text-slate-700 leading-relaxed" x-html="highlightKeyword(seg.text)"></span>
                    </div>
                </template>
                <div x-show="transcriptModal.loadingAfter" class="py-3 text-center text-xs text-slate-400">
                    <i class="fas fa-circle-notch fa-spin mr-1"></i> Cargando segmentos siguientes…
                </div>
                <div x-show="!transcriptModal.loadingAfter && transcriptModal.lastIndex !== null && transcriptModal.lastIndex >= transcriptModal.totalSegments - 1"
                     class="py-3 text-center text-xs text-slate-300">— fin de la transcripción —</div>
            </div>
        </div>

        {{-- Pie: acciones --}}
        <div class="px-5 py-3 border-t border-slate-200 flex items-center justify-between gap-3 bg-slate-50">
            <p class="text-[11px] text-slate-400 hidden sm:block">Haz click en cualquier segmento para escuchar desde ahí.</p>
            <div class="flex items-center gap-2">
                <template x-if="transcriptModal.meta?.can_view_file && transcriptModal.meta?.file_id">
                    <button @click="openFilesTab()"
                            class="px-3 py-2 text-xs border border-slate-300 hover:bg-white text-slate-600 rounded-lg font-medium">
                        <i class="fas fa-folder-open mr-1"></i> Abrir en Mis Archivos
                    </button>
                </template>
                <button x-show="transcriptModal.meta?.can_clip" @click="openClipFromAnchor()"
                        class="px-3 py-2 text-xs bg-slate-700 hover:bg-slate-800 text-white rounded-lg font-medium">
                    <i class="fas fa-scissors mr-1"></i> Generar corte
                </button>
                <button @click="closeTranscript()"
                        class="px-3 py-2 text-xs border border-slate-300 hover:bg-white text-slate-600 rounded-lg">Cerrar</button>
            </div>
        </div>
    </div>
</div>
