@extends('layouts.app')

@section('title', 'Detalle Avisos Inteligentes - Tcloud')

@section('content')
<div class="p-6" x-data="userDetail({{ $user->id }}, {{ json_encode($user->alertsInteligente?->emailsList() ?? []) }}, {{ $user->alertsInteligente?->keywords_quota ?? 0 }}, {{ $user->userKeywords->count() }})" x-init="init()">

    <div class="mb-4">
        <a href="/ia/avisos-inteligentes" class="text-sm text-brand-600 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i> Volver a usuarios
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">{{ $user->username ?? $user->email }}</h1>
                <p class="text-sm text-slate-500 mt-1">{{ $user->email }}</p>
            </div>
            <div class="flex gap-2">
                <button onclick="startAvisosInteligentesTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
                    <i class="fas fa-map-marked-alt"></i>
                    <span class="hidden sm:inline">Guía</span>
                </button>
            </div>
        </div>
        <div class="flex items-center gap-4 mt-3 text-sm flex-wrap">
            <div><span class="text-slate-500">Keywords:</span> <span class="font-semibold text-slate-800"><span x-text="used"></span> / <span x-text="quota"></span></span></div>
            <div><span class="text-slate-500">Correos:</span> <span class="font-semibold text-slate-800" x-text="emails.length"></span></div>
            <div><span class="text-slate-500">Acceso a transcripciones:</span> <span class="font-semibold text-slate-800"><span x-text="accessCount"></span> / <span x-text="storages.length"></span></span></div>
        </div>
        <div class="mt-4 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-600 flex items-center gap-2">
            <i class="fas fa-info-circle text-slate-400"></i>
            <span>
                <strong>Api-Transcriptor:</strong>
                <span class="font-semibold text-slate-800">{{ $globalTranscribing }}</span>
                <span class="text-slate-400">/</span>
                <span>{{ $globalStorages }}</span>
                storages transcribiendo globalmente. Esta pantalla solo concede <em>acceso a los resultados</em> a este cliente, no decide qué se transcribe.
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Correos -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-4">Correos de aviso</h2>
            <div class="flex gap-2 mb-4">
                <input type="email" x-model="newEmail" placeholder="correo@ejemplo.com" @keydown.enter="addEmail()"
                       class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                <button @click="addEmail()" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">Agregar</button>
            </div>
            <div x-show="emails.length === 0" class="text-sm text-slate-400 py-4 text-center">Sin correos registrados</div>
            <div class="space-y-2">
                <template x-for="(email, idx) in emails" :key="email">
                    <div class="flex items-center justify-between p-2.5 border border-slate-200 rounded-lg">
                        <span class="text-sm text-slate-700" x-text="email"></span>
                        <div class="flex gap-1.5">
                            <button @click="testEmail(email)" class="text-xs px-2 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 rounded">Probar</button>
                            <button @click="removeEmail(email)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Keywords -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-4">Keywords</h2>
            <div class="flex gap-2 mb-4">
                <input type="text" x-model="newKeyword" placeholder="palabra o frase" @keydown.enter="addKeyword()"
                       :disabled="used >= quota"
                       class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none disabled:opacity-50"
                       :title="used >= quota ? 'Cupo alcanzado' : ''">
                <button @click="addKeyword()" :disabled="used >= quota" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Agregar</button>
            </div>
            <div x-show="keywords.length === 0" class="text-sm text-slate-400 py-4 text-center">Sin keywords registradas</div>
            <div class="space-y-2 max-h-64 overflow-y-auto">
                <template x-for="kw in keywords" :key="kw.id">
                    <div class="flex items-center justify-between p-2.5 border border-slate-200 rounded-lg">
                        <span class="text-sm text-slate-700" x-text="kw.text"></span>
                        <button @click="removeKeyword(kw.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Canales del cliente. Aquí se concede acceso a los resultados que
         api-transcriptor produce. No se decide qué se transcribe (eso es
         /ia/api-transcriptor). El badge "Transcribe / Sin transcripción"
         anterior era espejo del flag global y se quitó para evitar la confusión
         de que parecía un control de transcripción. --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-700">Acceso a transcripciones por canal</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Activa el acceso por storage para que el cliente pueda ver las transcripciones y correcciones que api-transcriptor produce en ese canal. Qué se transcribe se gestiona en
                    <a href="/ia/api-transcriptor" class="text-brand-600 hover:underline">API Transcriptor</a>; aquí solo se concede acceso a los resultados.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">
                    <span class="font-semibold text-slate-800" x-text="accessCount"></span>
                    <span class="text-slate-400">/</span>
                    <span x-text="storages.length"></span>
                    <span class="text-slate-500">con acceso</span>
                </span>
                <input type="text" x-model="storageFilter" placeholder="Filtrar storage..."
                       class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 outline-none w-44">
            </div>
        </div>

        <div x-show="storages.length === 0" class="text-center py-12 text-slate-400">
            <p class="font-medium">Este cliente no tiene storages asignados</p>
            <p class="text-sm mt-1">Asígnaselos primero en <a href="/admin/storages" class="text-brand-600 hover:underline">Storages</a>.</p>
        </div>

        <div x-show="storages.length > 0" class="max-h-96 overflow-y-auto divide-y divide-slate-100">
            <template x-for="s in filteredStorages" :key="s.id">
                <div class="flex items-center justify-between px-4 py-2.5 hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="text-sm text-slate-700 truncate" x-text="s.name"></p>
                        <p class="text-xs text-slate-400" x-text="s.type"></p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span x-show="!s.transcription_enabled" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1" title="Api-Transcriptor no está produciendo en este canal. El acceso se aplicará cuando vuelva a habilitarse.">
                            <i class="fas fa-exclamation-triangle mr-1"></i>Sin producción
                        </span>
                        <button @click="setAccess(s)"
                                :data-tour="s.id === firstStorageId ? 'storage-access-toggle' : null"
                                type="button"
                                :disabled="togglingAccess.has(s.id)"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-wait"
                                :class="accessStates[s.id] ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                                :title="accessStates[s.id] ? 'Este cliente tiene acceso a las transcripciones de este canal. Clic para revocar.' : 'Sin acceso. Clic para conceder.'">
                            <span class="w-1.5 h-1.5 rounded-full" :class="accessStates[s.id] ? 'bg-green-500' : 'bg-slate-400'"></span>
                            <span x-text="accessStates[s.id] ? 'Con acceso' : 'Sin acceso'"></span>
                        </button>
                    </div>
                </div>
            </template>
            <div x-show="filteredStorages.length === 0" class="px-4 py-8 text-center text-sm text-slate-400">
                Ningún storage coincide con el filtro
            </div>
        </div>
    </div>

    <!-- Matches -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
            <h2 class="text-sm font-semibold text-slate-700">Matches</h2>
        </div>
        @php
            $matches = $matches ?? null;
        @endphp
        @if($matches && $matches->count())
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Fecha</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Grabación</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Minuto</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Keyword</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Snippet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($matches as $match)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $match->matched_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($match->transcription?->file_id)
                            <a href="/files/{{ $match->transcription->file_id }}/preview" class="text-brand-600 hover:underline">{{ $match->transcription?->file?->name ?? 'File' }}</a>
                            @else — @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $match->segment?->getStartLabel() ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-slate-700">{{ $match->keyword?->text ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600 truncate max-w-xs">{{ $match->snippet }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $matches->links() }}</div>
        @else
            <div class="text-center py-12 text-slate-400">
                <p class="font-medium">Sin matches aún</p>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
