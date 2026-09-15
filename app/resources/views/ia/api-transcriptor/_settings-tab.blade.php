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
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    {{-- Cola vs objetivo --}}
                    <div data-tour="cfg-queue" class="md:col-span-2">
                        <div class="flex items-baseline justify-between mb-1">
                            <span class="text-xs text-slate-500">Cola de despacho</span>
                            <span class="text-xs font-mono text-slate-600">
                                <span x-text="cfgRuntime?.queue_depth ?? '—'"></span> / <span x-text="cfgRuntime?.queue_target"></span>
                            </span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full transition-all duration-300"
                                 :class="queuePct() >= 100 ? 'bg-amber-500' : 'bg-brand-500'"
                                 :style="'width: ' + Math.min(100, queuePct()) + '%'"></div>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1.5">
                            <template x-if="cfgRuntime?.next_batch === 0">
                                <span class="text-amber-600 font-medium">
                                    <i class="fas fa-hand-paper"></i> El regulador frenaría: la cola está en/sobre el objetivo.
                                </span>
                            </template>
                            <template x-if="cfgRuntime?.next_batch > 0">
                                <span>Ahora mismo enviaría <strong x-text="cfgRuntime.next_batch"></strong> trabajos.</span>
                            </template>
                        </p>
                    </div>

                    {{-- Workers --}}
                    <div data-tour="cfg-workers">
                        <p class="text-xs text-slate-500 mb-1">Workers activos</p>
                        <p class="text-2xl font-semibold text-slate-800 leading-none">
                            <span x-text="cfgRuntime?.workers?.active ?? '—'"></span>
                            <span class="text-sm text-slate-400 font-normal">/ <span x-text="cfgRuntime?.workers?.installed ?? '—'"></span></span>
                        </p>
                        <p x-show="cfgRuntime?.workers?.orphans > 0" class="text-[11px] text-red-600 mt-1 font-medium">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span x-text="cfgRuntime.workers.orphans"></span> huérfanos activos
                        </p>
                        <p x-show="cfgRuntime?.workers?.override > 0" class="text-[11px] text-brand-600 mt-1">
                            Forzado a <span x-text="cfgRuntime.workers.override"></span>
                        </p>
                    </div>

                    {{-- Estados --}}
                    <div data-tour="cfg-states">
                        <p class="text-xs text-slate-500 mb-1">Transcripciones</p>
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
            </div>

            {{-- Grupos de knobs --}}
            <template x-for="group in cfgGroups()" :key="group">
                <div class="mb-4 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden" :data-tour="'cfg-group-' + group">
                    <div class="px-5 py-3 border-b border-slate-100 bg-slate-50/60 flex items-center gap-3">
                        <i class="fas text-brand-500 text-base" :class="cfgGroupIcons[group] || 'fa-cog'"></i>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-semibold text-slate-700" x-text="groupLabel(group)"></h3>
                            <p class="text-xs text-slate-400 mt-0.5" x-text="groupHelp(group)"></p>
                        </div>
                    </div>
                    <div class="divide-y divide-slate-100">
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

    {{-- Modal de progreso (envío manual) --}}
    <div x-cloak x-show="showProgress" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[60] p-4" x-transition @click.away="if (progressStep === 'done' || progressStep === 'error') closeProgress()">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-slate-800 mb-1">Progreso de la transcripción</h2>
                        <p class="text-xs text-slate-500 truncate" x-text="progressFile?.name"></p>
                    </div>
                    <button x-show="progressStep === 'done' || progressStep === 'error'" @click="closeProgress()" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                {{-- Barra de progreso --}}
                <div class="mb-4">
                    <div class="flex items-center justify-between text-[10px] text-slate-500 uppercase tracking-wide mb-1">
                        <span x-text="progressStep === 'converting' ? 'Convirtiendo audio' : (progressStep === 'uploading' ? 'Enviando a la API' : (progressStep === 'queued' ? 'Encolado en la API' : (progressStep === 'processing' ? 'Procesando en la API externa' : (progressStep === 'done' ? 'Listo' : (progressStep === 'error' ? 'Error' : 'Iniciando...')))))"></span>
                        <span x-text="progressPercent + '%'" class="font-mono"></span>
                    </div>
                    <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                        <div class="h-full transition-all duration-300"
                             :class="progressStep === 'error' ? 'bg-red-500' : (progressStep === 'done' ? 'bg-green-500' : 'bg-brand-500')"
                             :style="'width: ' + progressPercent + '%'"></div>
                    </div>
                </div>

