@extends('layouts.app')

@section('title', 'API Transcriptor - Tcloud')

@section('content')
<div class="p-6" x-data="apiTranscriptor()" x-init="init()">

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
            <p class="text-slate-500 mt-0.5">Storages habilitados para transcripción y jobs recientes</p>
        </div>
        <div class="flex gap-2">
            <button onclick="startApiTranscriptorTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
                <i class="fas fa-map-marked-alt"></i>
                <span class="hidden sm:inline">Guía</span>
            </button>
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
        <button @click="tab = 'config'"
                :class="tab === 'config' ? 'bg-white border-x border-t border-slate-200 text-brand-600' : 'text-slate-500 hover:text-slate-700'"
                class="px-5 py-2.5 rounded-t-lg text-sm font-medium border-b-2 -mb-px transition-colors"
                :class-extra="tab === 'config' ? 'border-b-brand-500' : 'border-b-transparent'">
            <i class="fas fa-sliders-h mr-1.5"></i>
            Configuración
            <span x-show="cfg.dispatch_paused" class="ml-1.5 inline-block w-2 h-2 bg-amber-500 rounded-full align-middle" title="Envío pausado"></span>
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

    {{-- Banner de novedades: medios/carpetas sin archivos. Click para expandir y ver, boton Omitir para desactivar storage. --}}
    <div x-show="emptyFolders && emptyFolders.total_missing_folders > 0"
         data-tour="storages-empties"
         class="mb-4 bg-amber-50 border border-amber-200 rounded-xl">
        <div class="px-5 py-3 flex items-center gap-3">
            <i class="fas fa-exclamation-triangle text-amber-600 text-lg"></i>
            <div class="flex-1">
                <div class="text-sm font-semibold text-amber-900">
                    <span x-text="emptyFolders.total_missing_folders"></span>
                    <span x-text="emptyFolders.total_missing_folders === 1 ? 'carpeta sin archivos' : 'carpetas sin archivos'"></span>
                    en
                    <span x-text="emptyFolders.storages_with_empty"></span>
                    <span x-text="emptyFolders.storages_with_empty === 1 ? 'storage' : 'storages'"></span>
                </div>
                <div class="text-xs text-amber-700 mt-0.5">
                    Si un medio no graba, puedes omitir su storage para no encolarlo.
                    <span x-show="!emptyFoldersExpanded">
                        <a href="#" @click.prevent="emptyFoldersExpanded = true" class="font-medium underline">Ver lista</a>
                    </span>
                </div>
            </div>
            <button @click="emptyFoldersExpanded = !emptyFoldersExpanded"
                    class="text-amber-700 hover:text-amber-900 px-2 py-1 rounded hover:bg-amber-100 transition-colors">
                <i :class="emptyFoldersExpanded ? 'fa-chevron-up' : 'fa-chevron-down'" class="fas text-xs"></i>
            </button>
        </div>
        <div x-show="emptyFoldersExpanded" x-transition class="border-t border-amber-200 px-5 py-3 space-y-3">
            <template x-for="item in emptyFolders.items" :key="item.storage_id">
                <div class="bg-white rounded-lg border border-amber-100 p-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium text-slate-800" x-text="item.storage_name"></div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                sp=<span x-text="item.storage_id"></span> ·
                                <span x-text="item.missing_count"></span>
                                <span x-text="item.missing_count === 1 ? 'carpeta' : 'carpetas'"></span>
                                sin archivos
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button @click="scanStorage(item.storage_id).catch(() => showToast('No se pudo escanear', 'error'))"
                                    class="text-xs px-3 py-1.5 text-slate-700 hover:bg-slate-100 rounded transition-colors">
                                <i class="fas fa-search mr-1"></i>Re-escanear
                            </button>
                            {{-- Omitir un medio que lleva días sin archivos: se apaga
                                 su storage aquí mismo, que es donde vive la decisión. --}}
                            <button @click="toggleStorage(storageById(item.storage_id))"
                                    x-show="storageById(item.storage_id)?.transcription_enabled"
                                    class="text-xs px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded transition-colors">
                                <i class="fas fa-power-off mr-1"></i>Dejar de transcribir
                            </button>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <template x-for="m in item.missing" :key="m">
                            <span class="text-xs px-2 py-0.5 bg-amber-100 text-amber-800 rounded font-mono" x-text="m"></span>
                        </template>
                        <span x-show="item.missing_count > item.missing.length"
                              class="text-xs px-2 py-0.5 text-amber-700 italic"
                              x-text="'+' + (item.missing_count - item.missing.length) + ' más'"></span>
                    </div>
                </div>
            </template>
        </div>
    </div>

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
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button @click="openFiles(s)"
                                        data-tour="storage-files"
                                        class="flex items-center gap-1 px-2.5 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors"
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

    {{-- TAB: TRABAJOS --}}
    <div x-show="tab === 'jobs'" x-transition:enter.opacity.duration.150ms>

        {{-- Sub-tabs: En proceso / Completados / Fallidos.
             Los contadores salen de stats.local (totales reales de BD), no de la
             pagina cargada: contar this.jobs mentia sobre el tamaño de la cola. --}}
        <div class="mb-3 flex items-center gap-2 flex-wrap">
            <button @click="setJobsSubTab('pending')"
                    :class="jobsSubTab === 'pending' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-clock text-xs"></i>
                En proceso
                <span :class="jobsSubTab === 'pending' ? 'bg-white text-brand-700' : 'bg-amber-100 text-amber-700'" class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold" x-text="jobsPendingCount.toLocaleString()"></span>
            </button>
            <button @click="setJobsSubTab('completed')"
                    :class="jobsSubTab === 'completed' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-check-circle text-xs"></i>
                Completados
                <span :class="jobsSubTab === 'completed' ? 'bg-white text-brand-700' : 'bg-green-100 text-green-700'" class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold" x-text="jobsCompletedCount.toLocaleString()"></span>
            </button>
            <button @click="setJobsSubTab('failed')"
                    :class="jobsSubTab === 'failed' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-triangle-exclamation text-xs"></i>
                Fallidos
                <span :class="jobsSubTab === 'failed' ? 'bg-white text-brand-700' : 'bg-red-100 text-red-700'" class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold" x-text="jobsFailedCount.toLocaleString()"></span>
            </button>

            <div class="flex-1"></div>

            <div class="relative">
                <input type="text" x-model="search" @input.debounce.400ms="reload()"
                       placeholder="Buscar por nombre de archivo..."
                       class="border border-slate-300 rounded-lg pl-9 pr-4 py-1.5 text-sm w-64 focus:ring-2 focus:ring-brand-500 outline-none">
                <i class="fas fa-search absolute left-3 top-2 text-slate-400 text-xs"></i>
            </div>
            <select x-model="stateFilter" @change="reload()"
                    class="border border-slate-300 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                <option value="">Todos</option>
                <template x-for="s in scopeStates()" :key="s">
                    <option :value="s" x-text="s"></option>
                </template>
            </select>
            <button @click="load()" class="text-xs text-brand-600 hover:underline whitespace-nowrap">
                <i class="fas fa-sync-alt" :class="loading ? 'fa-spin' : ''"></i>
            </button>
            <span x-show="batchRunning" x-transition.opacity class="inline-flex items-center gap-1.5 text-[10px] text-brand-700 bg-brand-50 border border-brand-200 rounded-full px-2 py-0.5 whitespace-nowrap">
                <i class="fas fa-circle-notch fa-spin text-[9px]"></i>
                <span>Sincronizando En proceso</span>
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
                    Los estados <b>pending</b> indican transcripciones creadas pero aún no enviadas a la API externa. Si tienes muchas, ejecuta <code class="bg-slate-100 px-1 rounded">php artisan transcriptor:diagnose-pending</code>. Para las que acabaron en <b>dead</b> porque el transcriptor perdió su resultado, usa <code class="bg-slate-100 px-1 rounded">php artisan transcription:backfill-lost --audit</code>.
                </p>
            </div>
        </details>

        <!-- Tabla de jobs (vista unificada) -->

        {{-- Action bar para dispatch masivo de jobs pendientes --}}
        {{-- Los contadores salen de jobsPendingCount (stats de BD), no de la pagina:
             sin seleccion el servidor autoselecciona hasta 2000, asi que anunciar
             las 50 filas visibles subestimaria el lote real. --}}
        <div x-show="jobsSubTab === 'pending' && (jobsPendingCount > 0 || bulkDispatchResult)"
             x-transition.opacity
             class="mb-3 flex items-center gap-3 px-4 py-2.5 bg-brand-50 border border-brand-200 rounded-lg text-sm">
            <div x-show="!bulkDispatchResult" class="flex items-center gap-3 flex-wrap">
                {{-- Desglose explicito: "N pendientes en cola" mezclaba dos
                     situaciones opuestas. El boton solo actua sobre las SIN
                     ENVIAR; las que ya estan en la API las recoge el poller. --}}
                <span x-show="selectJobMode && selectedJobIds.size > 0"
                      class="flex items-center gap-2 text-brand-800 font-medium">
                    <i class="fas fa-rocket text-brand-500"></i>
                    <span x-text="selectedJobIds.size + ' seleccionados'"></span>
                </span>
                <span x-show="!(selectJobMode && selectedJobIds.size > 0)" class="flex items-center gap-3 flex-wrap">
                    <span class="flex items-center gap-2 text-brand-800 font-medium"
                          title="Transcripciones creadas que aun no se han enviado a la API.">
                        <i class="fas fa-paper-plane text-brand-500"></i>
                        <span x-text="jobsUnsentCount.toLocaleString() + ' sin enviar'"></span>
                    </span>
                    <span x-show="jobsInApiCount > 0"
                          class="flex items-center gap-2 text-slate-600"
                          title="Ya enviadas a la API: el resultado se recoge automaticamente por polling. No requieren accion.">
                        <i class="fas fa-hourglass-half text-slate-400"></i>
                        <span x-text="jobsInApiCount.toLocaleString() + ' en la API esperando resultado'"></span>
                    </span>
                </span>
                <button @click="bulkDispatchPending()"
                        x-show="jobsUnsentCount > 0 || (selectJobMode && selectedJobIds.size > 0)"
                        :disabled="bulkDispatching || (selectJobMode && selectedJobIds.size === 0)"
                        class="flex items-center gap-2 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-xs">
                    <i class="fas fa-paper-plane text-[10px]" :class="bulkDispatching ? 'fa-spin' : ''"></i>
                    <span x-text="bulkDispatching ? 'Procesando...' : bulkActionLabel"></span>
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
                            {{-- La accion hace DOS cosas segun el estado de cada
                                 fila: enviar las que nunca salieron y recoger el
                                 resultado de las que ya estaban en la API. Antes
                                 solo se reportaba lo primero, asi que procesar
                                 filas queued parecia no hacer nada. --}}
                            <template x-if="bulkDispatchResult.enqueued > 0">
                                <span>
                                    <strong x-text="bulkDispatchResult.enqueued"></strong> enviadas a la API
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.collected > 0">
                                <span class="text-green-700">
                                    · <strong x-text="bulkDispatchResult.collected"></strong> transcripciones recogidas
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.still_pending > 0">
                                <span class="text-slate-500">
                                    · <strong x-text="bulkDispatchResult.still_pending"></strong> siguen en la API sin terminar (se recogen solas)
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.lost > 0">
                                <span class="text-red-700">
                                    · <strong x-text="bulkDispatchResult.lost"></strong> con el resultado perdido en la API (hay que reenviar el audio)
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.not_polled > 0">
                                <span class="text-slate-500">
                                    · <strong x-text="bulkDispatchResult.not_polled"></strong> sin consultar en esta tanda (las toma el poller de fondo)
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.dispatch_paused">
                                <span class="text-amber-700">
                                    · Envío pausado (dispatch_paused): solo se recogieron resultados
                                </span>
                            </template>
                            <template x-if="bulkDispatchResult.skipped_queued > 0">
                                <span class="text-slate-500">
                                    · <strong x-text="bulkDispatchResult.skipped_queued"></strong> omitidos (ya terminados)
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
                               title="Seleccionar todas las filas procesables de esta página">
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
                {{-- Sin filtro x-show por fila: el servidor ya devuelve solo el scope
                     de la sub-tab activa, paginado. --}}
                <template x-for="job in jobs" :key="job.id">
                    <tr class="hover:bg-slate-50">
                        <td x-show="jobsSubTab === 'pending' && selectJobMode" class="px-2 py-3 text-center" @click.stop>
                            <input type="checkbox"
                                   class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer disabled:opacity-40"
                                   :checked="isJobSelected(job.id)"
                                   :disabled="!isDispatchable(job)"
                                   :title="!isDispatchable(job) ? 'Job terminado: no hay nada que procesar'
                                           : (job.job_id ? 'Seleccionar para consultar su resultado en la API' : 'Seleccionar para enviar a la API')"
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
                                {{-- En proceso: Enviar ahora (sin job_id) / Refrescar estado (con job_id) --}}
                                <button x-show="jobsSubTab === 'pending' && ['pending','queued','processing'].includes(job.state)"
                                        @click="job.job_id ? refreshJobStatus(job) : dispatchJobNow(job)"
                                        :disabled="refreshingJobs && refreshingJobs.has(Number(job.id))"
                                        class="inline-flex items-center justify-center px-2.5 h-8 bg-brand-600 hover:bg-brand-700 text-white rounded-lg transition-colors text-xs font-medium whitespace-nowrap disabled:opacity-50"
                                        :title="job.job_id ? 'Consultar upstream y actualizar estado' : 'Enviar al transcriptor (ffmpeg + POST)'">
                                    <i :class="(refreshingJobs && refreshingJobs.has(Number(job.id))) ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" class="text-[10px] mr-1"></i>
                                    <span x-text="job.job_id ? 'Refrescar estado' : 'Enviar ahora'"></span>
                                </button>
                                {{-- En proceso (pending): Borrar (sin job_id, delete local) --}}
                                <button x-show="jobsSubTab === 'pending' && job.state === 'pending'"
                                        @click="cancelJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg transition-colors"
                                        title="Borrar fila pendiente (no fue enviada a la API externa)">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                                {{-- En proceso (queued|processing): Cancelar upstream --}}
                                <button x-show="jobsSubTab === 'pending' && (job.state === 'queued' || job.state === 'processing')"
                                        @click="cancelJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-amber-50 hover:bg-amber-100 text-amber-600 rounded-lg transition-colors"
                                        title="Cancelar job">
                                    <i class="fas fa-ban text-xs"></i>
                                </button>
                                {{-- Completados: Ver transcripción en modal, sin salir del listado --}}
                                <button x-show="job.state === 'done'"
                                        @click="openTranscript(job)"
                                        class="inline-flex items-center justify-center px-2.5 h-8 bg-brand-600 hover:bg-brand-700 text-white rounded-lg transition-colors text-xs font-medium whitespace-nowrap"
                                        title="Ver la transcripción en una ventana">
                                    <i class="fas fa-file-lines text-[10px] mr-1"></i>
                                    <span>Ver transcripción</span>
                                </button>
                                {{-- Completados: Reprocesar (done) --}}
                                <button x-show="job.state === 'done'"
                                        @click="reprocessJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-brand-50 hover:bg-brand-100 text-brand-600 rounded-lg transition-colors"
                                        title="Reprocesar transcripción">
                                    <i class="fas fa-redo text-xs"></i>
                                </button>
                                {{-- Fallidos: Reintentar (error/dead) --}}
                                <button x-show="job.state === 'error' || job.state === 'dead'"
                                        @click="reprocessJob(job)"
                                        class="inline-flex items-center justify-center w-8 h-8 bg-amber-50 hover:bg-amber-100 text-amber-600 rounded-lg transition-colors"
                                        title="Reintentar transcripción">
                                    <i class="fas fa-redo text-xs"></i>
                                </button>
                                <a :href="'/ia/api-transcriptor/jobs/' + job.id"
                                   class="inline-flex items-center justify-center w-8 h-8 bg-slate-100 hover:bg-brand-50 text-slate-500 hover:text-brand-600 rounded-lg transition-colors"
                                   title="Abrir la página de detalle">
                                    <i class="fas fa-eye text-xs"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr x-show="!loading && jobs.length === 0">
                    <td colspan="7" class="text-center py-12 text-slate-400">
                        <i class="fas fa-inbox text-3xl mb-2 block text-slate-200"></i>
                        <p class="font-medium" x-text="emptyStateTitle()"></p>
                        <p class="text-xs mt-1" x-text="emptyStateHint()"></p>
                    </td>
                </tr>
            </tbody>
        </table>

        {{-- Paginacion (servidor). El tope de window_max filas es de navegacion,
             no de datos: mas atras se busca por nombre, no se pagina. --}}
        <div x-show="!loading && pagination.total > 0"
             class="mt-3 flex items-center justify-between gap-3 flex-wrap text-sm">
            <div class="text-slate-500 text-xs">
                <span x-text="'Mostrando ' + pageRangeStart() + '–' + pageRangeEnd() + ' de ' + pagination.total.toLocaleString()"></span>
                <span x-show="pagination.capped" class="text-slate-400">
                    · mostrando los <span x-text="pagination.window_max"></span> más recientes; usa el buscador para ir más atrás
                </span>
            </div>
            <div class="flex items-center gap-2">
                <button @click="goToPage(pagination.page - 1)"
                        :disabled="pagination.page <= 1"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-chevron-left text-[10px]"></i> Anterior
                </button>
                <span class="text-xs text-slate-600 whitespace-nowrap"
                      x-text="'Página ' + pagination.page + ' de ' + pagination.total_pages"></span>
                <button @click="goToPage(pagination.page + 1)"
                        :disabled="pagination.page >= pagination.total_pages"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    Siguiente <i class="fas fa-chevron-right text-[10px]"></i>
                </button>
            </div>
        </div>

    </div> {{-- /TAB JOBS --}}

    {{-- TAB: CONFIGURACIÓN --}}
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
                            <span class="text-xs text-slate-500">Cola Redis</span>
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
                    <div class="px-5 py-3 border-b border-slate-100 bg-slate-50/60">
                        <h3 class="text-sm font-semibold text-slate-700" x-text="groupLabel(group)"></h3>
                        <p class="text-xs text-slate-400 mt-0.5" x-text="groupHelp(group)"></p>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <template x-for="k in cfgKeysIn(group)" :key="k">
                            <div class="px-5 py-3.5 flex items-start gap-4" :data-tour="'cfg-knob-' + k">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <label class="text-sm font-medium text-slate-700" x-text="cfgMeta[k].label"></label>
                                        <span x-show="cfgMeta[k].source === 'bd'"
                                              class="text-[10px] px-1.5 py-0.5 bg-brand-100 text-brand-700 rounded font-semibold">modificado</span>
                                        <code class="text-[10px] text-slate-400" x-text="k"></code>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-1 leading-relaxed" x-text="cfgMeta[k].help"></p>
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
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Tamaño del lote</label>
                        <div class="flex items-center gap-3">
                            <input type="range" min="10" :max="uiBatchMax" step="10" x-model.number="batchSize"
                                   class="flex-1 accent-brand-600">
                            <span class="text-2xl font-bold text-brand-600 w-16 text-center" x-text="batchSize"></span>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <button @click="batchSize = 50" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">50</button>
                            <button @click="batchSize = 100" class="px-2 py-1 text-xs bg-brand-100 text-brand-700 hover:bg-brand-200 rounded font-medium">100</button>
                            <button @click="batchSize = 200" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">200</button>
                            <button @click="batchSize = uiBatchMax" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded" x-text="uiBatchMax"></button>
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

    {{-- ============================ MODAL: VER TRANSCRIPCIÓN ============================
         Pantalla completa en móvil, panel centrado en escritorio. --}}
    <div x-cloak x-show="transcript.open"
         class="fixed inset-0 bg-black/50 z-50 flex items-stretch sm:items-center sm:justify-center sm:p-6"
         @keydown.escape.window="closeTranscript()"
         x-transition>
        <div class="bg-white w-full flex flex-col shadow-2xl sm:rounded-2xl sm:max-w-4xl sm:max-h-[85vh]"
             @click.away="closeTranscript()">

            {{-- Cabecera --}}
            <div class="px-4 sm:px-5 py-3 border-b border-slate-200 flex items-start justify-between gap-3 flex-shrink-0">
                <div class="min-w-0">
                    <h3 class="font-semibold text-slate-800 truncate text-sm sm:text-base">
                        <i class="fas fa-file-lines text-brand-500 mr-1.5"></i>
                        <span x-text="transcript.data?.file_name || transcript.job?.original_name || 'Transcripción'"></span>
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Transcripción #<span x-text="transcript.job?.id"></span>
                        <template x-if="transcript.data?.finished_at">
                            <span> · Finalizado <span x-text="formatDate(transcript.data.finished_at)"></span></span>
                        </template>
                    </p>
                </div>
                <button @click="closeTranscript()" class="text-slate-400 hover:text-slate-600 flex-shrink-0 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            {{-- Pestañas + buscador --}}
            <div class="px-4 sm:px-5 py-2.5 border-b border-slate-100 flex items-center gap-2 flex-wrap flex-shrink-0">
                <div class="flex items-center gap-1 bg-slate-100 rounded-lg p-0.5">
                    <button @click="transcript.view = 'texto'"
                            :class="transcript.view === 'texto' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
                            class="px-3 py-1 rounded-md text-xs font-medium transition-colors">Texto</button>
                    <button @click="transcript.view = 'segmentos'"
                            :class="transcript.view === 'segmentos' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
                            class="px-3 py-1 rounded-md text-xs font-medium transition-colors">
                        Segmentos
                        <span class="text-[10px] text-slate-400" x-show="transcript.data" x-text="'(' + (transcript.data?.segments?.length || 0) + ')'"></span>
                    </button>
                </div>
                <div class="relative flex-1 min-w-[140px]">
                    <input type="text" x-model="transcript.q"
                           placeholder="Buscar en la transcripción..."
                           class="w-full border border-slate-300 rounded-lg pl-8 pr-3 py-1.5 text-xs focus:ring-2 focus:ring-brand-500 outline-none">
                    <i class="fas fa-search absolute left-2.5 top-2 text-slate-400 text-[10px]"></i>
                </div>
                <span x-show="transcript.q && transcript.view === 'segmentos'" class="text-[11px] text-slate-500 whitespace-nowrap"
                      x-text="visibleTranscriptSegments().length + ' coincidencias'"></span>
            </div>

            {{-- Cuerpo --}}
            <div class="flex-1 overflow-y-auto px-4 sm:px-5 py-4 min-h-0">
                <template x-if="transcript.loading">
                    <div class="py-16 text-center text-slate-400">
                        <i class="fas fa-circle-notch fa-spin text-2xl mb-2 block"></i>
                        <p class="text-sm">Cargando transcripción...</p>
                    </div>
                </template>

                <template x-if="!transcript.loading && transcript.error">
                    <div class="py-16 text-center text-red-500">
                        <i class="fas fa-triangle-exclamation text-2xl mb-2 block"></i>
                        <p class="text-sm" x-text="transcript.error"></p>
                    </div>
                </template>

                <template x-if="!transcript.loading && !transcript.error && transcript.data">
                    <div>
                        <div x-show="transcript.data.segments_truncated"
                             class="mb-3 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-[11px] text-amber-800">
                            <i class="fas fa-info-circle mr-1"></i>
                            Transcripción muy larga: se muestran los primeros segmentos. Descarga el .srt para el contenido completo.
                        </div>

                        {{-- Vista texto --}}
                        <div x-show="transcript.view === 'texto'">
                            <p x-show="!transcript.data.plain_text" class="text-slate-400 italic text-sm py-8 text-center">
                                Esta transcripción no tiene texto asociado.
                            </p>
                            <p x-show="transcript.data.plain_text"
                               class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap break-words"
                               x-html="highlight(transcript.data.plain_text, transcript.q)"></p>
                        </div>

                        {{-- Vista segmentos --}}
                        <div x-show="transcript.view === 'segmentos'" class="space-y-1.5">
                            <p x-show="visibleTranscriptSegments().length === 0" class="text-slate-400 italic text-sm py-8 text-center">
                                <span x-text="transcript.q ? 'Ningún segmento coincide con la búsqueda.' : 'Esta transcripción no tiene segmentos.'"></span>
                            </p>
                            <template x-for="seg in visibleTranscriptSegments()" :key="seg.segment_index">
                                <div class="flex gap-3 px-2 py-1.5 rounded hover:bg-slate-50">
                                    <span class="text-[11px] font-mono text-slate-400 whitespace-nowrap pt-0.5 flex-shrink-0"
                                          x-text="seg.start_label + ' → ' + seg.end_label"></span>
                                    <span class="text-sm text-slate-700 leading-relaxed break-words"
                                          x-html="highlight(seg.text, transcript.q)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Pie --}}
            <div class="px-4 sm:px-5 py-3 border-t border-slate-200 flex items-center justify-between gap-3 flex-wrap flex-shrink-0 bg-slate-50 sm:rounded-b-2xl">
                <div class="text-[11px] text-slate-500" x-show="transcript.data">
                    <span x-show="transcript.data?.duration_seconds" x-text="formatDuration(transcript.data?.duration_seconds)"></span>
                    <span x-show="transcript.data?.word_count"> · <span x-text="(transcript.data?.word_count || 0).toLocaleString()"></span> palabras</span>
                    <span x-show="transcript.data?.language"> · <span x-text="transcript.data?.language"></span></span>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button @click="copyTranscript()" :disabled="!transcript.data?.plain_text"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition-colors disabled:opacity-40">
                        <i class="fas fa-copy text-[10px]"></i> Copiar
                    </button>
                    <button @click="downloadTranscriptSrt()" :disabled="!transcript.data?.srt_content"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition-colors disabled:opacity-40">
                        <i class="fas fa-download text-[10px]"></i> Descargar .srt
                    </button>
                    <a :href="'/ia/api-transcriptor/jobs/' + (transcript.job?.id || '')"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-medium transition-colors">
                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i> Abrir detalle
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

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

