{{--
    Partial: _mis-avisos.blade.php
    ============================================================================
    Shape de datos esperado:
      $context: 'admin' | 'client'              (requerido)
      $user:    App\Models\User|null            (requerido en contexto 'client')
      $data:    array                           (requerido en contexto 'admin')

    Contrato de gating:
      - admin:  siempre renderiza (los KPIs son globales del módulo)
      - client: renderiza si el módulo está habilitado para el usuario
                  ($data['enabled'] = true, equivalente a
                  alertsInteligente->enabled && keywords_quota > 0)
      - Si el flag es false → el partial sale vacío sin romper el dashboard.

    Privacidad:
      - admin:  incluye pares, pending, con hits, drift_negative
      - client: SOLO estado (ON/OFF, cuota, emails, cadencia)
                NUNCA hits de keyword_matches ni alert_logs
                NUNCA drift_missing / drift_orphan (datos de auditoría)

    Selector de tour estable:  data-dashboard-partial="mis-avisos"
--}}

@if($context === 'admin')
    @php
        $pairsTotal   = (int) ($data['pairs_total'] ?? 0);
        $pairsPending = (int) ($data['pairs_pending'] ?? 0);
        $pairsHits    = (int) ($data['pairs_with_hits'] ?? 0);
        $driftNeg     = (int) ($data['drift_negative'] ?? 0);
    @endphp
    <div data-dashboard-partial="mis-avisos" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-bell text-indigo-600"></i>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Mis Avisos</h3>
                    <p class="text-xs text-slate-400">Cobertura global del módulo</p>
                </div>
            </div>
            <a href="/ia/avisos-inteligentes" class="text-xs text-brand-600 hover:text-brand-700">Ver detalle →</a>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ $pairsTotal }}</p>
                <p class="text-[11px] text-slate-400 uppercase">Pares keyword×storage</p>
            </div>
            <div>
                <p class="text-2xl font-bold {{ $pairsPending > 0 ? 'text-amber-600' : 'text-green-600' }}">{{ $pairsPending }}</p>
                <p class="text-[11px] text-slate-400 uppercase">Pendientes</p>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ $pairsHits }}</p>
                <p class="text-[11px] text-slate-400 uppercase">Con hits</p>
            </div>
            <div>
                <p class="text-2xl font-bold {{ $driftNeg > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $driftNeg }}</p>
                <p class="text-[11px] text-slate-400 uppercase">Drift negativo</p>
            </div>
        </div>
    </div>
@elseif($context === 'client' && $user)
    @if($user->alertsInteligente?->enabled && ((int) ($user->alertsInteligente?->keywords_quota ?? 0)) > 0)
        @php
            $config       = $user->alertsInteligente;
            $keywordsUsed = $user->userKeywords()->count();
            $keywordsQta  = (int) $config->keywords_quota;
            $emails       = $config->emailsList();
            $emailsQta    = (int) ($config->emails_quota ?? 0);
            $cadence      = (int) ($config->alert_frequency_minutes ?? 30);
        @endphp
        <a href="/ia/mis-avisos"
           data-dashboard-partial="mis-avisos"
           class="block bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-brand-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-bell text-indigo-600 text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-slate-800">Mis Avisos</h3>
                    <p class="text-xs text-slate-500">
                        Módulo habilitado · {{ $keywordsUsed }}/{{ $keywordsQta }} keywords ·
                        {{ count($emails) }}/{{ $emailsQta }} emails ·
                        cada {{ $cadence }} min
                    </p>
                </div>
            </div>
        </a>
    @endif
@endif