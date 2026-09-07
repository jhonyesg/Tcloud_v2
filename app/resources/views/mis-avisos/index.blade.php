@extends('layouts.app')

@section('title', 'Mis Avisos - Tcloud')

@section('content')
<div class="p-6" x-data="misAvisosPage()">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Mis Avisos</h1>
            <p class="text-slate-500 mt-0.5">Tus palabras clave rastreadas en los medios que contrataste</p>
        </div>
        <a href="/mis-avisos/corrections/mine" class="text-sm text-brand-600 hover:underline whitespace-nowrap">Mis propuestas de corrección</a>
    </div>

    @if(! $moduleEnabled)
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-6">
        <i class="fas fa-info-circle text-amber-500 text-2xl mb-2 block"></i>
        <p class="font-medium text-amber-800">El administrador aún no te ha activado este módulo.</p>
    </div>
    @endif

    {{-- Tabs --}}
    <div class="flex gap-1 border-b border-slate-200 mb-6 overflow-x-auto" data-tour="tabs-bar">
        <template x-for="tab in ['live','history','keywords','prefs']" :key="tab">
            <button @click="switchTab(tab)"
                class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap flex items-center gap-2"
                :class="tab === activeTab ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
                <i class="fas text-sm" :class="tabIcons[tab]"></i>
                <span x-text="tabLabels[tab]"></span>
            </button>
        </template>
        <button onclick="startMisAvisosTour()" class="ml-auto flex items-center gap-1.5 text-xs text-slate-400 hover:text-brand-600 px-2" title="Guía interactiva">
            <i class="fas fa-circle-question"></i> Guía
        </button>
    </div>

    {{-- ═══ TAB: Keywords (split-screen: lista izquierda / alcance derecha) ═══ --}}
    <div x-show="activeTab === 'keywords'" x-cloak>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Izquierda: lista de keywords --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-4" data-tour="quota-counter">
                    <h2 class="text-sm font-semibold text-slate-700"><i class="fas fa-key mr-1.5 text-brand-500"></i>Mis palabras clave</h2>
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
                <div x-show="keywords.length === 0" class="text-sm text-slate-400 py-4 text-center">Sin palabras clave. Agrega la primera para empezar a rastrear.</div>
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <template x-for="kw in keywords" :key="kw.id">
                        <div @click="selectKeyword(kw)"
                             class="p-3 border rounded-lg cursor-pointer transition-colors"
                             :class="selectedKeywordId === kw.id ? 'border-brand-600 bg-brand-50/60' : 'border-slate-200 hover:bg-slate-50'">
                            <div class="flex items-center justify-between">
                                <div class="min-w-0">
                                    <span class="text-sm text-slate-700 font-medium" x-text="kw.text"></span>
                                    <div class="text-xs text-slate-400 mt-0.5" x-text="scopeLabel(kw)"></div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0 ml-2">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="scopeBadgeClass(kw)" :title="scopeBadgeTitle(kw)"></span>
                                    <button @click.stop="removeKeyword(kw.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Derecha: alcance de la keyword seleccionada --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6" x-show="selectedKeyword()" x-cloak>
                <h2 class="text-sm font-semibold text-slate-700 mb-1">
                    <i class="fas fa-tower-cell mr-1.5 text-brand-500"></i>
                    Dónde rastrear <span class="text-brand-700" x-text="'&quot;' + (selectedKeyword()?.text || '') + '&quot;'"></span>
                </h2>
                <p class="text-xs text-slate-500 mb-4">Marca los medios donde debe buscarse esta palabra. Sin marcar ninguno = se rastrea en todos tus medios.</p>

                <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100">
                    <span class="text-xs text-slate-500" x-text="scopeSummaryText()"></span>
                    <div class="flex gap-2">
                        <button @click="markAllStorages(true)" class="text-xs px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg">Marcar todos</button>
                        <button @click="markAllStorages(false)" class="text-xs px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg">Desmarcar todos</button>
                    </div>
                </div>

                <div class="space-y-2 max-h-72 overflow-y-auto mb-4">
                    <template x-for="st in storages" :key="st.id">
                        <label class="flex items-center gap-3 p-2.5 border rounded-lg cursor-pointer transition-colors"
                               :class="draftScope.includes(st.id) ? 'border-brand-500 bg-brand-50/50' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="checkbox" :value="st.id"
                                   :checked="draftScope.includes(st.id)"
                                   @change="toggleDraft(st.id, $event.target.checked)"
                                   class="w-4 h-4 accent-brand-600">
                            <i class="fas fa-tower-broadcast text-xs" :class="draftScope.includes(st.id) ? 'text-brand-600' : 'text-slate-300'"></i>
                            <span class="text-sm text-slate-700 flex-1" x-text="st.name"></span>
                            <span class="text-xs font-medium" :class="draftScope.includes(st.id) ? 'text-brand-700' : 'text-slate-400'"
                                  x-text="draftScope.includes(st.id) ? 'Activo' : 'Inactivo'"></span>
                        </label>
                    </template>
                    <div x-show="storages.length === 0" class="text-xs text-slate-400 py-3 text-center">
                        No tienes medios con acceso concedido. Pide al administrador activar el acceso a transcripciones.
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button @click="saveScope()" :disabled="!scopeDirty()" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Guardar alcance</button>
                    <button @click="revertScope()" :disabled="!scopeDirty()" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700 disabled:opacity-40">Cancelar</button>
                    <span x-show="scopeDirty()" class="text-xs text-amber-600"><i class="fas fa-triangle-exclamation mr-1"></i>Cambios sin guardar</span>
                </div>
            </div>

            {{-- Estado vacío del panel derecho --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6" x-show="!selectedKeyword() && keywords.length > 0" x-cloak>
                <div class="text-center py-10 text-slate-400">
                    <i class="fas fa-hand-pointer text-3xl mb-3 block text-slate-200"></i>
                    <p class="text-sm font-medium">Selecciona una palabra a la izquierda</p>
                    <p class="text-xs mt-1">y define en cuáles medios se rastrea.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ TAB: En vivo (tabla con filtros + paginación) ═══ --}}
    <div x-show="activeTab === 'live'" x-cloak>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Coincidencias de hoy</h2>
                <span class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full" :class="livePolling ? 'bg-green-500 animate-pulse' : 'bg-slate-300'"></span>
                    <span x-text="livePolling ? 'en vivo' : 'pausado'"></span>
                </span>
            </div>

            {{-- Filtros del feed en vivo (mismos filtros que el histórico) --}}
            <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-end gap-2 bg-slate-50/60">
                <div class="flex-1 min-w-[180px]">
                    <input type="text" x-model="liveFilters.q" @keydown.enter="applyLiveFilters()"
                           placeholder="Filtrar por texto (mín. 3 caracteres)…"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
                @include('mis-avisos._filter-storages', ['scope' => 'liveFilters'])
                <select x-model.number="liveFilters.keyword_id" @change="applyLiveFilters()"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-600 bg-white outline-none">
                    <option value="0">Todas mis keywords</option>
                    <template x-for="kw in keywords" :key="kw.id">
                        <option :value="kw.id" x-text="kw.text"></option>
                    </template>
                </select>
                <button @click="applyLiveFilters()" class="px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">Filtrar</button>
                <button x-show="liveHasFilters()" @click="clearLiveFilters()"
                        class="px-3 py-2 border border-slate-300 hover:bg-white text-slate-500 rounded-lg text-sm">Limpiar</button>
            </div>

            {{-- Aviso de coincidencias nuevas mientras se navega otra página --}}
            <div x-show="newLiveCount > 0" x-cloak class="px-4 py-2 bg-green-50 border-b border-green-100 text-xs text-green-700 flex items-center justify-between">
                <span><span x-text="newLiveCount"></span> coincidencia(s) nueva(s) mientras veías esta página</span>
                <button @click="goPage('live', 1); newLiveCount = 0" class="underline font-medium">Ir a la primera página</button>
            </div>

            @include('mis-avisos._table-hits', ['mode' => 'live'])
        </div>
    </div>

    {{-- ═══ TAB: Histórico (tabla con filtros + paginación) ═══ --}}
    <div x-show="activeTab === 'history'" x-cloak>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex flex-wrap items-end gap-3 mb-4">
                <div class="flex-1 min-w-[220px]">
                    <label class="text-xs text-slate-500 block mb-1">Buscar (mín. 3 caracteres)</label>
                    <input type="text" x-model="historyFilters.q" @keydown.enter="searchHistory(1)"
                           placeholder="término libre en las transcripciones..."
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="text-xs text-slate-500 block mb-1">Desde</label>
                    <input type="date" x-model="historyFilters.from" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs text-slate-500 block mb-1">Hasta</label>
                    <input type="date" x-model="historyFilters.to" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                @include('mis-avisos._filter-storages', ['scope' => 'historyFilters'])
                <div>
                    <label class="text-xs text-slate-500 block mb-1">Keyword</label>
                    <select x-model.number="historyFilters.keyword_id"
                            class="border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-600 bg-white outline-none">
                        <option value="0">Todas mis keywords</option>
                        <template x-for="kw in keywords" :key="kw.id">
                            <option :value="kw.id" x-text="kw.text"></option>
                        </template>
                    </select>
                </div>
                <button @click="searchHistory(1)" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">Buscar</button>
                <button @click="requestExport()" :disabled="exportBusy || !!activeExport"
                        class="px-4 py-2 border border-brand-600 text-brand-700 hover:bg-brand-50 rounded-lg text-sm font-medium disabled:opacity-50"
                        x-text="exportButtonLabel"></button>
            </div>
            <p class="text-xs text-slate-400 mb-4">Rango máximo: 60 días. Solo verás medios que contrataste. La exportación usa exactamente estos filtros.</p>

            <div x-show="activeExport" x-cloak class="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-800">
                <i class="fas fa-file-export mr-1"></i>
                <span x-text="exportStatusText"></span>
                <template x-if="activeExport && activeExport.status === 'ready'">
                    <span class="ml-2 flex gap-2 inline">
                        <a :href="activeExport.download_url" class="underline font-medium">Descargar</a>
                        <button @click="emailExport()" class="underline">Enviar a mi correo</button>
                    </span>
                </template>
            </div>
            <div x-show="historyError" x-cloak class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700" x-text="historyError"></div>

            @include('mis-avisos._table-hits', ['mode' => 'history'])
        </div>
    </div>

    {{-- ═══ TAB: Preferencias ═══ --}}
    <div x-show="activeTab === 'prefs'" x-cloak>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700 mb-1">¿Cada cuánto quieres recibir tus avisos por correo?</h2>
            <p class="text-sm text-slate-500 mb-4">Los avisos agrupan las coincidencias del día. Lo que no quepa en tu cupo diario sale en el resumen del día siguiente.</p>

            <div x-show="pendingReposition > 0" class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
                <i class="fas fa-clock mr-1"></i> Tienes <strong x-text="pendingReposition"></strong> coincidencia(s) retenida(s) por tu cupo diario de correos. Saldrán en el resumen de mañana.
            </div>

            <div class="space-y-2 mb-4">
                <template x-for="opt in frequencyOptions" :key="opt.minutes">
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                           :class="frequency === opt.minutes ? 'border-brand-600 bg-brand-50/50' : 'border-slate-200 hover:bg-slate-50'">
                        <input type="radio" name="frequency" :value="opt.minutes" x-model.number="frequency" class="mt-0.5 accent-brand-600">
                        <div class="flex-1">
                            <div class="text-sm font-medium text-slate-700" x-text="opt.label"></div>
                            <div class="text-xs text-slate-500" x-text="opt.hint"></div>
                            <div class="text-xs mt-1" :class="opt.minutes <= 5 ? 'text-amber-600' : 'text-slate-400'"
                                 x-text="opt.minutes <= 5
                                    ? '⚠ Con ' + opt.label.toLowerCase() + ' podrías recibir ~' + (projection[opt.minutes] ?? 0) + ' correos/semana según tu actividad reciente. Tu cupo diario es ' + emailsQuota + '; al alcanzarlo, el resto sale en el resumen del día siguiente.'
                                    : '~' + (projection[opt.minutes] ?? 0) + ' correos/semana con tu actividad de los últimos 7 días.'"></div>
                        </div>
                    </label>
                </template>
            </div>
            <button @click="saveFrequency()" :disabled="frequency === originalFrequency"
                    class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Guardar preferencia</button>
        </div>
    </div>

    @include('mis-avisos._correction-modal')
    @include('mis-avisos._transcript-modal')
