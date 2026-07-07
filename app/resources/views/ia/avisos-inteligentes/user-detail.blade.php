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
            <div class="flex gap-4 text-sm">
                <div><span class="text-slate-500">Keywords:</span> <span class="font-semibold text-slate-800"><span x-text="used"></span> / <span x-text="quota"></span></span></div>
                <div><span class="text-slate-500">Correos:</span> <span class="font-semibold text-slate-800" x-text="emails.length"></span></div>
            </div>
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
        newEmail: '',
        newKeyword: '',
        init() {},
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
@endpush
@endsection