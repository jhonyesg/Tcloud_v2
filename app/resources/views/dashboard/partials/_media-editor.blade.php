{{--
    Partial: _media-editor.blade.php
    ============================================================================
    Shape de datos esperado:
      $context: 'admin' | 'client'              (requerido)
      $user:    App\Models\User|null            (requerido en contexto 'client')
      $data:    array                           (requerido en contexto 'admin')

    Contrato de gating:
      - admin:  renderiza si $data['users_with_editor'] > 0
      - client: renderiza si $user->canUseMediaEditor() === true
                (admin OR media_editor_enabled = true)
      - Si el flag es false → el partial sale vacío sin romper el dashboard.

    Selector de tour estable:  data-dashboard-partial="media-editor"
--}}

@if($context === 'admin')
    @if(($data['users_with_editor'] ?? 0) > 0)
        <div data-dashboard-partial="media-editor" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-film text-purple-600"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800">Editor de Medios</h3>
                        <p class="text-xs text-slate-400">Resumen global del módulo</p>
                    </div>
                </div>
                <a href="/admin/media-editor" class="text-xs text-brand-600 hover:text-brand-700">Ver detalle →</a>
            </div>

            <div class="grid grid-cols-3 gap-3 text-center">
                <div>
                    <p class="text-2xl font-bold text-slate-800">{{ $data['clips_this_month'] }}</p>
                    <p class="text-[11px] text-slate-400 uppercase">Clips este mes</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-slate-800">{{ $data['users_with_editor'] }}</p>
                    <p class="text-[11px] text-slate-400 uppercase">Users ON</p>
                </div>
                <div>
                    <p class="text-2xl font-bold {{ ($data['near_limit'] ?? 0) > 0 ? 'text-amber-600' : 'text-slate-800' }}">
                        {{ $data['near_limit'] }}
                    </p>
                    <p class="text-[11px] text-slate-400 uppercase">Cerca del límite</p>
                </div>
            </div>

            @if(!empty($data['top_consumers']))
                <div class="mt-4 pt-3 border-t border-slate-100">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Top consumidores del mes</p>
                    <ul class="space-y-1.5">
                        @foreach($data['top_consumers'] as $u)
                            <li class="flex items-center justify-between text-xs">
                                <span class="text-slate-600 truncate">{{ $u['username'] ?: $u['email'] }}</span>
                                <span class="font-medium text-slate-800">{{ $u['clips_this_month'] }} clips</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
@elseif($context === 'client' && $user)
    @if($user->canUseMediaEditor())
        @php
            $clipsUsed = (int) $user->mediaEditorClipsThisMonth();
            $limit = (int) $user->media_editor_clip_limit;
            $limitEnabled = $limit > 0;
            $pct = $limitEnabled ? min(100, round(($clipsUsed / $limit) * 100, 1)) : 0;
        @endphp
        <div data-dashboard-partial="media-editor" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-film text-purple-600 text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-slate-800">Editor de Medios</h3>
                    <p class="text-xs text-slate-500">
                        Habilitado ·
                        @if($limitEnabled)
                            {{ $clipsUsed }}/{{ $limit }} clips este mes
                        @else
                            Sin límite de clips
                        @endif
                    </p>
                    @if($limitEnabled)
                        <div class="w-full bg-slate-100 rounded-full h-1.5 mt-2">
                            <div class="bg-purple-500 h-1.5 rounded-full" style="width: {{ max($pct, 2) }}%"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endif