</div>

@push('scripts')
<script>
function misAvisosPage() {
    return {
        activeTab: 'live',
        tabLabels: { keywords: 'Palabras clave', live: 'En vivo', history: 'Histórico', prefs: 'Preferencias' },
        tabIcons: { keywords: 'fa-key', live: 'fa-tower-broadcast', history: 'fa-clock-rotate-left', prefs: 'fa-sliders' },
        // Keywords (carga inicial server-side; el JS la re-hidrata con scope)
        used: {{ $used }}, quota: {{ $quota }},
        keywords: @json($user->userKeywords->map(fn($k) => ['id' => $k->id, 'text' => $k->text, 'storage_ids' => []])->values()),
        storages: @json($accessibleStorages),
        newKeyword: '',
        selectedKeywordId: null, draftScope: [],
        // En vivo (tabla con filtros + paginación server-side)
        liveRows: [], livePage: 1, liveLastPage: 1, liveTotal: 0,
        liveFilters: { q: '', storage_ids: [], keyword_id: 0 },
        newLiveCount: 0, liveTimer: null, livePolling: false,
        // Histórico
        historyFilters: { q: '', from: '', to: '', storage_ids: [], keyword_id: 0 },
        historyRows: [], historyPage: 1, historyLastPage: 1, historyTotal: 0,
        historySearched: false, historyError: '',
        // Visor de transcripción (mentions-viewer)
        transcriptModal: {
            open: false, loading: false, error: '',
            meta: null, hitKeyword: '',
            anchorSegmentId: null, anchorStart: null,
            segments: [], firstIndex: null, lastIndex: null, totalSegments: 0,
            loadingBefore: false, loadingAfter: false, activeIndex: null, pendingSeek: null, search: '',
        },
        // Corte: NO hay estado local. El editor vive unificado en /files;
        // openClipFromAnchor/openClipFromRow redirigen con deep-link.
        // Export
        activeExport: null, exportBusy: false, exportPoll: null,
        // Preferencias
        frequencyOptions: [
            { minutes: 1, label: 'Cada minuto', hint: 'Aviso casi inmediato al detectarse una coincidencia' },
            { minutes: 5, label: 'Cada 5 minutos', hint: 'Muy frecuente; solo para palabras poco comunes' },
            { minutes: 15, label: 'Cada 15 minutos', hint: '' },
            { minutes: 20, label: 'Cada 20 minutos', hint: '' },
            { minutes: 30, label: 'Cada 30 minutos', hint: 'Recomendado para empezar' },
            { minutes: 50, label: 'Cada 50 minutos', hint: '' },
            { minutes: 60, label: 'Cada hora', hint: 'Equilibrado' },
            { minutes: 240, label: '6 veces al día', hint: '' },
            { minutes: 480, label: '3 veces al día', hint: '' },
            { minutes: 1440, label: '1 vez al día', hint: 'Un resumen diario con todo el día' },
        ],
        frequency: 30, originalFrequency: 30, projection: {}, emailsQuota: 0, pendingReposition: 0,

        init() {
            this.hydrateHistoryFromUrl();
            this.loadKeywords();
            this.loadPreferences();
            this.startLive();
            this.$watch('activeTab', (t) => { if (t === 'live') this.startLive(); else this.stopLive(); });
        },

        csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        headers(json = true) {
            const h = { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() };
            if (json) h['Content-Type'] = 'application/json';
            return h;
        },

        switchTab(t) { this.activeTab = t; if (t === 'history' && !this.historySearched) this.searchHistory(1); },

        // ── Split-screen de alcance ──
        selectedKeyword() { return this.keywords.find(k => k.id === this.selectedKeywordId) || null; },
        selectKeyword(kw) {
            if (this.scopeDirty() && this.selectedKeywordId !== kw.id && !confirm('Hay cambios sin guardar en el alcance anterior. ¿Descartarlos?')) return;
            this.selectedKeywordId = kw.id;
            // Sin filas de scope = "todos mis medios": el editor muestra todos marcados.
            this.draftScope = (kw.storage_ids && kw.storage_ids.length > 0)
                ? [...kw.storage_ids]
                : this.storages.map(s => s.id);
        },
        toggleDraft(id, checked) {
            if (checked) { if (!this.draftScope.includes(id)) this.draftScope.push(id); }
            else this.draftScope = this.draftScope.filter(x => x !== id);
        },
        markAllStorages(on) { this.draftScope = on ? this.storages.map(s => s.id) : []; },
        scopeDirty() {
            const kw = this.selectedKeyword();
            if (!kw) return false;
            const a = [...this.draftScope].sort().join(',');
            const b = [...(kw.storage_ids || [])].sort().join(',');
            return a !== b;
        },
        revertScope() {
            const kw = this.selectedKeyword();
            if (!kw) return;
            this.draftScope = (kw.storage_ids && kw.storage_ids.length > 0) ? [...kw.storage_ids] : this.storages.map(s => s.id);
        },
        scopeSummaryText() {
            const kw = this.selectedKeyword();
            if (!kw) return '';
            const n = this.draftScope.length;
            if (n === 0) return 'Ningún medio marcado';
            if (n === this.storages.length) return 'Todos tus medios (' + n + ')';
            return n + ' de ' + this.storages.length + ' medios';
        },
        scopeBadgeClass(kw) {
            if (!kw.storage_ids || kw.storage_ids.length === 0) return 'bg-green-500';
            if (kw.storage_ids.length >= this.storages.length && this.storages.length > 0) return 'bg-green-500';
            return 'bg-amber-500';
        },
        scopeBadgeTitle(kw) {
            if (!kw.storage_ids || kw.storage_ids.length === 0) return 'Rastreando en todos tus medios';
            return 'Rastreando en ' + kw.storage_ids.length + ' de ' + this.storages.length + ' medios';
        },

        scopeLabel(kw) {
            if (!kw.storage_ids || kw.storage_ids.length === 0) return '· todos mis medios';
            const names = kw.storage_ids.map(id => (this.storages.find(s => s.id === id) || {}).name || '').filter(Boolean);
            return '· ' + (names.length ? names.join(', ') : 'medios seleccionados');
        },

        async loadKeywords() {
            // Hidratar el alcance real (scopes guardados) de cada keyword.
            const res = await apiFetch('/mis-avisos/storages', { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.storages = d.storages || [];
                const scopes = d.scopes || {};
                for (const kw of this.keywords) {
                    // Sin filas de scope = "todos mis medios" (default).
                    kw.storage_ids = scopes[kw.id] || [];
                }
                // Auto-seleccionar la primera keyword para mostrar el split de una vez.
                if (this.keywords.length > 0 && !this.selectedKeywordId) this.selectKeyword(this.keywords[0]);
            }
        },
        async loadStorages() { /* reemplazado: storages llegan en loadKeywords() */ },

        // ── Feed en vivo con filtros + paginación ──
        liveHasFilters() {
            const f = this.liveFilters;
            return !!(f.q.trim() || f.keyword_id || f.storage_ids.length > 0);
        },
        applyLiveFilters() {
            this.livePage = 1; this.liveTotal = 0; this.newLiveCount = 0;
            this.pollLive();
        },
        clearLiveFilters() {
            this.liveFilters = { q: '', storage_ids: [], keyword_id: 0 };
            this.applyLiveFilters();
        },
        toggleFilterStorage(scope, id, checked) {
            const list = this[scope].storage_ids;
            if (checked) { if (!list.includes(id)) list.push(id); }
            else this[scope].storage_ids = list.filter(x => x !== id);
            if (scope === 'liveFilters') this.applyLiveFilters();
        },
        goPage(mode, page) {
            if (mode === 'live') { this.livePage = Math.max(1, page); this.newLiveCount = 0; this.pollLive(); }
            else this.searchHistory(page);
        },

        startLive() {
            this.livePolling = true;
            this.pollLive();
            this.liveTimer = setInterval(() => this.pollLive(), 20000);
        },
        stopLive() {
            this.livePolling = false;
            if (this.liveTimer) clearInterval(this.liveTimer);
        },
        async pollLive() {
            try {
                const f = this.liveFilters;
                const params = new URLSearchParams();
                params.set('page', this.livePage);
                if (f.q.trim()) params.set('q', f.q.trim());
                if (f.keyword_id) params.set('keyword_id', f.keyword_id);
                f.storage_ids.forEach(id => params.append('storage_ids[]', id));
                const res = await apiFetch('/mis-avisos/feed?' + params.toString(), { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
                if (res.ok) {
                    const d = await res.json();
                    // Badge de nuevas solo si el cliente está en otra página.
                    if (d.total > this.liveTotal && this.liveTotal > 0 && this.livePage > 1) {
                        this.newLiveCount += d.total - this.liveTotal;
                    }
                    this.liveRows = d.data;
                    this.livePage = d.current_page;
                    this.liveLastPage = d.last_page;
                    this.liveTotal = d.total;
                }
            } catch (e) { /* silencio: el siguiente ciclo reintenta */ }
        },

        async searchHistory(page) {
            this.historyError = '';
            const f = this.historyFilters;
            const params = new URLSearchParams();
            params.set('page', page);
            if (f.q) params.set('q', f.q);
            if (f.from) params.set('from', f.from);
            if (f.to) params.set('to', f.to);
            if (f.keyword_id) params.set('keyword_id', f.keyword_id);
            f.storage_ids.forEach(id => params.append('storage_ids[]', id));
            this.syncHistoryUrl(params);
            const res = await apiFetch('/mis-avisos/history?' + params.toString(), { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.historyRows = d.data; this.historyPage = d.current_page;
                this.historyLastPage = d.last_page; this.historyTotal = d.total;
                this.historySearched = true;
            } else {
                const d = await res.json();
                this.historyError = d.error || 'Error al buscar';
                this.historyRows = [];
            }
        },
        syncHistoryUrl(params) {
            const clean = new URLSearchParams();
            for (const [k, v] of params) { if (k !== 'page' && v) clean.append(k, v); }
            const qs = clean.toString();
            history.replaceState(null, '', '/mis-avisos' + (qs ? '?' + qs : ''));
        },
        hydrateHistoryFromUrl() {
            const sp = new URLSearchParams(location.search);
            if (!sp.toString()) return false;
            const f = this.historyFilters;
            f.q = sp.get('q') || '';
            f.from = sp.get('from') || '';
            f.to = sp.get('to') || '';
            f.keyword_id = parseInt(sp.get('keyword_id') || '0', 10) || 0;
            f.storage_ids = sp.getAll('storage_ids[]').map(Number).filter(Boolean);
            this.activeTab = 'history';
            this.$nextTick(() => this.searchHistory(1));
            return true;
        },

        async saveScope() {
            const kw = this.selectedKeyword();
            if (!kw) return;
            // "Sin filas = todos": si marcó todos, guardar vacío (semántica default).
            const all = this.draftScope.length === this.storages.length && this.storages.length > 0;
            const payload = all ? [] : this.draftScope;
            const res = await apiFetch('/mis-avisos/keywords/' + kw.id + '/scope', {
                method: 'PUT', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify({ storage_ids: payload }),
            });
            if (res.ok) {
                kw.storage_ids = payload;
                this.draftScope = all ? this.storages.map(s => s.id) : [...payload];
            } else { const d = await res.json(); alert(d.error || 'Error'); }
        },

        get exportButtonLabel() {
            if (this.exportBusy) return 'Preparando...';
            if (this.activeExport && ['queued','processing'].includes(this.activeExport.status)) return 'Generando...';
            return 'Exportar CSV';
        },
        get exportStatusText() {
            const s = this.activeExport?.status;
            if (s === 'queued' || s === 'processing') return 'Generando tu archivo; en unos segundos estará listo.';
            if (s === 'ready') return 'Listo: ' + (this.activeExport.rows_count ?? 0) + ' coincidencia(s). El enlace expira automáticamente.';
            if (s === 'failed') return 'La exportación falló. Intenta de nuevo.';
            return '';
        },
        async requestExport() {
            this.exportBusy = true; this.historyError = '';
            const res = await apiFetch('/mis-avisos/exports', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify(this.historyFilters),
            });
            if (res.ok) {
                const d = await res.json();
                this.activeExport = { id: d.export_id, status: 'queued', rows_count: 0, download_url: null };
                this.pollExport();
            } else {
                const d = await res.json();
                this.historyError = d.error || 'No se pudo iniciar la exportación';
            }
            this.exportBusy = false;
        },
        async pollExport() {
            if (!this.activeExport) return;
            const res = await apiFetch('/mis-avisos/exports/' + this.activeExport.id, { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.activeExport = d;
                if (['queued','processing'].includes(d.status)) {
                    this.exportPoll = setTimeout(() => this.pollExport(), 3000);
                }
            }
        },
        async emailExport() {
            if (!this.activeExport) return;
            const res = await apiFetch('/mis-avisos/exports/' + this.activeExport.id + '/email', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
            });
            if (res.ok) { const d = await res.json(); alert('Enlace enviado a ' + d.sent + ' de ' + d.total + ' correos registrados.'); }
            else { const d = await res.json(); alert(d.error || 'Error al enviar'); }
        },

        async loadPreferences() {
            const res = await apiFetch('/mis-avisos/preferences', { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.frequency = this.originalFrequency = d.alert_frequency_minutes;
                this.projection = d.projection || {};
                this.emailsQuota = d.emails_quota;
                this.pendingReposition = d.pending_reposition;
            }
        },
        async saveFrequency() {
            const res = await apiFetch('/mis-avisos/preferences', {
                method: 'PUT', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify({ alert_frequency_minutes: this.frequency }),
            });
            if (res.ok) { this.originalFrequency = this.frequency; this.loadPreferences(); }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },

        // ── Visor de transcripción (mentions-viewer) ──
        filesDeepLink(row) {
            if (!row.file_id) return '/files';
            const p = new URLSearchParams();
            if (row.storage_id) p.set('storage_id', row.storage_id);
            if (row.parent_id) p.set('folder', row.parent_id);
            p.set('highlight_file', row.file_id);
            return '/files?' + p.toString();
        },
        openFilesTab() {
            const m = this.transcriptModal.meta;
            if (!m?.file_id) return;
            const p = new URLSearchParams();
            if (m.storage_id) p.set('storage_id', m.storage_id);
            if (m.parent_id) p.set('folder', m.parent_id);
            p.set('highlight_file', m.file_id);
            window.open('/files?' + p.toString(), '_blank');
        },
        norm(s) {
            return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        },
        // La extensión manda (el mime de la BD está cargado al revés en cientos
        // de miles de filas: .mp3 como video/mp4, .m4a como audio/mp4).
        mediaKind() {
            const m = this.transcriptModal.meta;
            if (!m) return 'none';
            const ext = ((m.file_name || '').split('.').pop() || '').toLowerCase();
            if (['mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm'].includes(ext)) return 'video';
            if (['mp3', 'm4a', 'wav', 'ogg', 'aac', 'flac', 'wma'].includes(ext)) return 'audio';
            if ((m.mime_type || '').startsWith('video/')) return 'video';
            if ((m.mime_type || '').startsWith('audio/')) return 'audio';
            return 'none';
        },
        visibleSegments() {
            const tm = this.transcriptModal;
            const q = this.norm(tm.search).trim();
            if (!q) return tm.segments;
            return tm.segments.filter(s => this.norm(s.text).includes(q));
        },
        hmsLabel(s) {
            const t = Math.max(0, Math.floor(Number(s) || 0));
            return [Math.floor(t / 3600), Math.floor((t % 3600) / 60), t % 60]
                .map(x => String(x).padStart(2, '0')).join(':');
        },
        accentAware(s) {
            // Regex que iguala con o sin tilde: Álvaro ≍ alvaro.
            const map = { a: '[aáàäâã]', e: '[eéèëê]', i: '[iíìïî]', o: '[oóòöôõ]', u: '[uúùüû]', n: '[nñ]', c: '[cç]' };
            return String(s).split('').map(ch => {
                const low = ch.toLowerCase();
                if (map[low]) return map[low];
                return ch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }).join('');
        },
        highlightKeyword(text) {
            const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            let safe = esc(text || '');
            const tm = this.transcriptModal;
            // Búsqueda dentro del modal (amarillo) con tokens para no anidar
            // marcas mal; la keyword de la mención va en ámbar.
            const q = (tm.search || '').trim();
            if (q) {
                safe = safe.replace(new RegExp(this.accentAware(q), 'gi'), m => '\u0001' + m + '\u0002');
            }
            const kw = (tm.hitKeyword || '').trim();
            if (kw) {
                safe = safe.replace(new RegExp(this.accentAware(kw), 'gi'), m => '<mark class="bg-amber-200/70 rounded px-0.5">' + m + '</mark>');
            }
            return safe
                .replace(/\u0001/g, '<mark class="bg-yellow-300/80 rounded px-0.5">')
                .replace(/\u0002/g, '</mark>');
        },
        async openTranscript(row, opts = {}) {
            const tm = this.transcriptModal;
            tm.open = true; tm.loading = true; tm.error = ''; tm.meta = null;
            tm.segments = []; tm.activeIndex = null;
            tm.hitKeyword = row.keyword || '';
            tm.anchorSegmentId = row.segment_id || null;
            tm.anchorStart = row.start_seconds ?? null;
            // "Ver": el reproductor arranca en el minuto de la mención.
            tm.pendingSeek = (opts.autoplay && tm.anchorStart !== null) ? tm.anchorStart : null;
            tm.firstIndex = tm.lastIndex = tm.totalSegments = 0;
            tm.loadingBefore = tm.loadingAfter = false;
            tm.search = '';
            try {
                const params = new URLSearchParams();
                if (row.segment_id) params.set('anchor_segment_id', row.segment_id);
                const res = await apiFetch('/mis-avisos/transcriptions/' + row.transcription_id + '?' + params.toString(),
                    { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
                if (res.ok) {
                    const d = await res.json();
                    tm.meta = d.transcription;
                    tm.segments = d.segments;
                    tm.firstIndex = d.first_index; tm.lastIndex = d.last_index; tm.totalSegments = d.total_segments;
                    this.$nextTick(() => {
                        const el = row.segment_id && document.getElementById('seg-' + row.segment_id);
                        el && el.scrollIntoView({ block: 'center' });
                    });
                } else {
                    const d = await res.json().catch(() => ({}));
                    tm.error = d.error || 'No se pudo cargar la transcripción';
                }
            } catch (e) { tm.error = 'Error de red: ' + e.message; }
            tm.loading = false;
        },
        onPlayerLoaded(e) {
            const tm = this.transcriptModal;
            const p = e.target;
            if (tm.pendingSeek !== null && typeof p.currentTime === 'number') {
                const max = Number.isFinite(p.duration) ? p.duration : tm.pendingSeek;
                p.currentTime = Math.min(tm.pendingSeek, max);
                p.play();
                tm.pendingSeek = null;
            }
        },
        closeTranscript() {
            const p = this.$refs.player;
            if (p) { try { p.pause(); } catch (e) {} }
            this.transcriptModal.open = false;
        },
        seekToSegment(seg) {
            const p = this.$refs.player;
            if (p && typeof p.currentTime === 'number') {
                p.currentTime = seg.start_seconds;
                p.play();
            }
            this.transcriptModal.activeIndex = seg.segment_index;
        },
        onPlayerTime(e) {
            const t = e.target.currentTime;
            const segs = this.transcriptModal.segments;
            if (!segs.length) return;
            let i = segs.findIndex(s => s.segment_index === this.transcriptModal.activeIndex);
            if (i < 0) i = 0;
            while (i > 0 && segs[i].start_seconds > t) i--;
            while (i < segs.length - 1 && segs[i + 1].start_seconds <= t) i++;
            const cur = segs[i];
            this.transcriptModal.activeIndex = (t >= cur.start_seconds && t < cur.end_seconds) ? cur.segment_index : null;
        },
        onSegmentsScroll(e) {
            const el = e.target;
            const tm = this.transcriptModal;
            const remaining = el.scrollHeight - el.scrollTop - el.clientHeight;
            if (!tm.loadingBefore && el.scrollTop < 400 && tm.firstIndex > 0) this.loadBefore();
            if (!tm.loadingAfter && remaining < 600 && tm.lastIndex < tm.totalSegments - 1) this.loadAfter();
        },
        async loadAfter() {
            const tm = this.transcriptModal;
            tm.loadingAfter = true;
            try {
                const res = await apiFetch('/mis-avisos/transcriptions/' + tm.meta.id + '/segments?after_index=' + tm.lastIndex,
                    { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
                if (res.ok) {
                    const d = await res.json();
                    tm.segments.push(...d.segments);
                    tm.lastIndex = d.last_index;
                }
            } catch (e) { /* reintenta en el próximo scroll */ }
            tm.loadingAfter = false;
        },
        async loadBefore() {
            const tm = this.transcriptModal;
            const el = this.$refs.segList;
            tm.loadingBefore = true;
            const prevHeight = el ? el.scrollHeight : 0;
            try {
                const res = await apiFetch('/mis-avisos/transcriptions/' + tm.meta.id + '/segments?before_index=' + tm.firstIndex,
                    { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
                if (res.ok) {
                    const d = await res.json();
                    tm.segments.unshift(...d.segments);
                    tm.firstIndex = d.first_index;
                    this.$nextTick(() => { if (el) el.scrollTop += el.scrollHeight - prevHeight; });
                }
            } catch (e) { /* reintenta en el próximo scroll */ }
            tm.loadingBefore = false;
        },

        // ── Corte: redirige al editor unificado en /files ──
        // El editor vive SOLO en /files/index.blade.php (un solo lugar para
        // mantener; si se ajusta el editor se ajusta para todos los módulos).
        buildClipDeepLink(fileId, storageId, start, end) {
            if (!fileId || !storageId) return null;
            const params = new URLSearchParams();
            params.set('storage_id', storageId);
            params.set('clip_file', fileId);
            if (start !== null && start !== undefined && !Number.isNaN(start)) {
                params.set('clip_start', Math.max(0, Math.floor(start)));
            }
            if (end !== null && end !== undefined && !Number.isNaN(end)) {
                params.set('clip_end', Math.max(0, Math.ceil(end)));
            }
            return '/files?' + params.toString();
        },
        openClipFromRow(row) {
            if (!row.can_clip || !row.file_id || !row.storage_id) return;
            const url = this.buildClipDeepLink(
                row.file_id,
                row.storage_id,
                row.start_seconds ?? 0,
                row.end_seconds ?? row.start_seconds ?? 0
            );
            if (url) window.location.href = url;
        },
        openClipFromAnchor() {
            const tm = this.transcriptModal;
            if (!tm.meta?.can_clip || !tm.meta?.file_id || !tm.meta?.storage_id) return;
            const anchor = tm.segments.find(s => s.id === tm.anchorSegmentId)
                || tm.segments.find(s => s.segment_index === tm.activeIndex)
                || tm.segments[0];
            if (!anchor) return;
            const url = this.buildClipDeepLink(
                tm.meta.file_id,
                tm.meta.storage_id,
                anchor.start_seconds,
                anchor.end_seconds
            );
            if (url) window.location.href = url;
        },

        // Métodos heredados (keywords + correcciones)
        async addKeyword() {
            if (!this.newKeyword || this.used >= this.quota) return;
            const res = await apiFetch('/mis-avisos/keywords', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify({ text: this.newKeyword }),
            });
            if (res.ok) { const d = await res.json(); this.keywords.push({ id: d.keyword.id, text: d.keyword.text, storage_ids: [] }); this.used = d.used; this.newKeyword = ''; }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },
        async removeKeyword(id) {
            const res = await apiFetch('/mis-avisos/keywords/' + id, {
                method: 'DELETE', credentials: 'same-origin', headers: this.headers(false),
            });
            if (res.ok) { this.keywords = this.keywords.filter(k => k.id !== id); this.used = Math.max(0, this.used - 1); }
        },
        showModal: false,
        form: { wrong_text: '', correct_text: '', segment_id: null },
        openCorrection(opts) {
            this.form.wrong_text = opts.wrong || '';
            this.form.correct_text = '';
            this.form.segment_id = opts.segmentId || null;
            this.showModal = true;
        },
        async submitCorrection() {
            const res = await apiFetch('/mis-avisos/corrections', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showModal = false; alert('Propuesta enviada para revisión'); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || d.error || 'Error'); }
        },
    };
}
</script>
@endpush
{{-- Tour interactivo (patrón interactive-tour.js) --}}
<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startMisAvisosTour() {
    TcloudTour.start({
        steps: [
            {
                title: 'Mis Avisos — tu rastreo de menciones',
                content: 'Este módulo vigila tus palabras clave en las transcripciones de los medios que contrataste. ' +
                         'Todo lo que ves aquí respeta el acceso concedido por el administrador.',
                icon: 'fa-bell',
                color: '#4654a8',
                selector: null,
                position: 'center',
            },
            {
                title: 'En vivo',
                content: 'Las coincidencias de hoy aparecen aquí automáticamente, sin recargar la página. ' +
                         'Cada una muestra el medio, el minuto exacto y un fragmento del texto.',
                icon: 'fa-satellite-dish',
                color: '#16a34a',
                selector: null,
                position: 'center',
            },
            {
                title: 'Histórico de 60 días',
                content: 'Busca libremente dentro de las transcripciones de tus medios y exporta los resultados a CSV. ' +
                         'La búsqueda cubre hasta 60 días atrás. Puedes enviarte el archivo a tu correo cuando lo necesites.',
                icon: 'fa-folder-open',
                color: '#0ea5e9',
                selector: null,
                position: 'center',
            },
            {
                title: 'Palabras clave y su alcance',
                content: 'Crea y administra tus palabras (el límite lo define tu plan). ' +
                         'Al seleccionar una palabra, a la derecha eliges en qué medios se busca: sin marcar ninguno se rastrea en todos tus medios.',
                icon: 'fa-key',
                color: '#4654a8',
                selector: null,
                position: 'center',
            },
            {
                title: 'Preferencias de aviso',
                content: 'Tú decides cada cuánto recibir correos: de cada minuto a un resumen diario. ' +
                         'La proyección usa tu actividad real. Al agotar tu cupo diario, lo pendiente sale en el resumen del día siguiente — nunca se pierde.',
                icon: 'fa-sliders',
                color: '#f59e0b',
                selector: null,
                position: 'center',
            },
        ],
    });
}
</script>
@endsection