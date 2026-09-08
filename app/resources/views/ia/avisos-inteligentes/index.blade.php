@extends('layouts.app')

@section('title', 'Avisos Inteligentes - Tcloud')

@section('content')
<div class="p-6" x-data="avisosInteligentes()" x-init="init()">

    <div class="mb-6 flex items-start justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Avisos Inteligentes</h1>
            <p class="text-slate-500 mt-0.5">Asigna el módulo a usuarios y gestiona cupo, correos y keywords</p>
        </div>
        <a href="/ia/avisos-inteligentes/admin/categories/page" class="text-sm text-brand-600 hover:underline self-center">
            <i class="fas fa-tags mr-1.5"></i>Gestionar categorías base
        </a>
    </div>

    <!-- Tabs: Clientes | Escaneo -->
    <div class="mb-4 flex items-center gap-1 border-b border-slate-200">
        <button @click="activeTab = 'clientes'"
                class="px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px"
                :class="activeTab === 'clientes' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-users mr-1.5"></i>Clientes
        </button>
        <button @click="activeTab = 'escaneo'; loadScan()"
                class="px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px"
                :class="activeTab === 'escaneo' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-radar mr-1.5"></i>Escaneo
        </button>
    </div>

    <!-- Filtros (solo pestaña clientes) -->
    <div x-show="activeTab === 'clientes'" class="mb-4 flex items-center gap-3 flex-wrap">
        <div class="relative flex-1 min-w-[200px] max-w-xs">
            <input type="text" x-model="search" @input.debounce.400ms="load()" placeholder="Buscar por usuario o email..."
                   class="w-full border border-slate-300 rounded-lg pl-9 pr-4 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
            <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-sm"></i>
        </div>
        <select x-model="moduleFilter" @change="load()" class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
            <option value="">Todos</option>
            <option value="on">Módulo activo</option>
            <option value="off">Módulo inactivo</option>
        </select>
    </div>

    <!-- Tabla -->
    <div x-show="activeTab === 'clientes'" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div x-show="loading" class="flex items-center justify-center py-16">
            <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
        </div>

        <div x-show="!loading && users.length === 0" class="text-center py-16 text-slate-400">
            <i class="fas fa-users text-4xl mb-3 block text-slate-200"></i>
            <p class="font-medium">No hay usuarios que coincidan</p>
        </div>

        <table x-show="!loading && users.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Usuario</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Módulo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Keywords</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell" title="Storages con acceso / storages asignados">Acceso</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="u in users" :key="u.id">
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-sm font-medium text-slate-700" x-text="u.username || u.email"></td>
                        <td class="px-4 py-3 text-sm text-slate-500" x-text="u.email"></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium"
                                  :class="u.alerts_inteligente?.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'">
                                <span class="w-1.5 h-1.5 rounded-full" :class="u.alerts_inteligente?.enabled ? 'bg-green-500' : 'bg-slate-400'"></span>
                                <span x-text="u.alerts_inteligente?.enabled ? 'Activo' : 'Inactivo'"></span>
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-600">
                            <span x-text="(u.keywords_count || 0) + ' / ' + (u.alerts_inteligente?.keywords_quota || 0)"></span>
                        </td>
                        {{-- Acceso = storages con transcription_access=true / storages asignados.
                             Es el control que el admin enciende en la ficha del cliente.
                             No es un atributo del storage; no se cuenta aquí la decisión de
                             api-transcriptor sobre el storage. --}}
                        <td class="px-4 py-3 hidden md:table-cell text-sm">
                            <span class="font-medium"
                                  :class="(u.storages_with_access || 0) > 0 ? 'text-green-700' : 'text-slate-400'"
                                  x-text="(u.storages_with_access || 0) + ' / ' + (u.storages_count || 0)"></span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button @click="openAssign(u)" class="px-3 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors">
                                    <i class="fas fa-edit text-[10px] mr-1"></i>Asignar
                                </button>
                                <a :href="'/ia/avisos-inteligentes/' + u.id"
                                   class="px-3 py-1 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-xs rounded-lg transition-colors">
                                    <i class="fas fa-eye text-[10px] mr-1"></i>Detalle
                                </a>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div x-show="!loading && meta" class="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-500">
            <span x-text="'Página ' + meta.current_page + ' de ' + meta.last_page"></span>
            <div class="flex gap-2">
                <button @click="prevPage()" :disabled="!links.prev" class="px-3 py-1 border rounded disabled:opacity-40">Anterior</button>
                <button @click="nextPage()" :disabled="!links.next" class="px-3 py-1 border rounded disabled:opacity-40">Siguiente</button>
            </div>
        </div>
    </div>

    <!-- ═══ Pestaña: Escaneo (avisos-scan-configuration) ═══ -->
    <div x-show="activeTab === 'escaneo'" x-cloak class="space-y-4">

        {{-- Estado y configuración --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-800"><i class="fas fa-radar mr-2 text-brand-600"></i>Escaneo de transcripciones</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Busca transcripciones terminadas sin avisos y genera las menciones de las keywords de los clientes. El envío de correos sigue gestionándose por la cadencia de cada cliente.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium"
                          :class="scanSettings.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'"
                          x-text="scanSettings.enabled ? 'Automático activo' : 'Automático desactivado'"></span>
                    <button @click="openScanPanel()" class="px-3 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg">
                        <i class="fas fa-sliders mr-1"></i>Configurar
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Última corrida</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="scan.last_run ? (scan.last_run.started_at || '').replace('T',' ').slice(0,16) : 'Nunca ha corrido'"></p>
                    <p class="text-[11px] text-slate-400 mt-0.5" x-text="scan.last_run ? ('origen: ' + scan.last_run.origin + ' · estado: ' + scan.last_run.status) : 'activa el automático o lanza una manual'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Frecuencia</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="'Cada ' + scanSettings.intervalMinutes + ' min'"></p>
                    <p class="text-[11px] text-slate-400 mt-0.5" x-text="'ventana de re-escaneo: ' + scanSettings.windowHours + ' h'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Pendientes en ventana</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="(scan.pending_estimate ?? '—') + ' transcripción(es) sin avisos'"></p>
                    <p class="text-[11px] text-slate-400 mt-0.5">terminadas y dentro de la ventana configurada</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-slate-500 mb-1">Storage (opcional)</label>
                    <select x-model="scanForm.storageId" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm outline-none">
                        <option value="">Todos los storages</option>
                        <template x-for="st in storages" :key="st.id">
                            <option :value="st.id" x-text="st.name"></option>
                        </template>
                    </select>
                </div>
                <button @click="runScan()" :disabled="scanRunning"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-40">
                    <i class="fas" :class="scanRunning ? 'fa-spinner fa-spin mr-1.5' : 'fa-play mr-1.5'"></i>Escanear ahora
                </button>
            </div>
            <div x-show="scanMessage" x-cloak class="mt-3 p-3 rounded-lg text-sm"
                 :class="scanError ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-800'"
                 x-text="scanMessage"></div>
        </div>

        {{-- Configuración del automático (panel desplegable) --}}
        <div x-show="scanPanelOpen" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-800 mb-4"><i class="fas fa-sliders mr-2 text-slate-500"></i>Configuración del escaneo automático</h3>
            <div class="space-y-4 max-w-md">
                <div class="flex items-center gap-3">
                    <button @click="scanForm.enabled = !scanForm.enabled"
                            :class="scanForm.enabled ? 'bg-green-500' : 'bg-slate-300'"
                            class="relative w-10 h-5 rounded-full transition-colors flex-shrink-0">
                        <span :class="scanForm.enabled ? 'translate-x-5' : 'translate-x-1'" class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"></span>
                    </button>
                    <span class="text-sm text-slate-600" x-text="scanForm.enabled ? 'Escaneo automático activado' : 'Escaneo automático desactivado'"></span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Intervalo entre corridas (minutos, mínimo 5)</label>
                    <input type="number" min="5" x-model.number="scanForm.intervalMinutes"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Ventana de re-escaneo (horas)</label>
                    <input type="number" min="1" x-model.number="scanForm.windowHours"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    <p class="text-[11px] text-slate-400 mt-1">Solo se escanean transcripciones terminadas dentro de esta ventana y que aún no tengan avisos. Para históricos más antiguos usa "Escanear ahora" con rango de fechas.</p>
                </div>
                <div class="flex gap-2">
                    <button @click="saveScanSettings()" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">Guardar configuración</button>
                    <button @click="scanPanelOpen = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm">Cerrar</button>
                </div>
            </div>
        </div>

        {{-- Historial de corridas --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="text-sm font-semibold text-slate-800">Últimas corridas</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-left text-xs text-slate-500 uppercase">
                        <th class="px-4 py-2.5 font-semibold">Fecha</th>
                        <th class="px-4 py-2.5 font-semibold">Origen</th>
                        <th class="px-4 py-2.5 font-semibold">Estado</th>
                        <th class="px-4 py-2.5 font-semibold">Escaneadas</th>
                        <th class="px-4 py-2.5 font-semibold">Hits nuevos</th>
                        <th class="px-4 py-2.5 font-semibold">Duración</th>
                        <th class="px-4 py-2.5 font-semibold">Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="run in scan.runs" :key="run.id">
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 text-xs text-slate-600" x-text="(run.started_at || '').replace('T',' ').slice(0,16)"></td>
                            <td class="px-4 py-2.5"><span class="px-2 py-0.5 rounded text-xs" :class="run.origin === 'cron' ? 'bg-slate-100 text-slate-600' : 'bg-brand-50 text-brand-700'" x-text="run.origin"></span>
                                <p class="text-[10px] text-slate-400 mt-0.5" x-text="runWindowLabel(run)" :title="JSON.stringify(run.params || {})"></p>
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="run.status === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                    <span class="w-1.5 h-1.5 rounded-full" :class="run.status === 'success' ? 'bg-green-500' : 'bg-red-500'"></span>
                                    <span x-text="run.status"></span>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600" x-text="run.scanned"></td>
                            <td class="px-4 py-2.5 text-slate-600" x-text="run.hits_new"></td>
                            <td class="px-4 py-2.5 text-xs text-slate-500" x-text="(run.duration_ms / 1000).toFixed(1) + ' s'"></td>
                            <td class="px-4 py-2.5 text-xs text-red-600 max-w-[240px] truncate" :title="JSON.stringify(run.error)" x-text="run.error ? (typeof run.error === 'string' ? run.error : JSON.stringify(run.error)) : '—'"></td>
                        </tr>
                    </template>
                    <tr x-show="scan.runs.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400 text-sm">Sin corridas registradas todavía.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de progreso del escaneo (reemplaza confirm/alert nativos) -->
    <div x-cloak x-show="scanModal.open" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl overflow-hidden" @click.away="scanModal.phase === 'done' && (scanModal.open = false)">
            <div class="p-5">
                <h2 class="text-lg font-bold text-slate-800 mb-1 flex items-center gap-2">
                    <i class="fas fa-radar text-brand-600"></i>Escaneo de menciones
                </h2>

                {{-- Fase confirm: ventana temporal + resumen antes de arrancar --}}
                <div x-show="scanModal.phase === 'confirm'" class="mt-3">
                    <p class="text-sm font-medium text-slate-700 mb-2">¿Qué ventana quieres escanear?</p>
                    <div class="space-y-1.5 mb-3">
                        <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer rounded-lg px-2 py-1.5 hover:bg-slate-50"
                               :class="scanForm.noWindow ? 'opacity-40 pointer-events-none' : ''">
                            <input type="radio" name="scan-window" value="8h" x-model="scanForm.preset" @change="onWindowChange()" class="accent-brand-600">
                            Últimas 8 horas
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer rounded-lg px-2 py-1.5 hover:bg-slate-50"
                               :class="scanForm.noWindow ? 'opacity-40 pointer-events-none' : ''">
                            <input type="radio" name="scan-window" value="24h" x-model="scanForm.preset" @change="onWindowChange()" class="accent-brand-600">
                            Último día (24 h)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer rounded-lg px-2 py-1.5 hover:bg-slate-50"
                               :class="scanForm.noWindow ? 'opacity-40 pointer-events-none' : ''">
                            <input type="radio" name="scan-window" value="3d" x-model="scanForm.preset" @change="onWindowChange()" class="accent-brand-600">
                            Últimos 3 días
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer rounded-lg px-2 py-1.5 hover:bg-slate-50"
                               :class="scanForm.noWindow ? 'opacity-40 pointer-events-none' : ''">
                            <input type="radio" name="scan-window" value="7d" x-model="scanForm.preset" @change="onWindowChange()" class="accent-brand-600">
                            Últimos 7 días
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer rounded-lg px-2 py-1.5 hover:bg-slate-50"
                               :class="scanForm.noWindow ? 'opacity-40 pointer-events-none' : ''">
                            <input type="radio" name="scan-window" value="today" x-model="scanForm.preset" @change="onWindowChange()" class="accent-brand-600">
                            Hoy (desde medianoche)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer rounded-lg px-2 py-1.5 hover:bg-slate-50"
                               :class="scanForm.noWindow ? 'opacity-40 pointer-events-none' : ''">
                            <input type="radio" name="scan-window" value="custom" x-model="scanForm.preset" @change="onWindowChange()" class="accent-brand-600">
                            Rango personalizado
                        </label>
                        <div x-show="scanForm.preset === 'custom'" x-cloak class="ml-7 mt-2 flex flex-wrap items-end gap-3">
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1">Desde</label>
                                <input type="datetime-local" x-model="scanForm.from" @change="onWindowChange()" class="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1">Hasta</label>
                                <input type="datetime-local" x-model="scanForm.to" @change="onWindowChange()" class="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
                            </div>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600 mb-3 cursor-pointer" title="Drena TODAS las transcripciones terminadas sin avisos, sin límite de fechas. Puede detener y retomar cuando quieras.">
                        <input type="checkbox" x-model="scanForm.noWindow" @change="onNoWindowToggle()" class="w-4 h-4 accent-violet-600">
                        Histórico completo (sin límite de fechas)
                    </label>
                    <div class="mb-3">
                        <label class="block text-xs text-slate-500 mb-1">Storage (opcional)</label>
                        <select x-model="scanForm.storageId" @change="refreshScanEstimate()" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm outline-none">
                            <option value="">Todos los storages</option>
                            <template x-for="st in storages" :key="st.id">
                                <option :value="st.id" x-text="st.name"></option>
                            </template>
                        </select>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-slate-600 mb-3 cursor-pointer rounded-lg p-2 hover:bg-slate-50" title="Úsalo si ya escaneaste antes y luego corregiste las transcripciones: re-escanea con el texto actualizado.">
                        <input type="checkbox" x-model="scanForm.force" class="w-4 h-4 accent-brand-600 mt-0.5">
                        <span>
                            <span class="font-medium text-slate-700">Forzar re-escaneo</span>
                            <span class="block text-[11px] text-slate-400 mt-0.5">Borra las menciones previas de las transcripciones objetivo y las genera de nuevo con el texto actual. Actívalo si corregiste transcripciones que ya habían sido escaneadas.</span>
                        </span>
                    </label>
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-3 text-sm text-slate-700 mb-4">
                        <p>Pendientes estimados: <span class="font-semibold" x-text="scanModal.estimate ?? '—'"></span></p>
                        <p class="text-xs text-slate-400 mt-1" x-text="windowLabel()">Se procesa en tandas de 50 con progreso en vivo. Puedes detener cuando quieras.</p>
                    </div>
                    <div class="flex gap-3">
                        <button @click="confirmScanStart()" :disabled="scanForm.preset === 'custom' && !scanForm.from && !scanForm.to"
                                class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium disabled:opacity-40">Iniciar escaneo</button>
                        <button @click="scanModal.open = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                    </div>
                </div>

                {{-- Fase running: progreso en vivo (worker en background del servidor) --}}
                <div x-show="scanModal.phase === 'running'" class="mt-3">
                    <div class="flex items-center gap-3 mb-1">
                        <i class="fas fa-circle-notch fa-spin text-brand-500"></i>
                        <span class="text-sm text-slate-600" x-text="'Procesando tanda #' + (scanModal.runs + 1) + ' (50 transcripciones por tanda)'"></span>
                    </div>
                    <p class="text-[11px] text-emerald-600 mb-3"><i class="fas fa-cloud mr-1"></i>Corriendo en el servidor: puedes cerrar esta ventana o recargar la página sin perder el progreso.</p>
                    <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden mb-4">
                        <div class="bg-brand-600 h-3 rounded-full transition-all duration-300"
                             :style="'width:' + (scanModal.estimate ? Math.min(100, Math.round(scanModal.scanned / Math.max(1, scanModal.estimate) * 100)) : 0) + '%'"></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-slate-50 p-2">
                            <p class="text-xl font-bold text-slate-800" x-text="scanModal.scanned"></p>
                            <p class="text-[11px] text-slate-400 uppercase">Escaneadas</p>
                        </div>
                        <div class="rounded-lg bg-violet-50 p-2">
                            <p class="text-xl font-bold text-violet-700" x-text="scanModal.hitsNew"></p>
                            <p class="text-[11px] text-violet-400 uppercase">Hits nuevos</p>
                        </div>
                        <div class="rounded-lg p-2" :class="scanModal.failed > 0 ? 'bg-red-50' : 'bg-slate-50'">
                            <p class="text-xl font-bold" :class="scanModal.failed > 0 ? 'text-red-600' : 'text-slate-800'" x-text="scanModal.failed"></p>
                            <p class="text-[11px] text-slate-400 uppercase">Fallos</p>
                        </div>
                    </div>
                    <button @click="stopScanSequence()" class="mt-4 w-full py-2 border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg text-sm">
                        <i class="fas fa-stop mr-1"></i>Detener
                    </button>
                </div>

                {{-- Fase done / error: resumen --}}
                <div x-show="scanModal.phase === 'done' || scanModal.phase === 'error'" class="mt-3">
                    <div class="rounded-lg p-4 text-center" :class="scanModal.phase === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'">
                        <i class="fas text-2xl mb-2 block" :class="scanModal.phase === 'error' ? 'fa-circle-exclamation text-red-500' : 'fa-circle-check text-green-500'"></i>
                        <p class="text-sm font-semibold" :class="scanModal.phase === 'error' ? 'text-red-700' : 'text-green-800'"
                           x-text="scanModal.phase === 'error' ? 'El escaneo terminó con error' : 'Escaneo finalizado'"></p>
                        <p class="text-xs text-slate-500 mt-1" x-text="scanModal.runs + ' tanda(s) · ' + scanModal.scanned + ' escaneadas · ' + scanModal.hitsNew + ' hits nuevos · ' + scanModal.failed + ' fallos'"></p>
                        <p class="text-[11px] text-slate-400 mt-0.5" x-text="windowLabel()"></p>
                    </div>
                    <div x-show="scanModal.errors.length" class="mt-3 max-h-28 overflow-y-auto rounded-lg bg-red-50 border border-red-200 p-2 text-xs text-red-700 space-y-1">
                        <template x-for="(err, i) in scanModal.errors" :key="i">
                            <p class="break-all" x-text="err"></p>
                        </template>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button x-show="scanModal.phase === 'done'" @click="runScan()" class="flex-1 py-2.5 border border-brand-300 text-brand-700 hover:bg-brand-50 rounded-xl text-sm font-medium">Nuevo escaneo</button>
                        <button @click="scanModal.open = false" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal asignar módulo -->
    <div x-cloak x-show="showAssign" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showAssign = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Asignar módulo</h2>
                <p class="text-sm text-slate-500 mb-5" x-text="currentUser?.username || currentUser?.email"></p>

                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <button @click="form.enabled = !form.enabled" :class="form.enabled ? 'bg-green-500' : 'bg-slate-300'" class="relative w-10 h-5 rounded-full transition-colors">
                            <span :class="form.enabled ? 'translate-x-5' : 'translate-x-1'" class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"></span>
                        </button>
                        <span class="text-sm text-slate-600" x-text="form.enabled ? 'Módulo activo' : 'Módulo inactivo'"></span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Cupo de keywords</label>
                        <input type="number" min="0" x-model.number="form.keywords_quota" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Cupo de correos</label>
                        <input type="number" min="0" x-model.number="form.emails_quota" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="saveAssign()" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Guardar</button>
                    <button @click="showAssign = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function avisosInteligentes() {
    return {
        loading: false,
        users: [],
        meta: null,
        links: {},
        page: 1,
        search: '',
        moduleFilter: '',
        showAssign: false,
        currentUser: null,
        form: { enabled: true, keywords_quota: 100, emails_quota: 1 },
        activeTab: 'clientes',
        // Escaneo (avisos-scan-configuration + avisos-scan-time-presets)
        scanSettings: { enabled: false, intervalMinutes: 30, windowHours: 72 },
        scan: { last_run: null, runs: [], pending_estimate: null },
        scanForm: { enabled: false, intervalMinutes: 30, windowHours: 72, storageId: '', preset: '24h', from: '', to: '', force: false, noWindow: false },
        scanPanelOpen: false,
        scanRunning: false,
        scanMessage: '',
        scanError: false,
        storages: [],
        scanModal: { open: false, phase: 'confirm', runId: null, estimate: null, scanned: 0, hitsNew: 0, failed: 0, runs: 0, errors: [], stopped: false, result: null },
        scanPollTimer: null,
        async init() {
            await this.load();
            this.loadScan();
            this.checkActiveScanRun();
            try {
                const r = await apiFetch('/user/storages', { headers: { 'Accept': 'application/json' } });
                if (r.ok) { const d = await r.json(); this.storages = d.storages || []; }
            } catch (e) {}
        },
        async loadScan() {
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const d = await res.json();
                    this.scan = { last_run: d.last_run, runs: d.runs || [], pending_estimate: d.pending_estimate };
                    this.scanSettings = { ...d.settings };
                    this.scanForm.enabled = !!d.settings.enabled;
                    this.scanForm.intervalMinutes = d.settings.intervalMinutes;
                    this.scanForm.windowHours = d.settings.windowHours;
                }
            } catch (e) { this.scanMessage = 'Error cargando el estado del escaneo'; this.scanError = true; }
        },
        // Presets de ventana (avisos-scan-time-presets)
        presetLabels: { '8h': 'Últimas 8 horas', '24h': 'Último día (24 h)', '3d': 'Últimos 3 días', '7d': 'Últimos 7 días', 'today': 'Hoy (desde medianoche)', 'custom': 'Rango personalizado' },
        windowLabel() {
            if (this.scanForm.noWindow) return 'Histórico completo: todas las transcripciones terminadas sin avisos, sin límite de fechas.';
            const label = this.presetLabels[this.scanForm.preset] || 'Ventana global del automático';
            return 'Ventana: ' + label + '.';
        },
        // Ventana efectiva de una corrida registrada (auditoría)
        runWindowLabel(run) {
            const p = run.params || {};
            if (p.preset) return this.presetLabels[p.preset] || p.preset;
            if (p.noWindow) return 'Histórico completo';
            if (p.from || p.to) {
                const fmt = (v) => (v || '').replace('T', ' ').slice(0, 16);
                return fmt(p.from) + ' → ' + (fmt(p.to) || 'ahora');
            }
            return '';
        },
        // Estimado con los filtros elegidos (preset/rango + storage + histórico)
        async refreshScanEstimate() {
            const params = new URLSearchParams();
            if (this.scanForm.noWindow) {
                params.set('no_window', '1');
            } else if (this.scanForm.preset && this.scanForm.preset !== 'custom') {
                params.set('preset', this.scanForm.preset);
            }
            if (!this.scanForm.noWindow && this.scanForm.preset === 'custom') {
                if (this.scanForm.from) params.set('from', this.scanForm.from.replace('T', ' '));
                if (this.scanForm.to) params.set('to', this.scanForm.to.replace('T', ' '));
            }
            if (this.scanForm.storageId) params.set('storageId', this.scanForm.storageId);
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan?' + params.toString(), { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const d = await res.json();
                    this.scanModal.estimate = d.pending_estimate;
                }
            } catch (e) {}
        },
        onWindowChange() {
            // Exclusividad: elegir un preset/rango apaga el histórico completo.
            if (this.scanForm.noWindow) this.scanForm.noWindow = false;
            this.refreshScanEstimate();
        },
        onNoWindowToggle() {
            // Exclusividad inversa: activar histórico desactiva presets.
            if (this.scanForm.noWindow) this.scanForm.preset = '';
            else if (!this.scanForm.preset) this.scanForm.preset = '24h';
            this.refreshScanEstimate();
        },
        openScanPanel() {
            this.scanForm.enabled = !!this.scanSettings.enabled;
            this.scanForm.intervalMinutes = this.scanSettings.intervalMinutes;
            this.scanForm.windowHours = this.scanSettings.windowHours;
            this.scanPanelOpen = !this.scanPanelOpen;
        },
        async saveScanSettings() {
            this.scanMessage = ''; this.scanError = false;
            const res = await apiFetch('/ia/avisos-inteligentes/scan/settings', {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ enabled: this.scanForm.enabled, intervalMinutes: this.scanForm.intervalMinutes, windowHours: this.scanForm.windowHours }),
            });
            if (res.ok) {
                const d = await res.json();
                this.scanSettings = { ...d.settings };
                this.scanMessage = 'Configuración guardada';
                this.scanPanelOpen = false;
            } else {
                const d = await res.json().catch(() => ({}));
                this.scanMessage = Object.values(d.errors || {})[0]?.[0] || d.error || 'Error al guardar';
                this.scanError = true;
            }
        },
        async runScan() {
            // Abre el modal de confirmación con la ventana a escanear
            // (avisos-scan-time-presets) y drena los candidatos por corridas
            // sucesivas de 50 — progreso real acumulado, sin diálogos nativos.
            this.scanModal = {
                open: true, phase: 'confirm',
                estimate: null, scanned: 0, hitsNew: 0, failed: 0, runs: 0,
                errors: [], stopped: false, result: null,
                catchup: !!this.scanForm.noWindow,
            };
            await this.refreshScanEstimate();
        },
        confirmScanStart() {
            // Lanza el runner en BACKGROUND (avisos-scan-bg-runner): el proceso
            // vive en el servidor (setsid) y esta UI solo hace polling. Se puede
            // recargar la página o cerrar el navegador sin truncar el escaneo.
            this.scanModal.phase = 'running';
            this.scanModal.stopped = false;
            this.runScanBackground();
        },
        stopScanSequence() {
            // Cancelación cooperativa vía API: el worker revisa la marca entre
            // tandas. Sigue viva aunque esta pestaña muera.
            this.scanModal.stopped = true;
            if (this.scanModal.runId) {
                apiFetch('/ia/avisos-inteligentes/scan/run-bg/' + this.scanModal.runId + '/stop', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                }).catch(() => {});
            }
        },
        async runScanBackground() {
            const m = this.scanModal;
            const body = {
                force: this.scanForm.force,
                noWindow: !!this.scanForm.noWindow,
                limit: 50,
            };
            if (this.scanForm.storageId) body.storageId = this.scanForm.storageId;
            if (!this.scanForm.noWindow) {
                if (this.scanForm.preset && this.scanForm.preset !== 'custom') {
                    body.preset = this.scanForm.preset;
                } else if (this.scanForm.preset === 'custom') {
                    if (this.scanForm.from) body.from = this.scanForm.from.replace('T', ' ');
                    if (this.scanForm.to) body.to = this.scanForm.to.replace('T', ' ');
                }
            }
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/run-bg', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(body),
                });
                const d = await res.json().catch(() => ({}));
                if (res.status === 409 && d.runId) {
                    // Ya hay un escaneo corriendo: re-adjuntarse a él.
                    this.attachScanRun(d.runId);
                    return;
                }
                if (!res.ok) {
                    m.phase = 'error';
                    m.errors = [d.error || 'No se pudo iniciar el escaneo'];
                    return;
                }
                this.attachScanRun(d.runId);
            } catch (e) {
                m.phase = 'error';
                m.errors = ['Error de red: ' + e.message];
            }
        },
        attachScanRun(runId) {
            const m = this.scanModal;
            m.runId = runId;
            m.phase = 'running';
            if (this.scanPollTimer) clearInterval(this.scanPollTimer);
            this.scanPollTimer = setInterval(() => this.pollScanRun(), 2000);
            this.pollScanRun();
        },
        async pollScanRun() {
            const m = this.scanModal;
            if (!m.runId) return;
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/run-bg/' + m.runId, { headers: { 'Accept': 'application/json' } });
                if (res.status === 404) {
                    clearInterval(this.scanPollTimer);
                    this.scanPollTimer = null;
                    m.phase = 'error';
                    m.errors = ['El escaneo ya no existe (expiró)'];
                    return;
                }
                const s = await res.json();
                m.scanned = s.scanned || 0;
                m.hitsNew = s.hits_new || 0;
                m.failed = s.failed || 0;
                m.runs = s.batches || 0;
                m.estimate = Math.max(m.estimate ?? 0, s.attempted_count || 0) || m.estimate;
                if (s.errors?.length) m.errors = s.errors;
                if (['done', 'error', 'stopped'].includes(s.status)) {
                    clearInterval(this.scanPollTimer);
                    this.scanPollTimer = null;
                    m.phase = s.status === 'error' ? 'error' : 'done';
                    if (s.error_message) m.errors = [s.error_message];
                    if (s.status === 'stopped') m.stopped = true;
                    await this.loadScan();
                }
            } catch (e) { /* red inestable: el siguiente tick reintenta */ }
        },
        // Re-adjuntarse a un escaneo activo al recargar la página.
        async checkActiveScanRun() {
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/run-bg/active', { headers: { 'Accept': 'application/json' } });
                if (res.status === 204) return;
                const s = await res.json();
                this.activeTab = 'escaneo';
                this.scanModal = {
                    open: true, phase: 'running', runId: s.runId,
                    estimate: s.attempted_count ?? null, scanned: s.scanned || 0,
                    hitsNew: s.hits_new || 0, failed: s.failed || 0, runs: s.batches || 0,
                    errors: [], stopped: false, result: null, catchup: !!s.noWindow,
                };
                this.attachScanRun(s.runId);
            } catch (e) {}
        },
        async load() {
        },
        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ page: this.page });
                if (this.search) params.set('q', this.search);
                if (this.moduleFilter) params.set('module', this.moduleFilter);
                const res = await apiFetch('/ia/avisos-inteligentes?' + params, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const d = await res.json();
                    this.users = d.data || [];
                    this.meta = { current_page: d.current_page, last_page: d.last_page };
                    this.links = { prev: d.prev_page_url, next: d.next_page_url };
                }
            } finally { this.loading = false; }
        },
        prevPage() { if (this.links.prev) { this.page--; this.load(); } },
        nextPage() { if (this.links.next) { this.page++; this.load(); } },
        openAssign(u) {
            this.currentUser = u;
            const cfg = u.alerts_inteligente || {};
            this.form = { enabled: !!cfg.enabled, keywords_quota: cfg.keywords_quota || 100, emails_quota: cfg.emails_quota || 1 };
            this.showAssign = true;
        },
        async saveAssign() {
            const res = await apiFetch('/ia/avisos-inteligentes/' + this.currentUser.id, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showAssign = false; await this.load(); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || 'Error'); }
        },
    };
}
</script>
@endpush
@endsection