function userDetail(userId, initialEmails, quota, initialUsed) {
    return {
        userId,
        quota,
        used: initialUsed,
        emails: initialEmails,
        keywords: @js($user->userKeywords->map(fn($k) => ['id' => $k->id, 'text' => $k->text])->values()),
        storages: @js($storages ?? []),
        accessStates: Object.fromEntries((@js($storages ?? [])).map(s => [s.id, !!s.transcription_access])),
        togglingAccess: new Set(),
        storageFilter: '',
        newEmail: '',
        newKeyword: '',
        init() {},
        get filteredStorages() {
            const q = this.storageFilter.trim().toLowerCase();
            if (!q) return this.storages;
            return this.storages.filter(s => (s.name || '').toLowerCase().includes(q));
        },
        get accessCount() {
            return Object.values(this.accessStates).filter(Boolean).length;
        },
        get firstStorageId() {
            return this.storages[0]?.id ?? null;
        },
        async setAccess(storage) {
            if (this.togglingAccess.has(storage.id)) return;
            const previous = this.accessStates[storage.id];
            const next = !previous;
            this.togglingAccess.add(storage.id);
            this.accessStates[storage.id] = next;
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/' + this.userId + '/storages/' + storage.id + '/transcription-access', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ access: next }),
                });
                if (!res.ok) {
                    this.accessStates[storage.id] = previous;
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'No se pudo cambiar el acceso');
                }
            } catch (e) {
                this.accessStates[storage.id] = previous;
                alert('Error de red');
            } finally {
                this.togglingAccess.delete(storage.id);
            }
        },
        async addEmail() {
            if (!this.newEmail) return;
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.userId + '/emails', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ email: this.newEmail }),
            });
            if (res.ok || res.status === 200) { const d = await res.json(); this.emails = d.emails || this.emails; this.newEmail = ''; }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },
        async removeEmail(email) {
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.userId + '/emails/' + encodeURIComponent(email), {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) { const d = await res.json(); this.emails = d.emails; }
        },
        async testEmail(email) {
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.userId + '/emails/' + encodeURIComponent(email) + '/test', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            const d = await res.json();
            alert((d.success ? 'Enviado: ' : 'Fallo: ') + (d.message || ''));
        },
        async addKeyword() {
            if (!this.newKeyword || this.used >= this.quota) return;
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.userId + '/keywords', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ text: this.newKeyword }),
            });
            if (res.ok) { const d = await res.json(); this.keywords.push(d.keyword); this.used = d.used; this.newKeyword = ''; }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },
        async removeKeyword(id) {
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.userId + '/keywords/' + id, {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) { this.keywords = this.keywords.filter(k => k.id !== id); this.used = Math.max(0, this.used - 1); }
        },
    };
}
</script>

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startAvisosInteligentesTour() {
    TcloudTour.start({
        steps: [
            {
                title: 'Avisos Inteligentes — orquestación por cliente',
                content: 'Aquí concedes a este cliente <strong>acceso a los resultados</strong> de las transcripciones, storage por storage. ' +
                         'No enciende ni apaga la transcripción: eso es decisión operativa de <a href="/ia/api-transcriptor" class="text-purple-700 underline">API Transcriptor</a>.',
                icon: 'fa-bell',
                color: '#4654a8',
                selector: null,
                position: 'center',
            },
            {
                title: 'Banner global X/Y',
                content: '<strong>Api-Transcriptor: X / Y</strong> storages transcribiendo globalmente. ' +
                         'Es solo informativo: te dice qué hay produciéndose en la plataforma. ' +
                         'El acceso por cliente es ortogonal.',
                icon: 'fa-info-circle',
                color: '#4654a8',
                selector: null,
                position: 'center',
            },
            {
                title: 'Toggle de acceso por canal',
                content: 'Activa el acceso por storage para darle al cliente permiso de ver las transcripciones y correcciones que api-transcriptor produce en ese canal. ' +
                         'Si el canal está <em>Sin producción</em> (badge ámbar), significa que api-transcriptor no está generando resultados nuevos; el acceso se aplicará cuando vuelva a habilitarse.',
                icon: 'fa-key',
                color: '#4654a8',
                selector: '[data-tour="storage-access-toggle"]',
                position: 'left',
            },
        ],
    });
}
</script>
@endpush
@endsection