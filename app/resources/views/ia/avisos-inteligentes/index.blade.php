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
            <i class="fas fa-broadcast-tower mr-1.5"></i>Escaneo
        </button>
        <button @click="activeTab = 'dashboard'; loadDashboard()"
                class="px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px"
                :class="activeTab === 'dashboard' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-gauge-high mr-1.5"></i>Dashboard
        </button>
        <button @click="activeTab = 'cobertura'; loadCoverage()"
                class="px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px"
                :class="activeTab === 'cobertura' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-shield-halved mr-1.5"></i>Cobertura
        </button>
        <button @click="activeTab = 'auditoria'; loadAuditLog(); loadHeatmap()"
                class="px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px"
                :class="activeTab === 'auditoria' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-clipboard-list mr-1.5"></i>Auditoría
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

    <!-- Paginación superior -->
    <div x-show="activeTab === 'clientes' && !loading && users.length > 0">
        @include('mis-avisos._pagination', ['scope' => 'users', 'position' => 'top'])
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
                    <th class="w-10 px-2 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">#</th>
                    <th class="px-4 py-3 text-left">
                        <button type="button" @click="setSort('users', 'username')"
                                :class="sortHeaderClass('users', 'username') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por usuario">
                            Usuario
                            <i class="fas text-[10px]" :class="sortIcon('users', 'username') + ' ' + sortIconClass('users', 'username')"></i>
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left">
                        <button type="button" @click="setSort('users', 'email')"
                                :class="sortHeaderClass('users', 'email') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por email">
                            Email
                            <i class="fas text-[10px]" :class="sortIcon('users', 'email') + ' ' + sortIconClass('users', 'email')"></i>
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left">
                        <button type="button" @click="setSort('users', 'module')"
                                :class="sortHeaderClass('users', 'module') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por estado del módulo">
                            Módulo
                            <i class="fas text-[10px]" :class="sortIcon('users', 'module') + ' ' + sortIconClass('users', 'module')"></i>
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left hidden md:table-cell">
                        <button type="button" @click="setSort('users', 'keywords_count')"
                                :class="sortHeaderClass('users', 'keywords_count') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por keywords">
                            Keywords
                            <i class="fas text-[10px]" :class="sortIcon('users', 'keywords_count') + ' ' + sortIconClass('users', 'keywords_count')"></i>
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left hidden md:table-cell" title="Storages con acceso / storages asignados">
                        <button type="button" @click="setSort('users', 'storages_with_access')"
                                :class="sortHeaderClass('users', 'storages_with_access') + ' inline-flex items-center gap-1.5 transition-colors'"
                                title="Ordenar por storages con acceso">
                            Acceso
                            <i class="fas text-[10px]" :class="sortIcon('users', 'storages_with_access') + ' ' + sortIconClass('users', 'storages_with_access')"></i>
                        </button>
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="(u, idx) in users" :key="u.id">
                    <tr class="hover:bg-slate-50">
                        <td class="px-2 py-3 text-center text-xs text-slate-400 tabular-nums"
                            x-text="(usersPage - 1) * usersPerPage + idx + 1"></td>
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

        <!-- Paginación inferior -->
        <div x-show="!loading && users.length > 0" class="px-4 py-3 border-t border-slate-200">
            @include('mis-avisos._pagination', ['scope' => 'users', 'position' => 'bottom'])
        </div>
    </div>

    <!-- ═══ Pestaña: Escaneo (avisos-scan-configuration) ═══ -->
    <div x-show="activeTab === 'escaneo'" x-cloak class="space-y-4">

        {{-- Estado y configuración --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-800"><i class="fas fa-broadcast-tower mr-2 text-brand-600"></i>Escaneo de transcripciones</h2>
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
            <div class="p-5 relative">
                {{-- Close button (X) en la esquina superior derecha — disponible en TODAS las fases
                     del modal. Cierra la vista del modal pero NO cancela el escaneo:
                     el worker sigue corriendo en background, el polling continúa y
                     el widget global del layout muestra el progreso en vivo. --}}
                <button type="button"
                        @click="scanModal.open = false"
                        class="absolute top-3 right-3 w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 text-lg"
                        title="Cerrar (el escaneo sigue corriendo en el servidor)">
                    ×
                </button>
                <h2 class="text-lg font-bold text-slate-800 mb-1 flex items-center gap-2 pr-8">
                    <i class="fas fa-broadcast-tower text-brand-600"></i>Escaneo de menciones
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
                    {{-- Modo mensual (fix-avisos-scan-by-months-no-saturation): --}}
                    <template x-if="scanModal.mode === 'monthly'">
                        <div class="mb-4">
                            <div class="flex items-center gap-3 mb-1">
                                <i class="fas fa-circle-notch fa-spin text-brand-500"></i>
                                <span class="text-sm text-slate-600"
                                      x-text="'Procesando mes ' + (scanModal.monthsDone + 1) + ' de ' + scanModal.monthsTotal + ' (' + (scanModal.currentMonth || '...') + ')'"></span>
                            </div>
                            <p class="text-[11px] text-emerald-600 mb-3"><i class="fas fa-cloud mr-1"></i>Modo mensual: procesando mes por mes. Puedes cerrar esta ventana o recargar la página sin perder el progreso.</p>
                            <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden mb-4">
                                <div class="bg-brand-600 h-3 rounded-full transition-all duration-300"
                                     :style="'width:' + (scanModal.monthsTotal ? Math.min(100, Math.round((scanModal.monthsDone || 0) / scanModal.monthsTotal * 100)) : 0) + '%'"></div>
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
                        </div>
                    </template>
                    {{-- Modo clásico (default): --}}
                    <template x-if="scanModal.mode !== 'monthly'">
                        <div>
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
                        </div>
                    </template>
                    <button @click="stopScanSequence()" class="mt-2 w-full py-2 border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg text-sm">
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

    <!-- ═══ Pestaña: Dashboard (avisos-scan-coverage-admin-dashboard) ═══ -->
    <div x-show="activeTab === 'dashboard'" x-cloak class="space-y-4">

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">
                        <i class="fas fa-gauge-high mr-2 text-brand-600"></i>Estado del módulo de cobertura
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Vista única de salud del sistema. Generado <span x-text="dashboard.generated_at?.replace('T',' ').slice(0,19) || '—'"></span>.
                    </p>
                </div>
                <button @click="loadDashboard()" :disabled="dashboard.loading"
                        class="px-3 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg disabled:opacity-50">
                    <i class="fas fa-sync mr-1" :class="dashboard.loading && 'fa-spin'"></i>Refrescar
                </button>
            </div>

            <div x-show="dashboard.loading" class="flex items-center justify-center py-12">
                <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
            </div>

            <div x-show="!dashboard.loading" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Pares totales</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1" x-text="dashboard.pairs_total ?? '—'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Pendientes catch-up</p>
                    <p class="text-3xl font-bold mt-1"
                       :class="(dashboard.pairs_pending > 0) ? 'text-amber-600' : 'text-green-600'"
                       x-text="dashboard.pairs_pending ?? '—'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Con hits</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1" x-text="dashboard.pairs_with_hits ?? '—'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Drift negativo</p>
                    <p class="text-3xl font-bold mt-1"
                       :class="(dashboard.drift_negative > 0) ? 'text-red-600' : 'text-green-600'"
                       x-text="dashboard.drift_negative ?? '—'"></p>
                    <button x-show="dashboard.drift_negative > 0"
                            @click="runReconcileFromDashboard()"
                            class="mt-2 text-[11px] text-brand-600 hover:text-brand-700 underline">
                        Reconciliar ahora
                    </button>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-800 mb-3">
                <i class="fas fa-database mr-2 text-brand-600"></i>Readiness de particionamiento
            </h3>
            <div class="space-y-3">
                <template x-for="(r, table) in dashboard.readiness || {}" :key="table">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-mono text-slate-700" x-text="table"></span>
                                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold"
                                      :class="{
                                          'bg-green-100 text-green-700': r.status === 'OK',
                                          'bg-amber-100 text-amber-700': r.status === 'WARNING',
                                          'bg-red-100 text-red-700': r.status === 'CRITICAL',
                                      }"
                                      x-text="r.status"></span>
                            </div>
                            <div class="text-xs text-slate-500">
                                <span x-text="r.count?.toLocaleString() || '0'"></span> / <span x-text="r.threshold?.toLocaleString() || '0'"></span> (<span x-text="r.pct"></span>%)
                            </div>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                            <div class="h-2 transition-all"
                                 :class="{
                                     'bg-green-500': r.status === 'OK',
                                     'bg-amber-500': r.status === 'WARNING',
                                     'bg-red-500': r.status === 'CRITICAL',
                                 }"
                                 :style="`width: ${Math.min(100, r.pct)}%`"></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-800 mb-3">
                    <i class="fas fa-clock-rotate-left mr-2 text-brand-600"></i>Últimas 5 acciones del audit
                </h3>
                <ul class="space-y-2">
                    <template x-for="a in dashboard.audit_recent || []" :key="a.id">
                        <li class="flex items-start gap-2 text-xs">
                            <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold flex-shrink-0"
                                  :class="{
                                      'bg-blue-100 text-blue-700': a.action === 'rewind_pair',
                                      'bg-purple-100 text-purple-700': a.action === 'full_scan',
                                      'bg-amber-100 text-amber-700': a.action === 'hook_auto',
                                      'bg-green-100 text-green-700': a.action === 'reconcile',
                                  }"
                                  x-text="a.action"></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-slate-600">
                                    <span class="font-medium" x-text="a.actor"></span>
                                    <span x-show="a.keyword_text"> · <span x-text="a.keyword_text"></span></span>
                                    <span x-show="a.storage_name"> · <span x-text="a.storage_name"></span></span>
                                </div>
                                <div class="text-slate-400 text-[10px]" x-text="a.created_at?.replace('T',' ').slice(0,19)"></div>
                            </div>
                        </li>
                    </template>
                    <li x-show="(dashboard.audit_recent || []).length === 0" class="text-xs text-slate-400">Sin acciones registradas.</li>
                </ul>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-800 mb-3">
                    <i class="fas fa-broadcast-tower mr-2 text-brand-600"></i>Últimas 3 corridas de scan
                </h3>
                <ul class="space-y-2">
                    <template x-for="s in dashboard.scans_recent || []" :key="s.id">
                        <li class="flex items-start gap-2 text-xs">
                            <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold flex-shrink-0"
                                  :class="{
                                      'bg-slate-100 text-slate-600': s.origin === 'manual',
                                      'bg-brand-100 text-brand-700': s.origin === 'cron',
                                      'bg-purple-100 text-purple-700': s.origin === 'full_scan',
                                  }"
                                  x-text="s.origin"></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-slate-600">
                                    <span class="font-mono" x-text="s.scanned"></span> escaneadas ·
                                    <span class="font-mono" x-text="s.hits_new"></span> hits nuevos
                                </div>
                                <div class="text-slate-400 text-[10px]">
                                    <span x-text="s.started_at?.replace('T',' ').slice(0,19)"></span> ·
                                    <span x-text="s.duration_ms ? Math.round(s.duration_ms/1000) + 's' : ''"></span>
                                </div>
                            </div>
                            <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold"
                                  :class="{
                                      'bg-green-100 text-green-700': s.status === 'success',
                                      'bg-red-100 text-red-700': s.status === 'failed',
                                      'bg-slate-100 text-slate-600': s.status === 'dry-run',
                                  }"
                                  x-text="s.status"></span>
                        </li>
                    </template>
                    <li x-show="(dashboard.scans_recent || []).length === 0" class="text-xs text-slate-400">Sin corridas recientes.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ═══ Pestaña: Cobertura (avisos-keyword-storage-watermark) ═══ -->
    <div x-show="activeTab === 'cobertura'" x-cloak class="space-y-4">

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">
                        <i class="fas fa-shield-halved mr-2 text-brand-600"></i>Cobertura de escaneo por keyword
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Cada par (keyword, storage) tiene un watermark que rastrea hasta dónde fue escaneado.
                        Una keyword nueva arranca con <code class="text-[11px] bg-slate-100 px-1 rounded">scanned_until = NULL</code>
                        y recibe catch-up automático. Aquí puedes forzar el catch-up de un par específico o lanzar un escaneo completo.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadCoverage()"
                            :disabled="coverage.loading"
                            class="px-3 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg disabled:opacity-50">
                        <i class="fas fa-sync mr-1" :class="coverage.loading && 'fa-spin'"></i>Refrescar
                    </button>
                    <button @click="openFullScanConfirm()"
                            class="px-3 py-1.5 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg">
                        <i class="fas fa-rocket mr-1"></i>Escaneo completo
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-4">
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Pares totales</p>
                    <p class="text-2xl font-bold text-slate-800 mt-1" x-text="coverage.summary.total ?? '—'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Pendientes catch-up</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1" x-text="coverage.summary.pending ?? '—'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Cubiertos</p>
                    <p class="text-2xl font-bold text-green-600 mt-1" x-text="coverage.summary.covered ?? '—'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-400 uppercase font-semibold">Hits totales</p>
                    <p class="text-2xl font-bold text-slate-800 mt-1" x-text="coverage.summary.hits ?? '—'"></p>
                </div>
            </div>

            <div x-show="coverage.loading" class="flex items-center justify-center py-12">
                <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
            </div>

            <div x-show="!coverage.loading && coverage.items.length === 0" class="text-center py-12 text-slate-400">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <p class="text-sm">No hay pares (keyword, storage) con cobertura registrada.</p>
            </div>

            <div x-show="!coverage.loading && coverage.items.length > 0" class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Keyword</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Storage</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Scanned until</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Último hit</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Hits</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="row in coverage.items" :key="row.keyword_id + '-' + row.storage_provider_id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 text-sm text-slate-700" x-text="row.keyword_text"></td>
                                <td class="px-4 py-3 text-sm text-slate-600" x-text="row.storage_name"></td>
                                <td class="px-4 py-3 text-sm">
                                    <span x-show="row.scanned_until"
                                          class="text-slate-600"
                                          x-text="row.scanned_until?.replace('T',' ').slice(0,16) || '—'"></span>
                                    <span x-show="!row.scanned_until"
                                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                        <i class="fas fa-clock mr-1"></i>pendiente
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-500"
                                    x-text="row.last_hit_at ? row.last_hit_at.replace('T',' ').slice(0,16) : '—'"></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600" x-text="row.hits_total"></td>
                                <td class="px-4 py-3 text-right">
                                    <button @click="rewindWatermark(row.keyword_id, row.storage_provider_id)"
                                            :disabled="coverage.rewinding === row.keyword_id + '-' + row.storage_provider_id"
                                            class="px-2.5 py-1 text-[11px] border border-brand-300 text-brand-700 hover:bg-brand-50 rounded disabled:opacity-50"
                                            title="Pone el watermark en NULL: el próximo cron escanea el histórico completo de este par">
                                        <i class="fas fa-undo mr-1"></i>Activar histórico
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div class="flex items-center justify-between px-4 py-3 bg-slate-50 border-t border-slate-200 text-sm">
                    <div class="text-slate-600">
                        Mostrando
                        <span x-text="coverage.items.length"></span>
                        de
                        <span x-text="coverage.total"></span>
                        pares
                    </div>
                    <div class="flex items-center gap-2">
                        <select x-model="coverage.perPage" @change="loadCoverage(1)"
                                class="border border-slate-300 rounded px-2 py-1 text-xs">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <button @click="loadCoverage(coverage.page - 1)" :disabled="coverage.page <= 1"
                                class="px-2 py-1 text-xs border border-slate-300 rounded disabled:opacity-50">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="text-xs text-slate-600">
                            Página <span x-text="coverage.page"></span> de <span x-text="coverage.lastPage"></span>
                        </span>
                        <button @click="loadCoverage(coverage.page + 1)" :disabled="coverage.page >= coverage.lastPage"
                                class="px-2 py-1 text-xs border border-slate-300 rounded disabled:opacity-50">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" x-model="coverage.filterQ" @input.debounce.400ms="loadCoverage(1)"
                           placeholder="Buscar keyword..."
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
                <select x-model="coverage.filterStorageId" @change="loadCoverage(1)"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Todos los storages</option>
                    <template x-for="sp in coverage.storages" :key="sp.id">
                        <option :value="sp.id" x-text="sp.name"></option>
                    </template>
                </select>
                <button @click="loadCoverage(1)" class="px-3 py-2 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg">
                    <i class="fas fa-sync mr-1"></i>Aplicar
                </button>
            </div>
        </div>

        <div x-show="coverage.flash" x-transition
             class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg p-3"
             x-text="coverage.flash"></div>

        <!-- Modal confirmación escaneo completo -->
        <div x-cloak x-show="fullScanModal.open" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="fullScanModal.open = false">
                <div class="p-6">
                    <h2 class="text-lg font-bold text-slate-800 mb-1">Escaneo completo</h2>
                    <p class="text-sm text-slate-600 mb-4">
                        Barre TODOS los pares (keyword, storage) con watermark atrasado, en lotes, con tope de tiempo.
                        Reanudable: si se interrumpe, la siguiente corrida continúa donde quedó.
                    </p>
                    <div class="bg-slate-50 rounded-lg p-3 mb-4 text-xs text-slate-600 space-y-1">
                        <div>Lote por corrida: <span class="font-mono" x-text="fullScanModal.batch || 200"></span></div>
                        <div>Tope de tiempo: <span class="font-mono" x-text="fullScanModal.maxRuntime || 600"></span> segundos</div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <button @click="fullScanModal.open = false" class="px-4 py-2 text-sm border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg">Cancelar</button>
                        <button @click="runFullScan()" class="px-4 py-2 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg">
                            <i class="fas fa-rocket mr-1"></i>Lanzar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Pestaña: Auditoría (avisos-scan-coverage-audit-ui) ═══ -->
    <div x-show="activeTab === 'auditoria'" x-cloak class="space-y-4">

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">
                        <i class="fas fa-calendar-week mr-2 text-brand-600"></i>Actividad últimos 90 días
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Click en una celda para filtrar el log por ese día.
                    </p>
                </div>
                <button @click="loadHeatmap()" :disabled="heatmap.loading"
                        class="px-3 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg disabled:opacity-50">
                    <i class="fas fa-sync mr-1" :class="heatmap.loading && 'fa-spin'"></i>
                </button>
            </div>
            <div class="overflow-x-auto">
                <div class="grid grid-cols-[repeat(13,minmax(0,1fr))] gap-1 min-w-[500px]">
                    <template x-for="d in heatmap.days || []" :key="d.date">
                        <button @click="filterAuditByDate(d.date)"
                                class="aspect-square rounded text-[9px] font-mono flex items-center justify-center cursor-pointer hover:ring-2 hover:ring-brand-500 transition-all"
                                :class="{
                                    'bg-slate-100 text-slate-300': d.total_actions === 0,
                                    'bg-blue-100 text-blue-700': d.total_actions > 0 && d.total_actions <= 5,
                                    'bg-blue-200 text-blue-800': d.total_actions > 5 && d.total_actions <= 20,
                                    'bg-blue-300 text-blue-900': d.total_actions > 20 && d.total_actions <= 50,
                                    'bg-blue-500 text-white': d.total_actions > 50,
                                }"
                                :title="d.date + ': ' + d.total_actions + ' acciones (' + Object.entries(d.by_action).map(([k,v])=>k+'='+v).join(', ') + ')'"
                                x-text="d.date.slice(5)"></button>
                    </template>
                </div>
            </div>
            <div class="flex items-center gap-2 mt-3 text-[10px] text-slate-500">
                <span>Sin actividad</span>
                <span class="w-3 h-3 rounded bg-slate-100"></span>
                <span>1-5</span>
                <span class="w-3 h-3 rounded bg-blue-100"></span>
                <span>6-20</span>
                <span class="w-3 h-3 rounded bg-blue-200"></span>
                <span>21-50</span>
                <span class="w-3 h-3 rounded bg-blue-300"></span>
                <span>50+</span>
                <span class="w-3 h-3 rounded bg-blue-500"></span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">
                        <i class="fas fa-clipboard-list mr-2 text-brand-600"></i>Auditoría de acciones sobre watermarks
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Quién movió qué par, cuándo y desde qué valor a qué valor. Append-only. Exportable a CSV.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="exportAuditCsv()"
                            :disabled="audit.exportLoading"
                            class="px-3 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg disabled:opacity-50">
                        <i class="fas fa-download mr-1"></i>Exportar CSV
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-6 gap-3 mb-4">
                <input type="text" x-model="audit.filters.actor" @input.debounce.400ms="loadAuditLog(1)" placeholder="Actor (LIKE)…"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-xs">
                <select x-model="audit.filters.action" @change="loadAuditLog(1)"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-xs">
                    <option value="">Todas las acciones</option>
                    <option value="rewind_pair">rewind_pair</option>
                    <option value="full_scan">full_scan</option>
                    <option value="hook_auto">hook_auto</option>
                    <option value="reconcile">reconcile</option>
                </select>
                <input type="text" x-model="audit.filters.keyword" @input.debounce.400ms="loadAuditLog(1)" placeholder="Keyword…"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-xs">
                <input type="text" x-model="audit.filters.storage" @input.debounce.400ms="loadAuditLog(1)" placeholder="Storage…"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-xs">
                <input type="date" x-model="audit.filters.since" @change="loadAuditLog(1)"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-xs">
                <input type="date" x-model="audit.filters.until" @change="loadAuditLog(1)"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-xs">
            </div>

            <div x-show="audit.loading" class="flex items-center justify-center py-12">
                <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
            </div>

            <div x-show="!audit.loading && audit.items.length === 0" class="text-center py-12 text-slate-400">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <p class="text-sm">Sin entradas que coincidan con los filtros.</p>
            </div>

            <div x-show="!audit.loading && audit.items.length > 0" class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Cuándo</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Actor</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Action</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Keyword</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Storage</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Before</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">After</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="row in audit.items" :key="row.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 text-xs text-slate-500" x-text="row.created_at?.replace('T',' ').slice(0,19)"></td>
                                <td class="px-3 py-2 text-xs">
                                    <span x-text="row.actor"
                                          :class="row.actor === 'sistema' ? 'text-slate-400 italic' : 'text-slate-700 font-medium'"></span>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold"
                                          :class="{
                                              'bg-blue-100 text-blue-700': row.action === 'rewind_pair',
                                              'bg-purple-100 text-purple-700': row.action === 'full_scan',
                                              'bg-amber-100 text-amber-700': row.action === 'hook_auto',
                                              'bg-green-100 text-green-700': row.action === 'reconcile',
                                          }"
                                          x-text="row.action"></span>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-600" x-text="row.keyword_text || '—'"></td>
                                <td class="px-3 py-2 text-xs text-slate-600" x-text="row.storage_name || '—'"></td>
                                <td class="px-3 py-2 text-xs text-slate-500 font-mono" x-text="row.before_value?.replace('T',' ').slice(0,16) || '—'"></td>
                                <td class="px-3 py-2 text-xs text-slate-500 font-mono" x-text="row.after_value?.replace('T',' ').slice(0,16) || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div class="flex items-center justify-between px-4 py-3 bg-slate-50 border-t border-slate-200 text-sm">
                    <div class="text-slate-600">
                        Mostrando <span x-text="audit.items.length"></span> de <span x-text="audit.total"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <select x-model="audit.perPage" @change="loadAuditLog(1)" class="border border-slate-300 rounded px-2 py-1 text-xs">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <button @click="loadAuditLog(audit.page - 1)" :disabled="audit.page <= 1"
                                class="px-2 py-1 text-xs border border-slate-300 rounded disabled:opacity-50">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="text-xs text-slate-600">
                            Página <span x-text="audit.page"></span> de <span x-text="audit.lastPage"></span>
                        </span>
                        <button @click="loadAuditLog(audit.page + 1)" :disabled="audit.page >= audit.lastPage"
                                class="px-2 py-1 text-xs border border-slate-300 rounded disabled:opacity-50">
                            <i class="fas fa-chevron-right"></i>
                        </button>
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
        usersPage: 1,
        usersLastPage: 1,
        usersTotal: 0,
        usersPerPage: 25,
        usersSort: { column: '', direction: '' },
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
        // Cobertura (avisos-keyword-storage-watermark)
        coverage: {
            items: [],
            loading: false,
            page: 1,
            lastPage: 1,
            perPage: 25,
            total: 0,
            filterStorageId: '',
            filterQ: '',
            summary: { total: 0, pending: 0, covered: 0, hits: 0 },
            rewinding: null,
            flash: '',
        },
        fullScanModal: { open: false, batch: 200, maxRuntime: 600 },
        // Auditoría (avisos-scan-coverage-audit-ui)
        audit: {
            items: [],
            loading: false,
            page: 1,
            lastPage: 1,
            perPage: 25,
            total: 0,
            filters: { actor: '', action: '', keyword: '', storage: '', since: '', until: '' },
            exportLoading: false,
        },
        // Dashboard (avisos-scan-coverage-admin-dashboard)
        dashboard: {
            loading: false,
            pairs_total: 0,
            pairs_pending: 0,
            pairs_with_hits: 0,
            drift_negative: 0,
            drift_orphan: 0,
            audit_recent: [],
            scans_recent: [],
            readiness: {},
            generated_at: null,
        },
        // Heatmap del audit log (últimos 90 días)
        heatmap: { loading: false, days: [] },
        scanError: false,
        storages: [],
        scanModal: { open: false, phase: 'confirm', runId: null, estimate: null, scanned: 0, hitsNew: 0, failed: 0, runs: 0, errors: [], stopped: false, result: null },
        scanPollTimer: null,
        async init() {
            await this.load();
            this.loadScan();
            this.checkActiveScanRun();
            try {
                const r = await apiFetch('/ia/avisos-inteligentes/storages', { headers: { 'Accept': 'application/json' } });
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
            // fix-avisos-scan-by-months-no-saturation: pre-launch confirmation
            // para el modo mensual. Solo confirmamos si la combinación coincide
            // con la regla del modo (noWindow + force + sin preset/rango).
            const willBeMonthly = body.force
                && body.noWindow
                && !body.preset
                && !body.from
                && !body.to;
            if (willBeMonthly) {
                try {
                    const previewRes = await apiFetch('/ia/avisos-inteligentes/scan/run-bg/preview', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify(body),
                    });
                    const preview = await previewRes.json().catch(() => ({}));
                    if (previewRes.ok && preview.mode === 'monthly') {
                        const msg = preview.month_count === 0
                            ? 'No hay transcripciones pendientes. ¿Iniciar el escaneo igual?'
                            : `Vas a procesar ${preview.month_count} mes(es) (${preview.first_month || '?'} → ${preview.last_month || '?'}). Esto puede tomar varios minutos. ¿Confirmar?`;
                        if (!confirm(msg)) {
                            // El operador canceló: volvemos al modal de configuración.
                            m.phase = 'confirm';
                            return;
                        }
                    }
                } catch (previewErr) {
                    // Si el preview falla, no bloqueamos el launch (defensa en profundidad).
                    console.warn('Preview failed, proceeding with launch:', previewErr);
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
            // fix-avisos-scan-by-months-no-saturation: inicializar campos mensuales
            m.mode = m.mode || null;
            m.monthsTotal = m.monthsTotal || 0;
            m.monthsDone = m.monthsDone || 0;
            m.currentMonth = m.currentMonth || null;
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
                // fix-avisos-scan-by-months-no-saturation: campos mensuales
                if (s.mode) m.mode = s.mode;
                if (typeof s.months_total !== 'undefined') m.monthsTotal = s.months_total;
                if (typeof s.months_done !== 'undefined') m.monthsDone = s.months_done;
                if (s.current_month) m.currentMonth = s.current_month;
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
                if (res.status === 204) {
                    // Si veníamos con un focus del widget global, no tenemos run al que abrir → limpiamos el focus
                    this.focusBgJob(null);
                    return;
                }
                const s = await res.json();
                // Cambiado (add-bg-job-indicator-widget): ya NO forzamos activeTab='escaneo' ni
                // abrimos el modal automáticamente. Solo attachamos el polling local para que
                // los datos estén frescos si el operador decide abrir el modal vía "Ver detalles"
                // del widget global (que navega con ?focus=bg-avisos-scan-{runId}).
                this.focusBgJob(s.runId);
                this.attachScanRun(s.runId);
            } catch (e) {}
        },
        // add-bg-job-indicator-widget: leer el parámetro focus y abrir el modal si aplica.
        // Mantiene compat: sin focus, no abre nada (comportamiento normal).
        focusBgJob(runId) {
            try {
                const params = new URLSearchParams(window.location.search);
                const focus = params.get('focus');
                if (!focus || !runId) return;
                const expected = `bg-avisos-scan-${runId}`;
                if (focus === expected) {
                    // Solo abrimos si el modal NO está ya abierto (idempotente)
                    if (!this.scanModal.open) {
                        this.scanModal = {
                            open: true, phase: 'running', runId: runId,
                            estimate: null, scanned: 0, hitsNew: 0, failed: 0, runs: 0,
                            errors: [], stopped: false, result: null, catchup: false,
                        };
                    }
                }
            } catch (e) {}
        },
        async load() {
        },
        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ page: this.usersPage, per_page: this.usersPerPage });
                if (this.search) params.set('q', this.search);
                if (this.moduleFilter) params.set('module', this.moduleFilter);
                if (this.usersSort.column) {
                    params.set('sort', this.usersSort.column);
                    params.set('direction', this.usersSort.direction);
                }
                const res = await apiFetch('/ia/avisos-inteligentes?' + params, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const d = await res.json();
                    this.users = d.data || [];
                    this.usersLastPage = d.last_page || 1;
                    this.usersTotal = d.total || 0;
                    if (d.per_page) this.usersPerPage = d.per_page;
                    if (d.current_page) this.usersPage = d.current_page;
                }
            } finally { this.loading = false; }
        },
        setSort(scope, column) {
            if (scope !== 'users') return;
            const cur = this.usersSort;
            if (cur.column === column) {
                cur.direction = cur.direction === 'asc' ? 'desc' : 'asc';
            } else {
                cur.column = column;
                cur.direction = 'asc';
            }
            this.usersSort = { ...cur };
            this.usersPage = 1;
            this.load();
        },
        sortIcon(scope, col) {
            if (scope !== 'users') return 'fa-sort';
            const sort = this.usersSort;
            if (sort.column !== col) return 'fa-sort';
            return sort.direction === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down';
        },
        sortIconClass(scope, col) {
            if (scope !== 'users') return 'text-slate-300';
            const sort = this.usersSort;
            return sort.column === col
                ? (sort.direction === 'asc' ? 'text-violet-600' : 'text-amber-600')
                : 'text-slate-300';
        },
        sortHeaderClass(scope, col) {
            if (scope !== 'users') return 'text-slate-500 hover:text-slate-800';
            return this.usersSort.column === col
                ? 'text-slate-800 font-semibold'
                : 'text-slate-500 hover:text-slate-800';
        },
        pageList(current, last) {
            if (!last || last <= 7) return Array.from({ length: Math.max(1, last || 1) }, (_, i) => i + 1);
            const pages = [1];
            const start = Math.max(2, current - 2);
            const end = Math.min(last - 1, current + 2);
            if (start > 2) pages.push('…');
            for (let p = start; p <= end; p++) pages.push(p);
            if (end < last - 1) pages.push('…');
            pages.push(last);
            return pages;
        },
        goPage(scope, page) {
            if (scope !== 'users') return;
            this.usersPage = page;
            this.load();
        },
        setPerPage(scope, n) {
            if (scope !== 'users') return;
            this.usersPerPage = n;
            this.usersPage = 1;
            this.load();
        },
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
        // ─── Cobertura (avisos-scan-coverage-reconciler) ────────────────
        async loadCoverage(page = 1) {
            this.coverage.loading = true;
            try {
                const params = new URLSearchParams({ page });
                params.set('per_page', this.coverage.perPage);
                if (this.coverage.filterStorageId) params.set('storageId', this.coverage.filterStorageId);
                if (this.coverage.filterQ) params.set('q', this.coverage.filterQ);
                const res = await apiFetch('/ia/avisos-inteligentes/scan/coverage?' + params, {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const d = await res.json();
                    this.coverage.items = d.items || [];
                    this.coverage.page = d.current_page || 1;
                    this.coverage.lastPage = d.last_page || 1;
                    this.coverage.total = d.total || 0;
                    this.coverage.storages = d.storages || [];
                    this.coverage.summary = {
                        total: d.total || 0,
                        pending: this.coverage.items.filter(r => r.pending_catchup).length,
                        covered: this.coverage.items.filter(r => !r.pending_catchup).length,
                        hits: this.coverage.items.reduce((s, r) => s + (r.hits_total || 0), 0),
                    };
                }
            } catch (e) { console.error('loadCoverage', e); }
            finally { this.coverage.loading = false; }
        },
        async rewindWatermark(keywordId, storageId) {
            // Paso 1: preview (sin mutar) para mostrar al admin cuántos pares se verán afectados.
            try {
                const previewRes = await apiFetch('/ia/avisos-inteligentes/scan/rewind?preview=true', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ keyword_id: keywordId, storage_provider_id: storageId }),
                });
                if (!previewRes.ok) {
                    const d = await previewRes.json().catch(() => ({}));
                    alert(d.message || 'Error en preview');
                    return;
                }
                const preview = await previewRes.json();
                if (!confirm(`¿Activar histórico de esta keyword?\n\nEsto procesará ${preview.candidates_count} transcripción(es) desde el inicio.`)) return;
            } catch (e) {
                if (!confirm(`¿Activar histórico de esta keyword?\n\n(no se pudo obtener el conteo previo)`)) return;
            }
            // Paso 2: mutación real (la auditoría se registra vía middleware).
            this.coverage.rewinding = keywordId + '-' + storageId;
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/rewind', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ keyword_id: keywordId, storage_provider_id: storageId }),
                });
                if (res.ok) {
                    this.coverage.flash = 'Watermark reiniciado: catch-up completo programado.';
                    setTimeout(() => this.coverage.flash = '', 4000);
                    await this.loadCoverage(this.coverage.page);
                } else {
                    const d = await res.json().catch(() => ({}));
                    alert(d.message || d.error || 'Error al rewind');
                }
            } finally { this.coverage.rewinding = null; }
        },
        openFullScanConfirm() { this.fullScanModal.open = true; },
        async runFullScan() {
            this.fullScanModal.open = false;
            this.coverage.flash = 'Escaneo completo en curso (background)…';
            // Lanzar en background via POST /scan/full-bg.
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/full-bg', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({
                        batch: this.fullScanModal.batch,
                        maxRuntime: this.fullScanModal.maxRuntime,
                    }),
                });
                if (res.status === 409) {
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'Ya hay un full scan en curso');
                    this.coverage.flash = '';
                    return;
                }
                if (!res.ok) {
                    const d = await res.json().catch(() => ({}));
                    alert(d.message || d.error || 'Error al lanzar full scan');
                    this.coverage.flash = '';
                    return;
                }
                const d = await res.json();
                this.coverage.flash = `Full scan lanzado (runId: ${d.runId}). Polling cada 2s…`;
                this.pollFullScan(d.runId);
            } catch (e) {
                this.coverage.flash = 'Error: ' + e.message;
            }
        },
        async pollFullScan(runId) {
            // Polling cada 2s hasta status final.
            while (true) {
                await new Promise(r => setTimeout(r, 2000));
                try {
                    const res = await apiFetch(`/ia/avisos-inteligentes/scan/full-bg/${runId}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!res.ok) break;
                    const s = await res.json();
                    const iter = s.iterations || 0;
                    const scn = s.scanned || 0;
                    const hits = s.hits_new || 0;
                    const dur = s.duration_ms ? Math.round(s.duration_ms / 1000) : 0;
                    this.coverage.flash = `Full scan ${s.status}: iter ${iter} · ${scn} escaneadas · ${hits} hits · ${dur}s`;
                    if (['done', 'stopped', 'failed'].includes(s.status)) {
                        if (s.status === 'done') {
                            await this.loadCoverage(1);
                            await new Promise(r => setTimeout(r, 4000));
                        }
                        this.coverage.flash = '';
                        break;
                    }
                } catch (e) {
                    console.error('pollFullScan', e);
                    break;
                }
            }
        },
        async loadAuditLog(page = 1) {
            this.audit.loading = true;
            try {
                const params = new URLSearchParams({ page, per_page: this.audit.perPage });
                Object.entries(this.audit.filters).forEach(([k, v]) => {
                    if (v) params.set(k, v);
                });
                const res = await apiFetch('/ia/avisos-inteligentes/scan/audit?' + params, {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const d = await res.json();
                    this.audit.items = d.items || [];
                    this.audit.page = d.current_page || 1;
                    this.audit.lastPage = d.last_page || 1;
                    this.audit.total = d.total || 0;
                }
            } catch (e) { console.error('loadAuditLog', e); }
            finally { this.audit.loading = false; }
        },
        async exportAuditCsv() {
            this.audit.exportLoading = true;
            try {
                const params = new URLSearchParams();
                Object.entries(this.audit.filters).forEach(([k, v]) => {
                    if (v) params.set(k, v);
                });
                const res = await apiFetch('/ia/avisos-inteligentes/scan/audit/export?' + params, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'text/csv' },
                });
                if (res.ok) {
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'audit-' + new Date().toISOString().slice(0, 10) + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                } else {
                    alert('Error al exportar CSV');
                }
            } catch (e) {
                alert('Error: ' + e.message);
            } finally {
                this.audit.exportLoading = false;
            }
        },
        async loadDashboard() {
            this.dashboard.loading = true;
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/dashboard', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const d = await res.json();
                    Object.assign(this.dashboard, d);
                }
            } catch (e) { console.error('loadDashboard', e); }
            finally { this.dashboard.loading = false; }
        },
        async runReconcileFromDashboard() {
            if (!confirm('¿Ejecutar reconcile ahora para reparar el drift negativo?')) return;
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/reconcile', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ dry_run: false }),
                });
                if (res.ok) {
                    await this.loadDashboard();
                    alert('Reconcile ejecutado.');
                } else {
                    alert('Error al ejecutar reconcile');
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        },
        async loadHeatmap() {
            this.heatmap.loading = true;
            try {
                const res = await apiFetch('/ia/avisos-inteligentes/scan/audit/heatmap?days=90', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const d = await res.json();
                    this.heatmap.days = d.days || [];
                }
            } catch (e) { console.error('loadHeatmap', e); }
            finally { this.heatmap.loading = false; }
        },
        async filterAuditByDate(date) {
            this.activeTab = 'auditoria';
            this.audit.filters.since = date;
            this.audit.filters.until = date;
            await this.loadAuditLog(1);
        },
    };
}
</script>
@endpush
@endsection