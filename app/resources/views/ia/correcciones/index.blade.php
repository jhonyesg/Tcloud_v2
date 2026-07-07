@extends('layouts.app')

@section('title', 'Correcciones - Tcloud')

@section('content')
<div class="p-6" x-data="correccionesAdmin()" x-init="init()">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Correcciones</h1>
            <p class="text-slate-500 mt-0.5">Diccionario moderado de correcciones del transcriptor</p>
        </div>
        <div class="flex gap-2">
            <button @click="openNew()" class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-plus"></i> Nueva corrección
            </button>
            <button @click="openApply()" class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-sync-alt"></i> Re-aplicar a todas
            </button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mb-4 flex gap-2">
        <button @click="tab = 'pending'" :class="tab === 'pending' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
            Pendientes <span x-show="pendingCount > 0" class="ml-1 px-1.5 py-0.5 bg-red-500 text-white text-[10px] rounded-full" x-text="pendingCount"></span>
        </button>
        <button @click="tab = 'approved'" :class="tab === 'approved' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
            Aprobadas
        </button>
    </div>

    <!-- Tab Pendientes -->
    <div x-show="tab === 'pending'" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div x-show="loadingPending" class="flex items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-brand-400"></i></div>
        <div x-show="!loadingPending && pending.length === 0" class="text-center py-12 text-slate-400">
            <p class="font-medium">No hay correcciones pendientes</p>
        </div>
        <table x-show="!loadingPending && pending.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Original</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Corrección</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Proponente</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Fecha</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="c in pending" :key="c.id">
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="c.wrong_text"></td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="c.correct_text"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500" x-text="c.proposed_by?.username || '—'"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500" x-text="formatDate(c.created_at)"></td>
                        <td class="px-4 py-3 text-right">
                            <button @click="approve(c)" class="px-3 py-1 bg-green-50 hover:bg-green-100 text-green-700 text-xs rounded-lg">Aprobar</button>
                            <button @click="openReject(c)" class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-700 text-xs rounded-lg">Rechazar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Tab Aprobadas -->
    <div x-show="tab === 'approved'" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @if($approved->count())
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Original</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Corrección</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Proponente</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Aprobador</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Aplicaciones</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Fecha</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($approved as $c)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm text-slate-700">{{ $c->wrong_text }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ $c->correct_text }}</td>
                    <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500">{{ $c->proposedBy?->username ?? '—' }}</td>
                    <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500">{{ $c->approvedBy?->username ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-700">{{ $c->applies_count }}</td>
                    <td class="px-4 py-3 hidden lg:table-cell text-sm text-slate-500">{{ $c->approved_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <button @click="destroyApproved({{ $c->id }})" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center py-12 text-slate-400">
            <p class="font-medium">No hay correcciones aprobadas</p>
        </div>
        @endif
    </div>

    <!-- Modal Nueva corrección -->
    <div x-cloak x-show="showNew" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showNew = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Nueva corrección</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Texto incorrecto</label>
                        <input type="text" x-model="form.wrong" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Corrección</label>
                        <input type="text" x-model="form.correct" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="saveNew()" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium">Guardar (aprobada)</button>
                    <button @click="showNew = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Rechazo -->
    <div x-cloak x-show="showReject" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showReject = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Rechazar corrección</h2>
                <p class="text-sm text-slate-500 mb-5" x-text="rejectItem ? (rejectItem.wrong_text + ' → ' + rejectItem.correct_text) : ''"></p>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Motivo (opcional)</label>
                    <textarea x-model="rejectReason" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none"></textarea>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="confirmReject()" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium">Rechazar</button>
                    <button @click="showReject = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Re-aplicar -->
    <div x-cloak x-show="showApply" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showApply = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Re-aplicar correcciones</h2>
                <p class="text-sm text-slate-500 mb-5">Se reaplicará el diccionario aprobado a todos los segmentos existentes.</p>
                <div x-show="preview !== null" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 mb-4">
                    Segmentos que se verían afectados: <span class="font-bold" x-text="preview"></span>
                </div>
                <div class="flex gap-3">
                    <button @click="runApply()" :disabled="applying" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium disabled:opacity-50">
                        <span x-text="applying ? 'Aplicando...' : 'Confirmar y aplicar'"></span>
                    </button>
                    <button @click="showApply = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
                <div x-show="applyResult" class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800" x-text="applyResult"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function correccionesAdmin() {
    return {
        tab: 'pending',
        pending: [],
        loadingPending: false,
        pendingCount: {{ $pendingCount }},
        showNew: false,
        showReject: false,
        showApply: false,
        rejectItem: null,
        rejectReason: '',
        applying: false,
        preview: null,
        applyResult: '',
        form: { wrong: '', correct: '' },
        async init() { await this.loadPending(); },
        async loadPending() {
            this.loadingPending = true;
            try {
                const res = await apiFetch('/ia/correcciones/pending', { headers: { 'Accept': 'application/json' } });
                if (res.ok) { this.pending = await res.json(); this.pendingCount = this.pending.length; }
            } finally { this.loadingPending = false; }
        },
        async approve(c) {
            const res = await apiFetch('/ia/correcciones/' + c.id + '/approve', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) { await this.loadPending(); window.location.reload(); }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },
        openReject(c) { this.rejectItem = c; this.rejectReason = ''; this.showReject = true; },
        async confirmReject() {
            const res = await apiFetch('/ia/correcciones/' + this.rejectItem.id + '/reject', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ rejected_reason: this.rejectReason }),
            });
            if (res.ok) { this.showReject = false; await this.loadPending(); }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },
        openNew() { this.form = { wrong: '', correct: '' }; this.showNew = true; },
        async saveNew() {
            const res = await apiFetch('/ia/correcciones', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]).content },
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showNew = false; window.location.reload(); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || 'Error'); }
        },
        async destroyApproved(id) {
            if (!confirm('¿Eliminar esta corrección aprobada?')) return;
            const res = await apiFetch('/ia/correcciones/' + id, {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) window.location.reload();
        },
        async openApply() {
            this.applyResult = ''; this.preview = null;
            try {
                const res = await apiFetch('/ia/correcciones/preview-retroactive', { headers: { 'Accept': 'application/json' } });
                if (res.ok) { const d = await res.json(); this.preview = d.would_update; }
            } catch { this.preview = '?'; }
            this.showApply = true;
        },
        async runApply() {
            this.applying = true;
            try {
                const res = await apiFetch('/ia/correcciones/apply-retroactive', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({}),
                });
                if (res.ok) {
                    const d = await res.json();
                    this.applyResult = `${d.updated} segmentos actualizados en ${d.elapsed_seconds} segundos`;
                    setTimeout(() => { this.showApply = false; window.location.reload(); }, 2500);
                } else { const d = await res.json(); alert(d.error || 'Error'); }
            } finally { this.applying = false; }
        },
        formatDate(d) { return d ? new Date(d).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) : '—'; },
    };
}
</script>
@endpush
@endsection