<div class="space-y-3 my-4">
                    {{-- Paso 1: convertir audio --}}
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center"
                             :class="progressStep === 'converting' ? 'bg-blue-500 text-white animate-pulse' : (['done','queued','processing'].includes(progressStep) ? 'bg-green-500 text-white' : (progressStep === 'error' ? 'bg-red-500 text-white' : 'bg-slate-200 text-slate-400'))">
                            <i x-show="progressStep === 'converting'" class="fas fa-cog fa-spin text-xs"></i>
                            <i x-show="['done','queued','processing'].includes(progressStep)" class="fas fa-check text-xs"></i>
                            <i x-show="progressStep === 'error'" class="fas fa-times text-xs"></i>
                            <i x-show="progressStep === 'sending'" class="fas fa-circle text-[6px]"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-700">Convertir audio a Opus (ffmpeg)</p>
                            <p class="text-xs text-slate-500" x-text="progressStep === 'converting' ? 'Convirtiendo ' + (progressFile?.size_human || '') + ' a {{ config('transcriptor.audio_output_format', 'wav') }} mono 16kHz...' : (['done','queued','processing'].includes(progressStep) ? 'Audio convertido correctamente' : 'Pendiente')"></p>
                        </div>
                    </div>

                    {{-- Paso 2: enviar a la API --}}
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center"
                             :class="progressStep === 'converting' ? 'bg-slate-200 text-slate-400' : (progressStep === 'queued' ? 'bg-blue-500 text-white animate-pulse' : (['done','processing'].includes(progressStep) ? 'bg-green-500 text-white' : (progressStep === 'error' ? 'bg-red-500 text-white' : 'bg-slate-200 text-slate-400')))">
                            <i x-show="progressStep === 'queued'" class="fas fa-cloud-upload-alt fa-spin text-xs"></i>
                            <i x-show="['done','processing'].includes(progressStep)" class="fas fa-check text-xs"></i>
                            <i x-show="progressStep === 'error'" class="fas fa-times text-xs"></i>
                            <i x-show="progressStep === 'converting' || progressStep === 'sending'" class="fas fa-circle text-[6px]"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-700">Enviar a la API del transcriptor</p>
                            <p class="text-xs text-slate-500" x-text="progressStep === 'queued' ? 'Subiendo Opus a la API externa...' : (['done','processing'].includes(progressStep) ? ('Encolado en la API · job_id: ' + (progressStatus?.job_id || '—')) : 'Pendiente')"></p>
                        </div>
                    </div>

                    {{-- Paso 3: procesamiento API --}}
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center"
                             :class="progressStep === 'processing' ? 'bg-blue-500 text-white animate-pulse' : (progressStep === 'done' ? 'bg-green-500 text-white' : (progressStep === 'error' ? 'bg-red-500 text-white' : 'bg-slate-200 text-slate-400'))">
                            <i x-show="progressStep === 'processing'" class="fas fa-spinner fa-spin text-xs"></i>
                            <i x-show="progressStep === 'done'" class="fas fa-check text-xs"></i>
                            <i x-show="progressStep === 'error'" class="fas fa-times text-xs"></i>
                            <i x-show="progressStep !== 'processing' && progressStep !== 'done' && progressStep !== 'error'" class="fas fa-circle text-[6px]"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-700">Procesando en la API externa</p>
                            <p class="text-xs text-slate-500" x-text="progressStep === 'processing' ? 'Job ID: ' + (progressStatus?.job_id || '—') + ' · ' + (progressElapsed || 0) + 's' : (progressStep === 'done' ? 'Procesamiento completado' : (progressStep === 'error' ? 'Error en la API' : 'Esperando estado...'))"></p>
                        </div>
                    </div>

                    {{-- Paso 4: resultado --}}
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center"
                             :class="progressStep === 'done' ? 'bg-green-500 text-white' : (progressStep === 'error' ? 'bg-red-500 text-white' : 'bg-slate-200 text-slate-400')">
                            <i x-show="progressStep === 'done'" class="fas fa-check text-xs"></i>
                            <i x-show="progressStep === 'error'" class="fas fa-times text-xs"></i>
                            <i x-show="progressStep !== 'done' && progressStep !== 'error'" class="fas fa-circle text-[6px]"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-700">Resultado</p>
                            <p class="text-xs text-slate-500" x-text="progressStep === 'done' ? 'Listo: ' + (progressResult?.segments_count || 0) + ' segmentos, ' + (progressResult?.duration_seconds || 0) + 's, ' + (progressResult?.word_count || 0) + ' palabras' : (progressStep === 'error' ? (progressError || 'Error desconocido') : 'Pendiente...')"></p>
                        </div>
                    </div>
                </div>

                <div x-show="progressStep === 'error'" class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
                    <strong>Error:</strong> <span x-text="progressError"></span>
                </div>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <p class="text-[10px] text-slate-400" x-text="progressStep !== 'done' && progressStep !== 'error' ? 'Actualizando cada 2s...' : ''"></p>
                    <div class="flex gap-2 ml-auto">
                        <a x-show="progressStep === 'done' && progressStatus?.id" :href="'/ia/api-transcriptor/jobs/' + (progressStatus?.id || '')"
                           class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium">
                            <i class="fas fa-eye mr-1"></i> Ver detalle
                        </a>
                        <button x-show="progressStep === 'done' || progressStep === 'error'" @click="closeProgress(); loadFiles(); load();"
                                class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Mini-modal confirmación carpeta/día --}}
    {{-- Confirmación de apagado de un storage. En modal propio, no en confirm()
         nativo: el navegador suprime esos diálogos cuando el usuario marca
         "impedir que esta página cree más diálogos", y el clic se queda mudo. --}}
    <div x-cloak x-show="storageToDisable" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl">
            <div class="p-5">
                <h3 class="text-base font-bold text-slate-800 mb-2">
                    ¿Dejar de transcribir "<span x-text="storageToDisable?.name"></span>"?
                </h3>
                <p class="text-sm text-slate-600 mb-4">
                    Se detiene el descubrimiento de archivos nuevos de este storage.
                    Lo ya transcrito se conserva, y los trabajos en cola terminan.
                </p>
                <div class="flex gap-2">
                    <button @click="confirmDisableStorage()"
                            class="flex-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-sm font-medium transition-colors">
                        Dejar de transcribir
                    </button>
                    <button @click="storageToDisable = null"
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div x-cloak x-show="showProcessConfirm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl">
            <div class="p-5">
                <h3 class="text-base font-bold text-slate-800 mb-3" x-text="processConfirmText"></h3>
                <label class="flex items-center gap-2 cursor-pointer mb-4">
                    <input type="checkbox" x-model="processAlerts" class="w-4 h-4 accent-brand-600 rounded">
                    <span class="text-sm text-slate-700">Generar alertas</span>
                </label>
                <div class="flex gap-2">
                    <button @click="executeProcessConfirm()"
                            class="flex-1 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                        Encolar
                    </button>
                    <button @click="showProcessConfirm = false"
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal de procesamiento por lotes --}}
    <div x-cloak x-show="showBatchModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800 mb-1">Escanear storages</h2>
                        <p class="text-xs text-slate-500">Busca archivos en storages habilitados que aún no tienen transcripción y los envía al transcriptor. El lote es <strong>por storage</strong>: cada storage procesa hasta el cupo configurado. Los más recientes primero.</p>
                    </div>
                    <button x-show="!batchRunning" @click="showBatchModal = false" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                {{-- Configuración del lote --}}
                <div x-show="!batchRunning && !batchResult" class="space-y-4">
                    {{-- transcriptor-scan-scope-selector: alcance del escaneo (tarjetas seleccionables) --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Alcance del escaneo</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" @click="batchScope = 'today'; refreshBatchEstimate()"
                                    :class="batchScope === 'today' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="p-3 rounded-xl border text-left transition-all">
                                <i class="fas fa-sun text-xs" :class="batchScope === 'today' ? 'text-brand-600' : 'text-slate-400'"></i>
                                <p class="text-sm font-semibold text-slate-800 mt-1">Hoy</p>
                                <p class="text-[10px] text-slate-400 leading-tight mt-0.5">Solo la carpeta del día</p>
                            </button>
                            <button type="button" @click="batchScope = 'range'; refreshBatchEstimate()"
                                    :class="batchScope === 'range' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="p-3 rounded-xl border text-left transition-all">
                                <i class="fas fa-calendar-alt text-xs" :class="batchScope === 'range' ? 'text-brand-600' : 'text-slate-400'"></i>
                                <p class="text-sm font-semibold text-slate-800 mt-1">Rango</p>
                                <p class="text-[10px] text-slate-400 leading-tight mt-0.5">Fechas específicas</p>
                            </button>
                            <button type="button" @click="batchScope = 'all'; refreshBatchEstimate()"
                                    :class="batchScope === 'all' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="p-3 rounded-xl border text-left transition-all">
                                <i class="fas fa-infinity text-xs" :class="batchScope === 'all' ? 'text-brand-600' : 'text-slate-400'"></i>
                                <p class="text-sm font-semibold text-slate-800 mt-1">Histórico</p>
                                <p class="text-[10px] text-slate-400 leading-tight mt-0.5">Todas las carpetas</p>
                            </button>
                        </div>
                        {{-- Inputs de fecha con icono, visibles solo en modo rango --}}
                        <div x-show="batchScope === 'range'" x-transition class="mt-3 p-3 bg-slate-50 border border-slate-200 rounded-xl">
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                                <label class="relative block">
                                    <i class="fas fa-calendar-day absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                    <input type="date" x-model="batchScopeFrom" @change="refreshBatchEstimate()"
                                           :max="new Date().toISOString().slice(0,10)"
                                           class="w-full text-sm border border-slate-300 rounded-lg pl-8 pr-2 py-2 bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
                                </label>
                                <div class="flex flex-col items-center text-slate-300 leading-none">
                                    <i class="fas fa-arrow-right text-[10px]"></i>
                                </div>
                                <label class="relative block">
                                    <i class="fas fa-calendar-day absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                    <input type="date" x-model="batchScopeTo" @change="refreshBatchEstimate()"
                                           :max="new Date().toISOString().slice(0,10)"
                                           class="w-full text-sm border border-slate-300 rounded-lg pl-8 pr-2 py-2 bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
                                </label>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-2"><i class="fas fa-info-circle mr-1"></i>Se escanean las carpetas diarias dentro del rango (formato de carpetas DDMMYYYY).</p>
                        </div>
                    </div>

                    {{-- Estimación previa (no muta) --}}
                    <div x-show="batchScope !== 'today'" class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                        <template x-if="batchEstimateLoading">
                            <p class="text-xs text-slate-500"><i class="fas fa-spinner fa-spin mr-1"></i>Estimando alcance...</p>
                        </template>
                        <template x-if="!batchEstimateLoading && batchEstimateError">
                            <p class="text-xs text-red-600"><i class="fas fa-exclamation-triangle mr-1"></i><span x-text="batchEstimateError"></span></p>
                        </template>
                        <template x-if="!batchEstimateLoading && batchEstimate">
                            <div class="text-xs text-slate-600 space-y-1">
                                <p><strong x-text="batchEstimate.files_missing.toLocaleString()"></strong> archivos sin transcripción en el alcance<span x-show="batchEstimate.estimation_capped"> (conteo parcial: superó el límite de estimación)</span></p>
                                <p x-show="batchEstimate.error_recoverable != null"><span x-text="batchEstimate.error_recoverable"></span> transcripciones en <strong>error</strong> reintenables con el checkbox de abajo</p>
                                <p x-show="batchEstimate.done_rescan != null && batchEstimate.done_rescan > 0"><span x-text="batchEstimate.done_rescan.toLocaleString()"></span> transcripciones en <strong>done</strong> reprocesables marcando "Incluir completados" abajo</p>
                                <p x-show="batchEstimate.dead_irrecoverable != null" class="text-amber-600"><span x-text="batchEstimate.dead_irrecoverable"></span> en <strong>dead</strong> NO se reintentan (audio ausente; solo upstream-lost con backfill-lost)</p>
                                <p class="text-slate-400"><i class="fas fa-info-circle mr-1"></i>El envío sigue regulado por ciclo; los pendientes sobrantes los recoge el cron automático.</p>
                            </div>
                        </template>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Tamaño del lote</label>
                        <div class="flex items-center gap-3">
                            <input type="range" min="10" :max="uiBatchMax" step="10" x-model.number="batchSize"
                                   class="flex-1 accent-brand-600">
                            <span class="text-2xl font-bold text-brand-600 w-16 text-center" x-text="batchSize"></span>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <button @click="batchSize = 50" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">50</button>
                            <button @click="batchSize = 100" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">100</button>
                            <button @click="batchSize = 150" x-show="uiBatchMax > 150" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">150</button>
                            {{-- El preset dinámico solo se muestra cuando NO duplica los fijos --}}
                            <button @click="batchSize = uiBatchMax" x-show="uiBatchMax !== 50 && uiBatchMax !== 100 && uiBatchMax !== 150"
                                    class="px-2 py-1 text-xs bg-brand-100 text-brand-700 hover:bg-brand-200 rounded font-medium" x-text="uiBatchMax + ' (máx)'"></button>
                        </div>
                        <p class="text-xs text-slate-400 mt-2"><i class="fas fa-info-circle mr-1"></i>Cupo por storage. Con 100, cada storage envía hasta 100 archivos por ciclo. Los más recientes primero.</p>
                    </div>
                    {{-- Checkbox alertas --}}
                    <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="batchAlerts" class="w-4 h-4 accent-brand-600 rounded">
                            <span class="text-sm text-slate-700">Generar alertas</span>
                        </label>
                        <span class="text-xs text-slate-400" x-show="!batchAlerts"><i class="fas fa-info-circle mr-1"></i>Las transcripciones se guardarán SIN generar menciones de keywords</span>
                        <span class="text-xs text-amber-600" x-show="batchAlerts"><i class="fas fa-bell mr-1"></i>Generará menciones de keywords; los correos los recibe cada cliente según su cadencia</span>
                    </div>
                    {{-- Checkbox reintentar fallidos --}}
                    <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="batchIncludeFailed" class="w-4 h-4 accent-amber-600 rounded">
                            <span class="text-sm font-medium text-slate-700">Reintentar fallidos</span>
                            <i class="fas fa-info-circle text-slate-400 text-xs cursor-help"
                               title="Reencola transcripciones en estado 'error' cuyo archivo sigue accesible. Máx. 3 reintentos automáticos; al cuarto fallo consecutivo pasan a 'dead'. Ojo: a 'dead' también se llega sin agotar reintentos, si el transcriptor pierde el resultado o si la fila caduca; esas se recuperan con transcription:backfill-lost."></i>
                        </label>
                        <span class="text-xs text-amber-700" x-show="batchIncludeFailed"><i class="fas fa-redo mr-1"></i>Se reencolarán transcripciones con error previo (archivo accesible, retries &lt; 3)</span>
                    </div>
                    {{-- transcriptor-rescan-completed: checkbox incluir completados --}}
                    <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="batchIncludeDone" class="w-4 h-4 accent-amber-600 rounded">
                            <span class="text-sm font-medium text-slate-700">Incluir completados</span>
                            <i class="fas fa-info-circle text-slate-400 text-xs cursor-help"
                               title="Reenvía transcripciones ya finalizadas (state='done') a la API externa para regenerarlas. Conserva el archivo en disco y la fila; solo se sobreescribe srt_content al confirmar el nuevo resultado. Si el reenvío falla, la fila queda en 'error' con el srt_content viejo como fallback. Genera nuevas alertas según el flag 'Generar alertas'."></i>
                        </label>
                        <span class="text-xs text-amber-700" x-show="batchIncludeDone"><i class="fas fa-redo mr-1"></i>Se reencolarán transcripciones finalizadas (archivo accesible, retries++). El srt_content viejo se mantiene hasta que el nuevo se confirme.</span>
                    </div>
                    <div x-show="storagesEnabled.length === 0" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-700">
                        <i class="fas fa-exclamation-triangle mr-1"></i>No hay storages habilitados para transcripción.
                    </div>
                    <div class="flex gap-2">
                        <button @click="runBatch()" x-show="storagesEnabled.length > 0" :disabled="!batchScopeValid()"
                                :class="!batchScopeValid() ? 'opacity-50 cursor-not-allowed' : ''"
                                :title="batchEstimateLoading ? 'Estimación cargando... podés iniciar de todos modos' : ''"
                                class="flex-1 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-play mr-1" :class="batchEstimateLoading ? 'fa-spin' : ''"></i>
                            <span x-text="batchEstimateLoading ? 'Iniciar (estimando...)' : 'Iniciar procesamiento'"></span>
                        </button>
                        <button @click="showBatchModal = false"
                                class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>

                {{-- Progreso en vivo mientras procesa en background --}}
                <div x-show="batchRunning" class="space-y-4">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin text-brand-500 text-3xl mb-3"></i>
                        <p class="text-sm font-medium text-slate-700">Procesando lote de <span x-text="batchSize"></span> archivos...</p>
                        <p class="text-xs text-slate-400 mt-1">Puedes minimizar o recargar. El lote corre en background.</p>
                    </div>
                    {{-- Barra de progreso --}}
                    <div x-show="batchProgress && batchProgress.total_to_process > 0">
                        <div class="flex items-center justify-between text-[10px] text-slate-500 uppercase tracking-wide mb-1">
                            <span x-text="batchProgress?.current_storage || ''"></span>
                            <span x-text="(batchProgress?.processed || 0) + '/' + (batchProgress?.total_to_process || 0)"></span>
                        </div>
                        <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                            <div class="h-full bg-brand-500 transition-all duration-300"
                                 :style="'width: ' + (batchProgress?.total_to_process ? Math.round((batchProgress?.processed || 0) / batchProgress.total_to_process * 100) : 0) + '%'"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-1.5 truncate" x-text="batchProgress?.current_file ? 'Procesando: ' + batchProgress.current_file : 'Iniciando...'"></p>
                        <div class="flex gap-4 mt-2 text-xs">
                            <span class="text-green-600"><i class="fas fa-check mr-1"></i><span x-text="batchProgress?.processed || 0"></span> OK</span>
                            <span class="text-red-600" x-show="(batchProgress?.errors || 0) > 0"><i class="fas fa-times mr-1"></i><span x-text="batchProgress?.errors || 0"></span> errores</span>
                        </div>
                    </div>
                    <div x-show="batchProgress && batchProgress.status === 'starting'" class="text-center text-xs text-slate-400">
                        <i class="fas fa-cog fa-spin mr-1"></i> Iniciando proceso en background...
                    </div>

                    {{-- transcriptor-scan-scope-selector: progreso por storage del descubrimiento --}}
                    <div x-show="batchProgress && (batchProgress.scan_scope === 'range' || batchProgress.scan_scope === 'all') && (batchProgress?.storages || []).length > 0">
                        <h3 class="text-xs font-semibold text-slate-600 mb-1.5"><i class="fas fa-database mr-1"></i>Descubrimiento por storage</h3>
                        <div class="space-y-1.5 max-h-64 overflow-y-auto">
                            <template x-for="(s, idx) in (batchProgress?.storages || [])" :key="s.id || idx">
                                <div class="flex items-center justify-between px-2.5 py-1.5 bg-slate-50 rounded-lg text-xs">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <i class="fas fa-database text-slate-400 text-[10px]"></i>
                                        <span class="font-medium text-slate-700 truncate" x-text="s.name"></span>
                                    </div>
                                    <div class="flex items-center gap-2.5 whitespace-nowrap">
                                        <span class="text-slate-400" x-text="s.scanned + ' esc.'"></span>
                                        <span class="text-brand-700 font-medium" x-text="s.files_created + ' arch.'"></span>
                                        <span class="text-green-600 font-medium" x-text="s.tx_created + ' pend.'"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Resultados --}}
                <div x-show="!batchRunning && batchResult" class="space-y-4">
                    {{-- Mensaje de error/resultado del backend --}}
                    <div x-show="batchResult?.message"
                         :class="(batchResult?.errors || 0) > 0 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-slate-50 border-slate-200 text-slate-700'"
                         class="border rounded-lg p-3 text-sm">
                        <div class="flex items-start gap-2">
                            <i :class="(batchResult?.errors || 0) > 0 ? 'fas fa-exclamation-triangle text-red-500 mt-0.5' : 'fas fa-info-circle text-slate-400 mt-0.5'"></i>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium" x-text="batchResult?.message || ''"></p>
                                <template x-if="batchResult?.per_storage_errors && batchResult.per_storage_errors.length > 0">
                                    <ul class="mt-2 space-y-1 text-xs">
                                        <template x-for="e in batchResult.per_storage_errors" :key="e.storage_id">
                                            <li class="bg-white/60 rounded px-2 py-1">
                                                <span class="font-medium" x-text="'Storage ' + e.storage_id + ' (' + e.storage_name + '): '"></span>
                                                <span class="text-red-700" x-text="e.message"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-green-600" x-text="batchResult?.processed || 0"></p>
                            <p class="text-xs text-green-700">Procesados</p>
                        </div>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-red-600" x-text="batchResult?.errors || 0"></p>
                            <p class="text-xs text-red-700">Errores</p>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-slate-600" x-text="batchResult?.total_candidates || 0"></p>
                            <p class="text-xs text-slate-500">Candidatos</p>
                        </div>
                    </div>

                    {{-- Resumen de reintentos de fallidos (solo si --include-failed) --}}
                    <div x-show="(batchResult?.failed_recovered ?? 0) > 0 || (batchResult?.failed_promoted_to_dead ?? 0) > 0 || (batchResult?.failed_skipped_max_retries ?? 0) > 0"
                         class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                        <h3 class="text-sm font-semibold text-amber-800 mb-2"><i class="fas fa-redo mr-1"></i>Reintento de fallidos</h3>
                        <div class="grid grid-cols-3 gap-2 text-xs">
                            <div class="bg-white/70 rounded p-2 text-center">
                                <p class="text-lg font-bold text-amber-700" x-text="batchResult?.failed_recovered || 0"></p>
                                <p class="text-amber-600">Recuperados</p>
                            </div>
                            <div class="bg-white/70 rounded p-2 text-center">
                                <p class="text-lg font-bold text-red-600" x-text="batchResult?.failed_promoted_to_dead || 0"></p>
                                <p class="text-red-500">Promovidos a dead</p>
                            </div>
                            <div class="bg-white/70 rounded p-2 text-center">
                                <p class="text-lg font-bold text-slate-500" x-text="batchResult?.failed_skipped_max_retries || 0"></p>
                                <p class="text-slate-400">Saltados (max retries)</p>
                            </div>
                        </div>
                    </div>

                    {{-- Resumen por storage --}}
                    <div x-show="batchResult?.storages && batchResult.storages.length > 0">
                        <h3 class="text-sm font-semibold text-slate-700 mb-2">Por storage</h3>
                        <div class="space-y-2">
                            <template x-for="s in (batchResult?.storages || [])" :key="s.storage_id">
                                <div class="flex items-center justify-between p-2.5 bg-slate-50 rounded-lg text-sm">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-database text-slate-400 text-xs"></i>
                                        <span class="font-medium text-slate-700" x-text="s.name"></span>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs">
                                        <span class="text-slate-500" x-text="s.quota + ' asignados'"></span>
                                        <span class="text-green-600 font-medium" x-text="s.processed + ' OK'"></span>
                                        <span x-show="s.errors > 0" class="text-red-600 font-medium" x-text="s.errors + ' err'"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Detalle de archivos con error --}}
                    <div x-show="batchResult?.files && batchResult.files.filter(f => !f.ok).length > 0">
                        <h3 class="text-sm font-semibold text-slate-700 mb-2">Archivos con error</h3>
                        <div class="space-y-1 max-h-48 overflow-y-auto">
                            <template x-for="f in (batchResult?.files || []).filter(f => !f.ok)" :key="f.file_id">
                                <div class="p-2 bg-red-50 border border-red-100 rounded text-xs">
                                    <span class="font-medium text-red-700" x-text="f.name"></span>
                                    <span class="text-red-400 ml-2" x-text="f.storage"></span>
                                    <p class="text-red-500 mt-0.5" x-text="f.error"></p>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <button @click="closeBatchModal(); load();"
                                class="flex-1 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-check mr-1"></i> Aceptar
                        </button>
                        <button @click="batchResult = null"
                                class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-redo mr-1"></i> Otro lote
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

