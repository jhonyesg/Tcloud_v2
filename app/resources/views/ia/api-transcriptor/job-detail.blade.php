@extends('layouts.app')

@section('title', 'Detalle del Job - Tcloud')

@section('content')
<div class="p-6" x-data="jobDetail()">

    <div class="mb-4">
        <a href="/ia/api-transcriptor" class="text-sm text-brand-600 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i> Volver al listado
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">
                    {{ $job->file?->name ?? ('File #' . $job->file_id) }}
                </h1>
                <p class="text-sm text-slate-500 mt-1">Job ID: {{ $job->job_id ?? '—' }} · Nodo: {{ $job->node_url ?? '—' }}</p>
                <p class="text-xs text-slate-400 mt-1">Transcripción #{{ $job->id }} · Iniciado: {{ $job->started_at?->format('Y-m-d H:i') ?? '—' }} · Finalizado: {{ $job->finished_at?->format('Y-m-d H:i') ?? '—' }}</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium {{ \App\Http\Controllers\Ia\ApiTranscriptorController::stateClass($job->state) }}">
                    <span class="w-2 h-2 rounded-full {{ \App\Http\Controllers\Ia\ApiTranscriptorController::stateDot($job->state) }}"></span>
                    {{ $job->state }}
                </span>
                @if($job->state === 'done')
                    @if($job->corrected === \App\Models\Transcription::CORRECTED_PENDING)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium bg-amber-100 text-amber-800" title="El corrector async de la API (whisper-turbo) aún no terminó. El SRT visible es la versión sin corregir.">
                            <i class="fas fa-hourglass-half text-xs"></i> Corrección pendiente
                        </span>
                    @elseif($job->corrected === \App\Models\Transcription::CORRECTED_DONE)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium bg-emerald-100 text-emerald-800" title="El corrector async terminó. El SRT visible es la versión corregida por la API.">
                            <i class="fas fa-spell-check text-xs"></i> Corregido
                        </span>
                    @elseif($job->corrected === \App\Models\Transcription::CORRECTED_LOST)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium bg-slate-200 text-slate-700" title="El corrector async no recuperó este job (timeout o motor reiniciado). El SRT visible es la versión sin corregir.">
                            <i class="fas fa-question-circle text-xs"></i> Sin corrección
                        </span>
                    @endif
                @endif
                @if(in_array($job->state, ['error','dead']))
                <form method="POST" action="/ia/api-transcriptor/jobs/{{ $job->id }}/retry" @submit.prevent="retry($event)">
                    @csrf
                    <button type="submit" class="flex items-center gap-1.5 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-redo"></i> Reintentar
                    </button>
                </form>
                @endif
                <button @click="destroyJob()" class="flex items-center gap-1.5 px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 rounded-xl text-sm font-medium transition-colors">
                    <i class="fas fa-trash"></i> Eliminar Transcription
                </button>
            </div>
        </div>

        @if($job->error_message)
        <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <i class="fas fa-exclamation-circle mr-1.5"></i> {{ $job->error_message }}
        </div>
        @endif

        <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><span class="text-slate-500">Duración:</span> <span class="font-medium text-slate-700">{{ $job->duration_seconds ? $job->duration_seconds . 's' : '—' }}</span></div>
            <div><span class="text-slate-500">Palabras:</span> <span class="font-medium text-slate-700">{{ $job->word_count ?? '—' }}</span></div>
            <div><span class="text-slate-500">Segmentos:</span> <span class="font-medium text-slate-700">{{ $job->segments->count() }}</span></div>
            <div><span class="text-slate-500">Idioma:</span> <span class="font-medium text-slate-700">{{ $job->language }}</span></div>
        </div>
    </div>

    @if($job->segments->isNotEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-slate-200">
            <h2 class="text-sm font-semibold text-slate-700">
                Segmentos <span class="text-slate-400 font-normal">({{ $job->segments->count() }})</span>
            </h2>
        </div>
        <div class="divide-y divide-slate-100 max-h-[600px] overflow-auto">
            @foreach($job->segments->sortBy('segment_index') as $seg)
            <div class="flex gap-3 px-4 py-2 hover:bg-slate-50">
                <span class="text-[11px] font-mono text-slate-400 whitespace-nowrap pt-0.5 flex-shrink-0">
                    {{ $seg->getStartLabel() }} &rarr; {{ $seg->getEndLabel() }}
                </span>
                <span class="text-sm text-slate-700 leading-relaxed break-words">{{ $seg->text }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($job->srt_content)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">SRT</h2>
            <a href="#" @click.prevent="downloadSrt()"
               class="text-xs text-brand-600 hover:underline">
                <i class="fas fa-download mr-1"></i> Descargar .srt
            </a>
        </div>
        <pre class="p-4 text-xs text-slate-700 overflow-auto max-h-[600px] bg-slate-50">{{ $job->srt_content }}</pre>
    </div>
    @endif
</div>

@push('scripts')
<script>
function jobDetail() {
    return {
        jobId: {{ $job->id }},
        srtContent: @js($job->srt_content),
        async retry(ev) {
            const form = ev.target;
            const res = await apiFetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) { window.location.href = '/ia/api-transcriptor'; }
            else { const d = await res.json(); alert(d.error || 'No se pudo reintentar'); }
        },
        async destroyJob() {
            if (!confirm('¿Eliminar esta transcripción?')) return;
            const res = await apiFetch('/ia/api-transcriptor/jobs/' + this.jobId, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) window.location.href = '/ia/api-transcriptor';
            else alert('Error al eliminar');
        },
        // Vive dentro del componente: como funcion global leia
        // window.jobDetail?.srtContent, que resuelve a una propiedad de la
        // *funcion* jobDetail (undefined) y descargaba un fichero vacio.
        downloadSrt() {
            const blob = new Blob([this.srtContent ?? ''], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'transcripcion_' + this.jobId + '.srt';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        },
    };
}
</script>
@endpush
@endsection