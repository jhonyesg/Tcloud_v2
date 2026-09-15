@extends('layouts.app')

@section('title', 'API Transcriptor - Tcloud')

@section('content')
<div class="p-6" x-data="apiTranscriptor({
    userId: {{ (int) session('user_id') }},
    pendingAlertThreshold: {{ (int) ($pending_alert_threshold ?? 5) }},
})" x-init="init()">

    {{-- Contenedor global de toasts (esquina superior derecha) --}}
    <div x-data class="fixed top-4 right-4 z-50 space-y-2 w-96 max-w-[calc(100vw-2rem)] pointer-events-none">
        <template x-for="t in $store.toasts.items" :key="t.id">
            <div x-show="t.visible"
                 x-transition:enter="transition ease-out duration-300 transform"
                 x-transition:enter-start="opacity-0 translate-x-8 scale-95"
                 x-transition:enter-end="opacity-100 translate-x-0 scale-100"
                 x-transition:leave="transition ease-in duration-200 transform"
                 x-transition:leave-start="opacity-100 translate-x-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-x-8 scale-95"
                 :class="{
                    'bg-green-50 border-green-300 text-green-900 ring-green-100': t.type === 'success',
                    'bg-red-50 border-red-300 text-red-900 ring-red-100': t.type === 'error',
                    'bg-blue-50 border-blue-300 text-blue-900 ring-blue-100': t.type === 'info',
                    'bg-amber-50 border-amber-300 text-amber-900 ring-amber-100': t.type === 'warning'
                 }"
                 class="border-2 rounded-xl shadow-lg ring-1 p-3.5 flex items-start gap-3 pointer-events-auto">
                <div class="flex-shrink-0 mt-0.5">
                    <i :class="{
                        'fas fa-check-circle text-green-500': t.type === 'success',
                        'fas fa-times-circle text-red-500': t.type === 'error',
                        'fas fa-info-circle text-blue-500': t.type === 'info',
                        'fas fa-exclamation-triangle text-amber-500': t.type === 'warning'
                    }" class="text-lg"></i>
                </div>
                <p class="flex-1 text-sm font-medium leading-snug" x-html="t.message"></p>
                <button @click="$store.toasts.dismiss(t.id)"
                        class="flex-shrink-0 text-slate-400 hover:text-slate-700 transition-colors -mr-1 -mt-1 p-1 rounded">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>
        </template>
    </div>

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">API Transcriptor</h1>
            <p class="text-slate-500 mt-0.5">Storages habilitados para transcripción y configuración del regulador</p>
        </div>
    </div>

    {{-- Panel de información: cómo funciona la API del transcriptor --}}
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <button @click="showInfo = !showInfo" class="w-full flex items-center justify-between px-5 py-3 text-left hover:bg-slate-50 transition-colors">
            <span class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <i class="fas fa-circle-info text-brand-500"></i>
                ¿Cómo funciona la API del transcriptor?
            </span>
            <i class="fas fa-chevron-down text-xs text-slate-400 transition-transform" :class="showInfo ? 'rotate-180' : ''"></i>
        </button>
        <div x-show="showInfo" x-transition class="px-5 pb-5 pt-1 text-sm text-slate-600 space-y-3 border-t border-slate-100">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <p class="font-medium text-slate-700"><i class="fas fa-arrow-right text-brand-400 mr-1"></i>Flujo automático</p>
                    {{-- El formato de audio se lee de la config en vez de estar
                         escrito a mano: la version anterior decia "Opus 64k"
                         mientras el pipeline llevaba tiempo enviando wav. --}}
                    <ol class="list-decimal list-inside space-y-1 text-xs text-slate-500">
                        <li>Cada 2 min <span class="font-mono bg-slate-100 px-1 rounded">transcription:tick</span> busca grabaciones nuevas en storages con <span class="font-mono bg-slate-100 px-1 rounded">transcripción habilitada</span> y crea filas en <b>pending</b>.</li>
                        <li>El regulador encola hasta llenar el objetivo de cola, no más: si la cola ya está en su tope, no inyecta trabajo.</li>
                        <li>El worker convierte el audio con <span class="font-mono bg-slate-100 px-1 rounded">ffmpeg</span> a <span class="font-mono bg-slate-100 px-1 rounded">{{ config('transcriptor.audio_output_format', 'wav') }}</span> mono 16&nbsp;kHz.</li>
                        <li>Se envía por <span class="font-mono bg-slate-100 px-1 rounded">POST /v1/transcribe</span>; la fila pasa a <b>queued</b> con su <span class="font-mono bg-slate-100 px-1 rounded">job_id</span>.</li>
                        <li><b>El resultado se recoge por polling:</b> <span class="font-mono bg-slate-100 px-1 rounded">transcription:poll-results</span> consulta <span class="font-mono bg-slate-100 px-1 rounded">GET /v1/jobs/{id}</span> cada minuto.</li>
                        <li>Al verlo terminado descarga el SRT, lo guarda en segmentos y dispara las alertas de keywords.</li>
                    </ol>
                    <p class="text-xs text-slate-400 mt-2">
                        <i class="fas fa-circle-info text-slate-300 mr-1"></i>
                        <b>No hay webhook entrante.</b> El transcriptor nunca llama a Tcloud: todo resultado llega porque el polling va a buscarlo.
                    </p>
                </div>
                <div class="space-y-2">
                    <p class="font-medium text-slate-700"><i class="fas fa-server text-brand-400 mr-1"></i>Endpoint y configuración</p>
                    <ul class="text-xs text-slate-500 space-y-1">
                        <li><span class="text-slate-400">URL base:</span> <span class="font-mono bg-slate-100 px-1 rounded">{{ config('transcriptor.base_url') }}</span></li>
                        <li><span class="text-slate-400">Idioma:</span> {{ config('transcriptor.language', 'es') }}</li>
                        <li><span class="text-slate-400">Estados de job:</span> <span class="font-mono bg-slate-100 px-1 rounded">pending · queued · processing · done · error · dead</span></li>
                    </ul>
                    {{-- Las tres rutas a `dead` no estaban documentadas en
                         ningun sitio de la ayuda; dos de ellas son nuevas. --}}
                    <p class="text-xs text-slate-500 mt-2">Una transcripción llega a <b>dead</b> por tres caminos:</p>
                    <ul class="text-xs text-slate-500 space-y-1 list-disc list-inside">
                        <li>Agotó los reintentos de envío (<span class="font-mono bg-slate-100 px-1 rounded">max_retries</span>).</li>
                        <li>El transcriptor <b>perdió su resultado</b>: el job figura terminado pero su SRT ya no existe.</li>
                        <li>Superó <span class="font-mono bg-slate-100 px-1 rounded">poll_max_age_hours</span> sin resolverse.</li>
                    </ul>
                    <p class="text-xs text-slate-400 mt-2">
                        <i class="fas fa-lightbulb text-amber-400 mr-1"></i>
                        En los dos últimos casos el audio original sigue en disco: <span class="font-mono bg-slate-100 px-1 rounded">php artisan transcription:backfill-lost</span> lo reenvía a ritmo controlado.
                    </p>
                    <p class="text-xs text-slate-400 mt-2"><i class="fas fa-hard-drive text-slate-300 mr-1"></i>La pestaña <b>Storages</b> permite habilitar o deshabilitar la transcripción por storage.</p>
                </div>
            </div>
        </div>
    </div>


    {{-- Pestañas principales: simplificado a Storages + Configuración --}}
    <div class="mb-4 flex items-center gap-1 border-b border-slate-200">
        <button @click="tab = 'storages'"
                :class="tab === 'storages' ? 'bg-white border-x border-t border-slate-200 text-brand-600' : 'text-slate-500 hover:text-slate-700'"
                class="px-5 py-2.5 rounded-t-lg text-sm font-medium border-b-2 -mb-px transition-colors"
                :class-extra="tab === 'storages' ? 'border-b-brand-500' : 'border-b-transparent'">
            <i class="fas fa-database mr-1.5"></i>
            Storages
            <span x-show="storagesEnabled.length > 0" class="ml-1.5 text-[10px] px-1.5 py-0.5 bg-brand-100 text-brand-700 rounded-full font-semibold" x-text="storagesEnabled.length"></span>
        </button>
        <button @click="tab = 'config'"
                :class="tab === 'config' ? 'bg-white border-x border-t border-slate-200 text-brand-600' : 'text-slate-500 hover:text-slate-700'"
                class="px-5 py-2.5 rounded-t-lg text-sm font-medium border-b-2 -mb-px transition-colors"
                :class-extra="tab === 'config' ? 'border-b-brand-500' : 'border-b-transparent'">
            <i class="fas fa-sliders-h mr-1.5"></i>
            Configuración
            <span x-show="cfg.dispatch_paused" class="ml-1.5 inline-block w-2 h-2 bg-amber-500 rounded-full align-middle" title="Envío pausado"></span>
        </button>
    </div>

    {{-- TAB: STORAGES --}}
    <div x-show="tab === 'storages'" x-transition:enter.opacity.duration.150ms>

    <!-- Estado vacío: sin storages habilitados -->

    <!-- Estado vacío: sin storages habilitados -->
    <div x-show="!loading && storagesEnabled.length === 0"
         class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-6">
        <i class="fas fa-exclamation-triangle text-amber-500 text-3xl mb-2 block"></i>
        <p class="font-medium text-amber-800">No hay storages con transcripción habilitada</p>
        <p class="text-sm text-amber-700 mt-1">Activa un storage abajo para empezar a transcribir grabaciones.</p>
    </div>

    <!-- Tarjetas resumen del modulo storages -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Cantidad Total</div>
            <div class="text-2xl font-bold text-slate-800 mt-1 tabular-nums" x-text="cantidadTotal()"></div>
            <div class="text-[11px] text-slate-400 mt-1">Suma de medios en todos los storages (con duplicados).</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-brand-200 bg-brand-50/40 p-4">
            <div class="text-[11px] font-semibold text-brand-700 uppercase tracking-wider">Cantidad Real (ponderado)</div>
            <div class="text-2xl font-bold text-brand-800 mt-1 tabular-nums" x-text="cantidadPonderada()"></div>
            <div class="text-[11px] text-slate-500 mt-1">Suma solo de storages hoja (sin duplicar padres que agregan hijos).</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Pendientes hoy</div>
            <div class="text-2xl font-bold text-slate-800 mt-1 tabular-nums" x-text="pendientesTotal()"></div>
            <div class="text-[11px] text-slate-400 mt-1">Transcripciones creadas hoy que aun no terminan.</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Listos hoy</div>
            <div class="text-2xl font-bold text-slate-800 mt-1 tabular-nums" x-text="listosTotal()"></div>
            <div class="text-[11px] text-slate-400 mt-1">Transcripciones que terminaron OK hoy.</div>
        </div>
    </div>

<!-- Tabla de storages -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3 flex-wrap">
                <h2 class="text-sm font-semibold text-slate-700">Storages</h2>
                <div class="flex items-center gap-2">
                    <form method="POST" action="/ia/api-transcriptor/retry-batch" target="_blank">
                        @csrf
                        <input type="hidden" name="max_age_hours" value="168">
                        <input type="hidden" name="limit" value="500">
                        <button type="submit"
                                onclick="return confirm('¿Reencolar todos los jobs error|dead (últimos 7 días) en el upstream? El proceso corre en background.')"
                                class="flex items-center gap-1.5 px-3 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-800 rounded-lg text-xs font-medium transition-colors"
                                title="Lanza transcription:retry-batch-upstream en background para re-encolar todos los jobs error/dead de los últimos 7 días en el upstream (sin re-ffmpeg).">
                            <i class="fas fa-rotate"></i> Reintentar fallidos (upstream batch)
                        </button>
                    </form>
                </div>
                <div class="relative">
                    <input type="text" x-model="storagesSearch" @input.debounce.200ms="storagesPage = 1"
                           placeholder="Buscar storage..."
                           class="pl-7 pr-2 py-1 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none w-48">
                    <i class="fas fa-search absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
                </div>
                <select @change="setStoragesStatusFilter($event.target.value)"
                        class="border border-slate-300 rounded-lg px-2 py-1 text-xs outline-none hover:border-brand-300 transition-colors"
                        title="Filtrar por estado de transcripcion">
                    <option value="all" :selected="storagesStatusFilter === 'all'">Todos</option>
                    <option value="enabled" :selected="storagesStatusFilter === 'enabled'">Transcribiendo</option>
                    <option value="disabled" :selected="storagesStatusFilter === 'disabled'">Inactivos</option>
                </select>
                <span class="text-xs text-slate-400 whitespace-nowrap" x-text="storagesEnabled.length + ' habilitado(s) de ' + storages.length"></span>
            </div>
        </div>
        <div x-show="storages.length === 0" class="text-center py-12 text-slate-400">
            <i class="fas fa-database text-3xl mb-2 block text-slate-200"></i>
            <p>No hay storages registrados.</p>
        </div>
        <div x-show="!loading && storages.length > 0" class="px-4 py-3 border-b border-slate-100 flex items-center justify-between gap-2 flex-wrap">
            <span class="text-xs text-slate-500"
                  x-text="'Página ' + storagesPage + ' de ' + storagesTotalPages() + ' · ' + filteredStorages().length.toLocaleString() + ' storage(s)'"></span>
            <div class="flex items-center gap-1.5">
                <button @click="storagesPage = Math.max(1, storagesPage - 1)"
                        :disabled="storagesPage <= 1"
                        class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                        title="Página anterior">
                    <i class="fas fa-chevron-left text-[10px]"></i>
                </button>
                <template x-for="p in storagesPageList(storagesPage, storagesTotalPages())" :key="'ppt-' + p">
                    <button x-show="p !== '…'" @click="storagesPage = p"
                            class="min-w-[2.2rem] px-2 py-1.5 rounded-lg text-sm font-medium transition-all border"
                            :class="p === storagesPage
                                ? 'bg-brand-600 text-white border-brand-600 shadow'
                                : 'border-slate-200 text-slate-600 hover:bg-brand-50 hover:border-brand-300'"
                            x-text="p"></button>
                </template>
                <button @click="storagesPage = Math.min(storagesTotalPages(), storagesPage + 1)"
                        :disabled="storagesPage >= storagesTotalPages()"
                        class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                        title="Página siguiente">
                    <i class="fas fa-chevron-right text-[10px]"></i>
                </button>
                <select @change="storagesPerPage = [25, 50, 100, 500].includes(+$event.target.value) ? +$event.target.value : 25; storagesPage = 1;"
                        class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm outline-none hover:border-brand-300 transition-colors"
                        title="Storages por página">
                    <template x-for="n in [25, 50, 100, 500]" :key="'sppt-' + n">
                        <option :value="n" x-text="n + ' / pág.'" :selected="storagesPerPage === n"></option>
                    </template>
                </select>
            </div>
        </div>
        <table x-show="storages.length > 0" class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr class="text-left text-xs text-slate-500">
                    <th class="py-2.5 pr-2 font-medium whitespace-nowrap text-right" title="Posicion en la lista ordenada (continua entre paginas)">#</th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                        <button type="button" @click="setStoragesSort('name')"
                                :class="storagesSortHeaderClass('name') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por storage">
                            Storage
                            <i class="fas text-[10px]" :class="storagesSortIcon('name') + ' ' + storagesSortIconClass('name')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-center">
                        <button type="button" @click="setStoragesSort('cantidad')"
                                :class="storagesSortHeaderClass('cantidad') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por cantidad">
                            Cantidad
                            <i class="fas text-[10px]" :class="storagesSortIcon('cantidad') + ' ' + storagesSortIconClass('cantidad')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                        <button type="button" @click="setStoragesSort('tipo')"
                                :class="storagesSortHeaderClass('tipo') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por tipo">
                            Tipo
                            <i class="fas text-[10px]" :class="storagesSortIcon('tipo') + ' ' + storagesSortIconClass('tipo')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-right">
                        <button type="button" @click="setStoragesSort('pending')"
                                :class="storagesSortHeaderClass('pending') + ' inline-flex items-center gap-1.5 transition-colors ml-auto'"
                                title="Ordenar por pendientes hoy">
                            Pendientes (hoy)
                            <i class="fas text-[10px]" :class="storagesSortIcon('pending') + ' ' + storagesSortIconClass('pending')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-right">
                        <button type="button" @click="setStoragesSort('done')"
                                :class="storagesSortHeaderClass('done') + ' inline-flex items-center gap-1.5 transition-colors ml-auto'"
                                title="Ordenar por listos hoy">
                            Listos (hoy)
                            <i class="fas text-[10px]" :class="storagesSortIcon('done') + ' ' + storagesSortIconClass('done')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-center">
                        <button type="button" @click="setStoragesSort('priority')"
                                :class="storagesSortHeaderClass('priority') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por prioridad">
                            Prioridad
                            <i class="fas text-[10px]" :class="storagesSortIcon('priority') + ' ' + storagesSortIconClass('priority')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                        <button type="button" @click="setStoragesSort('enabled')"
                                :class="storagesSortHeaderClass('enabled') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por estado de transcripcion">
                            Transcripción
                            <i class="fas text-[10px]" :class="storagesSortIcon('enabled') + ' ' + storagesSortIconClass('enabled')"></i>
                        </button>
                    </th>
                    <th class="py-2.5 font-medium whitespace-nowrap text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="(s, idx) in pagedStorages()" :key="s.id">
                    <tr class="hover:bg-slate-50/60 align-top"
                        :class="(s.transcription_enabled ? '' : 'opacity-70') + (emptyFoldersFor(s.id) ? ' bg-amber-50/40' : '')">
                        <td class="py-3 pr-2 text-right text-xs text-slate-400 tabular-nums select-none align-top"
                            :title="'Posicion ' + storageRowNumber(idx) + ' de ' + filteredStorages().length"
                            x-text="storageRowNumber(idx)"></td>
                        <td class="py-3 pr-3 text-sm font-medium text-slate-700">
                            <div class="flex items-center gap-2">
                                <template x-if="s.descendant_count > 0">
                                    <button type="button" @click.stop="toggleStorageExpansion(s.parent_scope_id)"
                                            class="text-slate-400 hover:text-slate-700 transition-transform"
                                            :class="expandedScopes.has(s.parent_scope_id) ? 'rotate-90' : ''"
                                            :title="expandedScopes.has(s.parent_scope_id) ? 'Colapsar hijos' : 'Expandir hijos'">
                                        <i class="fas fa-chevron-right text-[10px]"></i>
                                    </button>
                                </template>
                                <template x-if="!s.descendant_count || s.descendant_count === 0">
                                    <span class="w-3 inline-block"></span>
                                </template>
                                <span x-text="s.name"></span>
                                <template x-if="s.overlap_warning">
                                    <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full font-semibold"
                                          title="Este storage tiene allow_parent_overlap=true y descendientes habilitados. Los conteos pueden sumar de más si ambos escanean los mismos archivos.">⚠ solapamiento</span>
                                </template>
                                <template x-if="s.descendant_count > 0">
                                    <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded-full"
                                          :title="'Descendientes: ' + (s.descendant_names || []).join(', ')"
                                          x-text="s.descendant_count + ' hijo(s)'"></span>
                                </template>
                                <template x-if="emptyFoldersFor(s.id)">
                                    <button type="button" @click.stop="emptyFoldersExpanded = true; emptyFoldersExpanded && setTimeout(() => { const el = document.querySelector('[data-tour=\"storages-empties\"]'); if (el) el.scrollIntoView({behavior:'smooth', block:'start'}); }, 50)"
                                            class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-800 rounded-full font-semibold cursor-pointer hover:bg-amber-200 transition-colors"
                                            :title="emptyFoldersBadge(s.id) + '. Click para ver el detalle en el banner.'"
                                            x-text="'⚠ ' + emptyFoldersBadge(s.id)"></button>
                                </template>
                            </div>
                        </td>
                        <td class="py-3 pr-3 text-center">
                            <span class="px-2 py-0.5 rounded bg-brand-50 text-brand-700 text-xs font-semibold tabular-nums"
                                  :title="'Cantidad de medios en el scope: ' + s.cantidad"
                                  x-text="s.cantidad ?? 1"></span>
                        </td>
                        <td class="py-3 pr-3 text-xs text-slate-500" x-text="s.type"></td>
                        <td class="py-3 pr-3 text-sm text-slate-700 text-right tabular-nums">
                            <span x-text="s.funnel?.pending ?? 0"></span>
                            <template x-if="shouldWarnPending(s)">
                                <span class="ml-1 text-amber-600 cursor-help"
                                      :title="pendingWarningTitle(s)"
                                      @click.stop>⚠</span>
                            </template>
                        </td>
                        <td class="py-3 pr-3 text-sm text-slate-700 text-right tabular-nums" x-text="s.funnel?.done ?? 0"></td>
                        <td class="py-3 pr-3 text-center">
                            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-xs tabular-nums" x-text="s.transcription_priority ?? 0"></span>
                        </td>
                        {{-- Interruptor real: escribe storage_providers.transcription_enabled,
                             que es lo que lee el scanner. Decisión operativa de este
                             módulo; Avisos y Correcciones solo consumen lo que produce. --}}
                        <td class="px-4 py-3">
                            <button @click="toggleStorage(s)"
                                    type="button"
                                    :disabled="s.saving === true"
                                    data-tour="storage-toggle"
                                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-medium transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-wait"
                                    :class="s.transcription_enabled ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                                    :title="s.transcription_enabled ? 'Transcribiendo. Clic para dejar de transcribir este storage' : 'Sin transcribir. Clic para empezar a transcribir este storage'">
                                <span class="w-1.5 h-1.5 rounded-full" :class="s.transcription_enabled ? 'bg-green-500' : 'bg-slate-400'"></span>
                                <span x-text="s.transcription_enabled ? 'Transcribe' : 'Inactivo'"></span>
                            </button>
                        </td>
                        <td class="py-3 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button @click="openFiles(s)"
                                        data-tour="storage-files"
                                        class="flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors"
                                        :class="!s.transcription_enabled && 'opacity-50'">
                                    <i class="fas fa-file-audio text-[10px]"></i> Ver archivos
                                </button>
                                {{-- Botón "Escanear" eliminado: confundir con "Escanear storages" del header y bloquea navegador.
                                     Para escanear un storage específico, usar el flujo batch del header que es async. --}}
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        {{-- Paginacion (mismo patron que Mis Avisos: prev/next + numeros + selector por pagina) --}}
        <div x-show="!loading && storages.length > 0" class="px-4 py-3 border-t border-slate-200 flex items-center justify-between gap-2 flex-wrap">
            <span class="text-xs text-slate-500"
                  x-text="'Página ' + storagesPage + ' de ' + storagesTotalPages() + ' · ' + filteredStorages().length.toLocaleString() + ' storage(s)'"></span>
            <div class="flex items-center gap-1.5">
                <button @click="storagesPage = Math.max(1, storagesPage - 1)"
                        :disabled="storagesPage <= 1"
                        class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                        title="Página anterior">
                    <i class="fas fa-chevron-left text-[10px]"></i>
                </button>
                <template x-for="p in storagesPageList(storagesPage, storagesTotalPages())" :key="'pp-' + p">
                    <button x-show="p !== '…'" @click="storagesPage = p"
                            class="min-w-[2.2rem] px-2 py-1.5 rounded-lg text-sm font-medium transition-all border"
                            :class="p === storagesPage
                                ? 'bg-brand-600 text-white border-brand-600 shadow'
                                : 'border-slate-200 text-slate-600 hover:bg-brand-50 hover:border-brand-300'"
                            x-text="p"></button>
                </template>
                <button @click="storagesPage = Math.min(storagesTotalPages(), storagesPage + 1)"
                        :disabled="storagesPage >= storagesTotalPages()"
                        class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                        title="Página siguiente">
                    <i class="fas fa-chevron-right text-[10px]"></i>
                </button>
                <select @change="storagesPerPage = [25, 50, 100, 500].includes(+$event.target.value) ? +$event.target.value : 25; storagesPage = 1;"
                        class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm outline-none hover:border-brand-300 transition-colors"
                        title="Storages por página">
                    <template x-for="n in [25, 50, 100, 500]" :key="'spp-' + n">
                        <option :value="n" x-text="n + ' / pág.'" :selected="storagesPerPage === n"></option>
                    </template>
                </select>
            </div>
        </div>


    <!-- Modal navegador de archivos de un storage -->
    <div x-cloak x-show="showFiles" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-3xl shadow-2xl flex flex-col max-h-[90vh]" @click.away="if (!showProgress && !showBatchModal) closeFiles()">
            <div class="p-6 flex-1 overflow-y-auto min-h-0">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-bold text-slate-800" x-text="'Archivos — ' + (currentStorage?.name || '')"></h2>
                    <button @click="closeFiles()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
                </div>

                {{-- Botones de modo: Explorar / Hoy / Ayer --}}
                <div class="flex items-center gap-2 mb-3">
                    <button @click="setMode('browse')"
                            :class="filesMode === 'browse' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                        <i class="fas fa-folder-open text-[10px] mr-1"></i> Explorar
                    </button>
                    <button @click="setMode('today')"
                            :class="filesMode === 'today' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                        <i class="fas fa-calendar-day text-[10px] mr-1"></i> Hoy
                    </button>
                    <button @click="setMode('yesterday')"
                            :class="filesMode === 'yesterday' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                        <i class="fas fa-calendar-minus text-[10px] mr-1"></i> Ayer
                    </button>
                    <div class="flex-1"></div>
                    <div class="relative w-56">
                        <input type="text" x-model="filesSearch" @input.debounce.400ms="searchFiles()"
                               placeholder="Buscar archivo..."
                               class="w-full border border-slate-300 rounded-lg pl-8 pr-3 py-1.5 text-xs focus:ring-2 focus:ring-brand-500 outline-none">
                        <i class="fas fa-search absolute left-2.5 top-2 text-slate-400 text-xs"></i>
                    </div>
                </div>

                {{-- Breadcrumb (solo en modo browse) --}}
                <div x-show="filesMode === 'browse' && breadcrumb.length >= 0" class="flex items-center gap-1 text-xs text-slate-500 mb-2 flex-wrap">
                    <button @click="openFolder(null)" class="hover:text-brand-600">
                        <i class="fas fa-hdd mr-0.5"></i> Raíz
                    </button>
                    <template x-for="crumb in breadcrumb" :key="crumb.id">
                        <span class="flex items-center gap-1">
                            <i class="fas fa-chevron-right text-[9px] text-slate-300"></i>
                            <button @click="openFolder(crumb.id)" class="hover:text-brand-600" x-text="crumb.name"></button>
                        </span>
                    </template>
                </div>

                <div x-show="filesLoading" class="flex items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-brand-400"></i></div>

                <div x-show="!filesLoading && folders.length === 0 && filesFlat.length === 0 && filesGroups.length === 0" class="text-center py-10 text-slate-400 text-sm">
                    <i class="fas fa-folder-open text-2xl mb-2 block text-slate-200"></i>
                    <p x-text="filesSearch ? 'Sin resultados para la búsqueda' : (filesMode === 'today' ? 'No hay grabaciones hoy' : (filesMode === 'yesterday' ? 'No hay grabaciones de ayer' : 'Esta carpeta está vacía'))"></p>
                </div>

                {{-- TABLA unificada (BROWSE / HOY / AYER / SEARCH) --}}
                <div x-show="!filesLoading && (folders.length > 0 || filesFlat.length > 0 || filesGroups.length > 0)" class="max-h-[460px] overflow-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-100 sticky top-0 z-10">
                            <tr class="text-left text-slate-500 uppercase tracking-wide">
                                <th class="px-2 py-2 font-semibold w-8 text-center">
                                    <input type="checkbox"
                                           class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer disabled:opacity-40"
                                           :checked="isAllVisibleSelected()"
                                           :indeterminate.prop="!isAllVisibleSelected() && isSomeVisibleSelected()"
                                           :disabled="visibleFileCount() === 0"
                                           @change="toggleSelectAllVisible()"
                                           title="Seleccionar todos los visibles">
                                </th>
                                <th class="px-3 py-2 font-semibold">
                                    <div class="flex items-center gap-1">
                                        <i class="fas fa-font text-[9px]"></i>
                                        <input type="text" x-model="colFilters.name" @input.debounce.300ms="applyColumnFilter()" placeholder="Nombre" class="bg-white border border-slate-200 rounded px-1.5 py-0.5 text-[11px] w-40 font-normal normal-case">
                                    </div>
                                </th>
                                <th class="px-2 py-2 font-semibold w-20">
                                    <div class="flex items-center gap-1">
                                        <i class="fas fa-clock text-[9px]"></i>
                                        <input type="text" x-model="colFilters.time" @input.debounce.300ms="applyColumnFilter()" placeholder="HHMM" class="bg-white border border-slate-200 rounded px-1.5 py-0.5 text-[11px] w-14 font-normal normal-case font-mono">
                                        <button @click="toggleSort('time')" class="ml-auto text-slate-400 hover:text-slate-600">
                                            <i class="fas text-[9px]" :class="filesSort.key==='time' ? (filesSort.dir==='desc'?'fa-sort-down':'fa-sort-up') : 'fa-sort'"></i>
                                        </button>
                                    </div>
                                </th>
                                <th class="px-2 py-2 font-semibold w-24">
                                    <div class="flex items-center gap-1">
                                        <i class="fas fa-hdd text-[9px]"></i>
                                        <span>Tamaño</span>
                                        <button @click="toggleSort('size')" class="ml-auto text-slate-400 hover:text-slate-600">
                                            <i class="fas text-[9px]" :class="filesSort.key==='size' ? (filesSort.dir==='desc'?'fa-sort-down':'fa-sort-up') : 'fa-sort'"></i>
                                        </button>
                                    </div>
                                </th>
                                <th class="px-2 py-2 font-semibold w-28">
                                    <div class="flex items-center gap-1">
                                        <i class="fas fa-calendar text-[9px]"></i>
                                        <span>Fecha</span>
                                        <button @click="toggleSort('modified')" class="ml-auto text-slate-400 hover:text-slate-600">
                                            <i class="fas text-[9px]" :class="filesSort.key==='modified' ? (filesSort.dir==='desc'?'fa-sort-down':'fa-sort-up') : 'fa-sort'"></i>
                                        </button>
                                    </div>
                                </th>
                                <th class="px-2 py-2 font-semibold w-24 text-center">Estado</th>
                                <th class="px-2 py-2 font-semibold w-20 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            {{-- Carpetas (solo en modo BROWSE) --}}
                            <template x-if="filesMode === 'browse' && folders.length > 0">
                                <template x-for="folder in folders" :key="'f' + folder.id">
                                    <tr @click="openFolder(folder.id)" class="hover:bg-brand-50 cursor-pointer">
                                        <td colspan="7" class="px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-folder text-amber-400"></i>
                                                <span class="font-medium text-slate-700 truncate" x-text="folder.name"></span>
                                                <span class="text-slate-400 text-[10px]">carpeta</span>
                                                {{-- storage origen: si la carpeta viene de un descendiente del
                                                     storage que el operador clickeó, mostrar el nombre del
                                                     storage hijo para que pueda distinguir entre 11 carpetas
                                                     "14092026" (una por cada descendiente). Sin esto, las
                                                     carpetas del mismo nombre en distintos storages del
                                                     scope aparecen indistinguibles y el operador no sabe
                                                     a cuál descender. --}}
                                                <template x-if="folder.source_storage_id && folder.source_storage_id !== currentStorage?.id && storageById(folder.source_storage_id)">
                                                    <span class="ml-auto inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded font-medium"
                                                          :title="'Esta carpeta vive en el storage ' + (storageById(folder.source_storage_id)?.name || folder.source_storage_id)">
                                                        <i class="fas fa-server text-[9px]"></i>
                                                        <span x-text="storageById(folder.source_storage_id)?.name"></span>
                                                    </span>
                                                </template>
                                                <i class="fas fa-chevron-right text-slate-300 text-xs ml-auto"></i>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                            {{-- Archivos planos (BROWSE / HOY / AYER) --}}
                            <template x-if="filesMode !== 'search' && filesFlat.length > 0">
                                <template x-for="f in filesFlat" :key="'a' + f.id">
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-2 py-2 text-center" @click.stop>
                                            <input type="checkbox"
                                                   class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer disabled:opacity-40"
                                                   :checked="isSelected(f.id)"
                                                   :disabled="f.has_transcription"
                                                   :title="f.has_transcription ? 'Este archivo ya tiene transcripción' : 'Seleccionar para envío en lote'"
                                                   @change="toggleSelected(f.id)">
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <i class="fas fa-file-audio text-slate-400 flex-shrink-0"></i>
                                                <template x-if="f.transcription_id">
                                                    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                       class="text-brand-600 hover:underline font-medium truncate"
                                                       :title="f.name + ' — Ver transcripción (' + (f.transcription_state || '') + ')'"
                                                       x-text="f.name"></a>
                                                </template>
                                                <template x-if="!f.transcription_id">
                                                    <span class="text-slate-700 truncate" x-text="f.name" :title="f.name"></span>
                                                </template>
                                            </div>
                                        </td>
                                        <td class="px-2 py-2 font-mono text-slate-600" x-text="f.military_time ? (f.military_time.substr(0,2) + ':' + f.military_time.substr(2,2) + ':' + f.military_time.substr(4,2)) : '—'"></td>
                                        <td class="px-2 py-2 text-slate-500" x-text="formatSize(f.size)"></td>
                                        <td class="px-2 py-2 text-slate-500" x-text="formatDate(f.file_modified_at)"></td>
                                        <td class="px-2 py-2 text-center">
                                            <span x-show="!f.transcription_id" class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded-full">Pendiente</span>
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            <template x-if="!f.transcription_id">
                                                <button @click="openProgress(f)"
                                                        class="text-[10px] px-2 py-1 bg-brand-600 hover:bg-brand-700 text-white rounded whitespace-nowrap transition-colors">
                                                    <i class="fas fa-paper-plane text-[8px] mr-0.5"></i> Enviar
                                                </button>
                                            </template>
                                            <template x-if="f.transcription_id && f.transcription_state === 'done'">
                                                <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                   class="text-[10px] px-2 py-1 bg-brand-600 hover:bg-brand-700 text-white rounded whitespace-nowrap transition-colors inline-flex items-center">
                                                    <i class="fas fa-file-alt text-[8px] mr-0.5"></i> Ver transcripción
                                                </a>
                                            </template>
                                            <template x-if="f.transcription_id && ['pending','queued','processing'].includes(f.transcription_state)">
                                                <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                   class="text-[10px] px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded whitespace-nowrap transition-colors inline-flex items-center">
                                                    <i class="fas fa-spinner text-[8px] mr-0.5"></i> En proceso…
                                                </a>
                                            </template>
                                            <template x-if="f.transcription_id && ['error','dead'].includes(f.transcription_state)">
                                                <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                   class="text-[10px] px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded whitespace-nowrap transition-colors inline-flex items-center">
                                                    <i class="fas fa-exclamation-triangle text-[8px] mr-0.5"></i> Ver error
                                                </a>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                            {{-- Archivos agrupados por carpeta (SEARCH) --}}
                            <template x-if="filesMode === 'search' && filesGroups.length > 0">
                                <template x-for="group in filesGroups" :key="'g' + group.folder">
                                    <template x-for="(f, fi) in group.files" :key="'a' + f.id">
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-2 py-2 text-center align-middle" :class="fi === 0 ? 'border-t border-slate-200' : ''" @click.stop>
                                                <input type="checkbox"
                                                       class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer disabled:opacity-40"
                                                       :checked="isSelected(f.id)"
                                                       :disabled="f.has_transcription"
                                                       :title="f.has_transcription ? 'Este archivo ya tiene transcripción' : 'Seleccionar para envío en lote'"
                                                       @change="toggleSelected(f.id)">
                                            </td>
                                            <td class="px-3 py-2" :class="fi === 0 ? 'border-t border-slate-200' : ''">
                                                <div x-show="fi === 0" class="flex items-center gap-2 mb-1">
                                                    <i class="fas fa-folder text-amber-400 text-[10px]"></i>
                                                    <span class="text-[10px] font-semibold text-slate-600 uppercase tracking-wide" x-text="group.folder"></span>
                                                    <span class="text-[10px] text-slate-400" x-text="'(' + group.files.length + ')'"></span>
                                                </div>
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <i class="fas fa-file-audio text-slate-400 flex-shrink-0"></i>
                                                    <template x-if="f.transcription_id">
                                                        <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                           class="text-brand-600 hover:underline font-medium truncate"
                                                           :title="f.name + ' — Ver transcripción (' + (f.transcription_state || '') + ')'"
                                                           x-text="f.name"></a>
                                                    </template>
                                                    <template x-if="!f.transcription_id">
                                                        <span class="text-slate-700 truncate" x-text="f.name" :title="f.name"></span>
                                                    </template>
                                                </div>
                                            </td>
                                            <td class="px-2 py-2 font-mono text-slate-600" :class="fi === 0 ? 'border-t border-slate-200' : ''" x-text="f.military_time ? (f.military_time.substr(0,2) + ':' + f.military_time.substr(2,2) + ':' + f.military_time.substr(4,2)) : '—'"></td>
                                            <td class="px-2 py-2 text-slate-500" :class="fi === 0 ? 'border-t border-slate-200' : ''" x-text="formatSize(f.size)"></td>
                                            <td class="px-2 py-2 text-slate-500" :class="fi === 0 ? 'border-t border-slate-200' : ''" x-text="formatDate(f.file_modified_at)"></td>
                                            <td class="px-2 py-2 text-center" :class="fi === 0 ? 'border-t border-slate-200' : ''">
                                                <span x-show="!f.transcription_id" class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded-full">Pendiente</span>
                                            </td>
                                            <td class="px-2 py-2 text-right" :class="fi === 0 ? 'border-t border-slate-200' : ''">
                                                <template x-if="!f.transcription_id">
                                                    <button @click="openProgress(f)"
                                                            class="text-[10px] px-2 py-1 bg-brand-600 hover:bg-brand-700 text-white rounded whitespace-nowrap transition-colors">
                                                        <i class="fas fa-paper-plane text-[8px] mr-0.5"></i> Enviar
                                                    </button>
                                                </template>
                                                <template x-if="f.transcription_id && f.transcription_state === 'done'">
                                                    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                       class="text-[10px] px-2 py-1 bg-brand-600 hover:bg-brand-700 text-white rounded whitespace-nowrap transition-colors inline-flex items-center">
                                                        <i class="fas fa-file-alt text-[8px] mr-0.5"></i> Ver transcripción
                                                    </a>
                                                </template>
                                                <template x-if="f.transcription_id && ['pending','queued','processing'].includes(f.transcription_state)">
                                                    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                       class="text-[10px] px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded whitespace-nowrap transition-colors inline-flex items-center">
                                                        <i class="fas fa-spinner text-[8px] mr-0.5"></i> En proceso…
                                                    </a>
                                                </template>
                                                <template x-if="f.transcription_id && ['error','dead'].includes(f.transcription_state)">
                                                    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id"
                                                       class="text-[10px] px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded whitespace-nowrap transition-colors inline-flex items-center">
                                                        <i class="fas fa-exclamation-triangle text-[8px] mr-0.5"></i> Ver error
                                                    </a>
                                                </template>
                                            </td>
                                        </tr>
                                    </template>
                                </template>
</template>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between text-xs text-slate-400 gap-2">
                    <span x-text="(filesMode === 'browse' ? folders.length + ' carpetas, ' : '') + filesTotal + ' archivos' + (filesTranscribed ? ' · ' + filesTranscribed + ' transcritos' : '')"></span>
                    <div class="flex items-center gap-3">
                        <button x-show="filesMode === 'browse'" @click="confirmProcessFolder()"
                                class="text-brand-600 hover:underline"
                                title="Crea transcripciones pendientes para todos los archivos sin transcribir de la carpeta actual. El envío real lo hace el botón 'Escanear storages' o la tarea automática.">
                            <i class="fas fa-folder-open text-[10px] mr-1"></i> Procesar carpeta
                        </button>
                        <button x-show="filesMode === 'today' || filesMode === 'yesterday'" @click="confirmProcessDay()"
                                class="text-brand-600 hover:underline"
                                title="Crea transcripciones pendientes para todos los archivos del día (HOY o AYER) sin transcribir. El envío real lo hace el botón 'Escanear storages' o la tarea automática.">
                            <i class="fas fa-calendar-day text-[10px] mr-1"></i> Procesar día
                        </button>
                        <button @click="syncStorage(currentStorage)" :disabled="syncing"
                                class="text-slate-600 hover:underline disabled:opacity-40"
                                title="Escanea el disco del storage y registra en la base de datos los archivos nuevos que aún no aparecen aquí. No transcribe, solo descubre.">
                            <i class="fas fa-cloud-download-alt text-[10px] mr-1" :class="syncing ? 'fa-spin' : ''"></i>
                            <span x-text="syncing ? 'Sincronizando...' : 'Sincronizar archivos'"></span>
                        </button>
                        {{-- Botón "Escanear y encolar últimos N" eliminado: usaba scanStorage síncrono que bloquea el navegador.
                                 Para descubrimiento + dispatch, usar "Escanear storages" del header. --}}
                    </div>
                </div>
            </div>

            {{-- Footer de selección múltiple / envío en lote --}}
            <div x-show="selectedFileIds.size > 0 || bulkResult" x-transition.opacity
                 class="px-6 py-3 border-t border-brand-200 bg-brand-50/80 backdrop-blur flex-shrink-0">
                <div x-show="!bulkResult" class="flex items-center gap-3">
                    <div class="flex items-center gap-2 text-sm text-brand-800">
                        <i class="fas fa-check-square text-brand-500"></i>
                        <span class="font-medium" x-text="selectedFileIds.size + ' seleccionados'"></span>
                        <span class="text-xs text-brand-600/70" x-show="pendingSelectedCount() !== selectedFileIds.size">
                            (<span x-text="pendingSelectedCount()"></span> pendientes, <span x-text="selectedFileIds.size - pendingSelectedCount()"></span> ya transcritos)
                        </span>
                    </div>
                    <div class="flex-1"></div>
                    <button @click="clearSelection()"
                            class="text-xs text-slate-500 hover:text-slate-700 px-2 py-1.5">
                        Limpiar selección
                    </button>
                    <button @click="bulkSendSelected()" :disabled="bulkSending || pendingSelectedCount() === 0"
                            class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-paper-plane text-xs" :class="bulkSending ? 'fa-spin' : ''"></i>
                        <span x-text="bulkSending
                            ? (bulkProgress ? ('Enviando ' + bulkProgress.done + '/' + bulkProgress.total + '...') : 'Enviando...')
                            : ('Enviar ' + pendingSelectedCount() + ' seleccionados')"></span>
                    </button>
                </div>
                <div x-show="bulkResult" class="flex items-center gap-3">
                    <template x-if="bulkResult && bulkResult.errors === 0 && bulkResult.sent > 0">
                        <div class="flex items-center gap-2 text-sm text-green-700">
                            <i class="fas fa-check-circle"></i>
                            <span>
                                <strong x-text="bulkResult.sent"></strong> despachados correctamente
                                <span x-show="bulkResult.skipped > 0" class="text-slate-500">
                                    · <span x-text="bulkResult.skipped"></span> ya transcritos (omitidos)
                                </span>
                            </span>
                        </div>
                    </template>
                    <template x-if="bulkResult && bulkResult.errors > 0">
                        <div class="flex items-center gap-2 text-sm text-amber-700">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>
                                <strong x-text="bulkResult.sent"></strong> despachados, <strong x-text="bulkResult.errors"></strong> con error
                                <span x-show="bulkResult.skipped > 0" class="text-slate-500">
                                    · <span x-text="bulkResult.skipped"></span> ya transcritos (omitidos)
                                </span>
                            </span>
                        </div>
                    </template>
                    <template x-if="bulkResult && bulkResult.sent === 0 && bulkResult.errors === 0">
                        <div class="flex items-center gap-2 text-sm text-slate-600">
                            <i class="fas fa-info-circle"></i>
                            <span>No había archivos pendientes para enviar.</span>
                        </div>
                    </template>
                    <div class="flex-1"></div>
                    <button @click="bulkResult = null; clearSelection();"
                            class="text-xs text-brand-600 hover:underline px-2 py-1.5">
                        Aceptar
                    </button>
                </div>
            </div>
        </div>
    </div>

    </div> {{-- /TAB STORAGES --}}

    {{-- TAB: CONFIGURACIÓN --}}
    @include('ia.api-transcriptor._settings-tab')
@push('scripts')
<script>
// =========================================================================
// SISTEMA DE TOASTS GLOBAL — reemplaza alert() con notificaciones visuales
// =========================================================================
document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        nextId: 1,

        push(message, type = 'info', duration = 4000) {
            const id = this.nextId++;
            const item = { id, message, type, visible: true };
            this.items.push(item);
            // duration === 0 → persistente (no auto-dismiss). Para error crítico.
            // duration > 0 → auto-dismiss después de N ms.
            if (duration > 0) {
                setTimeout(() => this.dismiss(id), duration);
            }
            return id;
        },

        dismiss(id) {
            const item = this.items.find(i => i.id === id);
            if (item) {
                item.visible = false;
                setTimeout(() => {
                    this.items = this.items.filter(i => i.id !== id);
                }, 300);
            }
        },

        clear() {
            this.items.forEach(i => i.visible = false);
            setTimeout(() => { this.items = []; }, 300);
        },

        success(message, duration = 4000) { return this.push(message, 'success', duration); },
        error(message, duration = 6000)   { return this.push(message, 'error', duration); },
        info(message, duration = 4000)    { return this.push(message, 'info', duration); },
        warning(message, duration = 5000) { return this.push(message, 'warning', duration); },
    });
});

// Helper global para usar desde cualquier handler. Si Alpine no está listo,
// cae a alert() (no debería pasar, pero es defensivo).
window.showToast = function(message, type = 'info', duration = 4000) {
    if (window.Alpine && Alpine.store('toasts')) {
        return Alpine.store('toasts').push(message, type, duration);
    }
    console.warn('[toasts] Alpine no listo, fallback a alert');
    alert(message);
};

function apiTranscriptor(config = {}) {
    const storageExpansionKey = 'transcriptor-storages-expanded:' + (config.userId || 0);
    let initialExpanded = [];
    try {
        initialExpanded = JSON.parse(localStorage.getItem(storageExpansionKey) || '[]');
    } catch (e) { initialExpanded = []; }
    // Vista de la tabla storages (sort + filtro de estado) persistente por
    // usuario. Default: activos primero (column 'enabled' desc), que es como
    // el operador piensa la tabla: lo que esta transcribiendo arriba.
    const storagesViewKey = 'transcriptor-storages-view:' + (config.userId || 0);
    let savedView = {};
    try {
        savedView = JSON.parse(localStorage.getItem(storagesViewKey) || '{}') || {};
    } catch (e) { savedView = {}; }
    const VALID_STATUS_FILTERS = ['all', 'enabled', 'disabled'];
    const savedSort = (savedView.sort && savedView.sort.column) ? savedView.sort : null;
    const savedStatus = VALID_STATUS_FILTERS.includes(savedView.statusFilter) ? savedView.statusFilter : null;
    return {
        userId: config.userId || 0,
        pendingAlertThreshold: config.pendingAlertThreshold || 5,
        expandedScopes: new Set(initialExpanded),
        storagesSearch: '',
        storagesStatusFilter: savedStatus || 'all',
        storagesSort: savedSort || { column: 'enabled', direction: 'desc' },
        storagesPage: 1,
        storagesPerPage: 25,
        loading: false,
        jobs: [],
        storages: [],
        showInfo: false,
        // Tabs principales
        tab: 'storages', // storages | jobs | config
        // Pestaña Configuración: cfg = valores editables, cfgMeta = esquema
        // (rangos, etiquetas, origen) servido por el backend. Un solo esquema
        // alimenta formulario y validación, así que no pueden desincronizarse.
        cfg: {},
        cfgMeta: null,
        cfgRuntime: null,
        cfgErrors: {},
        cfgLoading: false,
        cfgSaving: false,
        cfgDirty: false,
        cfgPoll: null,
        // Sub-tabs de Trabajos. 'completed' es solo done; los terminales
        // fallidos (error/dead) viven en 'failed' para no leerse como exitos.
        jobsSubTab: 'pending', // pending | completed | failed
        search: '',
        stateFilter: '',
        // Paginacion resuelta en servidor (ver indexData()).
        pagination: { page: 1, per_page: 50, total: 0, total_pages: 1, capped: false, window_max: 500 },
        // Modal "Ver transcripción"
        transcript: { open: false, loading: false, error: null, job: null, view: 'texto', q: '', data: null },
        health: null,
        stats: null,
        // Shape inicial NO nulo: las expresiones hijas del banner ámbar
        // (x-text, x-for) se evalúan antes de que loadEmptyFolders() termine,
        // y aunque el <template x-if> exterior evita que se monten cuando
        // total_missing_folders === 0, mantener el mismo shape que el catch
        // de loadEmptyFolders evita asimetrías entre el primer render y el
        // estado tras error. Ver change 2026-09-07-api-transcriptor-fix-empty-folders-console-errors.
        emptyFolders: { items: [], storages_with_empty: 0, total_missing_folders: 0 },
        emptyFoldersExpanded: false,
        stateLabels: {
            pending: 'pendientes',
            queued: 'en cola',
            processing: 'en proceso',
            done: 'completados',
            error: 'con error',
            dead: 'fallidos',
        },
        // storage cuyo apagado espera confirmación en el modal (null = cerrado)
        storageToDisable: null,
        // modal archivos
        showFiles: false,
        currentStorage: null,
        files: [],         // datos crudos del backend
        filesFlat: [],     // lista filtrada+ordenada para BROWSE/HOY/AYER
        filesGroups: [],   // grupos para SEARCH
        folders: [],
        breadcrumb: [],
        filesMode: 'browse', // browse | today | search
        currentParent: null,
        filesLoading: false,
        filesSearch: '',
        filesTotal: 0,
        filesTranscribed: 0,
        syncing: false,
        colFilters: { name: '', time: '' },
        filesSort: { key: 'time', dir: 'desc' }, // default: hora militar desc
        // Modal de progreso (envío manual)
        showProgress: false,
        progressFile: null,
        progressStep: 'converting', // converting | uploading | queued | processing | done | error
        progressPercent: 0,
        progressStatus: null,
        progressResult: null,
        progressError: null,
        progressTranscriptionId: null,
        progressElapsed: 0,
        progressTimer: null,
        // Modal de procesamiento por lotes
        showBatchModal: false,
        batchRunning: false,
        batchSize: {{ (int) ($ui_limits['scan_batch'] ?? 100) }},
        batchAlerts: true,
        batchIncludeFailed: false,
        // transcriptor-rescan-completed: reprocesar transcripciones state='done'.
        batchIncludeDone: false,
        batchResult: null,
        batchRunId: null,
        batchExpanded: false,
        batchPollTimer: null,
        batchProgress: null,
        // transcriptor-scan-scope-selector: alcance del escaneo + estimación
        batchScope: 'today',
        batchScopeFrom: '',
        batchScopeTo: '',
        batchEstimate: null,
        batchEstimateLoading: false,
        batchEstimateError: null,
        // Mini-modal confirmación carpeta/día
        showProcessConfirm: false,
        processConfirmText: '',
        processConfirmAction: null,
        processAlerts: true,
        batch: 10,
        // Multi-selección de archivos para envío en lote
        selectedFileIds: new Set(),
        bulkSending: false,
        bulkResult: null,
        bulkProgress: null,
        // Tope de POST simultaneos del envio en lote. Cada uno corre ffmpeg +
        // POST sincronos en php-fpm, asi que sin tope 200 archivos = 200 procesos.
        //
        // Los dos topes vienen de la capa de settings (ui_limits), no de config():
        // asi un override guardado en la pantalla de Configuracion manda desde la
        // primera carga, sin tener que abrir esa pestana.
        uiMaxParallelSends: {{ (int) ($ui_limits['max_parallel_sends'] ?? 3) }},
        // Tope del slider de lote. Es el MISMO valor con el que el servidor
        // clampea en processBatch: antes eran 500 aqui y 200 alli, y el exceso
        // se truncaba en silencio.
        uiBatchMax: {{ (int) ($ui_limits['batch_max'] ?? 200) }},
        // Bulk dispatch de jobs pendientes desde la pestaña Trabajos
        selectedJobIds: new Set(),
        selectJobMode: false,
        bulkDispatching: false,
        bulkDispatchResult: null,
        refreshingJobs: new Set(),
        async init() {
            // Antes cargaba también loadHealth/loadStats/loadEmptyFolders contra
            // los endpoints eliminados en simplify-api-transcriptor-to-storage-and-config:
            // ahora devuelven 404 y ensucian la consola. La señal viva es
            // `load()` (storages) + `loadConfig()` (settings tab).
            await Promise.all([this.load(), this.loadConfig()]);
            // add-bg-job-indicator-widget: si el operador llega aquí con
            // ?focus=bg-transcriptor-batch-{runId} desde el widget global,
            // abrir el modal con el progreso del batch activo. Sin focus,
            // no auto-abrir nada (comportamiento normal).
            this.focusBgJob();
            this.$watch('jobsSubTab', () => {
                if (this.jobsSubTab !== 'pending') {
                    this.selectedJobIds = new Set();
                    this.selectJobMode = false;
                    this.bulkDispatchResult = null;
                }
            });
            // Carga diferida: la petición de settings no debe correr en cada
            // page load, solo al abrir la pestaña.
            this.$watch('tab', v => {
                if (v === 'config') {
                    this.loadConfig();
                    this.startConfigPoll();
                } else {
                    this.stopConfigPoll();
                }
            });
        },

        // ---------------------------------------------- pestaña Configuración

        cfgGroupsOrder: ['ritmo', 'descubrimiento', 'confiabilidad', 'api', 'workers', 'ui'],
        cfgGroupLabels: {
            ritmo: 'Ritmo de envío',
            descubrimiento: 'Descubrimiento',
            confiabilidad: 'Confiabilidad',
            api: 'API del transcriptor',
            workers: 'Pool de workers',
            ui: 'Interfaz',
        },
        cfgGroupHelps: {
            ritmo: 'Cuánto y cada cuánto se envía. Es lo que convierte la ráfaga en goteo.',
            descubrimiento: 'Qué archivos encuentra el escáner y cuántos toma por ciclo.',
            confiabilidad: 'Recogida de resultados y cierre de lo que no se resuelve. No hay webhook: si nadie consulta, nada vuelve.',
            api: 'Tiempos de espera y reintentos contra el transcriptor externo.',
            workers: 'Cuántos procesos consumen la cola. El tuner los ajusta cada 5 min.',
            ui: 'Topes de la propia interfaz.',
        },

        cfgGroups() {
            if (!this.cfgMeta) return [];
            const present = new Set(Object.values(this.cfgMeta).map(m => m.group));
            return this.cfgGroupsOrder.filter(g => present.has(g));
        },
        groupLabel(g) { return this.cfgGroupLabels[g] || g; },
        groupHelp(g) { return this.cfgGroupHelps[g] || ''; },
        cfgKeysIn(group) {
            if (!this.cfgMeta) return [];
            return Object.keys(this.cfgMeta).filter(k => this.cfgMeta[k].group === group);
        },
        queuePct() {
            const d = this.cfgRuntime?.queue_depth, t = this.cfgRuntime?.queue_target;
            if (d === null || d === undefined || !t) return 0;
            return Math.round((d / t) * 100);
        },
        fmtAgo(iso) {
            if (!iso) return '—';
            const secs = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
            if (secs < 60) return 'hace ' + secs + 's';
            if (secs < 3600) return 'hace ' + Math.floor(secs / 60) + ' min';
            return 'hace ' + Math.floor(secs / 3600) + ' h';
        },

        applyConfigPayload(data) {
            this.cfgMeta = data.groups;
            this.cfgRuntime = data.runtime;
            const next = {};
            for (const [k, m] of Object.entries(data.groups)) next[k] = m.value;
            this.cfg = next;
            this.cfgErrors = {};
            this.cfgDirty = false;
            // Propagar a los topes que consume el resto de la interfaz.
            if (next.ui_batch_max) this.uiBatchMax = next.ui_batch_max;
            if (next.ui_max_parallel_sends) this.uiMaxParallelSends = next.ui_max_parallel_sends;
            if (this.batchSize > this.uiBatchMax) this.batchSize = this.uiBatchMax;
        },

        async loadConfig() {
            this.cfgLoading = true;
            try {
                const r = await fetch('/ia/api-transcriptor/settings', {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                this.applyConfigPayload(await r.json());
            } catch (e) {
                showToast('No se pudo cargar la configuración: ' + e.message, 'error');
            } finally {
                this.cfgLoading = false;
            }
        },

        async refreshConfigRuntime() {
            // Solo el contexto en vivo: no pisa lo que el admin esté editando.
            try {
                const r = await fetch('/ia/api-transcriptor/settings', {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) return;
                const data = await r.json();
                this.cfgRuntime = data.runtime;
                if (!this.cfgDirty) this.cfgMeta = data.groups;
            } catch (e) { /* silencioso: es un refresco de fondo */ }
        },

        startConfigPoll() {
            this.stopConfigPoll();
            this.cfgPoll = setInterval(() => this.refreshConfigRuntime(), 10000);
        },
        stopConfigPoll() {
            if (this.cfgPoll) { clearInterval(this.cfgPoll); this.cfgPoll = null; }
        },

        async postConfig(url, body, okMsg) {
            this.cfgSaving = true;
            this.cfgErrors = {};
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(body),
                });
                const data = await r.json();
                if (r.status === 422 && data.errors) {
                    for (const [k, msgs] of Object.entries(data.errors)) this.cfgErrors[k] = msgs[0];
                    showToast('Revisa los valores marcados.', 'error');
                    return false;
                }
                if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
                if (data.groups) this.applyConfigPayload(data);
                showToast(okMsg || data.message, 'success');
                return true;
            } catch (e) {
                showToast('Error: ' + e.message, 'error');
                return false;
            } finally {
                this.cfgSaving = false;
            }
        },

        async saveConfig() {
            await this.postConfig('/ia/api-transcriptor/settings', { values: this.cfg }, null);
        },

        async resetKey(k) {
            await this.postConfig('/ia/api-transcriptor/settings/reset', { keys: [k] }, 'Valor restaurado.');
        },

        async togglePause() {
            const next = !this.cfg.dispatch_paused;
            if (next && !confirm('¿Pausar el envío?\n\nEl descubrimiento sigue activo y no se pierde nada, pero dejará de encolarse trabajo hasta que lo reanudes.')) return;
            await this.postConfig('/ia/api-transcriptor/settings', { values: { dispatch_paused: next } },
                next ? 'Envío pausado.' : 'Envío reanudado.');
        },

        async runTick(dryRun) {
            this.cfgSaving = true;
            try {
                const r = await fetch('/ia/api-transcriptor/settings/run-tick', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ dry_run: dryRun }),
                });
                const data = await r.json();
                if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
                showToast(data.message, 'success', 5000);
                setTimeout(() => this.refreshConfigRuntime(), 4000);
            } catch (e) {
                showToast('Error: ' + e.message, 'error');
            } finally {
                this.cfgSaving = false;
            }
        },
        get storagesEnabled() {
            return this.storages.filter(s => s.transcription_enabled);
        },
        visibleStorages() {
            return this.storages.filter(s => {
                // Root de scope (parent_scope_id = mi propio id): SIEMPRE visible
                // aunque el operador no haya expandido el scope. Sin esto, los
                // roots como "01 Emisoras 01" (con 11 hijos) desaparecian de la
                // tabla porque el filtro los trataba como "hijo colapsado".
                // Bug: usuario buscaba "emisoras" y solo veia las hojas
                // (Emisoras 03/05/ABC) pero no los roots que aparecian en el
                // banner amarillo.
                if (s.parent_scope_id && s.parent_scope_id === s.id) return true;
                // Storage sin scope (parent_scope_id null): visible siempre.
                if (!s.parent_scope_id) return true;
                // Hijo de un scope ajeno: visible solo si el scope esta expandido.
                return this.expandedScopes.has(s.parent_scope_id);
            });
        },
        filteredStorages() {
            const q = (this.storagesSearch || '').toLowerCase().trim();
            // Filtro rapido por estado de transcripcion (all | enabled | disabled).
            // Con filtro activo la vista se aplana: los hijos de un padre
            // colapsado matchean aunque el padre este oculto, igual que la
            // busqueda global del header.
            const statusActive = this.storagesStatusFilter !== 'all';
            let list = statusActive ? [...this.storages] : this.visibleStorages();
            if (this.storagesStatusFilter === 'enabled') {
                list = list.filter(s => s.transcription_enabled);
            } else if (this.storagesStatusFilter === 'disabled') {
                list = list.filter(s => !s.transcription_enabled);
            }
            if (q) list = list.filter(s => (s.name || '').toLowerCase().includes(q));
            // Sort client-side following el patron de Mis Avisos.
            // Desempate por nombre para orden determinista dentro del mismo valor.
            const col = this.storagesSort.column;
            const dir = this.storagesSort.direction === 'asc' ? 1 : -1;
            return [...list].sort((a, b) => {
                const av = this.storagesSortValue(a, col);
                const bv = this.storagesSortValue(b, col);
                if (av === bv) {
                    const an = (a.name || '').toString().toLowerCase();
                    const bn = (b.name || '').toString().toLowerCase();
                    if (an === bn) return 0;
                    return an < bn ? -1 : 1;
                }
                if (av < bv) return -1 * dir;
                return 1 * dir;
            });
        },
        persistStoragesView() {
            try {
                localStorage.setItem(storagesViewKey, JSON.stringify({
                    sort: this.storagesSort,
                    statusFilter: this.storagesStatusFilter,
                }));
            } catch (e) { /* localStorage no disponible, ignorar */ }
        },
        setStoragesStatusFilter(value) {
            if (!VALID_STATUS_FILTERS.includes(value)) return;
            this.storagesStatusFilter = value;
            this.storagesPage = 1;
            this.persistStoragesView();
        },
        storagesSortValue(s, col) {
            switch (col) {
                case 'cantidad':  return Number(s.cantidad || 0);
                case 'tipo':      return (s.type || '').toString().toLowerCase();
                case 'pending':   return Number(s.funnel?.pending || 0);
                case 'done':      return Number(s.funnel?.done || 0);
                case 'priority':  return Number(s.transcription_priority || 0);
                case 'enabled':   return s.transcription_enabled ? 1 : 0;
                case 'name':
                default:          return (s.name || '').toString().toLowerCase();
            }
        },
        setStoragesSort(column) {
            const cur = this.storagesSort;
            if (cur.column === column) {
                cur.direction = cur.direction === 'asc' ? 'desc' : 'asc';
            } else {
                cur.column = column;
                cur.direction = 'asc';
            }
            this.storagesSort = { ...cur };
            this.storagesPage = 1;
            this.persistStoragesView();
        },
        storagesSortIcon(col) {
            if (this.storagesSort.column !== col) return 'fa-sort';
            return this.storagesSort.direction === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down';
        },
        storagesSortIconClass(col) {
            if (this.storagesSort.column !== col) return 'text-slate-300';
            return this.storagesSort.direction === 'asc' ? 'text-violet-600' : 'text-amber-600';
        },
        storagesSortHeaderClass(col) {
            if (this.storagesSort.column !== col) return 'text-slate-500 hover:text-slate-800';
            return 'text-slate-800 font-semibold';
        },
        storagesPageList(current, last) {
            if (!last || last <= 7) return Array.from({ length: Math.max(1, last || 1) }, (_, i) => i + 1);
            const pages = [1];
            const start = Math.max(2, current - 2), end = Math.min(last - 1, current + 2);
            if (start > 2) pages.push('…');
            for (let p = start; p <= end; p++) pages.push(p);
            if (end < last - 1) pages.push('…');
            pages.push(last);
            return pages;
        },
        storagesTotalPages() {
            const total = this.filteredStorages().length;
            return Math.max(1, Math.ceil(total / this.storagesPerPage));
        },
        pagedStorages() {
            const filtered = this.filteredStorages();
            const start = (this.storagesPage - 1) * this.storagesPerPage;
            return filtered.slice(start, start + this.storagesPerPage);
        },
        // Numero global de fila (1-based) dentro de la lista filtrada/ordenada,
        // continuo entre paginas. Padding a 2 digitos para lectura rapida.
        storageRowNumber(index) {
            const n = (this.storagesPage - 1) * this.storagesPerPage + index + 1;
            return String(n).padStart(2, '0');
        },
        storagesRangeStart() {
            const total = this.filteredStorages().length;
            if (!total) return 0;
            return (this.storagesPage - 1) * this.storagesPerPage + 1;
        },
        storagesRangeEnd() {
            const total = this.filteredStorages().length;
            return Math.min(this.storagesPage * this.storagesPerPage, total);
        },
        // Cantidad total: suma de TODOS los storages con transcripcion ACTIVA
        // (incluye duplicados entre padres e hijos). El operador lo usa para
        // ver el tamano bruto del alcance que esta procesandose.
        cantidadTotal() {
            return this.storages.reduce((acc, s) => {
                if (!s.transcription_enabled) return acc;
                return acc + (Number(s.cantidad) || 0);
            }, 0);
        },
        // Cantidad real (ponderado): suma SOLO de storages hoja con
        // transcripcion activa, sin padres que ya agregan a sus hijos. Asi
        // "01 Radio FM Bogota" (hoja, 1) y "01 Emisoras 01" (padre con 12)
        // no se cuentan doble: 01 Radio FM Bogota vive dentro del scope de
        // Emisoras, su 1 ya esta cubierto por el conteo del padre. Solo
        // sumamos donde no hay duplicacion.
        cantidadPonderada() {
            return this.storages.reduce((acc, s) => {
                if (!s.transcription_enabled) return acc;
                if ((s.descendant_count || 0) > 0) return acc;
                return acc + (Number(s.cantidad) || 0);
            }, 0);
        },
        pendientesTotal() {
            return this.storages.reduce((acc, s) => acc + (Number(s.funnel?.pending) || 0), 0);
        },
        listosTotal() {
            return this.storages.reduce((acc, s) => acc + (Number(s.funnel?.done) || 0), 0);
        },
        toggleStorageExpansion(rootId) {
            if (!rootId) return;
            if (this.expandedScopes.has(rootId)) {
                this.expandedScopes.delete(rootId);
            } else {
                this.expandedScopes.add(rootId);
            }
            try {
                localStorage.setItem(storageExpansionKey, JSON.stringify([...this.expandedScopes]));
            } catch (e) { /* localStorage no disponible, ignorar */ }
        },
        shouldWarnPending(s) {
            if (!s) return false;
            const pending = s.funnel?.pending ?? 0;
            return pending > this.pendingAlertThreshold;
        },
        pendingWarningTitle(s) {
            if (!s) return '';
            const pending = s.funnel?.pending ?? 0;
            return pending + ' pendientes hoy supera el umbral de ' + this.pendingAlertThreshold;
        },
        // Los contadores de badge leen stats.local (totales de BD). Contar la
        // pagina cargada daria como mucho per_page y mentiria sobre la cola real.
        statCount(...states) {
            const local = (this.stats && this.stats.local) || {};
            return states.reduce((n, s) => n + (Number(local[s]) || 0), 0);
        },
        get jobsPendingCount()   { return this.statCount('pending', 'queued', 'processing'); },
        get jobsCompletedCount() { return this.statCount('done'); },
        get jobsFailedCount()    { return this.statCount('error', 'dead'); },
        // Desglose de la sub-tab "En proceso". Llamar "pendientes" a todo el
        // grupo hacia leer un backlog de jobs YA ENVIADOS como si fueran
        // grabaciones sin mandar: son problemas opuestos y se arreglan distinto.
        //   sin enviar  -> el envio no ha ocurrido (mirar tick/dispatch)
        //   en la API   -> ya enviado, falta recoger el resultado (mirar poll)
        get jobsUnsentCount()    { return this.statCount('pending'); },
        get jobsInApiCount()     { return this.statCount('queued', 'processing'); },
        // Estados que ofrece el <select> segun la sub-tab activa.
        scopeStates() {
            if (this.jobsSubTab === 'pending') return ['pending', 'queued', 'processing'];
            if (this.jobsSubTab === 'failed') return ['error', 'dead'];
            return ['done'];
        },
        setJobsSubTab(scope) {
            if (this.jobsSubTab === scope) return;
            this.jobsSubTab = scope;
            // El filtro de estado anterior puede no existir en el nuevo scope.
            if (this.stateFilter && !this.scopeStates().includes(this.stateFilter)) {
                this.stateFilter = '';
            }
            this.reload();
        },
        // Vuelve a la pagina 1: cambiar de scope, buscar o filtrar invalida la actual.
        reload() {
            this.pagination.page = 1;
            return this.load({ jobsOnly: true });
        },
        goToPage(page) {
            if (page < 1 || page > this.pagination.total_pages || page === this.pagination.page) return;
            this.pagination.page = page;
            return this.load({ jobsOnly: true });
        },
        pageRangeStart() {
            if (!this.pagination.total) return 0;
            return (this.pagination.page - 1) * this.pagination.per_page + 1;
        },
        pageRangeEnd() {
            return Math.min(this.pagination.page * this.pagination.per_page, this.pagination.total);
        },
        emptyStateTitle() {
            if (this.jobsSubTab === 'pending') return 'Sin trabajos pendientes';
            if (this.jobsSubTab === 'failed') return 'Sin trabajos fallidos';
            return 'Sin trabajos completados';
        },
        emptyStateHint() {
            if (this.search || this.stateFilter) return 'Ningún trabajo coincide con el filtro actual';
            if (this.jobsSubTab === 'pending') return 'Los trabajos nuevos aparecerán aquí';
            if (this.jobsSubTab === 'failed') return 'Los trabajos que agoten reintentos aparecerán aquí';
            return 'Cuando los jobs terminen aparecerán aquí';
        },
        /**
         * jobsOnly distingue "solo estoy navegando" de "algo cambió":
         *  - omite el bloque de storages del payload (~430ms de
         *    resolveInheritedTranscriptionScope), que paginar no necesita;
         *  - y evita refrescar los contadores, que tampoco cambian al paginar.
         *
         * Sin jobsOnly (tras cancelar, reprocesar, despachar...) se refrescan
         * los badges, que ahora leen stats y no el array de filas. El refresco
         * NO se espera: /stats llama primero a la API externa y un nodo lento
         * no debe retrasar la tabla.
         */
        async load(opts = {}) {
            this.loading = true;
            // loadStats() eliminado (endpoint /ia/api-transcriptor/stats ya no
            // existe). Si volvemos a un badge "Trabajos N" en algún futuro, lo
            // agregamos apuntando a un endpoint nuevo o a un GROUP BY local
            // sin lanzar 404.
            try {
                const params = new URLSearchParams();
                params.set('scope', this.jobsSubTab);
                params.set('page', this.pagination.page);
                params.set('per_page', this.pagination.per_page);
                if (this.search) params.set('q', this.search);
                if (this.stateFilter) params.set('state', this.stateFilter);
                if (opts.jobsOnly) params.set('only', 'jobs');
                const res = await apiFetch('/ia/api-transcriptor?' + params.toString(), { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    this.jobs = data.jobs || [];
                    if (data.pagination) this.pagination = data.pagination;
                    // `saving` se inicializa aquí a proposito. Sin el, la
                    // expresion :disabled="s.saving" del interruptor evalua
                    // undefined, y para un atributo booleano esta build de
                    // Alpine lo traduce a disabled="disabled": el boton nacia
                    // muerto y el clic no hacia nada, sin error ni peticion.
                    if (data.storages) this.storages = data.storages.map(s => ({ ...s, saving: false }));
                    // Limpiar selección de jobs: los que ya se despacharon
                    // (o cambiaron de estado) ya no son dispatchable.
                    const dispatchableIds = new Set(this.dispatchableJobs().map(j => Number(j.id)));
                    for (const id of [...this.selectedJobIds]) {
                        if (!dispatchableIds.has(id)) this.selectedJobIds.delete(id);
                    }
                }
            } finally { this.loading = false; }
        },
        // loadHealth / loadStats / loadEmptyFolders se reemplazaron por no-ops tras
        // simplify-api-transcriptor-to-storage-and-config. Sus endpoints fueron
        // eliminados y la UI que los consumía (banner "API en línea", cards
        // Trabajos N, banner carpetas vacías) también. Si quedan referencias
        // inesperadas desde el JS no se levantan HTTP 404.
        async loadHealth() { /* no-op: endpoint eliminado */ },
        async loadStats() { /* no-op: endpoint eliminado */ },
        async loadEmptyFolders() { /* no-op: endpoint eliminado */ },
        storageById(id) {
            return this.storages.find(s => s.id === Number(id));
        },
        // Badge de "carpetas sin archivos" en la tabla: lookup O(1) sobre el
        // array de emptyFolders (pocos items, <storages_with_empty). Permite
        // ver de un vistazo qué storages aparecen en el banner amarillo sin
        // tener que expandirlo. Sin esto, esos 10 storages se "esconden"
        // entre las 173 filas y el operador tiene que cruzar IDs a mano.
        emptyFoldersFor(storageId) {
            const items = (this.emptyFolders && this.emptyFolders.items) || [];
            return items.find(it => Number(it.storage_id) === Number(storageId)) || null;
        },
        emptyFoldersBadge(storageId) {
            const ef = this.emptyFoldersFor(storageId);
            if (!ef) return '';
            const n = Number(ef.missing_count || 0);
            return n === 1 ? '1 carpeta sin archivos' : `${n} carpetas sin archivos`;
        },
        // Encender es directo; apagar pide confirmación en un modal de la propia
        // página. NO se usa confirm() nativo: el navegador lo suprime en silencio
        // cuando el usuario marca "impedir que esta página cree más diálogos", y
        // entonces el clic no hace absolutamente nada — sin request, sin aviso.
        toggleStorage(s) {
            if (!s || s.saving) return;

            if (s.transcription_enabled) {
                this.storageToDisable = s;
                return;
            }

            this.applyStorageToggle(s, true);
        },
        confirmDisableStorage() {
            const s = this.storageToDisable;
            this.storageToDisable = null;
            if (s) this.applyStorageToggle(s, false);
        },
        // Escribe storage_providers.transcription_enabled, la bandera que lee el
        // scanner. Apagarla detiene el descubrimiento de ese canal; no borra nada
        // de lo ya transcrito.
        async applyStorageToggle(s, nuevo) {
            if (!s || s.saving) return;

            s.saving = true;
            try {
                const res = await apiFetch('/ia/api-transcriptor/storages/' + s.id + '/toggle', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ transcription_enabled: nuevo }),
                });
                if (res.ok) {
                    const d = await res.json();
                    s.transcription_enabled = d.transcription_enabled;
                    showToast(d.transcription_enabled
                        ? '"' + s.name + '" ya se transcribe. El pool de workers se ajusta solo en unos minutos.'
                        : '"' + s.name + '" deja de transcribirse.', 'success');
                } else {
                    const d = await res.json().catch(() => ({}));
                    console.error('toggleStorage: respuesta ' + res.status, d);
                    showToast(d.error || ('No se pudo cambiar la transcripción del storage (HTTP ' + res.status + ')'), 'error');
                }
            } catch (e) {
                // Con el mensaje real a la vista: un interruptor que falla en
                // silencio es indistinguible de uno que no está conectado.
                console.error('toggleStorage:', e);
                showToast('No se pudo cambiar la transcripción del storage: ' + (e && e.message ? e.message : e), 'error');
            } finally {
                s.saving = false;
            }
        },
        async openFiles(s) {
            this.currentStorage = s;
            this.filesSearch = '';
            this.currentParent = null;
            this.clearSelection();
            this.bulkResult = null;
            this.setMode('browse');
            this.showFiles = true;
        },
        closeFiles() {
            this.showFiles = false;
            this.clearSelection();
            this.bulkResult = null;
        },
        toggleSelected(fileId) {
            const id = Number(fileId);
            if (this.selectedFileIds.has(id)) this.selectedFileIds.delete(id);
            else this.selectedFileIds.add(id);
        },
        isSelected(fileId) {
            return this.selectedFileIds.has(Number(fileId));
        },
        visibleFiles() {
            if (this.filesMode === 'search') {
                const out = [];
                for (const g of (this.filesGroups || [])) for (const f of (g.files || [])) out.push(f);
                return out;
            }
            return this.filesFlat || [];
        },
        visibleFileCount() {
            return this.visibleFiles().length;
        },
        isAllVisibleSelected() {
            const list = this.visibleFiles();
            if (list.length === 0) return false;
            return list.every(f => this.selectedFileIds.has(Number(f.id)));
        },
        isSomeVisibleSelected() {
            const list = this.visibleFiles();
            if (list.length === 0) return false;
            let n = 0;
            for (const f of list) if (this.selectedFileIds.has(Number(f.id))) { n++; if (n > 1) return true; }
            return n > 0;
        },
        toggleSelectAllVisible() {
            const list = this.visibleFiles();
            if (list.length === 0) return;
            if (this.isAllVisibleSelected()) {
                for (const f of list) this.selectedFileIds.delete(Number(f.id));
            } else {
                for (const f of list) if (!f.has_transcription) this.selectedFileIds.add(Number(f.id));
            }
        },
        clearSelection() {
            this.selectedFileIds = new Set();
        },
        pendingSelectedCount() {
            let n = 0;
            for (const id of this.selectedFileIds) {
                const f = this.visibleFiles().find(x => Number(x.id) === id);
                if (f && !f.has_transcription) n++;
            }
            return n;
        },
        // --- Bulk dispatch de jobs pendientes (Trabajos → Pendientes) ---
        dispatchableJobs() {
            return (this.jobs || []).filter(j => ['pending', 'queued', 'processing'].includes(j.state));
        },
        dispatchableJobsCount() {
            return this.dispatchableJobs().length;
        },
        isDispatchable(job) {
            return job && ['pending', 'queued', 'processing'].includes(job.state);
        },
        // El boton hace dos cosas distintas segun lo seleccionado: enviar lo
        // que nunca salio y consultar el resultado de lo que ya esta en la
        // API. Decirlo en la etiqueta evita la expectativa de que "Procesar"
        // signifique siempre "mandar a transcribir".
        get bulkActionLabel() {
            if (!(this.selectJobMode && this.selectedJobIds.size > 0)) {
                return 'Enviar pendientes ahora';
            }

            const sel = (this.jobs || []).filter(j => this.selectedJobIds.has(Number(j.id)) && this.isDispatchable(j));
            const toSend = sel.filter(j => !j.job_id).length;
            const toCheck = sel.length - toSend;

            if (toSend > 0 && toCheck > 0) return `Enviar ${toSend} y consultar ${toCheck}`;
            if (toSend > 0) return `Enviar ${toSend} a la API`;
            if (toCheck > 0) return `Consultar resultado de ${toCheck}`;

            return 'Nada que procesar en la selección';
        },
        toggleJobSelected(jobId) {
            const id = Number(jobId);
            if (this.selectedJobIds.has(id)) this.selectedJobIds.delete(id);
            else this.selectedJobIds.add(id);
        },
        isJobSelected(jobId) {
            return this.selectedJobIds.has(Number(jobId));
        },
        isAllDispatchableSelected() {
            const list = this.dispatchableJobs();
            if (list.length === 0) return false;
            return list.every(j => this.selectedJobIds.has(Number(j.id)));
        },
        isSomeDispatchableSelected() {
            const list = this.dispatchableJobs();
            let n = 0;
            for (const j of list) if (this.selectedJobIds.has(Number(j.id))) { n++; if (n > 1) return true; }
            return n > 0;
        },
        toggleSelectAllDispatchable() {
            const list = this.dispatchableJobs();
            if (list.length === 0) return;
            if (this.isAllDispatchableSelected()) {
                for (const j of list) this.selectedJobIds.delete(Number(j.id));
            } else {
                for (const j of list) this.selectedJobIds.add(Number(j.id));
            }
        },
        clearJobSelection() {
            this.selectedJobIds = new Set();
            this.selectJobMode = false;
        },
        setMode(mode) {
            this.filesMode = mode;
            this.filesSearch = '';
            if (mode === 'browse') { this.currentParent = null; this.loadFiles(); }
            else if (mode === 'today' || mode === 'yesterday') { this.loadFiles(); }
        },
        openFolder(folderId) {
            this.currentParent = folderId ?? null;
            this.filesMode = 'browse';
            this.loadFiles();
        },
        searchFiles() {
            if (!this.filesSearch) { this.setMode('browse'); return; }
            this.filesMode = 'search';
            this.loadFiles();
        },
        async loadFiles() {
            if (!this.currentStorage) return;
            this.filesLoading = true;
            try {
                const params = new URLSearchParams({ limit: 2000 });
                if (this.filesMode === 'today' || this.filesMode === 'yesterday') params.set('mode', this.filesMode);
                else if (this.filesMode === 'search' && this.filesSearch) params.set('q', this.filesSearch);
                else {
                    params.set('mode', 'browse');
                    if (this.currentParent) params.set('parent', this.currentParent);
                }
                const res = await apiFetch('/ia/api-transcriptor/storages/' + this.currentStorage.id + '/files?' + params, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const d = await res.json();
                    this.folders = d.folders || [];
                    this.breadcrumb = d.breadcrumb || [];
                    this.currentParent = d.current_parent;
                    this.filesTotal = d.files_total || 0;
                    this.filesTranscribed = d.transcribed_count || 0;
                    // Backend devuelve lista plana en browse/today/yesterday, y
                    // grupos en search. Detectar por la presencia de 'folder'.
                    const raw = d.files || [];
                    if (raw.length && raw[0] && typeof raw[0].folder !== 'undefined') {
                        this.filesGroups = raw;
                        this.filesFlat = [];
                    } else {
                        this.filesGroups = [];
                        this.filesFlat = raw;
                    }
                    this.applyColumnFilter();
                }
            } finally { this.filesLoading = false; }
        },
        toggleSort(key) {
            if (this.filesSort.key === key) {
                this.filesSort.dir = this.filesSort.dir === 'desc' ? 'asc' : 'desc';
            } else {
                this.filesSort.key = key;
                this.filesSort.dir = 'desc';
            }
            this.applyColumnFilter();
        },
        applyColumnFilter() {
            if (this.filesMode === 'search') {
                // Filtrar/ordenar dentro de cada grupo por nombre y hora
                const nameRe = this.colFilters.name.toLowerCase();
                const timeRe = this.colFilters.time.toLowerCase();
                this.filesGroups = (this.filesGroups || []).map(g => {
                    let arr = g.files.filter(f =>
                        (!nameRe || (f.name || '').toLowerCase().includes(nameRe)) &&
                        (!timeRe || (f.military_time || '').includes(timeRe))
                    );
                    arr = this.sortList(arr);
                    return { folder: g.folder, files: arr };
                }).filter(g => g.files.length > 0);
                return;
            }
            const nameRe = (this.colFilters.name || '').toLowerCase();
            const timeRe = (this.colFilters.time || '').toLowerCase();
            this.filesFlat = (this.filesFlat || []).filter(f =>
                (!nameRe || (f.name || '').toLowerCase().includes(nameRe)) &&
                (!timeRe || (f.military_time || '').includes(timeRe))
            );
            this.filesFlat = this.sortList(this.filesFlat);
        },
        sortList(arr) {
            const k = this.filesSort.key, d = this.filesSort.dir;
            arr = arr.slice();
            arr.sort((a, b) => {
                let va, vb;
                if (k === 'time') { va = a.military_time || ''; vb = b.military_time || ''; }
                else if (k === 'size') { va = +a.size || 0; vb = +b.size || 0; }
                else if (k === 'modified') { va = a.file_modified_at || ''; vb = b.file_modified_at || ''; }
                else { va = a.name || ''; vb = b.name || ''; }
                if (va < vb) return d === 'desc' ? 1 : -1;
                if (va > vb) return d === 'desc' ? -1 : 1;
                return 0;
            });
            return arr;
        },
        dispatchJobNow(job) {
            // Enviar inmediatamente un job pendiente (queued sin job_id) a la API.
            // Abre el modal de progreso y ejecuta el dispatch síncrono.
            this.progressFile = { name: job.original_name || job.file?.name || ('File #' + job.file_id), id: job.file_id, size_human: '' };
            this.progressStep = 'converting';
            this.progressError = null;
            this.progressElapsed = 0;
            this.progressStatus = null;
            this.progressResult = null;
            this.showProgress = true;
            this.progressTranscriptionId = job.id;
            this.progressPercent = 0;

            this.runDispatchNow(job).catch(e => {
                this.progressStep = 'error';
                this.progressError = (e && e.message) || 'Error al procesar';
            });
        },
        async runDispatchNow(job) {
            const t0 = Date.now();
            try {
                const res = await apiFetch('/ia/api-transcriptor/jobs/' + job.id + '/dispatch-now', {
                    method: 'POST', credentials: 'same-origin',
                    timeout: 600000,
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                this.progressElapsed = ((Date.now() - t0) / 1000).toFixed(1);
                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    this.progressStep = 'error';
                    this.progressError = data.error || 'Error al enviar el job';
                    this.progressPercent = 0;
                    return;
                }

                // Si ya estaba enviado, solo refrescar estado con polling.
                if (data.already_submitted) {
                    this.progressTranscriptionId = data.transcription_id;
                    this.progressStatus = { state: data.state, job_id: data.job_id, id: data.transcription_id, elapsed_seconds: this.progressElapsed };
                    this.progressStep = data.state === 'queued' ? 'queued' : 'processing';
                    this.progressPercent = 100;
                    this.startPolling();
                    return;
                }

                this.progressTranscriptionId = data.transcription_id;
                this.progressStatus = { state: data.state, job_id: data.job_id, id: data.transcription_id, elapsed_seconds: this.progressElapsed };

                if (data.state === 'done') {
                    this.progressStep = 'done';
                    this.progressPercent = 100;
                    this.pollStatus();
                } else if (data.state === 'queued' || data.state === 'processing') {
                    this.progressStep = data.state === 'queued' ? 'queued' : 'processing';
                    this.progressPercent = 100;
                    this.startPolling();
                } else if (data.state === 'error' || data.state === 'dead') {
                    this.progressStep = 'error';
                    this.progressError = 'Estado final: ' + data.state;
                    this.progressPercent = 0;
                }
            } catch (e) {
                this.progressStep = 'error';
                this.progressError = (e && e.message) || 'Error de conexión';
            } finally {
                this.load();
            }
        },
        openProgress(f) {
            if (f.has_transcription && !confirm('Este archivo ya tiene transcripción. ¿Reenviar de todos modos?')) return;
            this.progressFile = f;
            this.progressStep = 'converting'; // paso inicial: ffmpeg + submit en el backend
            this.progressError = null;
            this.progressElapsed = 0;
            this.progressStatus = null;
            this.progressResult = null;
            this.showProgress = true;
            this.progressTranscriptionId = null;

            // Ejecutar el job SÍNCRONAMENTE (un solo request HTTP, hasta ~60s).
            // Mientras corre, el modal muestra "Convirtiendo..." y "Enviando a la API...".
            this.dispatchSyncTranscription(f).catch(e => {
                this.progressStep = 'error';
                this.progressError = (e && e.message) || 'Error al procesar';
            });
        },
        async dispatchSyncTranscription(f) {
            const t0 = Date.now();
            this.progressPercent = 0;
            // Polling del progreso REAL del backend (ffmpeg + upload).
            let progressKey = null;
            this._progressTimer = setInterval(async () => {
                if (progressKey) {
                    try {
                        const r = await apiFetch('/ia/api-transcriptor/transcribe/progress/' + progressKey, { headers: { 'Accept': 'application/json' } });
                        if (r.ok) {
                            const p = await r.json();
                            if (typeof p.percent === 'number') this.progressPercent = p.percent;
                            if (p.phase === 'converting') this.progressStep = 'converting';
                            else if (p.phase === 'uploading') this.progressStep = 'uploading';
                            else if (p.phase === 'queued') this.progressStep = 'queued';
                        }
                    } catch {}
                }
            }, 500);

            try {
                const res = await apiFetch('/ia/api-transcriptor/transcribe/' + f.id, {
                    method: 'POST', credentials: 'same-origin',
                    timeout: 600000,
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
                this.progressElapsed = elapsed;
                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    this.progressStep = 'error';
                    this.progressError = data.error || 'Error al procesar el archivo';
                    this.progressPercent = 0;
                    return;
                }

                progressKey = data.progress_key;
                this.progressTranscriptionId = data.transcription_id;
                this.progressStatus = { state: data.state, job_id: data.job_id, id: data.transcription_id, elapsed_seconds: elapsed };

                if (data.state === 'done') {
                    this.progressStep = 'done';
                    this.progressPercent = 100;
                    this.markFileTranscribed();
                    this.pollStatus();
                } else if (data.state === 'queued' || data.state === 'processing') {
                    this.progressStep = data.state === 'queued' ? 'queued' : 'processing';
                    this.progressPercent = 100;
                    this.startPolling();
                } else if (data.state === 'error' || data.state === 'dead') {
                    this.progressStep = 'error';
                    this.progressError = 'Estado final: ' + data.state;
                    this.progressPercent = 0;
                }
            } finally {
                clearInterval(this._progressTimer);
                this._progressTimer = null;
            }
        },
        startPolling() {
            this.stopPolling();
            this.progressTimer = setInterval(() => this.pollStatus(), 2000);
            this.pollStatus();
        },
        stopPolling() {
            if (this.progressTimer) { clearInterval(this.progressTimer); this.progressTimer = null; }
        },
        async pollStatus() {
            if (!this.progressTranscriptionId) return;
            try {
                const res = await apiFetch('/ia/api-transcriptor/jobs/' + this.progressTranscriptionId + '/status', {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                const s = await res.json();
                this.progressStatus = s;
                this.progressElapsed = s.elapsed_seconds ?? this.progressElapsed + 2;
                // Mapear estado a paso del timeline
                if (s.state === 'queued') this.progressStep = 'queued';
                else if (s.state === 'processing') this.progressStep = 'processing';
                else if (s.state === 'done') {
                    this.progressStep = 'done';
                    this.progressResult = s;
                    this.stopPolling();
                    this.markFileTranscribed();
                } else if (s.state === 'error' || s.state === 'dead') {
                    this.progressStep = 'error';
                    this.progressError = s.error_message || ('Estado: ' + s.state);
                    this.stopPolling();
                }
            } catch (e) {
                // continuar polling
            }
        },
        markFileTranscribed() {
            // Marcar el archivo como transcrito en la UI local
            if (this.progressFile) this.progressFile.has_transcription = true;
        },
        closeProgress() {
            this.stopPolling();
            this.showProgress = false;
            this.progressFile = null;
            this.progressStatus = null;
            this.progressResult = null;
            this.progressError = null;
            this.progressTranscriptionId = null;
        },
        async bulkSendSelected() {
            if (this.bulkSending) return;
            const visible = this.visibleFiles();
            const pending = [];
            const skipped = [];
            for (const id of this.selectedFileIds) {
                const f = visible.find(x => Number(x.id) === Number(id));
                if (!f) continue;
                if (f.has_transcription) skipped.push(f);
                else pending.push(f);
            }
            if (pending.length === 0 && skipped.length === 0) {
                this.bulkResult = { sent: 0, errors: 0, skipped: 0, total: 0 };
                return;
            }
            this.bulkSending = true;
            this.bulkResult = null;
            const csrf = document.querySelector('meta[name=csrf-token]').content;

            // Pool acotado. Antes esto era Promise.allSettled(pending.map(...)),
            // sin tope: cada request corre ffmpeg + POST SINCRONOS dentro de
            // php-fpm (transcribeFile, set_time_limit(600)), asi que seleccionar
            // 200 archivos levantaba 200 procesos php-fpm y 200 ffmpeg a la vez.
            const limit = Math.max(1, Number(this.uiMaxParallelSends) || 3);
            let idx = 0, sent = 0, errors = 0;
            const runners = Array.from({ length: Math.min(limit, pending.length) }, async () => {
                while (idx < pending.length) {
                    const f = pending[idx++];
                    try {
                        const r = await fetch('/ia/api-transcriptor/transcribe/' + f.id, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        });
                        if (r.ok) {
                            sent++;
                            f.has_transcription = true;
                        } else {
                            errors++;
                        }
                    } catch (e) {
                        errors++;
                    }
                    // Progreso incremental: el modal lo consume mientras corre.
                    this.bulkProgress = { done: sent + errors, total: pending.length };
                }
            });
            await Promise.all(runners);

            this.bulkProgress = null;
            this.bulkResult = { sent, errors, skipped: skipped.length, total: pending.length + skipped.length };
            this.bulkSending = false;
            this.load();
            this.loadFiles();
        },
        async bulkDispatchPending() {
            if (this.bulkDispatching) return;

            // Sin modo selección se envía el body SIN ids y el servidor
            // autoselecciona hasta 2000 pendientes. Mandar los ids de la página
            // limitaría el lote al tamaño de página (50) en vez de vaciar la cola.
            let ids = null;
            if (this.selectJobMode && this.selectedJobIds.size > 0) {
                ids = (this.jobs || [])
                    .filter(j => this.selectedJobIds.has(Number(j.id)) && this.isDispatchable(j))
                    .map(j => Number(j.id));
                if (ids.length === 0) {
                    this.bulkDispatchResult = {
                        enqueued: 0, skipped_queued: 0, errors: 0,
                        message: 'No hay trabajos dispatchable seleccionados.',
                    };
                    return;
                }
            } else if (this.jobsPendingCount === 0) {
                this.bulkDispatchResult = {
                    enqueued: 0, skipped_queued: 0, errors: 0,
                    message: 'No hay trabajos pendientes por encolar.',
                };
                return;
            }

            this.bulkDispatching = true;
            this.bulkDispatchResult = null;
            const csrf = document.querySelector('meta[name=csrf-token]').content;
            try {
                const res = await fetch('/ia/api-transcriptor/jobs/bulk-dispatch', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(ids ? { ids } : {}),
                });
                const data = await res.json().catch(() => ({}));
                if (res.status === 503) {
                    // Redis caído parcial
                    this.bulkDispatchResult = {
                        enqueued: data.enqueued ?? 0,
                        skipped_queued: data.skipped_queued ?? 0,
                        errors: data.errors ?? 1,
                        message: 'Redis no disponible — reintenta en unos segundos.',
                    };
                    showToast(this.bulkDispatchResult.message, 'warning', 5000);
                    return;
                }
                if (res.status === 422) {
                    const msg = (data.message || data.errors?.ids?.[0] || 'Validación fallida') + '';
                    this.bulkDispatchResult = { enqueued: 0, skipped_queued: 0, errors: 1, message: msg };
                    showToast(msg, 'error');
                    return;
                }
                if (!res.ok) {
                    this.bulkDispatchResult = {
                        enqueued: 0, skipped_queued: 0, errors: 1,
                        message: data.error || `Error HTTP ${res.status}`,
                    };
                    showToast(this.bulkDispatchResult.message, 'error');
                    return;
                }
                this.bulkDispatchResult = {
                    enqueued: data.enqueued ?? 0,
                    skipped_queued: data.skipped_queued ?? 0,
                    errors: data.errors ?? 0,
                };
                if (data.enqueued > 0 || data.errors === 0) {
                    showToast(`${data.enqueued} archivo(s) encolado(s), ${data.skipped_queued} saltado(s), ${data.errors} error(es).`, 'success');
                }
            } catch (e) {
                this.bulkDispatchResult = {
                    enqueued: 0, skipped_queued: 0, errors: 1,
                    message: (e && e.message) || 'Error de conexión',
                };
                showToast(this.bulkDispatchResult.message, 'error');
            } finally {
                this.selectedJobIds = new Set();
                this.selectJobMode = false;
                this.bulkDispatching = false;
                await this.load();
            }
        },
        async refreshJobStatus(job) {
            if (!job || !job.id) return;
            if (this.refreshingJobs && this.refreshingJobs.has(Number(job.id))) return;
            if (!this.refreshingJobs) this.refreshingJobs = new Set();
            this.refreshingJobs.add(Number(job.id));
            const csrf = document.querySelector('meta[name=csrf-token]').content;
            try {
                const r = await fetch('/ia/api-transcriptor/jobs/' + job.id + '/refresh-status', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                });
                const data = await r.json().catch(() => ({}));
                if (!r.ok) {
                    showToast(data.error || ('Error HTTP ' + r.status), 'error');
                    return;
                }
                // Antes solo se recargaba la lista: la fila desaparecia de
                // "En proceso" y el contador bajaba sin decir por que, lo que
                // se leia como "el boton lo envio a la API". No envia nada,
                // solo pregunta el estado — decirlo explicitamente.
                if (data.outcome_message) {
                    const tone = data.outcome === 'done' ? 'success'
                               : (data.outcome === 'pending' ? 'info' : 'error');
                    showToast(data.outcome_message, tone);
                }
                await this.load();
            } catch (e) {
                showToast('Error de conexión: ' + (e?.message || ''), 'error');
            } finally {
                this.refreshingJobs.delete(Number(job.id));
            }
        },
        openBatchModal() {
            this.batchResult = null;
            this.batchRunning = false;
            this.showBatchModal = true;
            // transcriptor-scan-scope-selector: estimar al abrir (modo vigente).
            this.$nextTick(() => this.refreshBatchEstimate());
        },
        closeBatchModal() {
            this.stopBatchPolling();
            this.showBatchModal = false;
            this.batchResult = null;
            this.batchRunning = false;
            this.batchProgress = null;
            this.batchRunId = null;
            this.batchEstimate = null;
            this.batchEstimateError = null;
        },
        // transcriptor-scan-scope-selector: consulta la estimación para el
        // alcance elegido (debounce interno de 400ms vía timer).
        async refreshBatchEstimate() {
            if (this.batchScope === 'range') {
                if (!this.batchScopeFrom || !this.batchScopeTo) { this.batchEstimate = null; return; }
                if (this.batchScopeFrom > this.batchScopeTo) {
                    this.batchEstimateError = 'La fecha "desde" es posterior a "hasta"';
                    this.batchEstimate = null;
                    return;
                }
            }
            if (this.batchEstimateLoading) return;
            this.batchEstimateLoading = true;
            this.batchEstimateError = null;
            try {
                const res = await apiFetch('/ia/api-transcriptor/scan/estimate', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        mode: this.batchScope,
                        from: this.dmYToIso(this.batchScopeFrom),
                        to: this.dmYToIso(this.batchScopeTo),
                        include_done: this.batchIncludeDone,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.batchEstimateError = data.error || 'Error al estimar';
                    this.batchEstimate = null;
                    return;
                }
                this.batchEstimate = data;
                this.batchEstimateError = null;
            } catch (e) {
                this.batchEstimateError = 'Error de conexión al estimar';
            } finally {
                this.batchEstimateLoading = false;
            }
        },
        // Convierte DDMMYYYY del input a YYYY-MM-DD para el estimador.
        // El input date del navegador ya da ISO; acepta ambos por robustez.
        dmYToIso(v) {
            const s = String(v || '').trim();
            const m = s.match(/^(\d{2})(\d{2})(\d{4})$/);
            if (m) return m[3] + '-' + m[2] + '-' + m[1];
            return s; // ya ISO (YYYY-MM-DD)
        },
        batchScopeValid() {
            if (this.batchScope === 'range') {
                return this.batchScopeFrom && this.batchScopeTo
                    && this.dmYToIso(this.batchScopeFrom) <= this.dmYToIso(this.batchScopeTo);
            }
            return true;
        },
        stopBatchPolling() {
            if (this.batchPollTimer) { clearInterval(this.batchPollTimer); this.batchPollTimer = null; }
            this.batchTableRefreshTick = 0;
        },
        async focusBgJob() {
            try {
                const params = new URLSearchParams(window.location.search);
                const focus = params.get('focus');
                if (!focus || !focus.startsWith('bg-transcriptor-batch-')) return;
                const runId = focus.replace('bg-transcriptor-batch-', '');
                if (!runId) return;
                // Si el modal ya está abierto (otro flow), no duplicar
                if (this.batchRunId === runId && this.batchRunning) return;
                this.batchRunId = runId;
                this.batchRunning = true;
                this.batchProgress = null;
                this.batchResult = null;
                this.showBatchModal = true;
                // Polling del run existente
                if (this.batchPollTimer) clearInterval(this.batchPollTimer);
                this.batchPollTimer = setInterval(() => this.pollBatch(), 2000);
                this.pollBatch();
            } catch (e) { /* silent */ }
        },
        async runBatch() {
            this.batchRunning = true;
            this.batchResult = null;
            this.batchProgress = null;
            this.batchExpanded = false;
            // bg-job-indicator-hide-completed: cerrar el modal bloqueante de
            // configuración/progreso para que la barra inline tome el control.
            // El operador puede volver a abrir el modal con el botón "Escanear
            // storages" si necesita reconfigurar otro batch.
            this.showBatchModal = false;
            const startPolling = (runId) => {
                this.batchRunId = runId;
                if (this.batchPollTimer) clearInterval(this.batchPollTimer);
                this.batchPollTimer = setInterval(() => this.pollBatch(), 2000);
                this.pollBatch();
            };
            const fetchPromise = apiFetch('/ia/api-transcriptor/process-batch', {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({
                    batch: this.batchSize,
                    generate_alerts: this.batchAlerts,
                    include_failed: this.batchIncludeFailed,
                    include_done: this.batchIncludeDone,
                    scope: this.batchScope === 'range'
                        ? { mode: 'range', from: this.batchScopeFrom, to: this.batchScopeTo }
                        : (this.batchScope === 'all' ? { mode: 'all' } : undefined),
                }),
            });

            // Watchdog blando: si la respuesta HTTP tarda más de `WATCHDOG_MS`
            // seguimos mostrando "Iniciando proceso en background..." en la UI
            // (eso ya lo hace `batchRunning = true`), pero NO cortamos el fetch.
            // El fetch original puede tardar tranquilamente hasta `MAX_FETCH_MS`
            // antes de considerarlo perdido. Esto reemplaza el `Promise.race`
            // anterior que rechazaba el fetch a los 5s y creaba un `runId`
            // sintético, dejando el polling ciego contra cache inexistente.
            const WATCHDOG_MS = 5000;   // solo cosmetic feedback
            const MAX_FETCH_MS = 30000; // real network timeout
            try {
                const res = await Promise.race([
                    fetchPromise,
                    new Promise((_, reject) => setTimeout(() => reject(new Error('watchdog-timeout')), WATCHDOG_MS))
                ]);
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    showToast(data.error || 'Error al iniciar el lote', 'error');
                    this.batchRunning = false;
                    return;
                }
                startPolling(data.run_id);
            } catch (watchdogErr) {
                // Watchdog "cosmetic": el fetch probablemente está en vuelo (latencia
                // de red, bootstrap de PHP-FPM). No lo cancelamos; seguimos
                // mostrando el spinner y esperamos la respuesta REAL.
                if (watchdogErr?.message === 'watchdog-timeout') {
                    console.warn('runBatch: watchdog cosmetic disparado a ' + WATCHDOG_MS + 'ms, esperando fetch real...');
                    try {
                        const res = await Promise.race([
                            fetchPromise,
                            new Promise((_, reject) => setTimeout(() => reject(new Error('fetch-timeout')), MAX_FETCH_MS))
                        ]);
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            showToast(data.error || 'Error al iniciar el lote', 'error');
                            this.batchRunning = false;
                            return;
                        }
                        if (data?.run_id) {
                            startPolling(data.run_id);
                            return;
                        }
                        // res.ok pero sin run_id (respuesta inesperada)
                        this.batchResult = {
                            processed: 0, errors: 1, total_candidates: 0,
                            storages: [], files: [], per_storage_errors: [],
                            message: 'El servidor respondió 200 pero sin run_id. Revisá los logs.',
                        };
                        this.batchRunning = false;
                        return;
                    } catch (realErr) {
                        // Fetch original falló después del watchdog: timeout de red
                        // total, 5xx no recuperable, error de CSRF, etc.
                        // NO inventamos un run_id sintético: mostramos error
                        // accionable y dejamos que el modal cierre limpio.
                        this.batchResult = {
                            processed: 0,
                            errors: 1,
                            total_candidates: 0,
                            storages: [],
                            files: [],
                            per_storage_errors: [],
                            message: 'Sin respuesta del servidor después de ' + Math.round(MAX_FETCH_MS/1000) + 's. ' +
                                     (realErr?.message === 'fetch-timeout'
                                       ? 'El endpoint no respondió a tiempo (revisá /tmp/kilo_artisan_bg.log, filtro [transcriptor:scan]).'
                                       : 'Error de conexión: ' + (realErr?.message || 'revisá los logs del servidor.')),
                        };
                        this.batchRunning = false;
                    }
                } else {
                    // Error inmediato del fetch (red caída antes del watchdog)
                    this.batchResult = {
                        processed: 0,
                        errors: 1,
                        total_candidates: 0,
                        storages: [],
                        files: [],
                        per_storage_errors: [],
                        message: 'Error de conexión: ' + (watchdogErr?.message || 'sin respuesta del servidor. Reintenta o revisa los logs.'),
                    };
                    this.batchRunning = false;
                }
            }
        },
        confirmProcessFolder() {
            this.processConfirmText = 'Procesar carpeta actual';
            this.processConfirmAction = 'folder';
            this.showProcessConfirm = true;
        },
        confirmProcessDay() {
            this.processConfirmText = 'Procesar ' + (this.filesMode === 'today' ? 'HOY' : 'AYER');
            this.processConfirmAction = 'day';
            this.processAlerts = false;
            this.showProcessConfirm = true;
        },
        async executeProcessConfirm() {
            const action = this.processConfirmAction;
            this.showProcessConfirm = false;
            if (action === 'folder') {
                await this.processFolder(this.currentParent);
            } else if (action === 'day') {
                await this.processDay(this.filesMode);
            }
        },
        async processFolder(parentId) {
            if (!this.currentStorage) return;
            try {
                const res = await apiFetch('/ia/api-transcriptor/storages/' + this.currentStorage.id + '/process-folder', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ parent_id: parentId ?? null, generate_alerts: this.processAlerts }),
                });
                const d = await res.json().catch(() => ({}));
                if (res.ok) {
                    showToast('Encolados ' + d.dispatched + ' archivos de ' + d.candidates + ' candidatos.', 'success');
                    await this.loadFiles();
                } else {
                    showToast(d.error || 'Error al procesar carpeta', 'error');
                }
            } catch (e) { showToast('Error de conexión', 'error'); }
        },
        async processDay(mode) {
            if (!this.currentStorage) return;
            try {
                const res = await apiFetch('/ia/api-transcriptor/storages/' + this.currentStorage.id + '/process-day', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ mode: mode, generate_alerts: this.processAlerts }),
                });
                const d = await res.json().catch(() => ({}));
                if (res.ok) {
                    showToast('Encolados ' + d.dispatched + ' archivos de ' + d.candidates + ' candidatos.', 'success');
                    await this.loadFiles();
                } else {
                    showToast(d.error || 'Error al procesar día', 'error');
                }
            } catch (e) { showToast('Error de conexión', 'error'); }
        },
        async pollBatch() {
            if (!this.batchRunId) return;
            try {
                const res = await apiFetch('/ia/api-transcriptor/batch-status/' + this.batchRunId, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                const data = await res.json();
                this.batchProgress = data;

                // Refrescar la tabla de Pendientes en cada poll mientras el batch
                // corre, para que el usuario vea los nuevos jobs a medida que los
                // workers los crean (antes había que esperar al 'done' final).
                if (data.status === 'running' || data.status === 'starting') {
                    this.batchTableRefreshTick = (this.batchTableRefreshTick || 0) + 1;
                    // Throttle: refrescar la tabla solo cada 2do poll (~4s) para no
                    // spammear el endpoint. El modal sí se actualiza cada 2s.
                    if (this.batchTableRefreshTick % 2 === 0) {
                        this.load();
                    }
                }

                // Si termino (done/queued/error/partial/not_found), detener polling y mostrar resultados.
// 'queued' es el estado final cuando el batch-and-submit terminó exitosamente y encoló jobs a Redis.
                if (data.status === 'done' || data.status === 'queued' || data.status === 'error' || data.status === 'partial' || data.status === 'not_found') {
                    this.stopBatchPolling();
                    this.batchRunning = false;
                    if (data.status === 'done' || data.status === 'partial' || data.status === 'queued') {
                        this.batchResult = data;
                    } else if (data.status === 'not_found') {
                        this.batchResult = {
                            processed: 0, errors: 0, total_candidates: 0,
                            storages: [], files: [],
                            per_storage_errors: [],
                            message: data.message || 'El lote no fue encontrado o ya expiró (cache TTL 2h).'
                        };
                    } else {
                        const fallbackMsg = 'El lote terminó con errores. Revisa storage/logs/transcription-batch-' + this.batchRunId + '.log';
                        this.batchResult = {
                            processed: data.processed ?? 0,
                            errors: data.errors ?? 1,
                            total_candidates: data.total_candidates ?? 0,
                            storages: data.storages ?? [],
                            files: data.files ?? [],
                            per_storage_errors: data.per_storage_errors ?? [],
                            message: data.message || fallbackMsg,
                        };
                    }
                    this.batchTableRefreshTick = 0;
                    this.load();
                }
            } catch (e) {
                // continuar polling
            }
        },
        async cancelJob(job) {
            let confirmMsg;
            if (job.state === 'pending') {
                confirmMsg = '¿Borrar esta fila pendiente? El archivo subyacente NO se elimina, solo la entrada de transcripción que aún no fue enviada.';
            } else {
                confirmMsg = '¿Cancelar este job? Se cancelará también en la API externa si está en cola.';
            }
            if (!confirm(confirmMsg)) return;
            try {
                const res = await apiFetch('/ia/api-transcriptor/jobs/' + job.id + '/cancel', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                const d = await res.json().catch(() => ({}));
                if (!res.ok) { showToast(d.error || 'No se pudo cancelar', 'error'); return; }
                if (d.message) console.info('[transcriptor]', d.message);
                await this.load();
            } catch (e) { showToast('Error de conexión', 'error'); }
        },
        reprocessJob(job) {
            const label = job.original_name || job.file?.name || ('File #' + job.file_id);
            if (!confirm('¿Reprocesar "' + label + '"? Se borrará la transcripción actual y se enviará de nuevo.')) return;
            // Abrir modal de progreso y ejecutar reprocess síncrono.
            this.progressFile = { name: label, id: job.file_id, size_human: '' };
            this.progressStep = 'converting';
            this.progressError = null;
            this.progressElapsed = 0;
            this.progressStatus = null;
            this.progressResult = null;
            this.showProgress = true;
            this.progressTranscriptionId = job.id;
            this.progressPercent = 0;
            this.runReprocess(job).catch(e => {
                this.progressStep = 'error';
                this.progressError = (e && e.message) || 'Error al reprocesar';
            });
        },
        async runReprocess(job) {
            const t0 = Date.now();
            try {
                const res = await apiFetch('/ia/api-transcriptor/jobs/' + job.id + '/reprocess', {
                    method: 'POST', credentials: 'same-origin',
                    timeout: 600000,
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                this.progressElapsed = ((Date.now() - t0) / 1000).toFixed(1);
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.progressStep = 'error';
                    this.progressError = data.error || 'Error al reprocesar';
                    this.progressPercent = 0;
                    return;
                }
                this.progressTranscriptionId = data.transcription_id;
                this.progressStatus = { state: data.state, job_id: data.job_id, id: data.transcription_id, elapsed_seconds: this.progressElapsed };
                if (data.state === 'done') {
                    this.progressStep = 'done';
                    this.progressPercent = 100;
                    this.pollStatus();
                } else if (data.state === 'queued' || data.state === 'processing') {
                    this.progressStep = data.state === 'queued' ? 'queued' : 'processing';
                    this.progressPercent = 100;
                    this.startPolling();
                } else if (data.state === 'error' || data.state === 'dead') {
                    this.progressStep = 'error';
                    this.progressError = 'Estado final: ' + data.state;
                    this.progressPercent = 0;
                }
            } catch (e) {
                this.progressStep = 'error';
                this.progressError = (e && e.message) || 'Error de conexión';
            } finally {
                this.load();
            }
        },
        async syncStorage(s) {
            if (!s || s.type !== 'local') { showToast('Solo storages locales se pueden sincronizar.', 'warning'); return; }
            this.syncing = true;
            try {
                const res = await apiFetch('/ia/api-transcriptor/storages/' + s.id + '/sync', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                const d = await res.json();
                if (res.ok) {
                    showToast('Sync: +' + (d.created||0) + ' archivos nuevos, -' + (d.deleted||0) + ' eliminados.', 'success');
                    await this.loadFiles();
                } else {
                    showToast(d.error || 'No se pudo sincronizar', 'error');
                }
            } finally { this.syncing = false; }
        },
        stateClass(state) {
            return {
                pending: 'bg-slate-200 text-slate-700',
                queued: 'bg-slate-100 text-slate-600',
                processing: 'bg-blue-100 text-blue-700',
                done: 'bg-green-100 text-green-700',
                error: 'bg-red-100 text-red-700',
                dead: 'bg-red-900 text-red-100',
            }[state] || 'bg-slate-100 text-slate-600';
        },
        stateDot(state) {
            return {
                pending: 'bg-slate-500',
                queued: 'bg-slate-400',
                processing: 'bg-blue-500',
                done: 'bg-green-500',
                error: 'bg-red-500',
                dead: 'bg-red-300',
            }[state] || 'bg-slate-400';
        },
        formatDate(d) {
            if (!d) return '—';
            return new Date(d).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
        },
        formatSize(bytes) {
            bytes = bytes || 0;
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        },
        formatDuration(seconds) {
            const s = Number(seconds) || 0;
            if (!s) return '—';
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const sec = s % 60;
            if (h > 0) return h + ' h ' + m + ' min';
            if (m > 0) return m + ' min ' + sec + ' s';
            return sec + ' s';
        },

        // ------------------------------------------- modal "Ver transcripción"

        async openTranscript(job) {
            this.transcript = { open: true, loading: true, error: null, job, view: 'texto', q: '', data: null };
            try {
                const res = await apiFetch('/ia/api-transcriptor/jobs/' + job.id + '/transcript', {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) {
                    this.transcript.error = 'No se pudo cargar la transcripción (HTTP ' + res.status + ').';
                    return;
                }
                this.transcript.data = await res.json();
            } catch (e) {
                this.transcript.error = 'Error de red: ' + e.message;
            } finally {
                this.transcript.loading = false;
            }
        },
        closeTranscript() {
            if (!this.transcript.open) return;
            this.transcript.open = false;
        },
        visibleTranscriptSegments() {
            const segs = this.transcript.data?.segments || [];
            const q = (this.transcript.q || '').trim().toLowerCase();
            if (!q) return segs;
            return segs.filter(s => (s.text || '').toLowerCase().includes(q));
        },
        /**
         * Resalta `q` dentro de `text`. Escapa SIEMPRE el texto antes de inyectar
         * el <mark>: va a un x-html y el contenido viene de audio transcrito.
         */
        highlight(text, q) {
            const escape = (s) => String(s ?? '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const safe = escape(text);
            const needle = (q || '').trim();
            if (!needle) return safe;
            const pattern = escape(needle).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return safe.replace(new RegExp(pattern, 'gi'),
                m => '<mark class="bg-amber-200 text-slate-900 rounded px-0.5">' + m + '</mark>');
        },
        async copyTranscript() {
            const text = this.transcript.data?.plain_text || '';
            if (!text) return;
            try {
                await navigator.clipboard.writeText(text);
                showToast('Transcripción copiada al portapapeles', 'success');
            } catch {
                showToast('El navegador bloqueó el portapapeles', 'warning');
            }
        },
        downloadTranscriptSrt() {
            const srt = this.transcript.data?.srt_content || '';
            if (!srt) return;
            const blob = new Blob([srt], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'transcripcion_' + (this.transcript.job?.id || 'sin_id') + '.srt';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        },
    };
}
</script>
@endpush
@endsection
