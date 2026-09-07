{{--
    Panel de diagnostico del pipeline (optimize-transcriptor-dispatch-throughput).

    Cuatro tarjetas con p50/p95 por etapa + semaforo del regulador +
    mini-tabla de count_by_state. El estado vive en
    pipelineDiagnostics() (Alpine.data en el parent).

    Si no existe el componente Alpine `pipelineDiagnostics`, este parcial
    queda inutil pero no rompe: las x-data referencian atributos que
    estaran vacios hasta que se monte el componente.
--}}
<details class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden"
         x-data="pipelineDiagnostics({
             latencyUrl: '{{ url('/ia/api-transcriptor/latency') }}',
             regulatorUrl: '{{ url('/ia/api-transcriptor/regulator-cause') }}',
             warnSeconds: {{ (int) (config('transcriptor.latency_p95_warn_seconds') ?? 300) }},
         })"
         @open-diagnostics.window="open = true">
    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 select-none flex items-center gap-2">
        <i class="fas fa-stethoscope text-brand-600 text-sm"></i>
        Diagnostico de pipeline
        <span class="text-[10px] text-slate-400 font-normal">— latencia por etapa y estado del regulador</span>
        <i class="fas fa-chevron-down text-[10px] text-slate-400 ml-auto transition-transform"
           :class="open ? 'rotate-180' : ''"></i>
    </summary>

    <div x-show="open" x-transition.opacity class="border-t border-slate-200 p-4 space-y-4">
        <div class="flex items-center gap-2">
            <button @click="refresh()"
                    :disabled="loading"
                    class="text-xs flex items-center gap-1.5 px-3 py-1.5 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded-lg transition-colors disabled:opacity-50"
                    title="Volver a pedir latencia y regulador">
                <i class="fas fa-sync-alt text-[10px]" :class="loading ? 'fa-spin' : ''"></i>
                <span x-text="loading ? 'Actualizando...' : 'Actualizar'"></span>
            </button>
            <span x-show="latency?.generated_at" class="text-[11px] text-slate-400">
                Ultima lectura: <span x-text="latency?.generated_at"></span>
            </span>
            <span x-show="error" class="ml-auto text-xs text-red-600" x-text="error"></span>
        </div>

        {{-- Cuatro tarjetas de percentiles por etapa --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <template x-for="(stage, key) in stageLabels" :key="key">
                <div class="rounded-lg border p-3"
                     :class="stageCardClass(latency?.stages?.[key])">
                    <div class="text-[10px] uppercase tracking-wide text-slate-500 font-medium" x-text="stage.title"></div>
                    <div class="mt-1 text-xs text-slate-400" x-text="stage.subtitle"></div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <div class="text-xl font-bold text-slate-800"
                             x-text="formatLatency(latency?.stages?.[key]?.p50_seconds)"></div>
                        <div class="text-[10px] text-slate-400 uppercase">p50</div>
                    </div>
                    <div class="mt-1.5 flex items-baseline gap-2">
                        <div class="text-sm font-semibold"
                             :class="(latency?.stages?.[key]?.p95_seconds ?? 0) > warnSeconds ? 'text-amber-700' : 'text-slate-600'"
                             x-text="formatLatency(latency?.stages?.[key]?.p95_seconds)"></div>
                        <div class="text-[10px] text-slate-400 uppercase">p95</div>
                        <div class="ml-auto text-[10px] text-slate-400"
                             x-text="(latency?.stages?.[key]?.samples ?? 0) + ' m'"></div>
                    </div>
                    <div x-show="(latency?.stages?.[key]?.p95_seconds ?? 0) > warnSeconds"
                         class="mt-1.5 text-[10px] text-amber-700">
                        <i class="fas fa-exclamation-triangle mr-0.5"></i>
                        p95 fuera de rango
                    </div>
                </div>
            </template>
        </div>

        <div x-show="!latency" class="text-center py-6 text-xs text-slate-400 italic">
            Aun no se ha pedido la latencia. Pulsa "Actualizar".
        </div>

        {{-- Semaforo del regulador + mini-tabla de count_by_state --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2 rounded-lg border p-3"
                 :class="regulatorCardClass()">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full"
                          :class="regulatorDotClass()"></span>
                    <span class="text-xs font-semibold text-slate-700" x-text="regulatorHeadline()"></span>
                </div>
                <div class="mt-1 text-[11px] text-slate-500" x-text="regulatorReasonHuman()"></div>
                <div class="mt-2 text-[10px] text-slate-400" x-show="regulator?.fired_at">
                    Ultimo tick: <span x-text="regulator?.fired_at"></span>
                    · modo <span class="font-mono" x-text="regulator?.regulator_mode || '—'"></span>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="sig in (regulator?.signals_evaluated || [])" :key="sig">
                        <span class="text-[10px] px-2 py-0.5 bg-slate-100 text-slate-600 rounded font-mono"
                              x-text="sig"></span>
                    </template>
                    <template x-for="(v, k) in (regulator?.values || {})" :key="k">
                        <span class="text-[10px] px-2 py-0.5 bg-slate-50 border border-slate-200 text-slate-500 rounded font-mono"
                              x-text="k + '=' + v"></span>
                    </template>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mb-2">Trabajos por estado (ventana 24h)</div>
                <div class="space-y-1" x-show="latency?.count_by_state">
                    <template x-for="s in ['pending','queued','processing','done','error','dead']" :key="s">
                        <div class="flex items-center gap-2 text-[11px]">
                            <span class="w-2 h-2 rounded-full flex-shrink-0"
                                  :class="stateDotLocal(s)"></span>
                            <span class="text-slate-600 font-mono w-20" x-text="s"></span>
                            <span class="ml-auto font-semibold text-slate-800"
                                  x-text="(latency?.count_by_state?.[s] ?? 0).toLocaleString()"></span>
                        </div>
                    </template>
                </div>
                <div x-show="!latency?.count_by_state" class="text-[10px] text-slate-400 italic">Sin datos.</div>
            </div>
        </div>
    </div>
</details>