function apiTranscriptor() {
    return {
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
        emptyFolders: null,
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
        batchAlerts: false,
        batchIncludeFailed: false,
        batchResult: null,
        batchRunId: null,
        batchPollTimer: null,
        batchProgress: null,
        // Mini-modal confirmación carpeta/día
        showProcessConfirm: false,
        processConfirmText: '',
        processConfirmAction: null,
        processAlerts: false,
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
            await Promise.all([this.load(), this.loadHealth(), this.loadEmptyFolders()]);
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
            if (!opts.jobsOnly) this.loadStats();
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
        async loadEmptyFolders() {
            try {
                const res = await apiFetch('/ia/api-transcriptor/empty-folders', { headers: { 'Accept': 'application/json' } });
                if (res.ok) this.emptyFolders = await res.json();
                else this.emptyFolders = { items: [], storages_with_empty: 0, total_missing_folders: 0 };
            } catch { this.emptyFolders = { items: [], storages_with_empty: 0, total_missing_folders: 0 }; }
        },
        storageById(id) {
            return this.storages.find(s => s.id === Number(id));
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
                    body: JSON.stringify({ batch: this.batchSize, generate_alerts: this.batchAlerts, include_failed: this.batchIncludeFailed }),
                });
                const res = await Promise.race([
                    fetchPromise,
                    new Promise((_, reject) => setTimeout(() => reject(new Error('watchdog-timeout')), 5000))
                ]);
const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        showToast(data.error || 'Error al iniciar el lote', 'error');
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
                    this.batchResult = {
                        processed: 0,
                        errors: 1,
                        total_candidates: 0,
                        storages: [],
                        files: [],
                        per_storage_errors: [],
                        message: 'Error de conexión: ' + (e?.message || 'sin respuesta del servidor. Reintenta o revisa los logs.'),
                    };
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

<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startApiTranscriptorTour() {
    function getAlpine() {
        var allData = document.querySelectorAll('[x-data]');
        for (var i = 0; i < allData.length; i++) {
            var el = allData[i];
            if (el._x_dataStack && el._x_dataStack[0]) {
                var data = el._x_dataStack[0];
                if (data.storages !== undefined && data.tab !== undefined) return data;
            }
        }
        var first = document.querySelector('[x-data]');
        return first ? (first._x_dataStack ? first._x_dataStack[0] : null) : null;
    }

    var alpine = getAlpine();
    if (alpine) {
        alpine.showBatchModal = false;
        alpine.showFiles = false;
        alpine.showInfo = false;
    }

    function scrollTo(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        return el;
    }

    function getFirstStorageRow() {
        return document.querySelector('table tbody tr') || null;
    }

    function getCellText(idx) {
        var row = getFirstStorageRow();
        if (!row) return null;
        var cells = row.querySelectorAll('td');
        return cells[idx] || null;
    }

    // Anclas data-tour en vez de selectores por @click: el atributo real es
    // @click="toggleEnabled(s)" y la coincidencia EXACTA de
    // button[\@click="toggleEnabled"] no casaba nunca. Ambos pasos llevaban
    // tiempo apuntando a null, asi que el tour los mostraba sin resaltar nada.
    function getStorageToggle() {
        var row = getFirstStorageRow();
        if (!row) return null;
        return row.querySelector('[data-tour="storage-toggle"]') || null;
    }

    function getVerArchivosBtn() {
        var row = getFirstStorageRow();
        if (!row) return null;
        return row.querySelector('[data-tour="storage-files"]') || null;
    }

    function switchTab(tab) {
        var a = getAlpine();
        if (a) a.tab = tab;
    }

    var currentTab = alpine ? alpine.tab : 'storages';

    // Espera a que el panel de Configuración termine de cargar. Es una pestaña
    // de carga diferida: si el tour arranca antes de que llegue la respuesta,
    // los selectores no existen todavía y los pasos se saltarían.
    function whenConfigReady(done) {
        var a = getAlpine();
        if (!a) { done(); return; }
        if (a.cfgMeta) { done(); return; }
        if (!a.cfgLoading) a.loadConfig();
        var tries = 0;
        var iv = setInterval(function () {
            var cur = getAlpine();
            if ((cur && cur.cfgMeta) || ++tries > 40) { clearInterval(iv); done(); }
        }, 150);
    }

    function tourEl(name) {
        return document.querySelector('[data-tour="' + name + '"]');
    }

    // Enfoca un elemento del panel, esperando primero a que el panel exista.
    function focusCfg(name) {
        return function (done) {
            whenConfigReady(function () {
                var el = tourEl(name);
                if (el) scrollTo(el);
                setTimeout(done, 180);
            });
        };
    }

    if (currentTab === 'config') {
        TcloudTour.start({
            steps: [
                {
                    title: 'Configuración del motor',
                    content: 'Desde aquí <strong>regulas el pipeline en caliente</strong>, sin editar archivos ni desplegar. ' +
                             'Todo lo que cambies aquí surte efecto en el siguiente ciclo, incluso en los procesos que llevan horas corriendo.<br><br>' +
                             'El problema que resuelve esta pestaña no es <em>cuánto</em> se envía, sino que se enviaba <strong>todo de golpe</strong>: ' +
                             'la tarea programada disparaba el lote entero en un instante y el servidor se ahogaba.',
                    icon: 'fa-sliders-h',
                    color: '#4654a8',
                    selector: null,
                    position: 'center',
                    async: true,
                    onShow: function (done) { whenConfigReady(done); }
                },
                {
                    title: 'Freno de emergencia',
                    content: 'Si el servidor se está saturando, este botón <strong>corta el envío al instante</strong>.<br><br>' +
                             'Importante: el descubrimiento <strong>sigue activo</strong>. Las grabaciones nuevas se siguen detectando y registrando; ' +
                             'solo deja de encolarse trabajo. <strong>No se pierde nada</strong>: al reanudar, todo lo acumulado sale en orden.<br><br>' +
                             'Mientras esté pausado, los botones de envío del módulo responden con un aviso en lugar de encolar.',
                    icon: 'fa-pause',
                    color: '#f59e0b',
                    selector: '[data-tour="cfg-pause"]',
                    position: 'bottom',
                    async: true,
                    onShow: focusCfg('cfg-pause')
                },
                {
                    title: 'La tarea programada',
                    content: 'La tarea de descubrimiento y envío: <code>transcription:tick</code>. ' +
                             'Cada pocos minutos <strong>escanea el disco</strong> buscando grabaciones nuevas y <strong>encola</strong> las que faltan.<br><br>' +
                             'Aquí ves cada cuánto corre y cuándo fue la última vez. El intervalo es ajustable más abajo ' +
                             '(<code>tick_interval_minutes</code>): subirlo espacia las tandas, bajarlo las acerca.',
                    icon: 'fa-clock',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-task"]',
                    position: 'bottom',
                    async: true,
                    onShow: focusCfg('cfg-task')
                },
                {
                    title: 'Probar sin arriesgar',
                    content: '<strong>Simular</strong> ejecuta la tarea en modo prueba: calcula y reporta qué haría, <em>sin encolar nada</em>. ' +
                             'Úsalo para ver el efecto de un cambio antes de dejarlo puesto.<br><br>' +
                             '<strong>Ejecutar ahora</strong> lanza el ciclo de verdad, sin esperar al siguiente turno.',
                    icon: 'fa-flask',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-run"]',
                    position: 'left',
                    async: true,
                    onShow: focusCfg('cfg-run')
                },
                {
                    title: 'Cola contra objetivo',
                    content: 'La barra compara <strong>lo que hay en cola</strong> con el <strong>objetivo</strong> que fijaste.<br><br>' +
                             'Debajo está el dato más útil de toda la pantalla: <strong>cuántos trabajos enviaría ahora mismo</strong> ' +
                             'con los valores actuales. Cambia un número arriba y este cálculo responde al instante.<br><br>' +
                             'Si la barra se pone ámbar, el regulador está frenando: la cola ya alcanzó el objetivo y no se enviará más hasta que baje.',
                    icon: 'fa-gauge-high',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-queue"]',
                    position: 'bottom',
                    async: true,
                    onShow: focusCfg('cfg-queue')
                },
                {
                    title: 'Workers activos',
                    content: 'Los <strong>workers</strong> son los procesos que sacan trabajo de la cola y ejecutan la conversión de audio. ' +
                             'Cuantos más, más carga simultánea sobre este servidor.<br><br>' +
                             'El número se ajusta solo cada 5 minutos. Si aparece <span style="color:#dc2626"><strong>huérfanos activos</strong></span> en rojo, ' +
                             'hay procesos fuera de control consumiendo la cola sin que el sistema los gestione — se desactivan solos en el siguiente ajuste.',
                    icon: 'fa-microchip',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-workers"]',
                    position: 'left',
                    async: true,
                    onShow: focusCfg('cfg-workers')
                },
                {
                    title: 'Estado de las transcripciones',
                    content: 'Conteo por estado en este momento. Sirve de termómetro rápido:<br><br>' +
                             '<ul style="margin:0;padding-left:18px;">' +
                             '<li><strong>pending</strong> alto: se están creando filas pero no salen hacia la API — mira el envío (¿pausado? ¿cola llena?)</li>' +
                             '<li><strong>queued / processing</strong> altos y subiendo: ya se enviaron, pero los resultados no se recogen al ritmo que entran</li>' +
                             '<li><strong>error</strong> creciendo: algo falla en la conversión o en el envío</li>' +
                             '<li><strong>dead</strong>: terminal. Puede ser por reintentos agotados, porque el transcriptor perdió el resultado, ' +
                             'o por caducidad (<code>poll_max_age_hours</code>). Los dos últimos se recuperan con ' +
                             '<code>transcription:backfill-lost</code>, que reenvía el audio original</li>' +
                             '</ul>',
                    icon: 'fa-list-check',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-states"]',
                    position: 'left',
                    async: true,
                    onShow: focusCfg('cfg-states')
                },
                {
                    title: 'Ritmo de envío — lo que evita el golpe',
                    content: 'Este grupo es el que resuelve tu problema. Tres controles, de menor a mayor efecto:<br><br>' +
                             '<ul style="margin:0;padding-left:18px;">' +
                             '<li><strong>Goteo entre envíos</strong>: separa cada trabajo unos milisegundos. En 0 salen todos juntos; ' +
                             'con 200 ms un lote de 145 se reparte en medio minuto en vez de arrancar de golpe. <em>Es el ajuste más directo contra la ráfaga.</em></li>' +
                             '<li><strong>Objetivo de cola</strong>: por encima de este número el sistema deja de enviar hasta que baje.</li>' +
                             '<li><strong>Máximo ffmpeg simultáneos</strong>: el tope real de conversiones a la vez, <em>sin importar cuántos workers haya</em>. ' +
                             'Es el freno más contundente si el servidor sufre.</li>' +
                             '</ul>',
                    icon: 'fa-wave-square',
                    color: '#16a34a',
                    selector: '[data-tour="cfg-group-ritmo"]',
                    position: 'top',
                    async: true,
                    onShow: focusCfg('cfg-group-ritmo')
                },
                {
                    title: 'API y tmpfs — protección contra /dev/shm lleno',
                    content: 'Este grupo blindan el envío contra el escenario que paraliza la transcripción: <strong>/dev/shm al 100%</strong>.<br><br>' +
                             '<ul style="margin:0;padding-left:18px;">' +
                             '<li><strong>Mínimo libre en /dev/shm</strong>: si la tmpfs tiene menos de esto (200 MB por defecto), el submit se aborta <em>antes</em> de invocar ffmpeg y el job se reencola automáticamente.</li>' +
                             '<li><strong>Minutos hasta reintento</strong>: tiempo que el tick ignora un job rebotado para no quemarlo a ffmpeg cada 2 min.</li>' +
                             '<li><strong>Umbral de WARNING en /dev/shm</strong>: porcentaje (80% por defecto) a partir del cual <code>transcription:check-shm-health</code> emite log de WARNING. Endpoint <code>/ia/api-transcriptor/shm-status</code> lo expone para UI/monitoring.</li>' +
                             '</ul>' +
                             'Si la fuga de fd reaparece, este grupo la detecta antes de que /dev/shm llegue al 100% y bloquee los workers.',
                    icon: 'fa-hard-drive',
                    color: '#dc2626',
                    selector: '[data-tour="cfg-group-api"]',
                    position: 'top',
                    async: true,
                    onShow: focusCfg('cfg-group-api')
                },
                {
                    title: 'Confiabilidad — que nada se quede a medias',
                    content: 'Este grupo gobierna la <strong>recogida de resultados</strong>, que es el único camino por el que vuelve una transcripción: ' +
                             'no hay webhook, si nadie consulta no llega nada.<br><br>' +
                             '<ul style="margin:0;padding-left:18px;">' +
                             '<li><strong>Jobs consultados por ciclo</strong> (<code>poll_limit</code>): cuántos se preguntan cada minuto. ' +
                             'Debe ir al menos al nivel del objetivo de cola, o la recogida no alcanza al envío y los terminados se acumulan.</li>' +
                             '<li><strong>Antigüedad máxima en queued</strong> (<code>poll_max_age_hours</code>): pasado ese plazo, una transcripción ' +
                             'que sigue sin resolverse se cierra como <code>dead</code> en lugar de sondearse para siempre.</li>' +
                             '<li><strong>Reintentos antes de dead</strong> (<code>max_retries</code>): fallos de envío consecutivos tolerados.</li>' +
                             '</ul>' +
                             '<div style="margin-top:10px;padding:8px 10px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:4px;font-size:0.92em;">' +
                             'La antigüedad máxima existe por un caso real: miles de transcripciones cuyo resultado el transcriptor había perdido ' +
                             'se reconsultaban cada minuto indefinidamente, gastando casi todos los slots del ciclo y tapando al trabajo del día. ' +
                             'Sin este corte, una fila condenada nunca sale de la cola por sí sola.' +
                             '</div>',
                    icon: 'fa-shield-halved',
                    color: '#f59e0b',
                    selector: '[data-tour="cfg-group-confiabilidad"]',
                    position: 'top',
                    async: true,
                    onShow: focusCfg('cfg-group-confiabilidad')
                },
                {
                    title: 'Cómo leer cada control',
                    content: 'Cada fila te dice todo lo que necesitas para decidir:<br><br>' +
                             '<ul style="margin:0;padding-left:18px;">' +
                             '<li>Una <strong>explicación</strong> de qué hace y por qué importa</li>' +
                             '<li>El <strong>rango permitido</strong>, bajo la casilla — no puedes salirte de él</li>' +
                             '<li>El <strong>valor por defecto</strong>, para saber de dónde partiste</li>' +
                             '<li>La etiqueta <strong>modificado</strong> cuando lo has cambiado, con un enlace <strong>restaurar</strong> para volver atrás</li>' +
                             '</ul>',
                    icon: 'fa-circle-info',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-knob-dispatch_stagger_ms"]',
                    position: 'top',
                    async: true,
                    onShow: focusCfg('cfg-knob-dispatch_stagger_ms')
                },
                {
                    title: 'Guardar y deshacer',
                    content: 'Los cambios <strong>no se aplican hasta que guardas</strong>. Mientras haya cambios pendientes verás el aviso en ámbar; ' +
                             '<strong>Descartar</strong> los revierte.<br><br>' +
                             'Si algún valor no es válido, la casilla se marca en rojo con el motivo y <strong>no se guarda nada</strong> — ' +
                             'nunca queda a medias.<br><br>' +
                             'Al guardar no hay que reiniciar nada: el siguiente ciclo ya usa los valores nuevos.',
                    icon: 'fa-floppy-disk',
                    color: '#4654a8',
                    selector: '[data-tour="cfg-save"]',
                    position: 'top',
                    async: true,
                    onShow: focusCfg('cfg-save')
                },
                {
                    title: 'Receta ante una saturación',
                    content: 'Si el servidor se está ahogando, en este orden:<br><br>' +
                             '<ol style="margin:0;padding-left:18px;">' +
                             '<li><strong>Pausa el envío</strong> para respirar. No pierdes nada.</li>' +
                             '<li>Sube el <strong>goteo</strong> a 200–500 ms.</li>' +
                             '<li>Si aun así sufre, pon <strong>máximo ffmpeg simultáneos</strong> en 2–4.</li>' +
                             '<li><strong>Simula</strong> para ver qué haría, y reanuda.</li>' +
                             '</ol><br>' +
                             'Todo es reversible con <strong>restaurar</strong>. Repite esta guía cuando quieras con el botón morado.',
                    icon: 'fa-check-circle',
                    color: '#16a34a',
                    selector: null,
                    position: 'center'
                }
            ]
        });
    } else if (currentTab === 'storages') {
        TcloudTour.start({
            steps: [
                {
                    title: 'API Transcriptor',
                    content: 'Este módulo permite <strong>transcribir audio</strong> automáticamente desde archivos almacenados en storages habilitados. ' +
                             'Usa una API externa: se le envía el audio con <code>ffmpeg</code> + <code>POST /v1/transcribe</code> y después ' +
                             '<strong>Tcloud va a buscar el resultado</strong> consultando <code>GET /v1/jobs/{id}</code>. No hay webhook: si nadie consulta, nada vuelve. ' +
                             'La página tiene tres vistas: <strong>Storages</strong> (qué se transcribe), <strong>Trabajos</strong> (qué está pasando) '
                             + 'y <strong>Configuración</strong> (a qué ritmo se envía, para que el servidor no se sature).',
                    icon: 'fa-microphone-alt',
                    color: '#6366f1',
                    selector: null,
                    position: 'center'
                },
                {
                    title: '¿Cómo funciona?',
                    content: 'Panel desplegable con la explicación detallada del flujo automático: ' +
                             'descubrimiento cada 2 min → regulador de cola → conversión con ffmpeg → POST al transcriptor → ' +
                             '<strong>polling que recoge el SRT</strong> → segmentos y alertas. ' +
                             'Incluye el endpoint, el idioma, los estados de job y los tres motivos por los que una transcripción acaba en <code>dead</code>.',
                    icon: 'fa-circle-info',
                    color: '#6366f1',
                    selector: 'button[\\@click="showInfo = !showInfo"]',
                    position: 'bottom',
                    onShow: function () {
                        var btn = document.querySelector('button[\\@click="showInfo = !showInfo"]');
                        if (btn) scrollTo(btn);
                    }
                },
                {
                    title: 'Pestañas',
                    content: 'Cambia entre <strong>Storages</strong> (gestión de orígenes) y <strong>Trabajos</strong> (jobs de transcripción). ' +
                             'El badge de Storages cuenta los habilitados; el de Trabajos, las transcripciones <strong>en proceso</strong> ' +
                             '(creadas pero sin enviar, más las que están en la API esperando resultado).',
                    icon: 'fa-layer-group',
                    color: '#475569',
                    selector: 'div.mb-4.flex.items-center.gap-1.border-b.border-slate-200',
                    position: 'bottom',
                    onShow: function () {
                        var tabs = document.querySelector('div.mb-4.flex.items-center.gap-1.border-b.border-slate-200');
                        if (tabs) scrollTo(tabs);
                    }
                },
                {
                    title: 'Escanear storages',
                    content: 'Botón clave del módulo. <strong>Escanea los storages habilitados</strong> en busca de archivos sin transcripción y los encola. ' +
                             'El proceso es asíncrono: mientras se ejecuta ves un spinner y el modal muestra progreso por storage. ' +
                             'No hace falta usarlo a diario — <code>transcription:tick</code> hace lo mismo cada 2 minutos.',
                    icon: 'fa-layer-group',
                    color: '#06b6d4',
                    selector: 'button[\\@click="openBatchModal()"]',
                    position: 'bottom',
                    onShow: function () {
                        var btn = document.querySelector('button[\\@click="openBatchModal()"]');
                        if (btn) scrollTo(btn);
                    }
                },
                {
                    title: 'Estado de la API',
                    content: 'Mini indicador en el header: <span style="color:#16a34a"><strong>verde (API en línea)</strong></span> o ' +
                             '<span style="color:#dc2626"><strong>rojo (Sin conexión)</strong></span>. ' +
                             'Sirve para saber rápidamente si el servicio externo responde.',
                    icon: 'fa-heartbeat',
                    color: '#16a34a',
                    selector: 'button[\\@click="loadHealth()"][title*="línea"]',
                    position: 'bottom',
                    onShow: function () {
                        var btns = document.querySelectorAll('button[\\@click="loadHealth()"]');
                        for (var i = 0; i < btns.length; i++) {
                            if (btns[i].getAttribute('title') && btns[i].getAttribute('title').indexOf('línea') !== -1) {
                                scrollTo(btns[i]);
                                return;
                            }
                        }
                    }
                },
                {
                    title: 'Novedades: carpetas sin archivos',
                    content: 'Este banner ámbar aparece cuando hay medios o carpetas que <strong>no están produciendo archivos</strong> ' +
                             '(emisoras fuera del aire, paths de ciudades sin programar, etc.). ' +
                             'Es una <strong>novedad</strong>: un aviso de algo que conviene revisar, no un error.<br><br>' +
                             'Se actualiza solo cada 5 min (cache), no es intrusivo y se oculta si todos los storages ' +
                             'tienen sus carpetas produciendo.',
                    icon: 'fa-exclamation-triangle',
                    color: '#f59e0b',
                    selector: '[data-tour="storages-empties"]',
                    position: 'bottom',
                    onShow: function () {
                        var el = document.querySelector('[data-tour="storages-empties"]');
                        if (el) scrollTo(el);
                    }
                },
                {
                    title: 'Ver lista y gestionar',
                    content: 'Pulsa <strong>Ver lista</strong> para expandir el banner. Cada storage con carpetas vacías aparece con su nombre, ' +
                             'el sp=ID, y la lista de paths sin archivos (medios, ciudades, fechas).<br><br>' +
                             '<strong>Re-escanear</strong> lanza processFolder sobre ese storage (útil si crees que el medio ya volvió al aire). ' +
                             '<strong>Gestionar en Avisos</strong> lleva al módulo donde se quita el servicio — cuando un medio no está grabando, ' +
                             'se desactiva por cliente y el storage deja de encolarse solo cuando ninguno lo tiene contratado.',
                    icon: 'fa-list-ul',
                    color: '#f59e0b',
                    selector: '[data-tour="storages-empties"]',
                    position: 'bottom',
                    onShow: function () {
                        var el = document.querySelector('[data-tour="storages-empties"]');
                        if (el) {
                            scrollTo(el);
                            // Auto-expand para que el usuario vea los botones.
                            var alpine = document.querySelector('[x-data]');
                            if (alpine && alpine._x_dataStack) {
                                alpine._x_dataStack[0].emptyFoldersExpanded = true;
                            }
                        }
                    },
                    onHide: function () {
                        var alpine = document.querySelector('[x-data]');
                        if (alpine && alpine._x_dataStack) {
                            alpine._x_dataStack[0].emptyFoldersExpanded = false;
                        }
                    }
                },
                {
                    title: 'Tabla de Storages',
                    content: 'Lista de storages del sistema con 4 columnas: ' +
                             '<strong>Storage</strong> (nombre), <strong>Tipo</strong> (local/s3), ' +
                             '<strong>Transcripción</strong> (toggle on/off) y <strong>Acciones</strong>. ' +
                             'El header muestra "X habilitado(s) de Y totales".',
                    icon: 'fa-table',
                    color: '#2563eb',
                    selector: function () {
                        return document.querySelector('h2.text-sm.font-semibold.text-slate-700') || null;
                    },
                    position: 'bottom',
                    onShow: function () {
                        var h2 = document.querySelector('h2.text-sm.font-semibold.text-slate-700');
                        if (h2) scrollTo(h2);
                    }
                },
                {
                    title: 'Columna: Storage',
                    content: 'Nombre del storage de donde se van a transcribir archivos. ' +
                             'Mismos storages que ves en la sección Admin → Storages.',
                    icon: 'fa-hdd',
                    color: '#1e293b',
                    selector: function () { return getCellText(0); },
                    position: 'bottom',
                    onShow: function () {
                        var c = getCellText(0);
                        if (c) scrollTo(c);
                    }
                },
                {
                    title: 'Columna: Tipo',
                    content: 'Tipo de backend: <strong>local</strong> (disco del servidor) o <strong>s3</strong> (AWS S3 compatible).',
                    icon: 'fa-server',
                    color: '#475569',
                    selector: function () { return getCellText(1); },
                    position: 'bottom',
                    onShow: function () {
                        var c = getCellText(1);
                        if (c) scrollTo(c);
                    }
                },
                {
                    title: 'Interruptor: Transcripción',
                    content: 'Clic para <strong>encender o apagar</strong> la transcripción de este storage. Solo los storages en ' +
                             '"Transcribe" son escaneados, y el pool de workers se reajusta solo a los pocos minutos.<br><br>' +
                             'Apagar detiene el descubrimiento de archivos nuevos; lo ya transcrito se conserva. ' +
                             '<span style="color:#64748b">Los storages contenedores con hijos ya transcribiendo se deshabilitan para evitar duplicación.</span>',
                    icon: 'fa-toggle-on',
                    color: '#16a34a',
                    selector: function () { return getStorageToggle(); },
                    position: 'left',
                    onShow: function () {
                        var t = getStorageToggle();
                        if (t) scrollTo(t);
                    }
                },
                {
                    title: 'Acción: Ver archivos',
                    content: 'Botón <strong>Ver archivos</strong> en cada fila: abre un explorador del storage en modo "modal" con botones Explorar/Hoy/Ayer, búsqueda y breadcrumb. ' +
                             'Desde ahí puedes lanzar tareas de descubrimiento, escaneo y creación de transcripciones pendientes.',
                    icon: 'fa-file-audio',
                    color: '#475569',
                    selector: function () { return getVerArchivosBtn(); },
                    position: 'left',
                    onShow: function () {
                        var b = getVerArchivosBtn();
                        if (b) scrollTo(b);
                    }
                },
                {
                    title: 'Trabajos: pestaña',
                    content: 'Cambia a la pestaña <strong>Trabajos</strong> para ver los jobs de transcripción generados: ' +
                             '<strong>pending</strong> (creada, sin enviar a la API), <strong>queued</strong> (enviada, esperando resultado), ' +
                             '<strong>processing</strong> (el transcriptor está trabajando), ' +
                             '<strong>done</strong> (completada), <strong>error</strong>, <strong>dead</strong>. ' +
                             'También permite reprocesar y consultar el estado de cada una.',
                    icon: 'fa-tasks',
                    color: '#7c3aed',
                    selector: 'button[\\@click="tab = \'jobs\'"]',
                    position: 'left',
                    onShow: function () {
                        switchTab('jobs');
                        var btn = document.querySelector('button[\\@click="tab = \'jobs\'"]');
                        if (btn) scrollTo(btn);
                    }
                },
                {
                    title: 'Guía Completada',
                    content: 'Conoces el módulo API Transcriptor: ' +
                             '<strong>habilitar storages</strong>, <strong>explorar archivos</strong>, <strong>escanear storages</strong> para crear jobs, y <strong>monitorear resultados</strong> en la pestaña Trabajos.<br><br>' +
                             'Si el servidor se satura, la pestaña <strong>Configuración</strong> tiene su propia guía: ábrela y pulsa de nuevo el botón morado.',
                    icon: 'fa-check-circle',
                    color: '#16a34a',
                    selector: null,
                    position: 'center'
                }
            ]
        });
    } else {
        // Tab Trabajos
        TcloudTour.start({
            steps: [
                {
                    title: 'Trabajos de Transcripción',
                    content: 'Lista de jobs de <strong>transcripción</strong> generados por el scanner. ' +
                             'Cada job representa un archivo de audio que el transcriptor está procesando o va a procesar.',
                    icon: 'fa-tasks',
                    color: '#6366f1',
                    selector: null,
                    position: 'center'
                },
                {
                    title: 'Pestañas',
                    content: 'Estás en la pestaña <strong>Trabajos</strong>. Cambia de pestaña para volver a <strong>Storages</strong> y gestionar orígenes.',
                    icon: 'fa-layer-group',
                    color: '#475569',
                    selector: 'div.mb-4.flex.items-center.gap-1.border-b.border-slate-200',
                    position: 'bottom',
                    onShow: function () {
                        var tabs = document.querySelector('div.mb-4.flex.items-center.gap-1.border-b.border-slate-200');
                        if (tabs) scrollTo(tabs);
                    }
                },
                {
                    title: 'Escanear storages',
                    content: 'Disponible desde cualquier pestaña. Crea nuevos jobs para archivos sin transcripción en storages habilitados.',
                    icon: 'fa-layer-group',
                    color: '#06b6d4',
                    selector: 'button[\\@click="openBatchModal()"]',
                    position: 'bottom',
                    onShow: function () {
                        var btn = document.querySelector('button[\\@click="openBatchModal()"]');
                        if (btn) scrollTo(btn);
                    }
                },
                {
                    title: 'Sub-tabs: En proceso, Completados, Fallidos',
                    content: 'La lista se divide en tres: <strong>En proceso</strong> (pending, queued, processing), ' +
                             '<strong>Completados</strong> (solo done) y <strong>Fallidos</strong> (error, dead).<br><br>' +
                             'Dentro de "En proceso" se distingue <strong>sin enviar</strong> (aún no salieron hacia la API: ' +
                             'actúa el botón de despacho) de <strong>en la API esperando resultado</strong> (ya enviadas, ' +
                             'las recoge el polling solo). Son problemas distintos y se diagnostican distinto.<br><br>' +
                             'Cada sub-tab consulta al servidor por separado y se pagina de 50 en 50, así que los completados ' +
                             'se ven siempre, por muchos pendientes que haya en la cola. El número del badge es el total real en ' +
                             'base de datos, no el de la página que estás viendo.',
                    icon: 'fa-folder-tree',
                    color: '#0ea5e9',
                    selector: null,
                    position: 'center'
                },
                {
                    title: 'Acción masiva: enviar y recoger',
                    content: 'La barra sobre la tabla resume el trabajo en curso: <strong>N sin enviar</strong> y ' +
                             '<strong>M en la API esperando resultado</strong>.<br><br>' +
                             'Marca <strong>Seleccionar</strong> para elegir filas concretas. El botón <strong>anuncia lo que va a hacer</strong> ' +
                             'según lo que hayas marcado: "Enviar 12 a la API", "Consultar resultado de 38" o "Enviar 12 y consultar 38".<br><br>' +
                             'Es decir, hace <strong>dos cosas distintas según cada fila</strong>: a las que nunca salieron las envía, ' +
                             'y a las que ya están en la API les consulta el resultado y lo recoge si está listo. ' +
                             'Al terminar desglosa cuántas se enviaron, cuántas transcripciones se recogieron, cuántas siguen sin terminar ' +
                             'y cuántas tenían el resultado perdido.<br><br>' +
                             '<span style="color:#64748b">Sin nada seleccionado envía todas las pendientes sin enviar. ' +
                             'Consultar en bloque tiene un tope por tanda; el resto lo recoge el polling de fondo, que recorre la cola cada minuto.</span>',
                    icon: 'fa-rocket',
                    color: '#4f46e5',
                    selector: null,
                    position: 'center'
                },
                {
                    title: 'Tabla de Trabajos',
                    content: 'Cada fila es un job con: <strong>Archivo</strong> (link al detalle), <strong>Estado</strong> (badge de color), ' +
                             '<strong>Duración</strong> (en segundos), <strong>Iniciado</strong>, <strong>Finalizado</strong> y <strong>Acciones</strong>.<br><br>' +
                             'En los completados, <strong>Ver transcripción</strong> abre el texto en una ventana sin salir del listado; ' +
                             'el icono del ojo lleva a la página de detalle. Debajo de la tabla tienes la paginación.' +
                             '<div style="margin-top:10px;padding:8px 10px;background:#f8fafc;border-left:3px solid #2563eb;border-radius:4px;font-size:0.92em;">' +
                             '<strong>"Refrescar estado" solo pregunta, no reenvía.</strong> Hace un <code>GET /v1/jobs/{id}</code> para saber si ya terminó. ' +
                             'Al pulsarlo aparece un aviso con lo que ocurrió de verdad: <em>terminado</em> (se descargó la transcripción), ' +
                             '<em>sigue en la API</em> (no hay nada que hacer, se recoge solo) o <em>resultado perdido</em> (hay que reenviar el audio).<br>' +
                             'Para <strong>enviar</strong> un archivo está "Enviar ahora", que solo aparece en filas que nunca salieron hacia la API.' +
                             '</div>',
                    icon: 'fa-table',
                    color: '#2563eb',
                    selector: 'table',
                    position: 'bottom',
                    onShow: function () {
                        var t = document.querySelector('table');
                        if (t) scrollTo(t);
                    }
                },
                {
                    title: 'Estados de un Job',
                    content: 'Los jobs pasan por estos estados: ' +
                             '<ul style="margin:6px 0 0 16px;padding:0;">' +
                             '<li><span style="color:#f59e0b"><strong>pending</strong></span>: creada, <strong>sin enviar</strong> a la API todavía</li>' +
                             '<li><span style="color:#3b82f6"><strong>queued</strong></span>: ya enviada, esperando el resultado</li>' +
                             '<li><span style="color:#06b6d4"><strong>processing</strong></span>: el transcriptor está trabajando</li>' +
                             '<li><span style="color:#16a34a"><strong>done</strong></span>: completada, SRT guardado</li>' +
                             '<li><span style="color:#dc2626"><strong>error</strong></span>: falló, no se reintenta automáticamente</li>' +
                             '<li><span style="color:#475569"><strong>dead</strong></span>: terminal, no se reintenta sola</li>' +
                             '</ul>' +
                             '<div style="margin-top:10px;padding:8px 10px;background:#fef2f2;border-left:3px solid #dc2626;border-radius:4px;font-size:0.92em;">' +
                             '<strong>Tres caminos llevan a <code>dead</code></strong>, y no se arreglan igual:<br>' +
                             '· <strong>Agotó los reintentos</strong> de envío (<code>max_retries</code>): suele ser el audio o ffmpeg.<br>' +
                             '· <strong>El transcriptor perdió el resultado</strong>: el job figura terminado pero su SRT ya no existe.<br>' +
                             '· <strong>Caducó</strong> sin resolverse (<code>poll_max_age_hours</code>).<br>' +
                             'En los dos últimos el texto se perdió, pero el audio original sigue en disco: ' +
                             '<code>php artisan transcription:backfill-lost</code> lo reenvía a ritmo controlado, sin competir con las grabaciones del día.' +
                             '</div>' +
                             '<div style="margin-top:10px;padding:8px 10px;background:#f8fafc;border-left:3px solid #7c3aed;border-radius:4px;font-size:0.92em;">' +
                             '<strong>Badge de corrección (visible en el detalle del job):</strong><br>' +
                             'Cuando un job llega a <code>done</code> con corrección de idioma async, aparece un segundo badge con tres valores:<br>' +
                             '<span style="color:#b45309"><strong>Corrección pendiente</strong></span> (corrected=0, el corrector async aún no termina), ' +
                             '<span style="color:#059669"><strong>Corregido</strong></span> (corrected=1, SRT ya corregido por la API) o ' +
                             '<span style="color:#475569"><strong>Sin corrección</strong></span> (corrected=-1, no recuperable). ' +
                             'El polling refresca este valor cada minuto.' +
                             '</div>',
                    icon: 'fa-info-circle',
                    color: '#7c3aed',
                    selector: null,
                    position: 'center'
                },
                {
                    title: 'Trabajo sin transcripciones',
                    content: 'Si ves "Aún no hay transcripciones", significa que el scanner aún no ha detectado archivos. ' +
                             'Ve a la pestaña <strong>Storages</strong>, habilita al menos uno y haz clic en <strong>Escanear storages</strong>.',
                    icon: 'fa-microphone',
                    color: '#94a3b8',
                    selector: '.fa-microphone.text-4xl',
                    position: 'center',
                    onShow: function () {
                        var icon = document.querySelector('.fa-microphone.text-4xl');
                        if (icon) scrollTo(icon);
                    }
                },
                {
                    title: 'Guía Completada',
                    content: 'Conoces la pestaña Trabajos del API Transcriptor. ' +
                             'Aquí monitoreas el ciclo de vida de cada transcripción. ' +
                             'Repite esta guía cuando quieras con el botón morado.',
                    icon: 'fa-check-circle',
                    color: '#16a34a',
                    selector: null,
                    position: 'center'
                }
            ]
        });
    }
}
</script>
@endpush
@endsection