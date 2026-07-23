@extends('layouts.app')

@section('title', 'API Transcriptor - Tcloud')

@section('content')
<div class="p-6" x-data="apiTranscriptor()" x-init="init()">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">API Transcriptor</h1>
            <p class="text-slate-500 mt-0.5">Storages habilitados para transcripción y jobs recientes</p>
        </div>
        <div class="flex gap-2">
            <button @click="loadHealth()"
                    class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-heartbeat"></i> Salud API
            </button>
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
                    <ol class="list-decimal list-inside space-y-1 text-xs text-slate-500">
                        <li>Cada 2 min el scanner busca archivos nuevos en storages con <span class="font-mono bg-slate-100 px-1 rounded">transcripción habilitada</span>.</li>
                        <li>El job convierte el audio a Opus 64k mono 16kHz con <span class="font-mono bg-slate-100 px-1 rounded">ffmpeg</span> (~28× menos ancho de banda).</li>
                        <li>Se envía por <span class="font-mono bg-slate-100 px-1 rounded">POST /v1/transcribe</span> con <span class="font-mono bg-slate-100 px-1 rounded">callback_url</span>.</li>
                        <li>Al terminar, el transcriptor llama al webhook <span class="font-mono bg-slate-100 px-1 rounded">/webhooks/transcription</span> (validado por token).</li>
                        <li>Se descarga el SRT, se guarda en segmentos y se disparan las alertas de keywords.</li>
                        <li>Si el webhook se pierde, un polling cada 5 min recupera los jobs stale (&gt;30 min).</li>
                    </ol>
                </div>
                <div class="space-y-2">
                    <p class="font-medium text-slate-700"><i class="fas fa-server text-brand-400 mr-1"></i>Endpoint y configuración</p>
                    <ul class="text-xs text-slate-500 space-y-1">
                        <li><span class="text-slate-400">URL base:</span> <span class="font-mono bg-slate-100 px-1 rounded">{{ config('transcriptor.base_url') }}</span></li>
                        <li><span class="text-slate-400">Callback host:</span> <span class="font-mono bg-slate-100 px-1 rounded">{{ config('transcriptor.callback_host') }}</span></li>
                        <li><span class="text-slate-400">Idioma:</span> {{ config('transcriptor.language', 'es') }}</li>
                        <li><span class="text-slate-400">Estados de job:</span> <span class="font-mono bg-slate-100 px-1 rounded">queued · processing · done · error · dead</span></li>
                    </ul>
                    <p class="text-xs text-slate-400 mt-2"><i class="fas fa-lightbulb text-amber-400 mr-1"></i>El botón <b>Storages</b> permite habilitar la transcripción por storage y asignar prioridad.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Pestañas principales: Storages | Trabajos --}}
    <div class="mb-4 flex items-center gap-1 border-b border-slate-200">
        <button @click="tab = 'storages'"
                :class="tab === 'storages' ? 'bg-white border-x border-t border-slate-200 text-brand-600' : 'text-slate-500 hover:text-slate-700'"
                class="px-5 py-2.5 rounded-t-lg text-sm font-medium border-b-2 -mb-px transition-colors"
                :class-extra="tab === 'storages' ? 'border-b-brand-500' : 'border-b-transparent'">
            <i class="fas fa-database mr-1.5"></i>
            Storages
            <span x-show="storagesEnabled.length > 0" class="ml-1.5 text-[10px] px-1.5 py-0.5 bg-brand-100 text-brand-700 rounded-full font-semibold" x-text="storagesEnabled.length"></span>
        </button>
        <button @click="tab = 'jobs'"
                :class="tab === 'jobs' ? 'bg-white border-x border-t border-slate-200 text-brand-600' : 'text-slate-500 hover:text-slate-700'"
                class="px-5 py-2.5 rounded-t-lg text-sm font-medium border-b-2 -mb-px transition-colors"
                :class-extra="tab === 'jobs' ? 'border-b-brand-500' : 'border-b-transparent'">
            <i class="fas fa-tasks mr-1.5"></i>
            Trabajos
            <span x-show="jobsPendingCount > 0" class="ml-1.5 text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full font-semibold" x-text="jobsPendingCount"></span>
        </button>
        <div class="flex-1"></div>
        <button @click="openBatchModal()"
                class="flex items-center gap-2 px-4 py-1.5 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg transition-colors mb-1 font-medium"
                :disabled="batchRunning"
                :class="batchRunning ? 'opacity-50 cursor-not-allowed' : ''"
                title="Escanear storages y crear jobs para archivos sin transcripción">
            <i class="fas fa-layer-group" :class="batchRunning ? 'fa-spin' : ''"></i>
            <span x-text="batchRunning ? 'Escaneando...' : 'Escanear storages'"></span>
        </button>
        <button @click="loadHealth()"
                class="flex items-center gap-2 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg transition-colors mb-1"
                :title="health?.ok ? 'API en línea' : 'API no disponible'">
            <i class="fas fa-heartbeat" :class="health?.ok ? 'text-green-500' : 'text-red-500'"></i>
            <span x-text="health?.ok ? 'API en línea' : 'Sin conexión'"></span>
        </button>
    </div>

    {{-- TAB: STORAGES --}}
    <div x-show="tab === 'storages'" x-transition:enter.opacity.duration.150ms>

    {{-- Banner de salud dentro del tab (también arriba del todo en el siguiente cambio) --}}
    <div class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                 :class="health?.ok ? 'bg-green-100' : 'bg-red-100'">
                <i class="fas fa-heartbeat" :class="health?.ok ? 'text-green-600' : 'text-red-600'"></i>
            </div>
            <div>
                <p class="text-xs text-slate-500 uppercase tracking-wide">Transcripción de API</p>
                <p class="text-sm font-medium" :class="health?.ok ? 'text-green-700' : 'text-red-700'"
                   x-text="loading ? '...' : (health?.ok ? 'En línea' : 'No disponible')"></p>
            </div>
        </div>
        <template x-for="(count, state) in (stats?.local || {})" :key="state">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs text-slate-500 uppercase tracking-wide" x-text="(stateLabels[state] ? 'Trabajos ' + stateLabels[state] : 'Trabajos ' + state)"></p>
                <p class="text-2xl font-bold text-slate-800" x-text="count"></p>
            </div>
        </template>
    </div>

    <!-- Estado vacío: sin storages habilitados -->
    <div x-show="!loading && storagesEnabled.length === 0"
         class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-6">
        <i class="fas fa-exclamation-triangle text-amber-500 text-3xl mb-2 block"></i>
        <p class="font-medium text-amber-800">No hay storages con transcripción habilitada</p>
        <p class="text-sm text-amber-700 mt-1">Activa un storage abajo para empezar a transcribir grabaciones.</p>
    </div>

    <!-- Tabla de storages -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Storages</h2>
            <span class="text-xs text-slate-400" x-text="storagesEnabled.length + ' habilitado(s) de ' + storages.length"></span>
        </div>
        <div x-show="storages.length === 0" class="text-center py-12 text-slate-400">
            <i class="fas fa-database text-3xl mb-2 block text-slate-200"></i>
            <p>No hay storages registrados.</p>
        </div>
        <table x-show="storages.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Storage</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Tipo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Transcripción</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="s in storages" :key="s.id">
                    <tr class="hover:bg-slate-50" :class="s.transcription_enabled ? '' : 'opacity-70'">
                        <td class="px-4 py-3 text-sm font-medium text-slate-700" x-text="s.name"></td>
                        <td class="px-4 py-3 text-xs text-slate-500" x-text="s.type"></td>
                        <td class="px-4 py-3">
                            <button @click="toggleEnabled(s)"
                                    :class="s.transcription_enabled ? 'bg-green-500' : 'bg-slate-300'"
                                    class="relative w-10 h-5 rounded-full transition-colors"
                                    :title="s.transcription_enabled ? 'Deshabilitar' : 'Habilitar'">
                                <span :class="s.transcription_enabled ? 'translate-x-5' : 'translate-x-1'"
                                      class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"></span>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button @click="openFiles(s)"
                                        class="flex items-center gap-1 px-2.5 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors"
                                        :class="!s.transcription_enabled && 'opacity-50'">
                                    <i class="fas fa-file-audio text-[10px]"></i> Ver archivos
                                </button>
                                <button @click="scanStorage(s)"
                                        class="flex items-center gap-1 px-2.5 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 text-xs rounded-lg transition-colors"
                                        :class="!s.transcription_enabled && 'opacity-50'">
                                    <i class="fas fa-sync-alt text-[10px]"></i> Escanear
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
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
                                title="Crea transcripciones pendientes para todos los archivos sin transcribir de la carpeta actual. El envío real lo hace 'Escanear y encolar' o el schedule automático.">
                            <i class="fas fa-folder-open text-[10px] mr-1"></i> Procesar carpeta
                        </button>
                        <button x-show="filesMode === 'today' || filesMode === 'yesterday'" @click="confirmProcessDay()"
                                class="text-brand-600 hover:underline"
                                title="Crea transcripciones pendientes para todos los archivos del día (HOY o AYER) sin transcribir. El envío real lo hace 'Escanear y encolar' o el schedule automático.">
                            <i class="fas fa-calendar-day text-[10px] mr-1"></i> Procesar día
                        </button>
                        <button @click="syncStorage(currentStorage)" :disabled="syncing"
                                class="text-slate-600 hover:underline disabled:opacity-40"
                                title="Escanea el disco del storage y registra en la base de datos los archivos nuevos que aún no aparecen aquí. No transcribe, solo descubre.">
                            <i class="fas fa-cloud-download-alt text-[10px] mr-1" :class="syncing ? 'fa-spin' : ''"></i>
                            <span x-text="syncing ? 'Sincronizando...' : 'Sincronizar archivos'"></span>
                        </button>
                        <button @click="scanStorage(currentStorage)" class="text-brand-600 hover:underline"
                                title="Toma los últimos N archivos pendientes (sin transcripción y con más de 60s de antigüedad) y los envía al transcriptor. Los workers Redis los procesan en paralelo.">
                            <i class="fas fa-sync-alt text-[10px] mr-1"></i> Escanear y encolar últimos <span x-text="batch"></span>
                        </button>
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
                        <span x-text="bulkSending ? 'Enviando...' : ('Enviar ' + pendingSelectedCount() + ' seleccionados')"></span>
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

    {{-- TAB: TRABAJOS --}}
    <div x-show="tab === 'jobs'" x-transition:enter.opacity.duration.150ms>

        {{-- Sub-tabs: Pendientes / Completados --}}
        <div class="mb-3 flex items-center gap-2">
            <button @click="jobsSubTab = 'pending'"
                    :class="jobsSubTab === 'pending' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-clock text-xs"></i>
                Pendientes
                <span :class="jobsSubTab === 'pending' ? 'bg-white text-brand-700' : 'bg-amber-100 text-amber-700'" class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold" x-text="jobsPendingCount"></span>
            </button>
            <button @click="jobsSubTab = 'completed'"
                    :class="jobsSubTab === 'completed' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-check-circle text-xs"></i>
                Completados
                <span :class="jobsSubTab === 'completed' ? 'bg-white text-brand-700' : 'bg-green-100 text-green-700'" class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold" x-text="jobsCompletedCount"></span>
            </button>

            <div class="flex-1"></div>

            <div class="relative">
                <input type="text" x-model="search" @input.debounce.400ms="load()"
                       placeholder="Buscar por nombre de archivo..."
                       class="border border-slate-300 rounded-lg pl-9 pr-4 py-1.5 text-sm w-64 focus:ring-2 focus:ring-brand-500 outline-none">
                <i class="fas fa-search absolute left-3 top-2 text-slate-400 text-xs"></i>
            </div>
            <select x-model="stateFilter" @change="load()"
                    class="border border-slate-300 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                <option value="">Todos</option>
                <option value="pending">pending</option>
                <option value="done">done</option>
                <option value="error">error</option>
                <option value="dead">dead</option>
            </select>
            <button @click="load()" class="text-xs text-brand-600 hover:underline whitespace-nowrap">
                <i class="fas fa-sync-alt" :class="loading ? 'fa-spin' : ''"></i>
            </button>
            <span x-show="batchRunning" x-transition.opacity class="inline-flex items-center gap-1.5 text-[10px] text-brand-700 bg-brand-50 border border-brand-200 rounded-full px-2 py-0.5 whitespace-nowrap">
                <i class="fas fa-circle-notch fa-spin text-[9px]"></i>
                <span>Sincronizando Pendientes</span>
            </span>
        </div>

        {{-- Panel colapsable: desglose por estado de BD (lee /ia/api-transcriptor/stats) --}}
        <details class="mb-3 text-xs">
            <summary class="cursor-pointer text-slate-500 hover:text-slate-700 select-none flex items-center gap-1.5">
                <i class="fas fa-chart-pie text-[10px]"></i>
                Estado por BD (resumen)
                <span class="text-slate-400">— click para expandir</span>
            </summary>
            <div class="mt-2 p-3 bg-slate-50 border border-slate-200 rounded-lg">
                <template x-if="!stats || !stats.local">
                    <p class="text-slate-400 italic">Cargando contadores...</p>
                </template>
                <template x-if="stats && stats.local">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                        <template x-for="s in ['pending','queued','processing','done','error','dead']" :key="s">
                            <div class="flex items-center gap-2 px-2 py-1.5 bg-white border border-slate-200 rounded">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" :class="stateDot(s)"></span>
                                <span class="text-slate-600 font-mono" x-text="s"></span>
                                <span class="ml-auto font-semibold text-slate-800" x-text="(stats.local[s] || 0).toLocaleString()"></span>
                            </div>
                        </template>
                    </div>
                </template>
                <p class="mt-2 text-[10px] text-slate-400">
                    <i class="fas fa-info-circle mr-0.5"></i>
                    Los estados <b>pending</b> indican transcripciones creadas pero aún no enviadas a la API externa. Si tienes muchas, ejecuta <code class="bg-slate-100 px-1 rounded">php artisan transcriptor:diagnose-pending</code>.
                </p>
            </div>
        </details>

        <!-- Tabla de jobs (vista unificada) -->

        {{-- Action bar para dispatch masivo de jobs pendientes --}}
        <div x-show="jobsSubTab === 'pending' && (dispatchableJobsCount() > 0 || bulkDispatchResult)"
             x-transition.opacity
             class="mb-3 flex items-center gap-3 px-4 py-2.5 bg-brand-50 border border-brand-200 rounded-lg text-sm">
            <div x-show="!bulkDispatchResult" class="flex items-center gap-3 flex-wrap">
                <span class="flex items-center gap-2 text-brand-800 font-medium">
                    <i class="fas fa-rocket text-brand-500"></i>
                    <span x-text="selectJobMode && selectedJobIds.size > 0 ? (selectedJobIds.size + ' seleccionados') : (dispatchableJobsCount() + ' pendientes disponibles')"></span>
                </span>
                <button @click="bulkDispatchPending()"
                        :disabled="bulkDispatching || (selectJobMode && selectedJobIds.size === 0)"
                        class="flex items-center gap-2 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-xs">
                    <i class="fas fa-paper-plane text-[10px]" :class="bulkDispatching ? 'fa-spin' : ''"></i>
                    <span x-text="bulkDispatching ? 'Despachando...' : (selectJobMode && selectedJobIds.size > 0 ? ('Procesar ' + selectedJobIds.size + ' seleccionados ahora') : ('Procesar ' + dispatchableJobsCount() + ' pendientes ahora'))"></span>
                </button>
                <label class="flex items-center gap-2 cursor-pointer text-xs text-brand-700 hover:text-brand-900">
                    <input type="checkbox" x-model="selectJobMode" class="w-3.5 h-3.5 rounded border-brand-300 text-brand-600 focus:ring-brand-500 cursor-pointer">
                    <span>Seleccionar</span>
                </label>
                <button x-show="selectJobMode && selectedJobIds.size > 0" @click="clearJobSelection()"
                        class="text-xs text-slate-500 hover:text-slate-700 underline">
                    Limpiar selección
                </button>
            </div>
            <div x-show="bulkDispatchResult" class="flex items-center gap-3 flex-wrap">
                <template x-if="bulkDispatchResult">
                    <div class="flex items-center gap-2"
                         :class="(bulkDispatchResult.errors || 0) > 0 ? 'text-amber-700' : 'text-green-700'">
                        <i :class="(bulkDispatchResult.errors || 0) > 0 ? 'fas fa-exclamation-triangle' : 'fas fa-check-circle'"></i>
                        <span class="flex items-center gap-2 flex-wrap text-xs">
                            <template x-if="bulkDispatchResult.enqueued > 0">
                                <span>
                                    <strong x-text="bulkDispatchResult.enqueued"></strong> trabajos encolados a Redis
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.skipped_queued > 0">
                                <span class="text-slate-500">
                                    · <strong x-text="bulkDispatchResult.skipped_queued"></strong> omitidos (ya enviados o terminales)
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.errors > 0">
                                <span class="text-amber-700">
                                    · <strong x-text="bulkDispatchResult.errors"></strong> con error de cola
                                </span>
                            </template>
                            <template x-if="!bulkDispatchResult.message && bulkDispatchResult.enqueued > 0">
                                <span class="text-slate-500">
                                    · Los workers supervisord los procesarán en background. El progreso se refleja arriba en "Estado por BD".
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.message">
                                <span class="text-slate-500" x-text="bulkDispatchResult.message"></span>
                            </template>
                        </span>
                    </div>
                </template>
                <button @click="bulkDispatchResult = null"
                        class="ml-auto text-xs text-brand-600 hover:underline">
                    Aceptar
                </button>
            </div>
        </div>

        <div x-show="loading" class="flex items-center justify-center py-16">
            <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
        </div>

        <div x-show="!loading && jobs.length === 0" class="text-center py-16 text-slate-400">
            <i class="fas fa-microphone text-4xl mb-3 block text-slate-200"></i>
            <p class="font-medium">Aún no hay transcripciones</p>
            <p class="text-sm mt-1">Cuando el scanner detecte archivos en storages habilitados aparecerán aquí.</p>
        </div>

        <table x-show="!loading && jobs.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th x-show="jobsSubTab === 'pending' && selectJobMode" class="px-2 py-3 w-8 text-center">
                        <input type="checkbox"
                               class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer disabled:opacity-40"
                               :checked="isAllDispatchableSelected()"
                               :indeterminate.prop="!isAllDispatchableSelected() && isSomeDispatchableSelected()"
                               :disabled="dispatchableJobsCount() === 0"
                               @change="toggleSelectAllDispatchable()"
                               title="Seleccionar todos los pendientes dispatchable">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Archivo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Estado</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Duración</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Iniciado</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Finalizado</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="job in jobs" :key="job.id">
                    <tr class="hover:bg-slate-50"
                        x-show="(jobsSubTab === 'pending' && ['pending','queued','processing'].includes(job.state)) || (jobsSubTab === 'completed' && ['done','error','dead'].includes(job.state))">
                        <td x-show="jobsSubTab === 'pending' && selectJobMode" class="px-2 py-3 text-center" @click.stop>
                            <input type="checkbox"
                                   class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer disabled:opacity-40"
                                   :checked="isJobSelected(job.id)"
                                   :disabled="!isDispatchable(job)"
                                   :title="isDispatchable(job) ? 'Seleccionar para dispatch' : 'Este job ya fue enviado o no está en cola'"
                                   @change="toggleJobSelected(job.id)">
                        </td>
                        <td class="px-4 py-3">
                            <a :href="'/ia/api-transcriptor/jobs/' + job.id"
                               class="text-sm font-medium text-brand-600 hover:underline" x-text="job.original_name || job.file?.name || ('File #' + job.file_id)"></a>
                            <p class="text-xs text-slate-400">ID del job: <span x-text="job.job_id || '—'"></span></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium"
                                  :class="stateClass(job.state)">
                                <span class="w-1.5 h-1.5 rounded-full" :class="stateDot(job.state)"></span>
                                <span x-text="job.state"></span>
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-600"
                            x-text="job.duration_seconds ? job.duration_seconds + 's' : '—'"></td>
                        <td class="px-4 py-3 hidden sm:table-cell text-sm text-slate-600"
                            x-text="formatDate(job.started_at)"></td>
                        <td class="px-4 py-3 hidden lg:table-cell text-sm text-slate-600"
                            x-text="formatDate(job.finished_at)"></td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                {{-- Pendientes: Enviar ahora (sin job_id) / Refrescar estado (con job_id) --}}
                                <button x-show="jobsSubTab === 'pending' && ['pending','queued','processing'].includes(job.state)"
                                        @click="job.job_id ? refreshJobStatus(job) : dispatchJobNow(job)"
                                        :disabled="refreshingJobs && refreshingJobs.has(Number(job.id))"
                                        class="inline-flex items-center justify-center px-2.5 h-8 bg-brand-600 hover:bg-brand-700 text-white rounded-lg transition-colors text-xs font-medium whitespace-nowrap disabled:opacity-50"
                                        :title="job.job_id ? 'Consultar upstream y actualizar estado' : 'Enviar al transcriptor (ffmpeg + POST)'">
                                    <i :class="(refreshingJobs && refreshingJobs.has(Number(job.id))) ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" class="text-[10px] mr-1"></i>
                                    <span x-text="job.job_id ? 'Refrescar estado' : 'Enviar ahora'"></span>
                                </button>
                                {{-- Pendientes (pending): Borrar (sin job_id, delete local) --}}
                                <button x-show="jobsSubTab === 'pending' && job.state === 'pending'"
                                        @click="cancelJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg transition-colors"
                                        title="Borrar fila pendiente (no fue enviada a la API externa)">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                                {{-- Pendientes (queued|processing): Cancelar upstream --}}
                                <button x-show="jobsSubTab === 'pending' && (job.state === 'queued' || job.state === 'processing')"
                                        @click="cancelJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-amber-50 hover:bg-amber-100 text-amber-600 rounded-lg transition-colors"
                                        title="Cancelar job">
                                    <i class="fas fa-ban text-xs"></i>
                                </button>
                                {{-- Completados: Reprocesar (done) --}}
                                <button x-show="jobsSubTab === 'completed' && job.state === 'done'"
                                        @click="reprocessJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-brand-50 hover:bg-brand-100 text-brand-600 rounded-lg transition-colors"
                                        title="Reprocesar transcripción">
                                    <i class="fas fa-redo text-xs"></i>
                                </button>
                                {{-- Completados: Reintentar (error/dead) --}}
                                <button x-show="jobsSubTab === 'completed' && (job.state === 'error' || job.state === 'dead')"
                                        @click="reprocessJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-amber-50 hover:bg-amber-100 text-amber-600 rounded-lg transition-colors"
                                        title="Reintentar transcripción">
                                    <i class="fas fa-redo text-xs"></i>
                                </button>
                                <a :href="'/ia/api-transcriptor/jobs/' + job.id"
                                   class="inline-flex items-center justify-center w-8 h-8 bg-slate-100 hover:bg-brand-50 text-slate-500 hover:text-brand-600 rounded-lg transition-colors">
                                    <i class="fas fa-eye text-xs"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr x-show="!loading && filteredJobsCount() === 0">
                    <td colspan="6" class="text-center py-12 text-slate-400">
                        <i class="fas fa-inbox text-3xl mb-2 block text-slate-200"></i>
                        <p class="font-medium" x-text="jobsSubTab === 'pending' ? 'Sin trabajos pendientes' : 'Sin trabajos completados'"></p>
                        <p class="text-xs mt-1" x-text="jobsSubTab === 'pending' ? 'Los trabajos nuevos aparecerán aquí' : 'Cuando los jobs terminen aparecerán aquí'"></p>
                    </td>
                </tr>
            </tbody>
        </table>

    </div> {{-- /TAB JOBS --}}

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
                            <p class="text-xs text-slate-500" x-text="progressStep === 'converting' ? 'Convirtiendo ' + (progressFile?.size_human || '') + ' a Opus 64k mono 16kHz...' : (['done','queued','processing'].includes(progressStep) ? 'Audio convertido correctamente' : 'Pendiente')"></p>
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
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Tamaño del lote</label>
                        <div class="flex items-center gap-3">
                            <input type="range" min="10" max="500" step="10" x-model.number="batchSize"
                                   class="flex-1 accent-brand-600">
                            <span class="text-2xl font-bold text-brand-600 w-16 text-center" x-text="batchSize"></span>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <button @click="batchSize = 50" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">50</button>
                            <button @click="batchSize = 100" class="px-2 py-1 text-xs bg-brand-100 text-brand-700 hover:bg-brand-200 rounded font-medium">100</button>
                            <button @click="batchSize = 200" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">200</button>
                            <button @click="batchSize = 500" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">500</button>
                        </div>
                        <p class="text-xs text-slate-400 mt-2"><i class="fas fa-info-circle mr-1"></i>Cupo por storage. Con 100, cada storage envía hasta 100 archivos por ciclo. Los más recientes primero.</p>
                    </div>
                    {{-- Checkbox alertas --}}
                    <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="batchAlerts" class="w-4 h-4 accent-brand-600 rounded">
                            <span class="text-sm text-slate-700">Generar alertas</span>
                        </label>
                        <span class="text-xs text-slate-400" x-show="!batchAlerts"><i class="fas fa-info-circle mr-1"></i>Las transcripciones se guardarán sin disparar emails de keywords</span>
                        <span class="text-xs text-amber-600" x-show="batchAlerts"><i class="fas fa-bell mr-1"></i>Se enviarán alertas por email cuando se detecten keywords</span>
                    </div>
                    <div x-show="storagesEnabled.length === 0" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-700">
                        <i class="fas fa-exclamation-triangle mr-1"></i>No hay storages habilitados para transcripción.
                    </div>
                    <div class="flex gap-2">
                        <button @click="runBatch()" x-show="storagesEnabled.length > 0"
                                class="flex-1 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                            <i class="fas fa-play mr-1"></i> Iniciar procesamiento
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
                </div>

                {{-- Resultados --}}
                <div x-show="!batchRunning && batchResult" class="space-y-4">
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

