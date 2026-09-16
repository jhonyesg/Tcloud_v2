    <div x-show="tab === 'config'" x-transition:enter.opacity.duration.150ms>

        <template x-if="cfgLoading && !cfgMeta">
            <div class="py-16 text-center text-slate-400">
                <i class="fas fa-circle-notch fa-spin text-2xl mb-2 block"></i>
                <p class="text-sm">Cargando configuración…</p>
            </div>
        </template>

        <template x-if="cfgMeta">
        <div>
            {{-- Freno de emergencia --}}
            <div data-tour="cfg-pause" class="mb-5 rounded-xl border p-4 flex items-start gap-4"
                 :class="cfg.dispatch_paused ? 'bg-amber-50 border-amber-300' : 'bg-white border-slate-200'">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                     :class="cfg.dispatch_paused ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-400'">
                    <i class="fas" :class="cfg.dispatch_paused ? 'fa-pause' : 'fa-play'"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-800"
                       x-text="cfg.dispatch_paused ? 'Envío pausado' : 'Envío activo'"></p>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Con el envío pausado la tarea sigue <strong>descubriendo</strong> grabaciones nuevas —
                        no se pierde nada— pero deja de encolar trabajo y los botones de envío quedan bloqueados.
                    </p>
                </div>
                <button @click="togglePause()" :disabled="cfgSaving"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors flex-shrink-0 disabled:opacity-50"
                        :class="cfg.dispatch_paused ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-amber-500 hover:bg-amber-600 text-white'"
                        x-text="cfg.dispatch_paused ? 'Reanudar envío' : 'Pausar envío'"></button>
            </div>

            {{-- Tarea programada + estado en vivo --}}
            <div data-tour="cfg-task" class="mb-5 bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <i class="fas fa-clock text-brand-500"></i> Tarea programada
                        </h3>
                        <p class="text-xs text-slate-500 mt-1">
                            <span class="font-mono bg-slate-100 px-1 rounded">transcription:tick</span>
                            escanea el disco y encola trabajo cada
                            <strong x-text="cfg.tick_interval_minutes"></strong> min.
                            <span x-show="cfgRuntime?.tick_last_run">
                                Última ejecución: <span x-text="fmtAgo(cfgRuntime.tick_last_run)"></span>.
                            </span>
                        </p>
                    </div>
                    <div data-tour="cfg-run" class="flex items-center gap-2 flex-shrink-0">
                        <button @click="runTick(true)" :disabled="cfgSaving"
                                class="px-3 py-1.5 text-xs rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors disabled:opacity-50">
                            <i class="fas fa-flask mr-1"></i> Simular
                        </button>
                        <button @click="runTick(false)" :disabled="cfgSaving"
                                class="px-3 py-1.5 text-xs rounded-lg bg-brand-600 hover:bg-brand-700 text-white transition-colors disabled:opacity-50">
                            <i class="fas fa-play mr-1"></i> Ejecutar ahora
                        </button>
                        {{-- Procesamiento personalizado: mismo modal que el boton
                             de la cabecera, accesible desde la tarea programada
                             porque es ahi donde el operador piensa el "que falta". --}}
                        <button @click="openPz()"
                                class="px-3 py-1.5 text-xs rounded-lg border border-brand-200 text-brand-700 bg-brand-50 hover:bg-brand-100 transition-colors">
                            <i class="fas fa-clock-rotate-left mr-1"></i> Procesar históricos
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    {{-- Cola vs objetivo --}}
                    <div data-tour="cfg-queue" class="md:col-span-2">
                        <div class="flex items-baseline justify-between mb-1">
                            <span class="text-xs text-slate-500">Cola de conversión (pendientes de hoy)</span>
                            <span class="text-xs font-mono text-slate-600">
                                <span x-text="cfgRuntime?.today?.pending ?? cfgRuntime?.queue_depth ?? '—'"></span>
                                <span class="text-slate-400">en cola</span>
                            </span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full transition-all duration-300"
                                 :class="inventoryPct() >= 100 ? 'bg-sky-500' : 'bg-brand-500'"
                                 :style="'width: ' + Math.min(100, inventoryPct()) + '%'"></div>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1.5">
                            <span>
                                La lista de pendientes <strong>no tiene tope</strong>: es todo lo del día.
                                El techo de <strong x-text="cfgRuntime?.target_remote_queue ?? 180"></strong> aplica a la
                                <strong>cola de la API remota</strong>
                                (<span x-text="cfgRuntime?.remote_status?.queue_queued ?? '—'"></span> ahora).
                            </span>
                            <span class="block mt-0.5">
                                Inventario listo en RAM disk:
                                <strong x-text="cfgRuntime?.staging?.files ?? 0"></strong>/<strong x-text="cfgRuntime?.staging?.target ?? 0"></strong>
                                (<span x-text="cfgRuntime?.staging?.bytes_mb ?? 0"></span> MB).
                            </span>
                        </p>
                    </div>

                    {{-- Workers --}}
                    <div data-tour="cfg-workers">
                        <p class="text-xs text-slate-500 mb-1">Workers (cola nativa PG)</p>
                        <p class="text-2xl font-semibold text-slate-800 leading-none">
                            <span x-text="cfgRuntime?.workers?.pg_running ?? cfgRuntime?.workers?.active ?? '—'"></span>
                            <span class="text-sm text-slate-400 font-normal">/ <span x-text="cfgRuntime?.workers?.pg_total ?? cfgRuntime?.workers?.installed ?? '—'"></span></span>
                        </p>
                        <p x-show="cfgRuntime?.workers?.orphans > 0" class="text-[11px] text-red-600 mt-1 font-medium">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span x-text="cfgRuntime.workers.orphans"></span> huérfanos activos
                        </p>
                        <p x-show="cfgRuntime?.workers?.override > 0" class="text-[11px] text-brand-600 mt-1">
                            Forzado a <span x-text="cfgRuntime.workers.override"></span>
                        </p>
                        {{-- Los workers legacy de Redis siguen vivos pero sin trabajo:
                             la cola nativa es PG. Se muestra para no confundirlos
                             con el pool real (antes la UI contaba solo estos). --}}
                        <p x-show="(cfgRuntime?.workers?.legacy_active ?? 0) > 0"
                           class="text-[10px] text-slate-400 mt-1"
                           x-text="'Legacy Redis: ' + cfgRuntime.workers.legacy_active + ' activos, ' + (cfgRuntime?.workers?.legacy_pending_jobs ?? 0) + ' jobs en cola'"></p>
                    </div>

                    {{-- Estados --}}
                    <div data-tour="cfg-states">
                        <p class="text-xs text-slate-500 mb-1">
                            Transcripciones
                            <span class="text-[10px] text-slate-400">(histórico)</span>
                        </p>
                        <div class="space-y-0.5">
                            <template x-for="(count, state) in (cfgRuntime?.states || {})" :key="state">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="text-slate-500" x-text="state"></span>
                                    <span class="font-mono text-slate-700" x-text="count"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Pipeline en vivo: fases del drenado -----------------------------------}}
                <div data-tour="cfg-pipeline" class="mt-4 pt-4 border-t border-slate-100">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs text-slate-500">
                            <i class="fas fa-diagram-project mr-1"></i>
                            Pipeline en vivo
                            <span class="text-[10px] text-slate-400 ml-2">audios en cada fase</span>
                        </p>
                        <p class="text-xs text-slate-500">
                            Tasa envío:
                            <span class="font-mono font-semibold text-slate-700"
                                  x-text="cfgRuntime?.throughput_per_min ?? '—'"></span>
                            <span class="text-slate-400">/min</span>
                        </p>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-2 text-[11px]">
                        {{-- 1. Cola local (candidatos sin tomar) --}}
                        <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-200">
                            <p class="text-slate-500 mb-0.5">Cola local</p>
                            <p class="text-lg font-semibold text-slate-800 leading-none"
                               x-text="cfgRuntime?.today?.pending ?? cfgRuntime?.queue_depth ?? '—'"></p>
                            <p class="text-[10px] text-slate-400 mt-1">sin convertir ni enviar</p>
                        </div>
                        {{-- 2. Listos en RAM disk (fase 1 completada) --}}
                        <div class="rounded-lg p-2.5 border"
                             :class="(cfgRuntime?.staging?.files ?? 0) > 0 ? 'bg-sky-50 border-sky-200' : 'bg-slate-50 border-slate-200'">
                            <p class="text-slate-500 mb-0.5">
                                <i class="fas fa-layer-group mr-0.5"
                                   :class="(cfgRuntime?.staging?.files ?? 0) > 0 ? 'text-sky-600' : ''"></i>
                                Listos
                            </p>
                            <p class="text-lg font-semibold leading-none"
                               :class="(cfgRuntime?.staging?.files ?? 0) > 0 ? 'text-sky-700' : 'text-slate-800'"
                               x-text="cfgRuntime?.staging?.files ?? '0'"></p>
                            <p class="text-[10px] text-slate-400 mt-1"
                               x-text="(cfgRuntime?.staging?.bytes_mb ?? 0) + ' MB en RAM disk'"></p>
                        </div>
                        {{-- 3. En espera (aplazados: cola remota llena o sin espacio) --}}
                        <div class="rounded-lg p-2.5 border"
                             :class="(cfgRuntime?.requeueable_count ?? 0) > 0 ? 'bg-amber-50 border-amber-200' : 'bg-slate-50 border-slate-200'">
                            <p class="text-slate-500 mb-0.5">
                                <i class="fas fa-hourglass-half mr-0.5"
                                   :class="(cfgRuntime?.requeueable_count ?? 0) > 0 ? 'text-amber-600' : ''"></i>
                                En espera
                            </p>
                            <p class="text-lg font-semibold leading-none"
                               :class="(cfgRuntime?.requeueable_count ?? 0) > 0 ? 'text-amber-700' : 'text-slate-800'"
                               x-text="cfgRuntime?.requeueable_count ?? '—'"></p>
                            <p class="text-[10px] text-slate-400 mt-1">aplazados por freno</p>
                        </div>
                        {{-- 4. Convirtiendo ffmpeg local --}}
                        <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-200">
                            <p class="text-slate-500 mb-0.5">
                                <i class="fas fa-gears mr-0.5"></i>
                                Procesando
                            </p>
                            <p class="text-lg font-semibold text-slate-800 leading-none"
                               x-text="cfgRuntime?.states?.processing ?? '—'"></p>
                            <p class="text-[10px] text-slate-400 mt-1">ffmpeg local</p>
                        </div>
                        {{-- 5. En GPU remota --}}
                        <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-200">
                            <p class="text-slate-500 mb-0.5">
                                <i class="fas fa-microchip mr-0.5"></i>
                                En remota
                            </p>
                            <p class="text-lg font-semibold text-slate-800 leading-none"
                               x-text="cfgRuntime?.remote_status?.queue_queued ?? cfgRuntime?.states?.queued ?? '—'"></p>
                            <p class="text-[10px] text-slate-400 mt-1">en la cola del nodo</p>
                        </div>
                        {{-- 6. Hechos hoy (real: por finished_at del dia) --}}
                        <div class="bg-emerald-50 rounded-lg p-2.5 border border-emerald-200">
                            <p class="text-slate-500 mb-0.5">
                                <i class="fas fa-circle-check text-emerald-600 mr-0.5"></i>
                                Hechos hoy
                            </p>
                            <p class="text-lg font-semibold text-emerald-700 leading-none"
                               x-text="cfgRuntime?.today?.done ?? cfgRuntime?.states_today?.done ?? '—'"></p>
                            <p class="text-[10px] text-slate-400 mt-1">transcripciones listas</p>
                        </div>
                    </div>

                    {{-- Desglose de pendientes de HOY: lo que realmente falta.
                         "missing" son archivos de hoy en storages habilitados SIN
                         fila de transcripcion (huecos de discovery). Antes eran
                         invisibles en todos los paneles. --}}
                    <div class="mt-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs text-slate-600">
                                <i class="fas fa-list-check mr-1 text-brand-500"></i>
                                <span class="font-medium">Pendientes de hoy</span>
                                <span class="text-[10px] text-slate-400 ml-1">archivos del día en storages habilitados sin transcripción lista</span>
                            </p>
                            <p class="text-xs text-slate-500">
                                total
                                <span class="font-mono font-semibold text-slate-700"
                                      x-text="cfgRuntime?.today?.total ?? '—'"></span>
                            </p>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 text-[11px]">
                            <div class="bg-white rounded border border-slate-200 p-2">
                                <p class="text-slate-700 font-medium">Sin indexar</p>
                                <p class="font-mono font-semibold text-slate-700 text-base" x-text="cfgRuntime?.today?.missing ?? '0'"></p>
                                <p class="text-slate-400 text-[10px] leading-tight mt-1">Sin fila de transcripción. El scanner los descubre en cada tick (lote de 500). Se vuelven "En stager" al indexarse.</p>
                            </div>
                            <div class="bg-white rounded border border-slate-200 p-2">
                                <p class="text-slate-700 font-medium">En stager</p>
                                <p class="font-mono font-semibold text-slate-700 text-base" x-text="cfgRuntime?.today?.pending ?? '0'"></p>
                                <p class="text-slate-400 text-[10px] leading-tight mt-1">Fila de transcripción creada. Esperando turno del stager para convertirse a WAV.</p>
                            </div>
                            <div class="bg-white rounded border border-slate-200 p-2">
                                <p class="text-slate-700 font-medium">Encolados</p>
                                <p class="font-mono font-semibold text-slate-700 text-base" x-text="cfgRuntime?.today?.queued ?? '0'"></p>
                                <p class="text-slate-400 text-[10px] leading-tight mt-1">Subidos a la API remota, aún sin respuesta.</p>
                            </div>
                            <div class="bg-white rounded border border-slate-200 p-2">
                                <p class="text-slate-700 font-medium">Procesando</p>
                                <p class="font-mono font-semibold text-slate-700 text-base" x-text="cfgRuntime?.today?.processing ?? '0'"></p>
                                <p class="text-slate-400 text-[10px] leading-tight mt-1">Convirtiendo o en cola upstream. Si pasa de 10 min, ver log.</p>
                            </div>
                            <div class="bg-white rounded border border-slate-200 p-2">
                                <p class="text-slate-700 font-medium">Con error</p>
                                <p class="font-mono font-semibold text-slate-700 text-base" x-text="cfgRuntime?.today?.error ?? '0'"></p>
                                <p class="text-slate-400 text-[10px] leading-tight mt-1">Reintentar con "Reenviar fallidos" o revisa si el upstream está caído.</p>
                            </div>
                            <div class="bg-white rounded border border-slate-200 p-2">
                                <p class="text-slate-700 font-medium">Irrecuperables</p>
                                <p class="font-mono font-semibold text-slate-700 text-base" x-text="cfgRuntime?.today?.dead ?? '0'"></p>
                                <p class="text-slate-400 text-[10px] leading-tight mt-1">Cerrados automáticamente. Revisar a mano o reescanear.</p>
                            </div>
                        </div>
                    </div>

                    {{-- Barra de la API remota --}}
                    <div class="mt-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-xs text-slate-600">
                                <i class="fas fa-satellite-dish mr-1 text-brand-500"></i>
                                <span class="font-medium">Cola del API remoto</span>
                                <span class="text-slate-400 ml-1"
                                      x-text="cfgRuntime?.remote_status ? '(' + cfgRuntime.remote_status.queue_queued + ' en cola + ' + cfgRuntime.remote_status.queue_processing + ' procesando)' : '(no reachable)'"></span>
                            </p>
                            <p class="text-xs text-slate-500">
                                techo
                                <span class="font-mono font-semibold text-slate-700"
                                      x-text="cfgRuntime?.target_remote_queue ?? '—'"></span>
                            </p>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden">
                            {{-- La barra mide ocupacion contra el techo; el color lo decide el pestillo
                                 del freno, no una comparacion cruda: asi el operador ve "frenada"
                                 incluso cuando la cola ya bajo un poco pero aun no llega al reanudo. --}}
                            <div class="h-2 rounded-full transition-all duration-300"
                                 :class="(cfgRuntime?.remote_brake?.braked) ? 'bg-amber-500' : ((cfgRuntime?.remote_status?.queue_queued ?? 0) >= (cfgRuntime?.target_remote_queue ?? 180) * 0.8 ? 'bg-amber-400' : 'bg-emerald-500')"
                                 :style="'width: ' + Math.min(100, ((cfgRuntime?.remote_status?.queue_queued ?? 0) / Math.max(1, cfgRuntime?.target_remote_queue ?? 180)) * 100) + '%'"></div>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-[10px] text-slate-500">
                            <span>
                                {{-- Histéresis: frena en el techo y no reanuda hasta el umbral de reanudo.
                                     El mensaje de "frenada" muestra ambos umbrales para que el operador
                                     entienda por que no se reanuda al bajar un par de jobs. --}}
                                <span x-show="cfgRuntime?.remote_brake?.braked"
                                      class="text-amber-700 font-medium">
                                    <i class="fas fa-hand-paper"></i> Frenada (baja a <span x-text="cfgRuntime?.remote_brake?.resume ?? 120"></span> para reanudar)
                                </span>
                                <span x-show="cfgRuntime?.remote_status && !(cfgRuntime?.remote_brake?.braked)"
                                      class="text-emerald-700">
                                    <i class="fas fa-circle-check"></i> Con headroom: envío activo
                                </span>
                                <span x-show="!cfgRuntime?.remote_status" class="text-slate-400">
                                    <i class="fas fa-circle-exclamation"></i> Sin telemetría
                                </span>
                            </span>
                            <span class="text-slate-400"
                                  x-show="cfgRuntime?.remote_brake?.braked"
                                  x-text="'revalida cada ' + (cfgRuntime?.remote_brake?.recheck_seconds ?? 30) + 's'"></span>
                        </div>
                    </div>

                    {{-- Salud del nodo remoto (solo las señales que regulan
                         el envío): RAM, RAM disk, disco y cola/corrector.
                         CPU y GPU no se muestran: en este módulo no accionan
                         un freno y ensucian el panel. --}}
                    <div class="mt-3 p-3 bg-white rounded-lg border border-slate-200"
                         x-show="cfgRuntime?.remote_status" x-cloak
                         :class="remoteHealthAlert() ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200'">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs text-slate-600">
                                <i class="fas fa-heart-pulse mr-1 text-brand-500"></i>
                                <span class="font-medium">Salud del nodo remoto</span>
                                <span class="text-[10px] text-slate-400 ml-1"
                                      x-text="cfgRuntime?.remote_status?.node_id ? '(' + cfgRuntime.remote_status.node_id + ')' : ''"></span>
                            </p>
                            <p class="text-[10px] text-slate-400">
                                <span x-text="(cfgRuntime?.remote_status?.workers ?? 0) + ' workers'"></span>
                                <span class="ml-1" x-text="cfgRuntime?.remote_status?.uptime_seconds ? '· UP ' + fmtUptime(cfgRuntime.remote_status.uptime_seconds) : ''"></span>
                            </p>
                        </div>
                        {{-- RAM, RAM disk y disco: las tres señales que importan
                             para regular el envío. CPU y GPU se omiten a propósito
                             (GPU al 100% significa que está trabajando, no saturada). --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-[11px]">
                            {{-- RAM --}}
                            <div class="p-2 rounded border"
                                 :class="metricClass(cfgRuntime?.remote_status?.ram_pct, 90, 75)">
                                <p class="text-slate-500 text-[10px]">RAM</p>
                                <p class="font-mono font-semibold"
                                   x-text="fmtPct(cfgRuntime?.remote_status?.ram_pct)"></p>
                                <p class="text-[10px] text-slate-400"
                                   x-text="(cfgRuntime?.remote_status?.ram_used_gb ?? 0).toFixed(1) + ' / ' + (cfgRuntime?.remote_status?.ram_total_gb ?? 0).toFixed(1) + ' GB'"></p>
                                <p class="text-[10px] text-slate-400"
                                   x-show="(cfgRuntime?.remote_status?.swap_pct ?? 0) > 0"
                                   x-text="'swap ' + fmtPct(cfgRuntime?.remote_status?.swap_pct)"></p>
                            </div>
                            {{-- RAM disk (tmpfs del nodo ASR) --}}
                            <div class="p-2 rounded border"
                                 :class="metricClass(cfgRuntime?.remote_status?.ramdisk_pct, 85, 70)">
                                <p class="text-slate-500 text-[10px]">RAM disk</p>
                                <p class="font-mono font-semibold"
                                   x-text="fmtPct(cfgRuntime?.remote_status?.ramdisk_pct)"></p>
                                <p class="text-[10px] text-slate-400"
                                   x-text="(cfgRuntime?.remote_status?.ramdisk_used_gb ?? 0).toFixed(1) + ' / ' + (cfgRuntime?.remote_status?.ramdisk_total_gb ?? 0).toFixed(1) + ' GB'"></p>
                                <p class="text-[10px]"
                                   :class="(cfgRuntime?.remote_status?.ramdisk_ok ?? true) ? 'text-slate-400' : 'text-red-600 font-medium'"
                                   x-text="(cfgRuntime?.remote_status?.ramdisk_ok ?? true) ? (cfgRuntime?.remote_status?.ramdisk_path || '') : 'no responde'"></p>
                            </div>
                            {{-- Disco del nodo --}}
                            <div class="p-2 rounded border"
                                 :class="metricClass(cfgRuntime?.remote_status?.disk_pct, 90, 80)">
                                <p class="text-slate-500 text-[10px]">Disco</p>
                                <p class="font-mono font-semibold"
                                   x-text="fmtPct(cfgRuntime?.remote_status?.disk_pct)"></p>
                                <p class="text-[10px] text-slate-400"
                                   x-text="(cfgRuntime?.remote_status?.disk_free_gb ?? 0).toFixed(1) + ' GB libres de ' + (cfgRuntime?.remote_status?.disk_total_gb ?? 0).toFixed(1)"></p>
                            </div>
                            {{-- Cola + corrector: es la señal que gobierna el envío. --}}
                            <div class="p-2 rounded border border-slate-200 bg-slate-50">
                                <p class="text-slate-500 text-[10px]">Cola / corrector</p>
                                <p class="font-mono font-semibold text-slate-700"
                                   x-text="(cfgRuntime?.remote_status?.queue_queued ?? 0) + ' / ' + (cfgRuntime?.remote_status?.queue_processing ?? 0)"></p>
                                <p class="text-[10px] text-slate-400"
                                   x-text="'corrector pendiente ' + (cfgRuntime?.remote_status?.queue_done_pending_corrector ?? 0)"></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-2 text-[10px]">
                            <span :class="remoteHealthAlert() ? 'text-amber-700 font-medium' : 'text-emerald-700'">
                                <i class="fas" :class="remoteHealthAlert() ? 'fa-triangle-exclamation' : 'fa-circle-check'"></i>
                                <span x-text="remoteHealthAlert() ? remoteHealthAlert() : 'Nodo remoto sano'"></span>
                            </span>
                            <span class="text-slate-400" x-text="'actualizado ' + fmtAgo(cfgRuntime?.remote_status?.fetched_at)"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Barra plegable: Expandir todo / Plegar todo --}}
            <div class="mb-4 flex items-center justify-end gap-2" data-tour="cfg-groups-toolbar">
                <button type="button" @click="expandAllGroups()"
                        class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 inline-flex items-center gap-1.5">
                    <i class="fas fa-chevrons-down text-[10px]"></i>
                    <span>Expandir todo</span>
                </button>
                <button type="button" @click="collapseAllGroups()"
                        class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 inline-flex items-center gap-1.5">
                    <i class="fas fa-chevrons-up text-[10px]"></i>
                    <span>Plegar todo</span>
                </button>
            </div>

            {{-- Grupos de knobs --}}
            <template x-for="group in cfgGroups()" :key="group">
                <div class="mb-4 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden" :data-tour="'cfg-group-' + group">
                    <button type="button" @click="toggleGroup(group)"
                            :aria-expanded="isGroupOpen(group) ? 'true' : 'false'"
                            :aria-controls="'cfg-group-body-' + group"
                            class="w-full text-left px-5 py-3 border-b border-slate-100 bg-slate-50/60 flex items-center gap-3 hover:bg-slate-50 transition-colors">
                        <i class="fas text-brand-500 text-base" :class="cfgGroupIcons[group] || 'fa-cog'"></i>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-semibold text-slate-700" x-text="groupLabel(group)"></h3>
                            <p class="text-xs text-slate-400 mt-0.5" x-text="groupHelp(group)"></p>
                        </div>
                        <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-150"
                           :class="!isGroupOpen(group) ? 'rotate-180' : ''"></i>
                    </button>
                    <div :id="'cfg-group-body-' + group"
                         x-show="isGroupOpen(group)"
                         x-transition.opacity.duration.150ms
                         class="divide-y divide-slate-100">
                        <template x-for="k in cfgKeysIn(group)" :key="k">
                            <div class="px-5 py-3.5" :data-tour="'cfg-knob-' + k">
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0 w-7 pt-0.5">
                                        <i class="fas text-slate-400 text-sm" :class="cfgMeta[k].icon || 'fa-cog'"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <label class="text-sm font-medium text-slate-700" x-text="cfgMeta[k].label"></label>
                                            <span x-show="cfgMeta[k].scope"
                                                  class="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase tracking-wide"
                                                  :class="scopeBadgeClass(cfgMeta[k].scope)"
                                                  x-text="scopeBadgeLabel(cfgMeta[k].scope)"></span>
                                            <span x-show="cfgMeta[k].state && cfgMeta[k].state !== 'live'"
                                                  class="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase tracking-wide"
                                                  :class="stateBadgeClass(cfgMeta[k].state)"
                                                  x-text="stateBadgeLabel(cfgMeta[k].state)"></span>
                                            <span x-show="cfgMeta[k].source === 'bd'"
                                                  class="text-[10px] px-1.5 py-0.5 bg-brand-100 text-brand-700 rounded font-semibold">modificado</span>
                                            <code class="text-[10px] text-slate-400" x-text="k"></code>
                                        </div>
                                        <p class="text-xs text-slate-500 mt-1 leading-relaxed" x-text="cfgMeta[k].help"></p>
                                        <button x-show="cfgMeta[k].detail"
                                                @click="toggleDetail(k)"
                                                class="text-[11px] mt-1 text-brand-600 hover:underline flex items-center gap-1">
                                            <i class="fas text-[9px]" :class="detailOpen[k] ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                                            <span x-text="detailOpen[k] ? 'Ocultar detalle' : 'Ver detalle'"></span>
                                        </button>
                                        <div x-show="detailOpen[k] && cfgMeta[k].detail"
                                             x-transition.opacity.duration.150ms
                                             class="mt-2 p-3 bg-slate-50 border border-slate-200 rounded-lg text-[11px] text-slate-700 space-y-2">
                                            <template x-if="cfgMeta[k].detail && cfgMeta[k].detail.alcance">
                                                <div>
                                                    <p class="font-semibold text-slate-600 mb-0.5">Alcance</p>
                                                    <p class="leading-relaxed" x-text="cfgMeta[k].detail.alcance"></p>
                                                </div>
                                            </template>
                                            <template x-if="cfgMeta[k].detail && cfgMeta[k].detail.cuando_tocar">
                                                <div>
                                                    <p class="font-semibold text-slate-600 mb-0.5">Cuándo tocar</p>
                                                    <p class="leading-relaxed" x-text="cfgMeta[k].detail.cuando_tocar"></p>
                                                </div>
                                            </template>
                                            <template x-if="cfgMeta[k].detail && cfgMeta[k].detail.riesgos">
                                                <div>
                                                    <p class="font-semibold text-slate-600 mb-0.5">Riesgos</p>
                                                    <p class="leading-relaxed" x-text="cfgMeta[k].detail.riesgos"></p>
                                                </div>
                                            </template>
                                        </div>
                                        <p class="text-[11px] text-slate-400 mt-1">
                                            Por defecto: <span class="font-mono" x-text="String(cfgMeta[k].default)"></span>
                                            <span x-text="cfgMeta[k].source === 'env' ? '(.env)' : '(archivo)'"></span>
                                            <button x-show="cfgMeta[k].source === 'bd'" @click="resetKey(k)"
                                                    class="ml-2 text-brand-600 hover:underline">restaurar</button>
                                        </p>
                                        <p x-show="cfgErrors[k]" class="text-[11px] text-red-600 mt-1 font-medium" x-text="cfgErrors[k]"></p>
                                    </div>

                                    <div class="flex-shrink-0 w-40">
                                        {{-- booleano --}}
                                        <template x-if="cfgMeta[k].type === 'bool'">
                                            <button @click="cfg[k] = !cfg[k]; cfgDirty = true"
                                                    class="w-11 h-6 rounded-full transition-colors relative"
                                                    :class="cfg[k] ? 'bg-brand-500' : 'bg-slate-300'">
                                                <span class="absolute top-0.5 w-5 h-5 bg-white rounded-full transition-all shadow"
                                                      :class="cfg[k] ? 'left-[22px]' : 'left-0.5'"></span>
                                            </button>
                                        </template>
                                        {{-- enum --}}
                                        <template x-if="cfgMeta[k].options">
                                            <select x-model="cfg[k]" @change="cfgDirty = true"
                                                    class="w-full px-2.5 py-1.5 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                                                <template x-for="o in cfgMeta[k].options" :key="o">
                                                    <option :value="o" x-text="o"></option>
                                                </template>
                                            </select>
                                        </template>
                                        {{-- entero --}}
                                        <template x-if="cfgMeta[k].type === 'int'">
                                            <div>
                                                <input type="number" x-model.number="cfg[k]" @input="cfgDirty = true"
                                                       :min="cfgMeta[k].min" :max="cfgMeta[k].max"
                                                       class="w-full px-2.5 py-1.5 text-sm border rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                                                       :class="cfgErrors[k] ? 'border-red-300' : 'border-slate-200'">
                                                <p class="text-[10px] text-slate-400 mt-1 text-right">
                                                    <span x-text="cfgMeta[k].min"></span>–<span x-text="cfgMeta[k].max"></span>
                                                </p>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Barra de guardado --}}
            <div data-tour="cfg-save" class="sticky bottom-4 mt-5">
                <div class="bg-white rounded-xl shadow-lg border border-slate-200 px-5 py-3 flex items-center justify-between gap-4">
                    <p class="text-xs text-slate-500">
                        <template x-if="cfgDirty"><span class="text-amber-600 font-medium"><i class="fas fa-circle text-[6px] align-middle"></i> Cambios sin guardar</span></template>
                        <template x-if="!cfgDirty"><span>Los cambios aplican en el siguiente ciclo, sin reiniciar nada.</span></template>
                    </p>
                    <div class="flex items-center gap-2">
                        <button @click="loadConfig()" :disabled="cfgSaving"
                                class="px-3 py-1.5 text-xs rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50">
                            Descartar
                        </button>
                        <button @click="saveConfig()" :disabled="!cfgDirty || cfgSaving"
                                class="px-5 py-2 rounded-lg text-sm font-medium bg-brand-600 hover:bg-brand-700 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas" :class="cfgSaving ? 'fa-circle-notch fa-spin' : 'fa-save'"></i>
                            <span x-text="cfgSaving ? 'Guardando…' : 'Guardar cambios'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </template>
    </div> {{-- /TAB CONFIG --}}


    {{-- =====================================================================
         Modal: Procesamiento personalizado ("Procesar históricos")

         Permite elegir alcance (hoy / rango / histórico) y QUÉ tipo de trabajo
         atacar (descubrir sin fila, reintentar con error, reprocesar hechos),
         con estimación previa para decidir informado.

         Backend: POST /api-transcriptor/scan/estimate (solo lectura) y
         POST /api-transcriptor/scan/run (background + polling). El envío sigue
         regulado por el tick y el worker PG: este modal DESCUBRE y ENCOLA,
         nunca salta el regulador ni la histéresis de la cola remota.
         ===================================================================== --}}
    <div x-cloak x-show="pzOpen" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800 mb-1">
                            <i class="fas fa-clock-rotate-left text-brand-500 mr-1"></i> Procesamiento personalizado
                        </h2>
                        <p class="text-xs text-slate-500">
                            Envía a procesar lo que elijas: pendientes, con error o sin transcripción.
                            El envío real lo regula el pipeline (stager + worker PG), así que no satura el host.
                        </p>
                    </div>
                    <button x-show="!pzRunning" @click="closePz()" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                {{-- Configuración --}}
                <div x-show="!pzRunning && !pzResult" class="space-y-4">
                    {{-- Alcance --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Alcance</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" @click="pzScope = 'today'; refreshPzEstimate()"
                                    :class="pzScope === 'today' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="p-3 rounded-xl border text-left transition-all">
                                <i class="fas fa-sun text-xs" :class="pzScope === 'today' ? 'text-brand-600' : 'text-slate-400'"></i>
                                <p class="text-sm font-semibold text-slate-800 mt-1">Hoy</p>
                                <p class="text-[10px] text-slate-400 leading-tight mt-0.5">Carpeta del día</p>
                            </button>
                            <button type="button" @click="pzScope = 'range'; refreshPzEstimate()"
                                    :class="pzScope === 'range' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="p-3 rounded-xl border text-left transition-all">
                                <i class="fas fa-calendar-alt text-xs" :class="pzScope === 'range' ? 'text-brand-600' : 'text-slate-400'"></i>
                                <p class="text-sm font-semibold text-slate-800 mt-1">Rango</p>
                                <p class="text-[10px] text-slate-400 leading-tight mt-0.5">Fechas específicas</p>
                            </button>
                            <button type="button" @click="pzScope = 'all'; refreshPzEstimate()"
                                    :class="pzScope === 'all' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="p-3 rounded-xl border text-left transition-all">
                                <i class="fas fa-infinity text-xs" :class="pzScope === 'all' ? 'text-brand-600' : 'text-slate-400'"></i>
                                <p class="text-sm font-semibold text-slate-800 mt-1">Histórico</p>
                                <p class="text-[10px] text-slate-400 leading-tight mt-0.5">Todo el archivo</p>
                            </button>
                        </div>

                        <div x-show="pzScope === 'range'" x-transition class="mt-3 p-3 bg-slate-50 border border-slate-200 rounded-xl">
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                                <label class="relative block">
                                    <i class="fas fa-calendar-day absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                    <input type="date" x-model="pzFrom" @change="refreshPzEstimate()"
                                           :max="new Date().toISOString().slice(0,10)"
                                           class="w-full text-sm border border-slate-300 rounded-lg pl-8 pr-2 py-2 bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
                                </label>
                                <div class="flex flex-col items-center text-slate-300 leading-none">
                                    <i class="fas fa-arrow-right text-[10px]"></i>
                                </div>
                                <label class="relative block">
                                    <i class="fas fa-calendar-day absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                    <input type="date" x-model="pzTo" @change="refreshPzEstimate()"
                                           :max="new Date().toISOString().slice(0,10)"
                                           class="w-full text-sm border border-slate-300 rounded-lg pl-8 pr-2 py-2 bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
                                </label>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>Se procesan las carpetas diarias (formato DDMMYYYY) dentro del rango.
                            </p>
                        </div>
                    </div>

                    {{-- Qué procesar --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Qué procesar</label>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="pzIncludeMissing" class="w-4 h-4 accent-brand-600 rounded">
                                    <span class="text-sm text-slate-700">Sin transcripción <span x-show="pzEstimate" class="text-slate-400" x-text="'(' + (pzEstimate?.files_missing ?? 0).toLocaleString() + ')'"></span></span>
                                </label>
                                <span class="text-xs text-slate-400">Descubre archivos sin fila y los encola</span>
                            </div>
                            <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="pzIncludeFailed" class="w-4 h-4 accent-amber-600 rounded">
                                    <span class="text-sm text-slate-700">Con error <span x-show="pzEstimate" class="text-amber-600" x-text="'(' + (pzEstimate?.error_recoverable ?? 0).toLocaleString() + ')'"></span></span>
                                </label>
                                <span class="text-xs text-amber-700">Reintenta las que fallaron (archivo accesible)</span>
                            </div>
                            <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="pzIncludeDone" class="w-4 h-4 accent-amber-600 rounded">
                                    <span class="text-sm text-slate-700">Completados <span x-show="pzEstimate" class="text-amber-600" x-text="'(' + (pzEstimate?.done_rescan ?? 0).toLocaleString() + ')'"></span></span>
                                </label>
                                <span class="text-xs text-amber-700">Reprocesa las ya finalizadas (reemplaza el SRT)</span>
                            </div>
                        </div>
                    </div>

                    {{-- Estimación --}}
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                        <template x-if="pzEstimating">
                            <p class="text-xs text-slate-500"><i class="fas fa-spinner fa-spin mr-1"></i>Estimando alcance…</p>
                        </template>
                        <template x-if="!pzEstimating && pzError">
                            <p class="text-xs text-red-600"><i class="fas fa-triangle-exclamation mr-1"></i><span x-text="pzError"></span></p>
                        </template>
                        <template x-if="!pzEstimating && !pzError && pzEstimate">
                            <div class="text-xs text-slate-600 space-y-1">
                                <p class="font-medium text-slate-700">
                                    <span x-text="pzEstimate.storages_count"></span> storages habilitados en el alcance
                                    <span x-show="pzEstimate.capped" class="text-amber-600">(conteo parcial: superó el límite)</span>
                                </p>
                                <p><i class="fas fa-file-circle-plus text-slate-400 mr-1"></i><span x-text="(pzEstimate.files_missing ?? 0).toLocaleString()"></span> archivos sin transcripción</p>
                                <p><i class="fas fa-triangle-exclamation text-slate-400 mr-1"></i><span x-text="(pzEstimate.error_recoverable ?? 0).toLocaleString()"></span> con error reintenables</p>
                                <p><i class="fas fa-rotate text-slate-400 mr-1"></i><span x-text="(pzEstimate.done_rescan ?? 0).toLocaleString()"></span> completadas reprocesables</p>
                                <p class="text-slate-400 pt-1">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    El envío lo regula el pipeline: se llena el RAM disk y se respeta el techo de la cola remota.
                                </p>
                            </div>
                        </template>
                    </div>

                    {{-- Ajustes finos --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Límite por storage <span class="text-slate-400">(0 = config)</span></label>
                            <input type="number" min="0" max="200" x-model.number="pzBatch"
                                   class="w-full text-sm border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="pzAlerts" class="w-4 h-4 accent-brand-600 rounded">
                                <span class="text-sm text-slate-700">Generar alertas</span>
                            </label>
                        </div>
                    </div>

                    <div x-show="!pzHasWork()" class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-500">
                        <i class="fas fa-info-circle mr-1"></i>Marca al menos un tipo de trabajo para continuar.
                    </div>

                    <div class="flex gap-2">
                        <button @click="runPz()" :disabled="!pzHasWork()"
                                :class="!pzHasWork() ? 'opacity-50 cursor-not-allowed' : ''"
                                class="flex-1 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-play mr-1"></i> Iniciar procesamiento
                        </button>
                        <button @click="closePz()"
                                class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>

                {{-- Progreso --}}
                <div x-show="pzRunning" class="space-y-4">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin text-brand-500 text-3xl mb-3"></i>
                        <p class="text-sm font-medium text-slate-700">Procesando en background…</p>
                        <p class="text-xs text-slate-400 mt-1">Puedes cerrar el modal o recargar: la corrida continúa.</p>
                    </div>
                    <div x-show="pzProgress">
                        <div class="flex items-center justify-between text-[10px] text-slate-500 uppercase tracking-wide mb-1">
                            <span x-text="pzProgress?.current_storage || pzProgress?.status || ''"></span>
                            <span x-text="(pzProgress?.processed || 0) + '/' + (pzProgress?.total_to_process || 0)"></span>
                        </div>
                        <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                            <div class="h-full bg-brand-500 transition-all duration-300"
                                 :style="'width: ' + (pzProgress?.total_to_process ? Math.round((pzProgress?.processed || 0) / pzProgress.total_to_process * 100) : 0) + '%'"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-1.5 truncate"
                           x-text="pzProgress?.current_file ? 'Procesando: ' + pzProgress.current_file : 'Iniciando…'"></p>
                        <div class="flex gap-4 mt-2 text-xs">
                            <span class="text-green-600"><i class="fas fa-check mr-1"></i><span x-text="pzProgress?.pending_created || 0"></span> pendientes creados</span>
                            <span class="text-brand-600"><i class="fas fa-paper-plane mr-1"></i><span x-text="pzProgress?.dispatched || 0"></span> encolados</span>
                            <span class="text-red-600" x-show="(pzProgress?.errors || 0) > 0"><i class="fas fa-times mr-1"></i><span x-text="pzProgress?.errors || 0"></span> errores</span>
                        </div>
                    </div>
                    <div x-show="pzProgress && (pzProgress?.storages || []).length > 0">
                        <h3 class="text-xs font-semibold text-slate-600 mb-1.5"><i class="fas fa-database mr-1"></i>Descubrimiento por storage</h3>
                        <div class="space-y-1.5 max-h-52 overflow-y-auto">
                            <template x-for="(s, idx) in (pzProgress?.storages || [])" :key="s.id || idx">
                                <div class="flex items-center justify-between px-2.5 py-1.5 bg-slate-50 rounded-lg text-xs">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <i class="fas fa-database text-slate-400 text-[10px]"></i>
                                        <span class="font-medium text-slate-700 truncate" x-text="s.name"></span>
                                    </div>
                                    <div class="flex items-center gap-2.5 whitespace-nowrap">
                                        <span class="text-slate-400" x-text="(s.scanned || 0) + ' esc.'"></span>
                                        <span class="text-brand-700 font-medium" x-text="(s.files_created || 0) + ' arch.'"></span>
                                        <span class="text-green-600 font-medium" x-text="(s.tx_created || 0) + ' pend.'"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    <button @click="closePz()"
                            class="w-full px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                        Ocultar (sigue en background)
                    </button>
                </div>

                {{-- Resultado --}}
                <div x-show="!pzRunning && pzResult" class="space-y-4">
                    <div class="rounded-lg border p-3 text-sm"
                         :class="(pzResult?.errors || 0) > 0 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-green-50 border-green-200 text-green-800'">
                        <i class="fas" :class="(pzResult?.errors || 0) > 0 ? 'fa-triangle-exclamation text-red-500' : 'fa-check-circle text-green-500'"></i>
                        <span x-text="pzResult?.message || ((pzResult?.errors || 0) > 0 ? 'Terminó con errores.' : 'Procesamiento completado.')"></span>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-slate-700" x-text="pzResult?.pending_created || 0"></p>
                            <p class="text-xs text-slate-500">Pendientes creados</p>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-brand-600" x-text="pzResult?.dispatched || 0"></p>
                            <p class="text-xs text-slate-500">Encolados</p>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-red-600" x-text="pzResult?.errors || 0"></p>
                            <p class="text-xs text-slate-500">Errores</p>
                        </div>
                    </div>
                    <div x-show="(pzResult?.failed_recovered ?? 0) > 0 || (pzResult?.failed_promoted_to_dead ?? 0) > 0"
                         class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                        <h3 class="text-sm font-semibold text-amber-800 mb-2"><i class="fas fa-rotate mr-1"></i>Reintento de fallidos</h3>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="bg-white/70 rounded p-2 text-center">
                                <p class="text-lg font-bold text-amber-700" x-text="pzResult?.failed_recovered || 0"></p>
                                <p class="text-amber-600">Recuperados</p>
                            </div>
                            <div class="bg-white/70 rounded p-2 text-center">
                                <p class="text-lg font-bold text-red-600" x-text="pzResult?.failed_promoted_to_dead || 0"></p>
                                <p class="text-red-500">Promovidos a dead</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button @click="closePz(); load(); loadConfig();"
                                class="flex-1 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-check mr-1"></i> Aceptar
                        </button>
                        <button @click="pzResult = null"
                                class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-redo mr-1"></i> Otro procesamiento
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
