{{--
    Partial: _active-sessions.blade.php
    ============================================================================
    Shape de datos esperado:
      $context: 'admin'                        (requerido)
      $data:    {total: int, top_users: array<{id, username, email, count}>}

    Contrato de gating:
      - Solo contexto admin.
      - Renderiza siempre que $data['total'] > 0.
      - Si no hay sesiones activas → partial sale vacío.

    Selector de tour estable:  data-dashboard-partial="active-sessions"
--}}

@if($context === 'admin')
    @if(($data['total'] ?? 0) > 0)
        <div data-dashboard-partial="active-sessions" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-shield-alt text-green-600"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800">Sesiones Activas</h3>
                        <p class="text-xs text-slate-400">Totales en el sistema</p>
                    </div>
                </div>
                <a href="/admin/sessions" class="text-xs text-brand-600 hover:text-brand-700">Ver todas →</a>
            </div>

            <p class="text-3xl font-bold text-slate-800 mb-3">{{ $data['total'] }}</p>

            @if(!empty($data['top_users']))
                <div class="pt-3 border-t border-slate-100">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Top con más sesiones</p>
                    <ul class="space-y-1.5">
                        @foreach($data['top_users'] as $u)
                            <li class="flex items-center justify-between text-xs">
                                <span class="text-slate-600 truncate">{{ $u['username'] ?: $u['email'] ?: ('user#'.$u['id']) }}</span>
                                <span class="font-medium text-slate-800">{{ $u['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
@endif