</div>

@push('scripts')
<script>
function apiTranscriptor() {
    return {
        loading: false,
        jobs: [],
        storages: [],
        showInfo: false,
        // Tabs principales
        tab: 'storages', // storages | jobs
        // Sub-tabs de Trabajos
        jobsSubTab: 'pending', // pending | completed
        search: '',
        stateFilter: '',
        health: null,
        stats: null,
        stateLabels: {
            pending: 'pendientes',
            queued: 'en cola',
            processing: 'en proceso',
            done: 'completados',
            error: 'con error',
            dead: 'fallidos',
        },
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
        batchSize: {{ (int) config('transcriptor.scan_batch', 100) }},
        batchAlerts: false,
        batchResult: null,
        batchRunId: null,
        batchPollTimer: null,
        batchProgress: null,
        // Mini-modal confirmación carpeta/día
        showProcessConfirm: false,
        processConfirmText: '',
        processConfirmAction: null,
        processAlerts: false,
        batch: {{ (int) config('transcriptor.scan_batch', 100) }},
        // Multi-selección de archivos para envío en lote
        selectedFileIds: new Set(),
        bulkSending: false,
        bulkResult: null,
        // Bulk dispatch de jobs pendientes desde la pestaña Trabajos
        selectedJobIds: new Set(),
        selectJobMode: false,
        bulkDispatching: false,
        bulkDispatchResult: null,
        refreshingJobs: new Set(),
        async init() {
            await Promise.all([this.load(), this.loadHealth(), this.loadStats()]);
            this.$watch('jobsSubTab', () => {
                if (this.jobsSubTab !== 'pending') {
                    this.selectedJobIds = new Set();
                    this.selectJobMode = false;
                    this.bulkDispatchResult = null;
                }
            });
        },
        get storagesEnabled() {
            return this.storages.filter(s => s.transcription_enabled);
        },
        get jobsPendingCount() {
            return this.jobs.filter(j => ['pending', 'queued', 'processing'].includes(j.state)).length;
        },
        get jobsCompletedCount() {
            return this.jobs.filter(j => ['done', 'error', 'dead'].includes(j.state)).length;
        },
        filteredJobsCount() {
            if (this.jobsSubTab === 'pending') return this.jobsPendingCount;
            return this.jobsCompletedCount;
        },
        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                if (this.search) params.set('q', this.search);
                if (this.stateFilter) params.set('state', this.stateFilter);
                const qs = params.toString() ? '?' + params.toString() : '';
                const res = await apiFetch('/ia/api-transcriptor' + qs, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    this.jobs = data.jobs || [];
                    this.storages = data.storages || [];
                    // Limpiar selección de jobs: los que ya se despacharon
                    // (o cambiaron de estado) ya no son dispatchable.
                    const dispatchableIds = new Set(this.dispatchableJobs().map(j => Number(j.id)));
                    for (const id of [...this.selectedJobIds]) {
                        if (!dispatchableIds.has(id)) this.selectedJobIds.delete(id);
                    }
                }
            } finally { this.loading = false; }
        },
        async loadHealth() {
            try {
                const res = await apiFetch('/ia/api-transcriptor/health', { headers: { 'Accept': 'application/json' } });
                if (res.ok) this.health = await res.json();
                else this.health = { ok: false };
            } catch { this.health = { ok: false }; }
        },
        async loadStats() {
            try {
                const res = await apiFetch('/ia/api-transcriptor/stats', { headers: { 'Accept': 'application/json' } });
                if (res.ok) this.stats = await res.json();
            } catch { this.stats = { local: {} }; }
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
        async transcribeFile(f) {
            // Alias para mantener compatibilidad con bindings viejos.
            return this.openProgress(f);
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
            const results = await Promise.allSettled(pending.map(f => fetch('/ia/api-transcriptor/transcribe/' + f.id, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            }).then(r => ({ ok: r.ok, status: r.status, file: f }))));
            let sent = 0, errors = 0;
            for (const r of results) {
                if (r.status === 'fulfilled' && r.value.ok) {
                    sent++;
                    const f = r.value.file;
                    f.has_transcription = true;
                } else {
                    errors++;
                }
            }
            this.bulkResult = { sent, errors, skipped: skipped.length, total: pending.length + skipped.length };
            this.bulkSending = false;
            this.load();
            this.loadFiles();
        },
        async bulkDispatchPending() {
            if (this.bulkDispatching) return;
            let targets;
            if (this.selectJobMode && this.selectedJobIds.size > 0) {
                targets = (this.jobs || []).filter(j =>
                    this.selectedJobIds.has(Number(j.id)) && this.isDispatchable(j)
                );
            } else {
                targets = this.dispatchableJobs();
            }
            if (targets.length === 0) {
                this.bulkDispatchResult = {
                    enqueued: 0, skipped_queued: 0, errors: 0,
                    message: 'No hay trabajos dispatchable seleccionados.',
                };
                return;
            }
            this.bulkDispatching = true;
            this.bulkDispatchResult = null;
            const csrf = document.querySelector('meta[name=csrf-token]').content;
            const ids = targets.map(j => Number(j.id));
            try {
                const res = await fetch('/ia/api-transcriptor/jobs/bulk-dispatch', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ ids }),
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
                    alert(this.bulkDispatchResult.message);
                    return;
                }
                if (res.status === 422) {
                    const msg = (data.message || data.errors?.ids?.[0] || 'Validación fallida') + '';
                    this.bulkDispatchResult = { enqueued: 0, skipped_queued: 0, errors: 1, message: msg };
                    alert(msg);
                    return;
                }
                if (!res.ok) {
                    this.bulkDispatchResult = {
                        enqueued: 0, skipped_queued: 0, errors: 1,
                        message: data.error || `Error HTTP ${res.status}`,
                    };
                    alert(this.bulkDispatchResult.message);
                    return;
                }
                this.bulkDispatchResult = {
                    enqueued: data.enqueued ?? 0,
                    skipped_queued: data.skipped_queued ?? 0,
                    errors: data.errors ?? 0,
                };
            } catch (e) {
                this.bulkDispatchResult = {
                    enqueued: 0, skipped_queued: 0, errors: 1,
                    message: (e && e.message) || 'Error de conexión',
                };
                alert(this.bulkDispatchResult.message);
            } finally {
                this.selectedJobIds = new Set();
                this.selectJobMode = false;
                this.bulkDispatching = false;
                await this.load();
                await this.loadStats();
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
                    alert(data.error || ('Error HTTP ' + r.status));
                    return;
                }
                await this.load();
                await this.loadStats();
            } catch (e) {
                alert('Error de conexión: ' + (e?.message || ''));
            } finally {
                this.refreshingJobs.delete(Number(job.id));
            }
        },
        openBatchModal() {
            this.batchResult = null;
            this.batchRunning = false;
            this.showBatchModal = true;
        },
        closeBatchModal() {
            this.stopBatchPolling();
            this.showBatchModal = false;
            this.batchResult = null;
            this.batchRunning = false;
            this.batchProgress = null;
            this.batchRunId = null;
        },
        stopBatchPolling() {
            if (this.batchPollTimer) { clearInterval(this.batchPollTimer); this.batchPollTimer = null; }
            this.batchTableRefreshTick = 0;
        },
        async runBatch() {
            this.batchRunning = true;
            this.batchResult = null;
            this.batchProgress = null;
            // Watchdog: si la respuesta HTTP tarda >5s, asumimos que el proceso fue
            // iniciado y entramos a polling igual. El backend usa proc_open + /dev/null
            // para no bloquear, pero la red o el browser pueden introducir latencia.
            const startPolling = (runId) => {
                this.batchRunId = runId;
                if (this.batchPollTimer) clearInterval(this.batchPollTimer);
                this.batchPollTimer = setInterval(() => this.pollBatch(), 2000);
                this.pollBatch();
            };
            try {
                const fetchPromise = apiFetch('/ia/api-transcriptor/process-batch', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ batch: this.batchSize, generate_alerts: this.batchAlerts }),
                });
                const res = await Promise.race([
                    fetchPromise,
                    new Promise((_, reject) => setTimeout(() => reject(new Error('watchdog-timeout')), 5000))
                ]);
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(data.error || 'Error al iniciar el lote');
                    this.batchRunning = false;
                    return;
                }
                startPolling(data.run_id);
            } catch (e) {
                // Timeout del watchdog: la request probablemente está en vuelo o el
                // server la está procesando. Asumimos "started" y entramos a polling
                // usando el runId que el server habrá publicado en cache al iniciar.
                if (e?.message === 'watchdog-timeout') {
                    // Esperar un poco a que el server registre el runId y luego iniciar polling.
                    setTimeout(() => {
                        // Si el server responde tarde pero con run_id, este startPolling
                        // se ejecutará. Si nunca respondió, el polling verá cache vacío
                        // y mostrará error después de varios intentos.
                        this.batchRunId = this.batchRunId || ('timeout_' + Date.now());
                        startPolling(this.batchRunId);
                    }, 1500);
                } else {
                    alert('Error de conexión: ' + (e?.message || ''));
                    this.batchRunning = false;
                }
            }
        },
        confirmProcessFolder() {
            this.processConfirmText = 'Procesar carpeta actual';
            this.processConfirmAction = 'folder';
            this.processAlerts = false;
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
                    alert('Encolados ' + d.dispatched + ' archivos de ' + d.candidates + ' candidatos.');
                    await this.loadFiles();
                } else {
                    alert(d.error || 'Error al procesar carpeta');
                }
            } catch (e) { alert('Error de conexión'); }
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
                    alert('Encolados ' + d.dispatched + ' archivos de ' + d.candidates + ' candidatos.');
                    await this.loadFiles();
                } else {
                    alert(d.error || 'Error al procesar día');
                }
            } catch (e) { alert('Error de conexión'); }
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
                        this.loadStats();
                    }
                }

                // Si termino (done/error/not_found), detener polling y mostrar resultados.
                if (data.status === 'done' || data.status === 'error' || data.status === 'not_found') {
                    this.stopBatchPolling();
                    this.batchRunning = false;
                    if (data.status === 'done') {
                        this.batchResult = data;
                    } else if (data.status === 'not_found') {
                        this.batchResult = { processed: 0, errors: 0, total_candidates: 0, storages: [], files: [], message: data.message };
                    } else {
                        this.batchResult = { processed: 0, errors: 1, total_candidates: 0, storages: [], files: [], message: data.message };
                    }
                    this.batchTableRefreshTick = 0;
                    this.load();
                    this.loadStats();
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
                if (!res.ok) { alert(d.error || 'No se pudo cancelar'); return; }
                if (d.message) console.info('[transcriptor]', d.message);
                await this.load();
            } catch (e) { alert('Error de conexión'); }
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
        async scanStorage(s) {
            if (!s.transcription_enabled) { alert('Habilita la transcripción para este storage primero.'); return; }
            const res = await apiFetch('/ia/api-transcriptor/storages/' + s.id + '/scan?batch=' + this.batch, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (res.ok) {
                const d = await res.json();
                alert('Scanner: ' + d.dispatched + ' jobs despachados (' + d.candidates + ' candidatos).');
                await this.load();
            } else {
                const d = await res.json().catch(() => ({}));
                alert(d.error || 'No se pudo escanear');
            }
        },
        async syncStorage(s) {
            if (!s || s.type !== 'local') { alert('Solo storages locales se pueden sincronizar.'); return; }
            this.syncing = true;
            try {
                const res = await apiFetch('/ia/api-transcriptor/storages/' + s.id + '/sync', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                const d = await res.json();
                if (res.ok) {
                    alert('Sync: +' + (d.created||0) + ' archivos nuevos, -' + (d.deleted||0) + ' eliminados.');
                    await this.loadFiles();
                } else {
                    alert(d.error || 'No se pudo sincronizar');
                }
            } finally { this.syncing = false; }
        },
        async toggleEnabled(s) {
            const newValue = !s.transcription_enabled;
            const res = await apiFetch('/ia/api-transcriptor/storages/' + s.id + '/toggle', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ transcription_enabled: newValue }),
            });
            if (res.ok) { const updated = await res.json(); Object.assign(s, updated); }
            else { s.transcription_enabled = !newValue; alert('No se pudo cambiar el estado del storage'); }
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
    };
}
</script>
@endpush
@endsection