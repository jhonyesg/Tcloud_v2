{{--
    Partial: _bg-jobs-active.blade.php
    ============================================================================
    Shape de datos esperado:
      $context: 'admin'                        (requerido)
      $data:    array de jobs (shape BgJobRegistry)
                [{kind, runId, module, label, startedAt, progress, url}]

    Contrato de gating:
      - Solo contexto admin.
      - Si $data está vacío → partial sale vacío (sin renderizar tarjeta).

    Selector de tour estable:  data-dashboard-partial="bg-jobs-active"
--}}

@if($context === 'admin')
    @if(!empty($data))
        @php
            $byKind = [];
            foreach ($data as $job) {
                $k = $job['kind'] ?? 'unknown';
                if (!isset($byKind[$k])) {
                    $byKind[$k] = ['kind' => $k, 'count' => 0, 'module' => $job['module'] ?? null, 'jobs' => []];
                }
                $byKind[$k]['count']++;
                $byKind[$k]['jobs'][] = $job;
            }
        @endphp
        <div data-dashboard-partial="bg-jobs-active" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 bg-slate-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-cogs text-slate-600"></i>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Jobs en Background</h3>
                    <p class="text-xs text-slate-400">{{ count($data) }} activo{{ count($data) !== 1 ? 's' : '' }}</p>
                </div>
            </div>
            <ul class="space-y-2">
                @foreach($byKind as $group)
                    <li class="flex items-center justify-between text-xs py-1.5 border-b border-slate-100 last:border-b-0">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-700">{{ $group['module'] ?? $group['kind'] }}</p>
                            <p class="text-slate-400">{{ $group['kind'] }} · {{ $group['count'] }} job{{ $group['count'] !== 1 ? 's' : '' }}</p>
                        </div>
                        @php
                            $firstUrl = $group['jobs'][0]['url'] ?? null;
                        @endphp
                        @if($firstUrl)
                            <a href="{{ $firstUrl }}" class="text-brand-600 hover:text-brand-700 shrink-0">Ver →</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endif