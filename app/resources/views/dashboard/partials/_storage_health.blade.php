{{--
    Partial: _storage_health.blade.php
    ============================================================================
    Shape de datos esperado:
      $context: 'admin' | 'client'              (requerido)
      $data:    array                           (requerido)

      $data shape (admin only):
        - enabled:           bool                        (SystemSetting 'storage_health_enabled')
        - audit_finished_at: ?string ISO8601
        - items: list<{
            storage_id: int,
            name: string,
            base_path: ?string,
            untraversable_count: int,
            owner_mismatch_count: int,
          }>

    Contrato de gating:
      - Renderiza SOLO si $context === 'admin' (cliente NUNCA).
      - Renderiza SOLO si $data['enabled'] === true.
      - Renderiza SOLO si count($data['items']) > 0.
      - Si no aplica → el partial sale vacío sin romper el dashboard.

    Selector de tour estable:  data-dashboard-partial="storage-health"
--}}

@if($context === 'admin' && ($data['enabled'] ?? false) && count($data['items'] ?? []) > 0)
    <div data-dashboard-partial="storage-health" class="bg-white rounded-xl shadow-sm border border-amber-200 p-5">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-folder-tree text-amber-600"></i>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Permisos de almacenamiento</h3>
                    <p class="text-xs text-slate-400">
                        @if($data['audit_finished_at'])
                            Última verificación: {{ \Carbon\Carbon::parse($data['audit_finished_at'])->diffForHumans() }}
                        @else
                            Aún no se ha corrido la auditoría
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            @foreach($data['items'] as $item)
                <div class="border border-slate-200 rounded-lg p-3">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <div class="text-sm font-semibold text-slate-700">{{ $item['name'] }}</div>
                            @if($item['base_path'])
                                <div class="text-xs text-slate-400 font-mono truncate max-w-md">{{ $item['base_path'] }}</div>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            @if($item['untraversable_count'] > 0)
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-700">
                                    {{ $item['untraversable_count'] }} untraversable
                                </span>
                            @endif
                            @if($item['owner_mismatch_count'] > 0)
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-700">
                                    {{ $item['owner_mismatch_count'] }} owner-mismatch
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="flex gap-2 flex-wrap">
                        <code class="text-xs bg-slate-50 px-2 py-1 rounded border border-slate-200 text-slate-600">
                            php artisan storage:audit-ownership --storage={{ $item['storage_id'] }}
                        </code>
                        @if($item['untraversable_count'] > 0)
                            <code class="text-xs bg-slate-50 px-2 py-1 rounded border border-slate-200 text-slate-600">
                                sudo php artisan storage:repair-ownership --storage={{ $item['storage_id'] }} --apply --yes
                            </code>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif