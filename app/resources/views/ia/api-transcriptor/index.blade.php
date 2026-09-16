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
        {{-- Procesamiento personalizado: recupera históricos (sin fila, con
             error, completados) sin esperar al tick. El envío lo sigue
             regulando el pipeline. --}}
        <button @click="openPz()"
                class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-medium transition-colors shadow-sm">
            <i class="fas fa-clock-rotate-left text-brand-500 mr-1.5"></i> Procesar históricos
        </button>
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

    <!-- Tarjetas resumen del modulo storages (solo volumen; el funnel por
         storage vive en las columnas de la tabla y los errores del dia en la
         celda Snapshot. Tarjetas "Pendientes hoy" / "Listos hoy" retiradas:
         mezclaban error/dead con pendientes reales y no accionaban nada.
         Ver change fix-storages-tab-cards-and-dead-retry-button. -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
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
    </div>

<!-- Tabla de storages -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3 flex-wrap">
                <h2 class="text-sm font-semibold text-slate-700">Storages</h2>
                {{-- Botón "Reintentar fallidos (upstream batch)" retirado: su ruta
                     POST /ia/api-transcriptor/retry-batch fue eliminada en el
                     change simplify-api-transcriptor-to-storage-and-config y
                     respondía 404. La recuperación masiva vive en el cron semanal
                     transcription:retry-batch-upstream (lunes 04:00) y en CLI. --}}
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
                {{-- Errores hoy: suma reactiva de los error_count de los snapshots
                     por fila de la página visible (ver snapshotErrorsTotal). --}}
                <span class="text-xs whitespace-nowrap tabular-nums font-medium"
                      :class="snapshotErrorsTotal() > 0 ? 'text-red-600' : 'text-slate-400'"
                      title="Suma de errores del día según el último snapshot por storage (página visible). Se actualiza cada 15 min con el snapshot.">
                    <i class="fas fa-triangle-exclamation text-[10px]"></i>
                    Errores hoy: <span x-text="snapshotErrorsTotal()"></span>
                </span>
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
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-right"
                        title="Conteo en vivo del funnel de pendientes por storage. Calculado en zona America/Bogota (helper BogotaTime::todayStart()).">
                        <button type="button" @click="setStoragesSort('pending')"
                                :class="storagesSortHeaderClass('pending') + ' inline-flex items-center gap-1.5 transition-colors ml-auto'"
                                title="Ordenar por pendientes">
                            Pendientes (live)
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
                    <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-center"
                        title="Archivos de hoy en este storage (con transcripción habilitada) que todavía no tienen fila de descubrimiento. Se resuelven en los próximos ciclos del escáner.">
                        <button type="button" @click="setStoragesSort('missing')"
                                :class="storagesSortHeaderClass('missing') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por archivos sin fila de transcripción">
                            Sin fila
                            <i class="fas text-[10px]" :class="storagesSortIcon('missing') + ' ' + storagesSortIconClass('missing')"></i>
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
                                <span class="w-3 inline-block"></span>
                                <span x-text="s.name"></span>
                                <template x-if="s.overlap_warning">
                                    <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full font-semibold"
                                          title="Este storage tiene allow_parent_overlap=true y descendientes habilitados. Los conteos pueden sumar de más si ambos escanean los mismos archivos.">⚠ solapamiento</span>
                                </template>
                                <template x-if="s.descendant_count > 0">
                                    <button type="button" @click.stop="openDescendantsModal(s)"
                                            class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-700 rounded-full font-semibold cursor-pointer hover:bg-brand-100 hover:text-brand-700 transition-colors"
                                            :title="'Ver los ' + s.descendant_count + ' hijos de este storage'"
                                            x-text="s.descendant_count + ' hijo(s)'"></button>
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
                            {{-- Archivos del día en este storage, con transcripción
                                 habilitada, que aún NO tienen fila de descubrimiento.
                                 Antes no existía señal alguna de ellos: el funnel hacía
                                 INNER JOIN con transcriptions y los ocultaba. --}}
                            <span x-show="(s.funnel?.missing ?? 0) > 0"
                                  class="px-2 py-0.5 rounded bg-amber-50 text-amber-800 text-[10px] font-semibold tabular-nums"
                                  :title="'Archivos de hoy sin fila de transcripción (huecos de descubrimiento)'"
                                  x-text="s.funnel.missing"></span>
                            <span x-show="(s.funnel?.missing ?? 0) === 0" class="text-[10px] text-slate-300">—</span>
                        </td>
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
                        {{-- Columna Acciones retirada en change remove-api-transcriptor-orphan-files-modal:
                             el botón "Ver archivos" apuntaba a endpoints /storages/{id}/files, /process-folder
                             y /process-day que fueron eliminados en 2026-09-15-simplify-api-transcriptor-to-storage-and-config.
                             Sin endpoint vivo, el modal abría vacío. Ver spec archivada transcriptor-storage-files-srt-link. --}}

                        {{-- Snapshot transcriptor (transcriptor-pg-native-queue): tarjeta con el ultimo snapshot
                             y delta vs el anterior. Solo si transcription_enabled=true; si no, "N/A". --}}
                        <td class="py-3 pr-3 text-xs"
                            :title="s.transcription_enabled ? 'Snapshot del transcriptor por storage (15min)' : 'Transcripcion deshabilitada'">
                            <template x-if="s.transcription_enabled">
                                <div x-data="{ snapshot: null, loading: false, fetch() { if (this.snapshot) return; this.loading = true; fetch('/ia/api-transcriptor/storages/' + s.id + '/snapshot').then(r => r.ok ? r.json() : null).then(d => { this.snapshot = d; this.loading = false; if (d && d.current && d.current.error_count != null) $data.snapshotErrors[s.id] = Number(d.current.error_count); }).catch(() => { this.loading = false; }); } }"
                                     x-init="fetch()"
                                     class="text-slate-600">
                                    <template x-if="loading">
                                        <div class="flex items-center gap-1 text-slate-400">
                                            <i class="fas fa-spinner fa-spin text-[10px]"></i>
                                            <span>cargando…</span>
                                        </div>
                                    </template>
                                     <template x-if="!loading && snapshot && snapshot.current">
                                         <div class="space-y-0.5">
                                             <div class="flex items-center gap-1">
                                                 <span class="font-semibold text-slate-700 tabular-nums" x-text="snapshot.current.pending_count"></span>
                                                 <span class="text-slate-500">pendientes</span>
                                             </div>
                                             <div class="flex items-center gap-1 text-[10px] text-slate-400">
                                                 <span x-text="snapshot.delta && snapshot.delta.pending_count != null ? (snapshot.delta.pending_count > 0 ? '+' + snapshot.delta.pending_count : snapshot.delta.pending_count) + ' vs 15min' : 'primer snapshot'"></span>
                                             </div>
                                             <div class="flex items-center gap-1 text-[10px] text-slate-400">
                                                 <i class="fas fa-clock text-[9px]"></i>
                                                 <span x-text="'cola remota: ' + (snapshot.current.remote_queue_queued != null ? snapshot.current.remote_queue_queued : '—')"></span>
                                             </div>
                                             {{-- Errores del dia (change fix-storages-tab-cards-and-dead-retry-button):
                                                  del campo error_count del snapshot. Para rol cliente el
                                                  endpoint NO devuelve error_count -> la linea no se renderiza. --}}
                                             <template x-if="snapshot.current.error_count != null && snapshot.current.error_count > 0">
                                                 <div class="flex items-center gap-1 text-[10px] text-red-600 font-semibold"
                                                      :title="'error_count=' + snapshot.current.error_count + ' en snapshot ' + (snapshot.current.captured_at || '')">
                                                     <i class="fas fa-triangle-exclamation text-[9px]"></i>
                                                     <span x-text="snapshot.current.error_count + (snapshot.current.error_count === 1 ? ' error hoy' : ' errores hoy')"></span>
                                                 </div>
                                             </template>
                                         </div>
                                     </template>
                                    <template x-if="!loading && !snapshot">
                                        <span class="text-slate-400">Sin datos</span>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!s.transcription_enabled">
                                <span class="text-slate-300">N/A</span>
                            </template>
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


    </div> {{-- /TAB STORAGES --}}

    {{-- Modal: hijos de un storage root --}}
    <div x-show="descendantsModal.open"
         x-transition.opacity
         @keydown.escape.window="closeDescendantsModal()"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="display:none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="closeDescendantsModal()"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-2xl border border-slate-200 flex flex-col max-h-[80vh]">
            <div class="flex items-start justify-between px-5 py-4 border-b border-slate-200">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        <i class="fas fa-sitemap text-slate-400 mr-2"></i>
                        Hijos de <span class="text-brand-700" x-text="descendantsModal.root?.name"></span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-1">
                        <span x-text="(descendantsModal.items || []).length"></span> storage(s) descendiente(s)
                        con transcripción habilitada dentro del alcance de este root.
                    </p>
                </div>
                <button type="button" @click="closeDescendantsModal()"
                        class="text-slate-400 hover:text-slate-700 transition-colors p-1"
                        title="Cerrar">
                    <i class="fas fa-times text-base"></i>
                </button>
            </div>
            <div class="overflow-y-auto px-5 py-4">
                <template x-if="(descendantsModal.items || []).length === 0">
                    <p class="text-sm text-slate-500 text-center py-6">
                        Este root no tiene descendientes habilitados para transcripción.
                    </p>
                </template>
                <template x-if="(descendantsModal.items || []).length > 0">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="child in descendantsModal.items" :key="child.id">
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="fas fa-folder text-slate-300 text-xs"></i>
                                    <span class="text-sm font-medium text-slate-700 truncate" x-text="child.name"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded-full tabular-nums"
                                          x-text="child.cantidad ?? 1"
                                          :title="'Medios en este storage: ' + (child.cantidad ?? 1)"></span>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-slate-500 tabular-nums shrink-0">
                                    <span>
                                        <span class="text-slate-400">Pend:</span>
                                        <span class="font-semibold text-slate-700" x-text="child.funnel?.pending ?? 0"></span>
                                    </span>
                                    <span>
                                        <span class="text-slate-400">Hechos:</span>
                                        <span class="font-semibold text-slate-700" x-text="child.funnel?.done ?? 0"></span>
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full" :class="child.transcription_enabled ? 'bg-green-500' : 'bg-slate-400'"></span>
                                        <span x-text="child.transcription_enabled ? 'On' : 'Off'"></span>
                                    </span>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
            <div class="flex justify-end px-5 py-3 border-t border-slate-200 bg-slate-50">
                <button type="button" @click="closeDescendantsModal()"
                        class="px-3 py-1.5 text-sm font-medium bg-white border border-slate-200 rounded-lg hover:bg-slate-100 transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

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
        descendantsModal: { open: false, root: null, items: [] },
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
        // ------------------------------------------------------------------
        // Procesamiento personalizado ("Procesar históricos")
        //
        // Descubrimiento + encolado manual acotado por alcance, para recuperar
        // históricos sin esperar al tick. El envío sigue regulado por el
        // pipeline (stager + worker PG + histéresis de cola remota): estos
        // campos solo controlan el DESCUBRIMIENTO.
        // ------------------------------------------------------------------
        pzOpen: false,
        pzRunning: false,
        pzScope: 'range',
        pzFrom: new Date(Date.now() - 86400000).toISOString().slice(0, 10),
        pzTo: new Date().toISOString().slice(0, 10),
        pzIncludeMissing: true,
        pzIncludeFailed: false,
        pzIncludeDone: false,
        pzAlerts: true,
        pzBatch: 0,
        pzEstimate: null,
        pzEstimating: false,
        pzError: null,
        pzProgress: null,
        pzResult: null,
        pzRunId: null,
        pzPollTimer: null,
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
            // Hidratar el estado plegable de los grupos de Configuración desde
            // localStorage. Idempotente: si ya está hidratado, no-op.
            this.hydrateGroupOpen();
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

        cfgGroupsOrder: ['ritmo', 'staging', 'descubrimiento', 'api', 'workers', 'saturacion', 'burst', 'webhook', 'confiabilidad', 'ia', 'ui'],
        cfgGroupLabels: {
            ritmo: 'Ritmo de envío',
            staging: 'Staging local (RAM disk)',
            descubrimiento: 'Descubrimiento',
            api: 'API del transcriptor',
            workers: 'Pool de workers',
            saturacion: 'Defensa contra saturación',
            burst: 'Ráfaga manual',
            webhook: 'Webhook entrante (experimental)',
            confiabilidad: 'Confiabilidad',
            ia: 'Pase de coherencia IA',
            ui: 'Interfaz',
        },
        cfgGroupHelps: {
            ritmo: 'Cuánto y cada cuánto se envía. Es lo que convierte la ráfaga en goteo.',
            staging: 'Fase 1 del pipeline: convierte con ffmpeg y deja el audio listo en /dev/shm. Baja el pico de CPU del host local porque el envío se desacopla de la conversión.',
            descubrimiento: 'Qué archivos encuentra el escáner y cuántos toma por ciclo.',
            api: 'Tiempos de espera y reintentos contra el transcriptor externo.',
            workers: 'Cuántos procesos consumen la cola. El tuner los ajusta cada 5 min.',
            saturacion: 'Circuit breaker, idempotency y backoff. Protege a la API upstream de nuestros reintentos cuando va mal.',
            burst: 'Solo aplica si ejecutas `transcription:burst-dispatch` a mano. El cron automático NO usa este flujo todavía.',
            webhook: 'Recepción alternativa de resultados por webhook en vez de polling. Off por defecto; requiere coordinación con la API upstream (Fase D).',
            confiabilidad: 'Recogida de resultados y cierre de lo que no se resuelve. No hay webhook activo: si nadie consulta, nada vuelve.',
            ia: 'Corrige con LLM los segmentos con inglés residual que el diccionario no cubre. Activo por defecto; usar LLM cuesta latencia y dinero, ajustá los topes si lo necesitás.',
            ui: 'Topes de la propia interfaz.',
        },
        cfgGroupIcons: {
            ritmo: 'fa-gauge-high',
            staging: 'fa-layer-group',
            descubrimiento: 'fa-magnifying-glass',
            api: 'fa-paper-plane',
            workers: 'fa-microchip',
            saturacion: 'fa-shield-halved',
            burst: 'fa-bolt',
            webhook: 'fa-link',
            confiabilidad: 'fa-shield-halved',
            ia: 'fa-brain',
            ui: 'fa-sliders',
        },
        // Badges de scope (a quién afecta el knob)
        scopeBadgeClass(scope) {
            const map = {
                local:  'bg-slate-100 text-slate-700',
                remoto: 'bg-purple-100 text-purple-700',
                mixto:  'bg-emerald-100 text-emerald-700',
            };
            return map[scope] || 'bg-slate-100 text-slate-700';
        },
        scopeBadgeLabel(scope) {
            const map = { local: 'Local', remoto: 'Remoto', mixto: 'Mixto' };
            return map[scope] || (scope || '');
        },
        // Badges de state (estado del knob). LIVE no se muestra.
        stateBadgeClass(state) {
            const map = {
                experimental: 'bg-amber-100 text-amber-700',
                manual:       'bg-blue-100 text-blue-700',
                obsoleto:     'bg-red-100 text-red-700',
            };
            return map[state] || '';
        },
        stateBadgeLabel(state) {
            const map = { experimental: 'Experimental', manual: 'Manual', obsoleto: 'Obsoleto' };
            return map[state] || '';
        },
        // Estado del acordeón de detalle por knob
        detailOpen: {},
        toggleDetail(k) {
            this.detailOpen = { ...this.detailOpen, [k]: !this.detailOpen[k] };
        },
        // Estado del acordeón de grupos (config). Persistido en localStorage.
        // Default: todos cerrados. El operador expande los que necesite con
        // un click en el header o con el botón "Expandir todo".
        groupOpen: {},
        groupOpenHydrated: false,
        DEFAULT_OPEN_GROUPS: [],
        CFG_GROUPS_STORAGE_KEY: 'tcloud:api-transcriptor:cfg-groups:v1',
        isGroupOpen(g) {
            // Un grupo está abierto a menos que explícitamente sea false.
            // Si groupOpen[g] === undefined → abierto (default).
            // Si groupOpen[g] === false → cerrado.
            // Si groupOpen[g] === true → abierto (explícito).
            const v = this.groupOpen[g];
            return v === false ? false : true;
        },
        toggleGroup(g) {
            this.groupOpen = { ...this.groupOpen, [g]: !this.isGroupOpen(g) };
            this.persistGroupOpen();
        },
        expandAllGroups() {
            const all = {};
            for (const g of this.cfgGroupsOrder) { all[g] = true; }
            this.groupOpen = all;
            this.persistGroupOpen();
        },
        collapseAllGroups() {
            const all = {};
            for (const g of this.cfgGroupsOrder) { all[g] = false; }
            this.groupOpen = all;
            this.persistGroupOpen();
        },
        hydrateGroupOpen() {
            if (this.groupOpenHydrated) return;
            let stored = null;
            try {
                const raw = localStorage.getItem(this.CFG_GROUPS_STORAGE_KEY);
                if (raw) stored = JSON.parse(raw);
            } catch (e) {
                stored = null;
            }
            const valid = new Set(this.cfgGroupsOrder);
            const next = {};
            if (stored && typeof stored === 'object') {
                // Podar: solo conservamos claves de grupos válidos y valores booleanos.
                for (const g of Object.keys(stored)) {
                    if (valid.has(g) && typeof stored[g] === 'boolean') {
                        next[g] = stored[g];
                    }
                }
            }
            // Si storage vacío o corrupto → caer al default (ritmo + staging abiertos).
            const hasAnyValidKey = Object.keys(next).length > 0;
            if (!hasAnyValidKey) {
                for (const g of this.DEFAULT_OPEN_GROUPS) { next[g] = true; }
            }
            this.groupOpen = next;
            this.groupOpenHydrated = true;
        },
        persistGroupOpen() {
            try {
                // Podar al escribir: solo claves de grupos válidos.
                const valid = new Set(this.cfgGroupsOrder);
                const pruned = {};
                for (const g of Object.keys(this.groupOpen)) {
                    if (valid.has(g)) pruned[g] = this.groupOpen[g];
                }
                localStorage.setItem(this.CFG_GROUPS_STORAGE_KEY, JSON.stringify(pruned));
            } catch (e) {
                // localStorage no disponible (modo privado, política IT): no-op silencioso.
            }
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
        // Inventario listo en RAM disk vs objetivo. Es la barra que importa hoy:
        // la lista de pendientes es ilimitada (todo el día), el amortiguador es
        // el inventario de audios ya convertidos.
        inventoryPct() {
            const f = this.cfgRuntime?.staging?.files ?? 0;
            const t = this.cfgRuntime?.staging?.target ?? 0;
            if (!t) return 0;
            return Math.round((f / t) * 100);
        },
        fmtPct(v) {
            if (v === null || v === undefined) return '—';
            const n = Number(v);
            if (Number.isNaN(n)) return '—';
            return n.toFixed(0) + '%';
        },
        // Clase de color por umbral. El llamador pasa los cortes que
        // corresponden a cada métrica (los porcentajes usan 90/75, temperaturas
        // 83/75, etc.): no hay una escala única que sirva a todas.
        metricClass(v, danger, warn) {
            const n = Number(v ?? 0);
            const level = n >= danger ? 'danger' : (n >= warn ? 'warn' : 'ok');
            const map = {
                ok:     'border-slate-200 bg-slate-50 text-slate-800',
                warn:   'border-amber-300 bg-amber-50 text-amber-800',
                danger: 'border-red-300 bg-red-50 text-red-800',
            };
            return map[level];
        },
        // Devuelve una alerta textual si alguna métrica remota esta en zona de
        // riesgo, o cadena vacía si todo esta sano. Se muestra en el pie del
        // panel de salud: la cola puede tener headroom y aun asi convenir bajar
        // el ritmo si el ramdisk o la RAM estan al limite.
        //
        // Solo RAM, RAM disk, disco y circuit breaker: son las señales que
        // importan en este módulo. CPU y GPU se omiten a propósito (GPU al 100%
        // significa que está trabajando, no que esté saturada).
        remoteHealthAlert() {
            const r = this.cfgRuntime?.remote_status;
            if (!r) return '';
            const alerts = [];
            if ((r.ram_pct ?? 0) >= 90) alerts.push('RAM ' + this.fmtPct(r.ram_pct));
            if ((r.ramdisk_pct ?? 0) >= 85) alerts.push('RAM disk ' + this.fmtPct(r.ramdisk_pct));
            if (r.ramdisk_ok === false) alerts.push('RAM disk no responde');
            if ((r.disk_pct ?? 0) >= 90) alerts.push('disco ' + this.fmtPct(r.disk_pct));
            if ((r.circuit_open ?? false) === true) alerts.push('circuit breaker abierto');
            return alerts.length ? 'Atención: ' + alerts.join(', ') : '';
        },
        // Uptime del nodo remoto en formato corto (2d 4h / 4h 12m / 12m).
        fmtUptime(seconds) {
            const s = Number(seconds ?? 0);
            if (!s || s < 60) return '';
            const d = Math.floor(s / 86400);
            const h = Math.floor((s % 86400) / 3600);
            const m = Math.floor((s % 3600) / 60);
            if (d > 0) return d + 'd ' + h + 'h';
            if (h > 0) return h + 'h ' + m + 'm';
            return m + 'm';
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
        },

        // ------------------------------------------------------------------
        // Procesamiento personalizado
        // ------------------------------------------------------------------

        /**
         * Deep-link del widget global: ?focus=bg-transcriptor-scan-{runId}.
         *
         * Si el operador llega con ese parámetro, abre el modal y se re-adjunta
         * al progreso de la corrida. Sin focus no abre nada (comportamiento
         * normal). Idempotente: si ya está mostrando ese run, no duplica.
         *
         * Nota: el widget dejó de registrar `bg-transcriptor-batch-*` cuando se
         * retiró el batch legacy; el prefijo vigente es `bg-transcriptor-scan-`.
         */
        focusBgJob() {
            try {
                const params = new URLSearchParams(window.location.search);
                const focus = params.get('focus');
                if (!focus || !focus.startsWith('bg-transcriptor-scan-')) return;
                const runId = focus.replace('bg-transcriptor-scan-', '');
                if (!runId) return;
                if (this.pzRunId === runId && this.pzRunning) return;
                this.pzRunId = runId;
                this.pzRunning = true;
                this.pzProgress = null;
                this.pzResult = null;
                this.pzOpen = true;
                this.startPzPoll();
            } catch (e) { /* silent: el deep-link es best-effort */ }
        },

        openPz() {
            this.pzOpen = true;
            this.pzResult = null;
            this.pzProgress = null;
            this.pzError = null;
            this.refreshPzEstimate();
        },

        closePz() {
            this.pzOpen = false;
            // El polling sigue si hay una corrida activa: el operador puede
            // reabrir el modal y retomar el progreso. Solo se detiene al
            // terminar (ver pollPz).
            if (!this.pzRunning && this.pzPollTimer) {
                clearInterval(this.pzPollTimer);
                this.pzPollTimer = null;
            }
        },

        pzHasWork() {
            return this.pzIncludeMissing || this.pzIncludeFailed || this.pzIncludeDone;
        },

        /** Alcance actual en el shape que espera el backend. */
        pzScopePayload() {
            const p = { scope: this.pzScope };
            if (this.pzScope === 'range') {
                p.from = this.pzFrom;
                p.to = this.pzTo;
            }
            return p;
        },

        async refreshPzEstimate() {
            this.pzError = null;
            if (this.pzScope === 'range' && (!this.pzFrom || !this.pzTo)) {
                this.pzEstimate = null;
                return;
            }
            this.pzEstimating = true;
            try {
                const r = await fetch('/ia/api-transcriptor/scan/estimate', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.pzScopePayload()),
                });
                const data = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
                this.pzEstimate = data;
            } catch (e) {
                this.pzEstimate = null;
                this.pzError = e.message;
            } finally {
                this.pzEstimating = false;
            }
        },

        async runPz() {
            if (!this.pzHasWork()) return;
            this.pzRunning = true;
            this.pzResult = null;
            this.pzProgress = null;
            this.pzError = null;

            const payload = Object.assign(this.pzScopePayload(), {
                batch: this.pzBatch || 0,
                include_failed: this.pzIncludeFailed,
                include_done: this.pzIncludeDone,
                generate_alerts: this.pzAlerts,
            });

            try {
                const r = await fetch('/ia/api-transcriptor/scan/run', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
                this.pzRunId = data.run_id;
                this.startPzPoll();
                showToast('Procesamiento iniciado en background.', 'success');
            } catch (e) {
                this.pzRunning = false;
                this.pzError = e.message;
                showToast('No se pudo iniciar: ' + e.message, 'error');
            }
        },

        startPzPoll() {
            if (this.pzPollTimer) clearInterval(this.pzPollTimer);
            this.pzPollTimer = setInterval(() => this.pollPz(), 2000);
            this.pollPz();
        },

        async pollPz() {
            if (!this.pzRunId) return;
            try {
                const r = await fetch('/ia/api-transcriptor/scan/status/' + encodeURIComponent(this.pzRunId), {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (r.status === 404) {
                    // La corrida expiró de cache sin estado terminal: cerrar.
                    this.stopPzPoll();
                    this.pzRunning = false;
                    this.pzError = 'La corrida expiró o no se encontró.';
                    return;
                }
                if (!r.ok) return;
                const data = await r.json();
                this.pzProgress = data;

                if (['done', 'completed', 'error', 'partial', 'queued'].includes(data.status)) {
                    this.stopPzPoll();
                    this.pzRunning = false;
                    this.pzResult = data;
                    // Refrescar el panel: los contadores cambiaron.
                    this.refreshConfigRuntime();
                }
            } catch (e) { /* red intermitente: el próximo tick reintenta */ }
        },

        stopPzPoll() {
            if (this.pzPollTimer) {
                clearInterval(this.pzPollTimer);
                this.pzPollTimer = null;
            }
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
            return this.storages;
        },
        openDescendantsModal(root) {
            if (!root || !root.id) return;
            const scope = (this.storages || []).filter(s => s.parent_scope_id === root.id && s.id !== root.id);
            this.descendantsModal = { open: true, root: root, items: scope };
        },
        closeDescendantsModal() {
            this.descendantsModal = { open: false, root: null, items: [] };
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
                case 'missing':   return Number(s.funnel?.missing || 0);
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
            const page = filtered.slice(start, start + this.storagesPerPage);
            // Purga de errores de storages que ya no están en la página visible:
            // el contador "Errores hoy" solo suma la página actual (spec del
            // change fix-storages-tab-cards-and-dead-retry-button). Sin esto,
            // al paginar quedarían residuos de la página anterior.
            const visible = new Set(page.map(s => Number(s.id)));
            for (const id of Object.keys(this.snapshotErrors)) {
                if (!visible.has(Number(id))) delete this.snapshotErrors[id];
            }
            return page;
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
        // Snapshot de errores del día: suma reactiva de los error_count de los
        // snapshots por fila (los que la celda "Snapshot transcriptor" ya
        // fetcha). Cambia cuando cada fetch resuelve. Solo suma storages con
        // transcription_enabled=true (los inhabilitados no fetchan snapshot).
        // Ver change fix-storages-tab-cards-and-dead-retry-button.
        snapshotErrors: {},
        snapshotErrorsTotal() {
            return Object.values(this.snapshotErrors).reduce((acc, n) => acc + (Number(n) || 0), 0);
        },
        shouldWarnPending(s) {
            if (!s) return false;
            const pending = s.funnel?.pending ?? 0;
            return pending > this.pendingAlertThreshold;
        },
        pendingWarningTitle(s) {
            if (!s) return '';
            const pending = s.funnel?.pending ?? 0;
            const missing = s.funnel?.missing ?? 0;
            const base = pending + ' pendientes hoy supera el umbral de ' + this.pendingAlertThreshold;
            return missing > 0 ? base + ' (' + missing + ' de ellos sin fila de transcripción)' : base;
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
        // Jobs que hoy se pueden despachar/encolar manualmente. `load()` los
        // usa para podar la selección cuando un job cambió de estado.
        //
        // NOTA: estos dos helpers quedaron huérfanos al retirarse la pestaña
        // Trabajos (remove-api-transcriptor-orphan-files-modal), pero `load()`
        // los seguía invocando: la consola del navegador registraba
        // "this.dispatchableJobs is not a function" en cada carga del módulo.
        // Se restauran como contrato mínimo hasta que se retire también la
        // selección de jobs del estado Alpine.
        dispatchableJobs() {
            return (this.jobs || []).filter(j => ['pending', 'queued', 'processing'].includes(j.state));
        },
        isDispatchable(job) {
            return job && ['pending', 'queued', 'processing'].includes(job.state);
        },
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
                    // Servicio no disponible (típicamente dispatch_paused o saturación upstream)
                    this.bulkDispatchResult = {
                        enqueued: data.enqueued ?? 0,
                        skipped_queued: data.skipped_queued ?? 0,
                        errors: data.errors ?? 1,
                        message: 'Servicio no disponible — reintenta en unos segundos.',
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
