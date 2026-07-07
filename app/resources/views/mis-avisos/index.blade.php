@extends('layouts.app')

@section('title', 'Mis Avisos - Tcloud')

@section('content')
<div class="p-6" x-data="misAvisos({{ $used }}, {{ $quota }}, {{ json_encode($user->userKeywords->map(fn($k) => ['id' => $k->id, 'text' => $k->text])->values()) }})">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">Mis Avisos</h1>
        <p class="text-slate-500 mt-0.5">Gestiona tus palabras clave y revisa el historial de alertas</p>
    </div>

    @if(! $moduleEnabled)
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-6">
        <i class="fas fa-info-circle text-amber-500 text-2xl mb-2 block"></i>
        <p class="font-medium text-amber-800">El administrador aún no te ha activado este módulo.</p>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Mis palabras clave -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Mis palabras clave</h2>
                <span class="text-sm font-medium text-slate-600"><span x-text="used" x-cloak></span> / <span x-text="quota" x-cloak></span></span>
            </div>
            <div class="flex gap-2 mb-4">
                <input type="text" x-model="newKeyword" placeholder="palabra o frase" @keydown.enter="addKeyword()"
                       :disabled="used >= quota || quota === 0"
                       class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none disabled:opacity-50"
                       :title="used >= quota ? 'Cupo alcanzado' : ''">
                <button @click="addKeyword()" :disabled="used >= quota || quota === 0"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Agregar</button>
            </div>
            <div x-show="keywords.length === 0" class="text-sm text-slate-400 py-4 text-center">Sin keywords registradas</div>
            <div class="space-y-2 max-h-72 overflow-y-auto">
                <template x-for="kw in keywords" :key="kw.id">
                    <div class="flex items-center justify-between p-2.5 border border-slate-200 rounded-lg">
                        <span class="text-sm text-slate-700" x-text="kw.text"></span>
                        <button @click="removeKeyword(kw.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Alertas recibidas -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Alertas recibidas</h2>
                <a href="/mis-avisos/corrections/mine" class="text-xs text-brand-600 hover:underline">Mis propuestas</a>
            </div>
            @if($matches->count())
                <div class="divide-y divide-slate-100 max-h-96 overflow-y-auto">
                    @foreach($matches as $match)
                    <div class="p-4">
                        <div class="flex items-center justify-between mb-1">
                            <a href="/files/{{ $match->transcription?->file_id }}/preview" class="text-sm font-medium text-brand-600 hover:underline">{{ $match->transcription?->file?->name ?? '—' }}</a>
                            <span class="text-xs text-slate-400">{{ $match->matched_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-500 mb-2">
                            <span class="px-2 py-0.5 bg-brand-50 text-brand-700 rounded">{{ $match->keyword?->text }}</span>
                            <span>{{ $match->segment?->getStartLabel() ?? '—' }}</span>
                        </div>
                        <p class="text-sm text-slate-600 mb-2">{{ $match->snippet }}</p>
                        <button @click="openCorrection({ wrong: @js($match->snippet), segmentId: {{ $match->segment_id }} })"
                                class="text-xs text-brand-600 hover:underline">
                            <i class="fas fa-pen mr-1"></i> Reportar corrección
                        </button>
                    </div>
                    @endforeach
                </div>
                <div class="px-4 py-3 border-t border-slate-200">{{ $matches->links() }}</div>
            @else
                <div class="text-center py-12 text-slate-400">
                    <i class="fas fa-search text-3xl mb-2 block text-slate-200"></i>
                    <p class="font-medium">Aún no se han detectado coincidencias</p>
                    <p class="text-sm mt-1">Las alertas llegan por email en tiempo real.</p>
                </div>
            @endif
        </div>
    </div>
</div>

@include('mis-avisos._correction-modal')

@push('scripts')
<script>
function misAvisos(used, quota, initialKeywords) {
    return {
        used, quota,
        keywords: initialKeywords,
        newKeyword: '',
        showModal: false,
        form: { wrong_text: '', correct_text: '', segment_id: null },
        async addKeyword() {
            if (!this.newKeyword || this.used >= this.quota) return;
            const res = await apiFetch('/mis-avisos/keywords', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ text: this.newKeyword }),
            });
            if (res.ok) { const d = await res.json(); this.keywords.push(d.keyword); this.used = d.used; this.newKeyword = ''; }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },
        async removeKeyword(id) {
            const res = await apiFetch('/mis-avisos/keywords/' + id, {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) { this.keywords = this.keywords.filter(k => k.id !== id); this.used = Math.max(0, this.used - 1); }
        },
        openCorrection(opts) {
            this.form.wrong_text = opts.wrong || '';
            this.form.correct_text = '';
            this.form.segment_id = opts.segmentId || null;
            this.showModal = true;
        },
        async submitCorrection() {
            const res = await apiFetch('/mis-avisos/corrections', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showModal = false; alert('Propuesta enviada para revisión'); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || d.error || 'Error'); }
        },
    };
}
</script>
@endpush
@endsection