@extends('layouts.app')

@section('title', 'Solicitud Puntual de TV y Radio - Tcloud')

@section('content')
<div class="p-6" x-data="puntualManager()" x-init="init()" data-admin="{{ session('user_role') === 'admin' ? '1' : '0' }}">

    <div class="mb-4 sm:mb-6 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg sm:text-2xl font-bold text-slate-800">Solicitud Puntual de TV y Radio</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
                Pide una franja horaria o el día completo de un medio que <strong>no</strong> tienes asignado.
                Recibes enlaces de solo lectura con 3 días de vigencia.
            </p>
        </div>
        <button onclick="startPuntualTour()"
                class="shrink-0 flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-sm font-medium transition-colors"
                title="Guía interactiva">
            <i class="fas fa-map-marked-alt"></i>
            <span class="hidden sm:inline">Guía</span>
        </button>
    </div>

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- ================= PESTAÑAS ================= -->
    <div class="flex items-center gap-1 mb-5 border-b border-slate-200" data-puntual-tour="tabs">
        <button @click="tab = 'medios'"
                class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                :class="tab === 'medios' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-satellite-dish mr-1.5"></i>Medios
            <span class="ml-1 text-xs text-slate-400" x-text="'(' + medios.length + ')'"></span>
        </button>
        <button @click="tab = 'solicitudes'"
                class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                :class="tab === 'solicitudes' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-list-check mr-1.5"></i>Mis solicitudes
            <span class="ml-1 text-xs text-slate-400" x-text="'(' + (history.total || 0) + ')'"></span>
        </button>
        {{-- Pestaña de CONTROL: solo para el administrador. El cliente no la ve. --}}
        @if(session('user_role') === 'admin')
        <button @click="tab = 'control'; if (!control) loadControl()"
                data-puntual-tour="control"
                class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                :class="tab === 'control' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
            <i class="fas fa-chart-column mr-1.5"></i>Control por cliente
        </button>
        @endif
    </div>

    <!-- ================= CATÁLOGO ================= -->
    <section x-show="tab === 'medios'" class="mb-8" data-puntual-tour="catalogo">

        <!-- Barra superior: búsqueda, filtro y paginación -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200">
            <div class="px-4 py-3 flex items-center justify-between flex-wrap gap-3 border-b border-slate-100">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                        Medios que puedes solicitar
                        <span class="ml-1 font-normal normal-case text-slate-400" x-text="'(' + medios.length + ')'"></span>
                    </h2>
                    {{-- Nota de cobro: la informacion que necesita el cliente
                         para decidir entre franja y dia antes de pedir. --}}
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed max-w-2xl">
                        Las solicitudes puntuales se cobran a la tarifa normal.
                        Si necesitas <strong class="text-slate-600">más de dos horas</strong>,
                        te sale más económico pedir el
                        <strong class="text-slate-600">día completo</strong>.
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="relative">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="search" x-model="search" @input.debounce.350ms="loadMedios()"
                               placeholder="Buscar medio, región o canal…"
                               class="w-72 pl-9 pr-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
<div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="Filtrar por tipo">
                    <button type="button" @click="setKind('')"
                            class="px-3 py-1.5 rounded-[10px] text-xs font-semibold transition-colors"
                            :class="kind === '' ? 'bg-white text-slate-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            title="Ver TV y Radio">
                        Todos
                    </button>
                    <button type="button" @click="setKind('tv')"
                            class="px-3 py-1.5 rounded-[10px] text-xs font-semibold transition-colors inline-flex items-center gap-1.5"
                            :class="kind === 'tv' ? 'bg-green-600 text-white shadow-sm' : 'text-green-700 hover:bg-green-50'"
                            title="Solo televisión">
                        <i class="fas fa-tv text-[10px]"></i> TV
                    </button>
                    <button type="button" @click="setKind('radio')"
                            class="px-3 py-1.5 rounded-[10px] text-xs font-semibold transition-colors inline-flex items-center gap-1.5"
                            :class="kind === 'radio' ? 'bg-blue-600 text-white shadow-sm' : 'text-blue-700 hover:bg-blue-50'"
                            title="Solo radio">
                        <i class="fas fa-tower-broadcast text-[10px]"></i> Radio
                    </button>
                    </div>
                    <span class="text-xs text-slate-500 hidden lg:inline"
                          x-text="'Mostrando ' + rangoMedios().desde + '-' + rangoMedios().hasta + ' de ' + medios.length"></span>
                    <div class="flex items-center gap-1">
                        <button @click="paginaMedios = Math.max(1, paginaMedios - 1)" :disabled="paginaMedios <= 1"
                                class="px-2 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <template x-for="p in paginasMedios()" :key="'top' + p">
                            <button @click="paginaMedios = p"
                                    class="px-2.5 py-1 rounded-lg text-xs font-medium"
                                    :class="p === paginaMedios ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                                    x-text="p"></button>
                        </template>
                        <button @click="paginaMedios = Math.min(paginasMedios().slice(-1)[0] || 1, paginaMedios + 1)"
                                :disabled="paginaMedios >= (paginasMedios().slice(-1)[0] || 1)"
                                class="px-2 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div x-show="loadingMedios" class="text-sm text-slate-400 py-10 text-center">
                <i class="fas fa-spinner fa-spin mr-2"></i>Cargando medios…
            </div>

            <div x-show="!loadingMedios && medios.length === 0" class="p-10 text-center">
                <i class="fas fa-check-circle text-3xl text-green-400 mb-3"></i>
                <p class="text-slate-600 font-medium">No hay medios por solicitar.</p>
                <p class="text-slate-400 text-sm mt-1">
                    Tienes asignados todos los medios del catálogo. Para lo que ya tienes, usa Mis Archivos.
                </p>
            </div>

            <!-- Tabla -->
            <div x-show="!loadingMedios && medios.length > 0" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-right font-semibold text-slate-400 w-14" title="Numeración del listado">#</th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="ordenarMedios('name')" class="flex items-center gap-1 hover:text-slate-700">
                                    Medio
                                    <i class="fas fa-sort text-[10px]" x-show="mediosSort !== 'name'"></i>
                                    <i class="fas text-[10px] text-brand-600" x-show="mediosSort === 'name'"
                                       :class="mediosDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="ordenarMedios('region')" class="flex items-center gap-1 hover:text-slate-700">
                                    Región
                                    <i class="fas fa-sort text-[10px]" x-show="mediosSort !== 'region'"></i>
                                    <i class="fas text-[10px] text-brand-600" x-show="mediosSort === 'region'"
                                       :class="mediosDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="ordenarMedios('media_kind')" class="flex items-center gap-1 hover:text-slate-700">
                                    Tipo
                                    <i class="fas fa-sort text-[10px]" x-show="mediosSort !== 'media_kind'"></i>
                                    <i class="fas text-[10px] text-brand-600" x-show="mediosSort === 'media_kind'"
                                       :class="mediosDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="ordenarMedios('storage')" class="flex items-center gap-1 hover:text-slate-700">
                                    Storage
                                    <i class="fas fa-sort text-[10px]" x-show="mediosSort !== 'storage'"></i>
                                    <i class="fas text-[10px] text-brand-600" x-show="mediosSort === 'storage'"
                                       :class="mediosDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-right font-semibold">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(m, idx) in mediosPagina()" :key="m.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 text-right text-xs font-mono text-slate-400 tabular-nums"
                                    x-text="numeroFila(idx)"></td>
                                <td class="px-4 py-2 font-medium text-slate-800" x-text="m.name"></td>
                                <td class="px-4 py-2 text-slate-600" x-text="m.region || '—'"></td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                          :class="m.media_kind === 'tv' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                          x-text="(m.media_kind || '').toUpperCase()"></span>
                                </td>
                                <td class="px-4 py-2 text-slate-500 text-xs" x-text="m.storage || '—'"></td>
                                <td class="px-4 py-2 text-right">
                                    <button @click="openRequest(m)"
                                            class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-medium transition-colors">
                                        Solicitar
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Pie: buscador y paginación duplicados -->
            <div x-show="!loadingMedios && medios.length > 0"
                 class="px-4 py-3 border-t border-slate-100 flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs text-slate-500"
                          x-text="'Mostrando ' + rangoMedios().desde + '-' + rangoMedios().hasta + ' de ' + medios.length + ' medios'"></span>
                    <div class="relative">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="search" x-model="search" @input.debounce.350ms="loadMedios()"
                               placeholder="Buscar medio, región o canal…"
                               class="w-64 pl-9 pr-3 py-1.5 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
<div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="Filtrar por tipo">
                    <button type="button" @click="setKind('')"
                            class="px-3 py-1.5 rounded-[10px] text-xs font-semibold transition-colors"
                            :class="kind === '' ? 'bg-white text-slate-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            title="Ver TV y Radio">
                        Todos
                    </button>
                    <button type="button" @click="setKind('tv')"
                            class="px-3 py-1.5 rounded-[10px] text-xs font-semibold transition-colors inline-flex items-center gap-1.5"
                            :class="kind === 'tv' ? 'bg-green-600 text-white shadow-sm' : 'text-green-700 hover:bg-green-50'"
                            title="Solo televisión">
                        <i class="fas fa-tv text-[10px]"></i> TV
                    </button>
                    <button type="button" @click="setKind('radio')"
                            class="px-3 py-1.5 rounded-[10px] text-xs font-semibold transition-colors inline-flex items-center gap-1.5"
                            :class="kind === 'radio' ? 'bg-blue-600 text-white shadow-sm' : 'text-blue-700 hover:bg-blue-50'"
                            title="Solo radio">
                        <i class="fas fa-tower-broadcast text-[10px]"></i> Radio
                    </button>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button @click="paginaMedios = Math.max(1, paginaMedios - 1)" :disabled="paginaMedios <= 1"
                            class="px-2 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <template x-for="p in paginasMedios()" :key="'bot' + p">
                        <button @click="paginaMedios = p"
                                class="px-2.5 py-1 rounded-lg text-xs font-medium"
                                :class="p === paginaMedios ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                                x-text="p"></button>
                    </template>
                    <button @click="paginaMedios = Math.min(paginasMedios().slice(-1)[0] || 1, paginaMedios + 1)"
                            :disabled="paginaMedios >= (paginasMedios().slice(-1)[0] || 1)"
                            class="px-2 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= HISTORIAL ================= -->
    <section x-show="tab === 'solicitudes'" data-puntual-tour="historial">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-3">
            <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Mis solicitudes</h2>
            <div class="flex items-center gap-2">
                <input type="search" x-model="histSearch" @input.debounce.350ms="loadHistory(1)"
                       placeholder="Buscar por medio o motivo…"
                       class="w-64 px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <select x-model="histEstado" @change="loadHistory(1)"
                        class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Todos los estados</option>
                    <option value="ready">Vigentes</option>
                    <option value="expired">Expiradas</option>
                    <option value="unavailable">Sin material</option>
                    <option value="revoked">Revocadas</option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="sortBy('fecha')" class="flex items-center gap-1 hover:text-slate-700">
                                    Fecha <i class="fas fa-sort text-[10px]" x-show="sort === 'fecha'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="sortBy('medio')" class="flex items-center gap-1 hover:text-slate-700">
                                    Medio <i class="fas fa-sort text-[10px]" x-show="sort === 'medio'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">Horario</th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="sortBy('archivos')" class="flex items-center gap-1 hover:text-slate-700">
                                    Archivos <i class="fas fa-sort text-[10px]" x-show="sort === 'archivos'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="sortBy('bytes')" class="flex items-center gap-1 hover:text-slate-700">
                                    Tamaño <i class="fas fa-sort text-[10px]" x-show="sort === 'bytes'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-semibold">
                                <button @click="sortBy('estado')" class="flex items-center gap-1 hover:text-slate-700">
                                    Estado <i class="fas fa-sort text-[10px]" x-show="sort === 'estado'"></i>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-right font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="s in history.data" :key="s.id">
                            <tr>
                                <td class="px-4 py-2 text-slate-700" x-text="s.fecha_legible"></td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-slate-800" x-text="s.medio.name"></span>
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-semibold"
                                          :class="s.medio.media_kind === 'tv' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                          x-text="s.medio.media_kind.toUpperCase()"></span>
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    <span x-text="s.hora_inicio + ' → ' + s.hora_fin"></span>
                                    <span class="text-xs text-slate-400 ml-1" x-text="'(' + s.duracion_minutos + ' min)'"></span>
                                    <span class="block text-xs text-slate-400" x-text="s.modo === 'dia' ? 'día completo' : 'franja'"></span>
                                </td>
                                <td class="px-4 py-2 text-slate-600" x-text="s.archivos_total"></td>
                                <td class="px-4 py-2 text-slate-600" x-text="humanBytes(s.bytes_total)"></td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                          :class="statusClass(s.status)"
                                          x-text="statusLabel(s.status)"></span>
                                </td>
                                <td class="px-4 py-2 text-right space-x-1 whitespace-nowrap">
                                    <button @click="openDetail(s)"
                                            class="px-2 py-1 rounded-lg text-xs font-medium text-brand-700 hover:bg-brand-50">
                                        Ver
                                    </button>
                                    {{-- Revocar es del admin: un cliente podía
                                         descargar el material y revocar para borrar
                                         el rastro. El admin revoca desde "Control
                                         por cliente". --}}
                                    @if(session('user_role') === 'admin')
                                    <button @click="revoke(s)"
                                            x-show="s.status !== 'revoked'"
                                            class="px-2 py-1 rounded-lg text-xs font-medium text-red-600 hover:bg-red-50"
                                            title="Revocar (administrador)">
                                        Revocar
                                    </button>
                                    @endif
                                </td>
                            </tr>
                        </template>
                        <template x-if="history.data.length === 0">
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                    Todavía no has hecho ninguna solicitud.
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div x-show="history.pages > 1" class="px-4 py-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-500" x-text="history.total + ' solicitudes'"></span>
                <div class="flex items-center gap-1">
                    <template x-for="p in pageNumbers()" :key="p">
                        <button @click="loadHistory(p)"
                                class="px-2.5 py-1 rounded-lg text-xs font-medium"
                                :class="p === history.page ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                                x-text="p"></button>
                    </template>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= MODAL DE SOLICITUD ================= -->
    <div x-cloak x-show="modalOpen" x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
         @keydown.escape.window="closeRequest()">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col" data-puntual-tour="modal">
            <div class="px-6 py-4 border-b border-slate-200 flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-bold text-slate-800" x-text="selected?.name || ''"></h3>
                    <p class="text-sm text-slate-500 mt-0.5">Elige la fecha y el horario exacto que necesitas.</p>
                </div>
                <button @click="closeRequest()" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Fecha</label>
                        <input type="date" x-model="form.fecha" @change="onFechaCambiada()"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <p class="text-[11px] mt-1"
                           :class="diasMedio.includes(fechaIso(form.fecha)) ? 'text-green-600' : 'text-amber-600'"
                           x-text="diasMedio.length === 0
                                ? 'Sin días indexados para este medio'
                                : (diasMedio.includes(fechaIso(form.fecha))
                                    ? 'Hay material ese día'
                                    : 'Ese día no tiene material. Último: ' + fechaLegible(diasMedio[0]))"></p>
                        <div class="mt-1.5 flex items-center gap-1 flex-wrap" x-show="diasMedio.length > 0" data-puntual-tour="dias">
                            <span class="text-[11px] text-slate-400">Ir a:</span>
                            <template x-for="d in diasMedio.slice(0, 4)" :key="d">
                                <button type="button" @click="usarDia(d)"
                                        class="px-1.5 py-0.5 rounded text-[11px] font-medium"
                                        :class="fechaIso(form.fecha) === d ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                        x-text="fechaLegible(d)"></button>
                            </template>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Desde</label>
                        <input type="time" x-model="form.desde" step="1" :disabled="form.diaCompleto"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Hasta</label>
                        <input type="time" x-model="form.hasta" step="1" :disabled="form.diaCompleto"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>
                </div>

                {{-- Dia completo: evita escribir el horario. Marca el rango a
                     00:00-23:59 y la solicitud se crea en modo `dia`, que
                     entrega UN enlace sobre la carpeta del dia. --}}
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 cursor-pointer hover:bg-slate-50 transition-colors"
                       data-puntual-tour="dia-completo">
                    <input type="checkbox" x-model="form.diaCompleto" @change="onDiaCompleto()"
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-700">Quiero el día completo</span>
                        <span class="block text-xs text-slate-500 mt-0.5">
                            Se entrega <strong>un solo enlace</strong> con la carpeta del día.
                            Si necesitas más de dos horas, es la opción más económica.
                        </span>
                    </span>
                </label>

                <div class="flex items-center gap-2">
                    <button @click="estimate()" :disabled="estimating"
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors disabled:opacity-50">
                        <i class="fas fa-magnifying-glass-chart mr-1" :class="estimating ? 'fa-spin' : ''"></i>
                        Consultar cobertura
                    </button>
                    <span class="text-xs text-slate-400" x-show="form.desde && form.hasta" x-text="duracionMinutos() + ' minutos'"></span>
                </div>

                <!-- Resultado de la cobertura -->
                <div x-show="estimateData" class="rounded-xl border border-slate-200 overflow-hidden">
                    <div class="px-4 py-2.5 flex items-center justify-between"
                         :class="estimateData?.cobertura?.estado === 'completa' ? 'bg-green-50' : (estimateData?.cobertura?.estado === 'parcial' ? 'bg-amber-50' : 'bg-red-50')">
                        <div class="flex items-center gap-2">
                            <i class="fas"
                               :class="estimateData?.cobertura?.estado === 'completa' ? 'fa-circle-check text-green-600'
                                     : (estimateData?.cobertura?.estado === 'parcial' ? 'fa-triangle-exclamation text-amber-600'
                                     : (estimateData?.cobertura?.estado === 'material_no_indexado' ? 'fa-database text-brand-600' : 'fa-circle-xmark text-red-600'))"></i>
                            <span class="font-semibold text-sm"
                                  :class="estimateData?.cobertura?.estado === 'completa' ? 'text-green-800' : (estimateData?.cobertura?.estado === 'parcial' ? 'text-amber-800' : (estimateData?.cobertura?.estado === 'material_no_indexado' ? 'text-brand-800' : 'text-red-800'))"
                                  x-text="estimateData?.cobertura?.estado === 'completa' ? 'Cobertura completa'
                                        : (estimateData?.cobertura?.estado === 'parcial' ? 'Cobertura parcial'
                                        : (estimateData?.cobertura?.estado === 'material_no_indexado' ? 'Material disponible, pendiente de indexar' : 'Sin material para ese horario'))"></span>
                        </div>
                        <span class="text-xs text-slate-500"
                              x-text="estimateData ? (form.diaCompleto
                                    ? estimateData.cobertura.archivos + ' archivos del día · ' + humanBytes(estimateData.cobertura.bytes)
                                    : estimateData.cobertura.archivos + ' archivos · ' + humanBytes(estimateData.cobertura.bytes)) : ''"></span>
                    </div>

                    <div x-show="estimateData?.cobertura?.mensajes?.length" class="px-4 py-2 bg-amber-50 border-t border-amber-200">
                        <template x-for="(m, i) in (estimateData?.cobertura?.mensajes || [])" :key="i">
                            <p class="text-xs text-amber-800" x-text="m"></p>
                        </template>
                    </div>

                    <div x-show="estimateData?.cobertura?.completos?.length" class="max-h-52 overflow-y-auto divide-y divide-slate-100">
                        <template x-for="(c, i) in (estimateData?.cobertura?.completos || [])" :key="i">
                            <div class="px-4 py-2 flex items-center justify-between gap-3">
                                <span class="text-xs font-mono text-slate-500 w-24 shrink-0" x-text="c.inicio + ' → ' + c.fin"></span>
                                <span class="text-xs text-slate-700 truncate flex-1" x-text="c.name"></span>
                                <span class="text-xs text-slate-400 shrink-0" x-text="humanBytes(c.size)"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Recomendación: el día completo sale más barato -->
                <div x-show="estimateData && estimateData.comparacion.recomendado === 'dia' && !form.diaCompleto"
                     class="rounded-xl border border-brand-200 bg-brand-50 p-4" data-puntual-tour="recomendacion">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-lightbulb text-brand-600 mt-0.5"></i>
                        <div class="flex-1">
                            <p class="font-semibold text-sm text-slate-800">Te conviene pedir el día completo</p>
                            <p class="text-xs text-slate-600 mt-1">
                                Una franja de <span x-text="estimateData?.comparacion?.franja?.minutos"></span> minutos genera
                                <strong x-text="estimateData?.comparacion?.franja?.links"></strong> enlaces que vencen por separado.
                                El día completo es <strong>un</strong> enlace sobre la carpeta del día
                                (<span x-text="estimateData?.comparacion?.dia?.archivos"></span> archivos,
                                <span x-text="humanBytes(estimateData?.comparacion?.dia?.bytes)"></span>).
                            </p>
                            <button @click="usarDia()"
                                    class="mt-3 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-medium transition-colors">
                                Pedir el día completo
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">
                        Motivo de la solicitud
                        <span class="font-normal text-slate-400 normal-case">(opcional)</span>
                    </label>
                    <textarea x-model="form.motivo" rows="2" maxlength="1000"
                              placeholder="Para qué necesitas el material (opcional, queda en el registro)"
                              class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                    <p class="text-xs text-slate-400 mt-1">Si lo escribes, queda registrado en el control de solicitudes.</p>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
                <span class="text-xs text-slate-400" x-show="estimateData?.cobertura?.estado !== 'unavailable'">
                    <template x-if="form.diaCompleto">
                        <span>Se generará <strong>1 enlace</strong> sobre la carpeta del día, con 3 días de vigencia.</span>
                    </template>
                    <template x-if="!form.diaCompleto">
                        <span>Se generarán <span x-text="estimateData?.comparacion?.franja?.links"></span> enlace(s) con 3 días de vigencia.</span>
                    </template>
                </span>
                <div class="flex items-center gap-2">
                    <button @click="closeRequest()"
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                    <button @click="submit()"
                            :disabled="submitting || !puedeSolicitar()"
                            class="px-4 py-2 bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-paper-plane mr-1" :class="submitting ? 'fa-spin' : ''"></i>
                        <span x-text="submitting ? 'Enviando…' : 'Solicitar'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= CONTROL POR CLIENTE (solo admin) ================= --}}
    @if(session('user_role') === 'admin')
    <section x-show="tab === 'control'" data-puntual-tour="control-panel">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Control de solicitudes por cliente</h2>
                <p class="text-xs text-slate-400 mt-0.5">Para el control manual del cobro. Exportable a TXT para imprimir o archivar.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <input type="date" x-model="controlFiltros.desde" @change="loadControl()"
                       class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <span class="text-slate-400 text-xs">a</span>
                <input type="date" x-model="controlFiltros.hasta" @change="loadControl()"
                       class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <select x-model="controlFiltros.estado" @change="loadControl()"
                        class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="todas">Todas</option>
                    <option value="entregadas">Solo entregadas</option>
                    <option value="vigentes">Solo vigentes</option>
                    <option value="revocadas">Solo revocadas</option>
                    <option value="sin_material">Sin material</option>
                </select>
                <select x-model="controlFiltros.user_id" @change="loadControl()"
                        class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Todos los clientes</option>
                    <template x-for="c in controlClientes" :key="c.id">
                        <option :value="c.id" x-text="c.nombre + ' (' + c.username + ')'"></option>
                    </template>
                </select>
                <button @click="exportarControl()"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                    <i class="fas fa-file-arrow-down mr-1"></i>Exportar TXT
                </button>
            </div>
        </div>

        <div x-show="controlLoading" class="text-sm text-slate-400 py-8 text-center">
            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando reporte…
        </div>

        <template x-if="control && !controlLoading">
            <div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                        <p class="text-2xl font-bold text-slate-800" x-text="control.totales.solicitudes"></p>
                        <p class="text-xs text-slate-500 mt-0.5">Solicitudes</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                        <p class="text-2xl font-bold text-slate-800" x-text="control.totales.clientes"></p>
                        <p class="text-xs text-slate-500 mt-0.5">Clientes</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                        <p class="text-2xl font-bold text-slate-800" x-text="control.totales.minutos"></p>
                        <p class="text-xs text-slate-500 mt-0.5">Minutos</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                        <p class="text-2xl font-bold text-slate-800" x-text="control.totales.archivos"></p>
                        <p class="text-xs text-slate-500 mt-0.5">Archivos</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                        <p class="text-2xl font-bold text-slate-800" x-text="humanBytes(control.totales.bytes)"></p>
                        <p class="text-xs text-slate-500 mt-0.5">Entregado</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <template x-for="c in control.clientes" :key="c.user_id">
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                            <div class="px-4 py-3 border-b border-slate-100 flex items-start justify-between flex-wrap gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-800">
                                        <span x-text="c.nombre"></span>
                                        <span class="text-slate-400 font-normal text-sm" x-text="'(' + c.username + ')'"></span>
                                    </p>
                                    <p class="text-xs text-slate-400" x-text="c.email"></p>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-slate-600 flex-wrap">
                                    <span><strong x-text="c.solicitudes"></strong> solicitudes</span>
                                    <span class="text-slate-300">·</span>
                                    <span><strong x-text="c.minutos"></strong> min</span>
                                    <span class="text-slate-300">·</span>
                                    <span><strong x-text="c.archivos"></strong> archivos</span>
                                    <span class="text-slate-300">·</span>
                                    <span><strong x-text="humanBytes(c.bytes)"></strong></span>
                                    <span class="text-slate-300">·</span>
                                    <span class="text-green-700" x-text="c.vigentes + ' vigentes'"></span>
                                    <span x-show="c.revocadas > 0" class="text-red-600" x-text="'· ' + c.revocadas + ' revocadas'"></span>
                                    <span x-show="c.expiradas > 0" class="text-slate-500" x-text="'· ' + c.expiradas + ' expiradas'"></span>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                        <tr>
                                            <th class="px-4 py-1.5 text-left font-semibold">Fecha</th>
                                            <th class="px-4 py-1.5 text-left font-semibold">Medio</th>
                                            <th class="px-4 py-1.5 text-left font-semibold">Horario</th>
                                            <th class="px-4 py-1.5 text-right font-semibold">Min</th>
                                            <th class="px-4 py-1.5 text-right font-semibold">Archivos</th>
                                            <th class="px-4 py-1.5 text-right font-semibold">Tamaño</th>
                                            <th class="px-4 py-1.5 text-left font-semibold">Estado</th>
                                            <th class="px-4 py-1.5 text-left font-semibold">Enlaces</th>
                                            <th class="px-4 py-1.5 text-right font-semibold">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <template x-for="d in c.detalle" :key="d.id">
                                            <tr>
                                                <td class="px-4 py-1.5 text-slate-700 whitespace-nowrap" x-text="d.fecha_legible"></td>
                                                <td class="px-4 py-1.5 text-slate-800">
                                                    <span x-text="d.medio"></span>
                                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-semibold"
                                                          :class="d.media_kind === 'tv' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                                          x-text="(d.media_kind || '').toUpperCase()"></span>
                                                </td>
                                                <td class="px-4 py-1.5 text-slate-600 whitespace-nowrap" x-text="d.hora_inicio + ' → ' + d.hora_fin"></td>
                                                <td class="px-4 py-1.5 text-slate-600 text-right" x-text="d.duracion_minutos"></td>
                                                <td class="px-4 py-1.5 text-slate-600 text-right" x-text="d.archivos"></td>
                                                <td class="px-4 py-1.5 text-slate-600 text-right" x-text="humanBytes(d.bytes)"></td>
                                                <td class="px-4 py-1.5">
                                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                                          :class="statusClass(d.estado)"
                                                          x-text="statusLabel(d.estado)"></span>
                                                </td>
                                                <td class="px-3 py-1.5">
                                                    <div class="flex items-center gap-1 flex-wrap max-w-xs">
                                                        <template x-for="(l, li) in (d.links || [])" :key="li">
                                                            <a :href="l.url" target="_blank" rel="noopener"
                                                               class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-700 text-[11px] font-medium"
                                                               :title="l.nombre">
                                                                <i class="fas fa-link text-[9px]"></i>
                                                                <span x-text="l.inicio || 'día'"></span>
                                                            </a>
                                                        </template>
                                                        <span x-show="!d.links || d.links.length === 0" class="text-xs text-slate-400">—</span>
                                                    </div>
                                                    <p class="text-[10px] text-slate-400 mt-0.5 truncate"
                                                       x-show="d.motivo"
                                                       :title="d.motivo"
                                                       x-text="d.motivo"></p>
                                                </td>
                                                <td class="px-4 py-1.5 text-right whitespace-nowrap">
                                                    <button @click="revocarDesdeControl(d, c)"
                                                            x-show="d.estado !== 'revoked'"
                                                            class="px-2 py-1 rounded-lg text-xs font-medium text-red-600 hover:bg-red-50"
                                                            title="Revocar: los enlaces dejan de servir el material, el registro queda">
                                                        Revocar
                                                    </button>
                                                    <span x-show="d.estado === 'revoked'" class="text-xs text-slate-400 mr-1">revocada</span>
                                                    <button @click="eliminarDesdeControl(d, c)"
                                                            class="px-2 py-1 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-red-700"
                                                            title="Eliminar el registro: desaparece de la vista. Queda trazado en el log.">
                                                        <i class="fas fa-trash-can text-[11px]"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <div x-show="control.clientes.length === 0"
                         class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
                        <i class="fas fa-inbox text-3xl text-slate-300 mb-3"></i>
                        <p class="text-slate-500">No hay solicitudes en el período seleccionado.</p>
                    </div>
                </div>
            </div>
        </template>
    </section>
    @endif

    <!-- ================= DETALLE DE UNA SOLICITUD ================= -->
    <div x-cloak x-show="detailOpen" x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
         @keydown.escape.window="detailOpen = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-200 flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-bold text-slate-800" x-text="detail?.medio?.name || ''"></h3>
                    <p class="text-sm text-slate-500 mt-0.5">
                        <span x-text="detail?.fecha_legible ?? ''"></span>
                        · <span x-text="detail ? (detail.hora_inicio + ' → ' + detail.hora_fin) : ''"></span>
                        · <span x-text="detail ? detail.duracion_minutos + ' minutos' : ''"></span>
                    </p>
                </div>
                <button @click="detailOpen = false" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl bg-slate-50 py-3">
                        <p class="text-lg font-bold text-slate-800" x-text="detail?.archivos_total ?? 0"></p>
                        <p class="text-xs text-slate-500">Archivos</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 py-3">
                        <p class="text-lg font-bold text-slate-800" x-text="humanBytes(detail?.bytes_total ?? 0)"></p>
                        <p class="text-xs text-slate-500">Tamaño</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 py-3">
                        <p class="text-lg font-bold text-slate-800" x-text="detail?.links_total ?? 0"></p>
                        <p class="text-xs text-slate-500">Enlaces</p>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 px-4 py-3">
                    <p class="text-xs font-semibold text-slate-500 mb-1">Motivo</p>
                    <p class="text-sm text-slate-700"
                       :class="detail?.motivo ? '' : 'italic text-slate-400'"
                       x-text="detail?.motivo || 'Sin motivo'"></p>
                    <p class="text-xs text-slate-400 mt-2" x-show="detail?.link_expires_at"
                       x-text="detail?.link_expires_at ? 'Los enlaces vencen el ' + new Date(detail.link_expires_at).toLocaleString('es-CO') : ''"></p>
                </div>

                <div x-show="detail?.estado === 'revoked'" class="rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                    <i class="fas fa-circle-xmark mr-1"></i>Esta solicitud fue revocada: sus enlaces ya no sirven el material.
                </div>

                <div x-show="detail?.links?.length">
                    <p class="text-xs font-semibold text-slate-500 mb-2">Enlaces de entrega</p>
                    <div class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                        <template x-for="(l, i) in (detail?.links ?? [])" :key="i">
                            <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-700 truncate" x-text="l.nombre"></p>
                                    <p class="text-xs text-slate-400" x-show="l.inicio" x-text="l.inicio"></p>
                                </div>
                                <a :href="l.url" target="_blank" rel="noopener"
                                   class="shrink-0 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-medium transition-colors">
                                    Abrir
                                </a>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 flex justify-end">
                <button @click="detailOpen = false"
                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
{{-- Motor de la guia interactiva: se carga SOLO en las pantallas que la
     usan (Mis Archivos, el dashboard admin y este modulo). Sin esta linea
     el boton "Guia" quedaba mudo, porque `TcloudTour` no existia. --}}
<script src="/js/interactive-tour.js?v=20"></script>
<script>
function puntualManager() {
    return {
        tab: 'medios',
        medios: [],
        loadingMedios: false,
        search: '',
        kind: '',
        paginaMedios: 1,
        porPagina: 60,
        mediosSort: 'name',
        mediosDir: 'asc',

        history: { data: [], total: 0, page: 1, pages: 1 },
        histSearch: '',
        histEstado: '',

        modalOpen: false,
        selected: null,
        form: { fecha: '', desde: '12:00', hasta: '13:00', motivo: '' },
        estimateData: null,
        estimating: false,
        diasMedio: [],
        submitting: false,

        detailOpen: false,
        detail: null,

        // Control por cliente (solo admin)
        control: null,
        controlLoading: false,
        controlClientes: [],
        controlFiltros: { desde: '', hasta: '', estado: 'todas', user_id: '' },

        sort: 'creada',
        dir: 'desc',

        toast: null,

        init() {
            this.loadMedios();
            this.loadHistory(1);
        },

        // ---------------------------------------------------------------
        // Control por cliente (solo admin)
        // ---------------------------------------------------------------
        async loadControl() {
            this.controlLoading = true;

            const params = new URLSearchParams();
            if (this.controlFiltros.desde) params.set('desde', this.controlFiltros.desde);
            if (this.controlFiltros.hasta) params.set('hasta', this.controlFiltros.hasta);
            if (this.controlFiltros.estado) params.set('estado', this.controlFiltros.estado);
            if (this.controlFiltros.user_id) params.set('user_id', this.controlFiltros.user_id);

            try {
                if (this.controlClientes.length === 0) {
                    const rc = await fetch('/solicitud-puntual/control/clientes', {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (rc.ok) {
                        this.controlClientes = (await rc.json()).clientes ?? [];
                    }
                }

                const res = await fetch(`/solicitud-puntual/control?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!res.ok) throw new Error('respuesta ' + res.status);

                this.control = await res.json();
            } catch (e) {
                this.showToast('No se pudo cargar el control: ' + e.message, 'error');
            } finally {
                this.controlLoading = false;
            }
        },

        /**
         * Revoca una solicitud desde el panel de control.
         *
         * El admin revoca CUALQUIER solicitud: es una decisión de control
         * contable. El cliente ya no puede revocar la suya (ver
         * `PuntualRequestService::revoke`).
         */
        async revocarDesdeControl(detalle, cliente) {
            try {
                const res = await fetch(`/solicitud-puntual/${detalle.id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                });

                const data = await res.json();

                if (!res.ok) throw new Error(data.error || ('respuesta ' + res.status));

                this.showToast(`Solicitud #${detalle.id} de ${cliente.username} revocada.`, 'success');
                this.loadControl();
            } catch (e) {
                this.showToast('No se pudo revocar: ' + e.message, 'error');
            }
        },

        /**
         * Elimina el REGISTRO de una solicitud.
         *
         * Es destructivo e irreversible, y quita el registro contable de la
         * vista. Se pide confirmacion explicita con los datos a la vista: el
         * operador tiene que leer qué va a borrar antes de aceptar.
         */
        async eliminarDesdeControl(detalle, cliente) {
            const resumen = [
                `Cliente: ${cliente.nombre} (${cliente.username})`,
                `Medio: ${detalle.medio}`,
                `Fecha: ${detalle.fecha_legible}  ${detalle.hora_inicio} → ${detalle.hora_fin}`,
                `Archivos: ${detalle.archivos} · ${this.humanBytes(detalle.bytes)} · ${detalle.enlaces} enlace(s)`,
            ].join('\n');

            const confirmado = window.confirm(
                'ELIMINAR EL REGISTRO de la solicitud #' + detalle.id + '?\n\n' +
                resumen + '\n\n' +
                'Esto es IRREVERSIBLE: la solicitud desaparece del control y del historial, ' +
                'y sus enlaces dejan de funcionar.\n\n' +
                'Queda una copia en el log de la plataforma para auditoria.\n\n' +
                'Si solo quieres cortar el acceso sin borrar el registro, usa Revocar.'
            );

            if (! confirmado) return;

            try {
                const res = await fetch(`/solicitud-puntual/${detalle.id}/registro`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                });

                const data = await res.json();

                if (!res.ok) throw new Error(data.error || ('respuesta ' + res.status));

                this.showToast(`Registro #${detalle.id} eliminado.`, 'success');
                this.loadControl();
                this.loadHistory(this.history.page);
            } catch (e) {
                this.showToast('No se pudo eliminar: ' + e.message, 'error');
            }
        },

        /**
         * Descarga el reporte en TXT con los filtros vigentes.
         *
         * Se navega con un enlace temporal en vez de `fetch`: la respuesta es
         * un adjunto y el navegador la guarda con su nombre.
         */
        exportarControl() {
            const params = new URLSearchParams();
            if (this.controlFiltros.desde) params.set('desde', this.controlFiltros.desde);
            if (this.controlFiltros.hasta) params.set('hasta', this.controlFiltros.hasta);
            if (this.controlFiltros.estado) params.set('estado', this.controlFiltros.estado);
            if (this.controlFiltros.user_id) params.set('user_id', this.controlFiltros.user_id);

            const a = document.createElement('a');
            a.href = '/solicitud-puntual/control/exportar?' + params.toString();
            a.download = '';
            document.body.appendChild(a);
            a.click();
            a.remove();
        },

        // ---------------------------------------------------------------
        // Catálogo
        // ---------------------------------------------------------------
        async loadMedios() {
            this.paginaMedios = 1;
            this.loadingMedios = true;
            const params = new URLSearchParams();
            if (this.search) params.set('q', this.search);
            if (this.kind) params.set('kind', this.kind);

            try {
                const res = await fetch(`/solicitud-puntual/catalogo?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error('respuesta ' + res.status);
                const data = await res.json();
                this.medios = data.medios ?? [];
            } catch (e) {
                this.showToast('No se pudo cargar el catálogo: ' + e.message, 'error');
            } finally {
                this.loadingMedios = false;
            }
        },

        /**
         * Filtra por tipo desde los botones: TV, Radio o Todos.
         *
         * Un clic en el boton ya activo lo apaga (vuelve a "Todos"), que es
         * el comportamiento esperado de un grupo de botones y evita tener
         * que ir al boton "Todos" para limpiar el filtro.
         */
        setKind(valor) {
            this.kind = this.kind === valor ? '' : valor;
            this.loadMedios();
        },

        /**
         * Ordena el catálogo por columna.
         *
         * El orden es en memoria (el catálogo completo ya está cargado): un
         * clic ordena ascendente, el segundo invierte.
         */
        ordenarMedios(columna) {
            if (this.mediosSort === columna) {
                this.mediosDir = this.mediosDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.mediosSort = columna;
                this.mediosDir = 'asc';
            }

            this.paginaMedios = 1;
        },

        /**
         * Catálogo con el orden aplicado.
         */
        mediosOrdenados() {
            const clave = (m) => {
                if (this.mediosSort === 'region') return (m.region || '').toString();
                if (this.mediosSort === 'storage') return (m.storage || '').toString();
                if (this.mediosSort === 'media_kind') return (m.media_kind || '').toString();
                return (m.name || '').toString();
            };

            const factor = this.mediosDir === 'asc' ? 1 : -1;

            return [...this.medios].sort((a, b) => {
                const ca = clave(a);
                const cb = clave(b);

                // Los vacíos siempre al final, en las dos direcciones.
                if (ca === '' && cb !== '') return 1;
                if (cb === '' && ca !== '') return -1;

                return ca.localeCompare(cb, 'es', { sensitivity: 'base', numeric: true }) * factor;
            });
        },

        /**
         * Número de fila del listado, de tres dígitos y CONTINUO entre
         * páginas: la página 2 empieza en 061, no en 001. Así el operador
         * puede referirse a "el medio 137" sin ambigüedad.
         */
        numeroFila(indice) {
            const numero = (this.paginaMedios - 1) * this.porPagina + indice + 1;

            // Con más de 999 medios el ancho crece solo: padStart no recorta.
            return String(numero).padStart(3, '0');
        },

        /**
         * Rango visible ("Mostrando 1-60 de 253").
         */
        rangoMedios() {
            const total = this.medios.length;
            if (total === 0) return { desde: 0, hasta: 0 };

            const desde = (this.paginaMedios - 1) * this.porPagina + 1;
            const hasta = Math.min(desde + this.mediosPagina().length - 1, total);

            return { desde, hasta };
        },

        /**
         * Medios de la página actual.
         *
         * El catálogo tiene cientos de medios: sin paginar la pantalla es un
         * muro de tarjetas y no hay forma de ubicar nada.
         */
        mediosPagina() {
            const desde = (this.paginaMedios - 1) * this.porPagina;
            return this.mediosOrdenados().slice(desde, desde + this.porPagina);
        },

        paginasMedios() {
            const total = Math.ceil(this.medios.length / this.porPagina) || 1;
            const actual = this.paginaMedios;
            const desde = Math.max(1, actual - 2);
            const hasta = Math.min(total, desde + 4);
            const out = [];
            for (let p = desde; p <= hasta; p++) out.push(p);
            return out;
        },

        // ---------------------------------------------------------------
        // Solicitud
        // ---------------------------------------------------------------
        openRequest(medio) {
            this.selected = medio;
            this.estimateData = null;
            this.diasMedio = [];
            this.form = { fecha: this.defaultDate(), desde: '12:00', hasta: '13:00', motivo: '', diaCompleto: false };
            this.modalOpen = true;

            // Los días con material los conoce el backend. Se piden para
            // arrancar en el último día que SÍ tiene grabaciones: la mayoría
            // de las emisoras todavía no grabó el día de hoy, así que abrir en
            // "hoy" daba "sin material" y parecía un error.
            this.loadDias(medio);
        },

        async loadDias(medio) {
            try {
                const params = new URLSearchParams({
                    medio_id: medio.id,
                    fecha: this.toDisplayDate(this.form.fecha),
                    desde: this.form.desde,
                    hasta: this.form.hasta,
                });

                const res = await fetch('/solicitud-puntual/dias?' + params, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!res.ok) return;

                const data = await res.json();
                this.diasMedio = data.dias ?? [];

                // Si el día por defecto no tiene material, saltar al último
                // que sí, para que el operador no vea "no disponible" sin
                // motivo.
                if (this.diasMedio.length > 0 && !this.diasMedio.includes(this.fechaIso(this.form.fecha))) {
                    this.form.fecha = this.diaAIso(this.diasMedio[0]);
                    this.estimate();
                }
            } catch (e) {
                // Silencioso: la ausencia de la lista no impide escribir la fecha.
            }
        },

        /**
         * `DDMMYYYY` (formato del backend) -> `YYYY-MM-DD` (input date).
         */
        diaAIso(dia) {
            if (!dia || dia.length !== 8) return '';
            return `${dia.slice(4, 8)}-${dia.slice(2, 4)}-${dia.slice(0, 2)}`;
        },

        /**
         * `YYYY-MM-DD` (input date) -> `DDMMYYYY` (backend).
         */
        fechaIso(fecha) {
            if (!fecha || fecha.length !== 10) return '';
            const [y, m, d] = fecha.split('-');
            return `${d}${m}${y}`;
        },

        fechaLegible(dia) {
            if (!dia || dia.length !== 8) return '';
            return `${dia.slice(0, 2)}/${dia.slice(2, 4)}/${dia.slice(4, 8)}`;
        },

        onFechaCambiada() {
            this.estimateData = null;
        },

        /**
         * Marca/desmarca el dia completo.
         *
         * Con el check puesto, el rango es el dia entero y los campos de hora
         * quedan deshabilitados: no tiene sentido pedir "dia completo de
         * 12:00 a 13:00". Al consultar, la solicitud se crea en modo `dia`.
         */
        onDiaCompleto() {
            if (this.form.diaCompleto) {
                this.form.desde = '00:00';
                this.form.hasta = '23:59';
            } else {
                this.form.desde = '12:00';
                this.form.hasta = '13:00';
            }

            this.estimateData = null;
            this.estimate();
        },

        /**
         * Modo que se manda al backend segun el check.
         */
        modoActual() {
            return this.form.diaCompleto ? 'dia' : 'franja';
        },

        usarDia(dia) {
            this.form.fecha = this.diaAIso(dia);
            this.estimate();
        },

        closeRequest() {
            this.modalOpen = false;
            this.estimating = false;
            this.submitting = false;
        },

        defaultDate() {
            const d = new Date();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${d.getFullYear()}-${mm}-${dd}`;
        },

        duracionMinutos() {
            if (!this.form.desde || !this.form.hasta) return 0;
            const [h1, m1] = this.form.desde.split(':').map(Number);
            const [h2, m2] = this.form.hasta.split(':').map(Number);
            const diff = (h2 * 60 + m2) - (h1 * 60 + m1);
            return diff > 0 ? diff : 0;
        },

        async estimate() {
            if (!this.selected || !this.form.fecha) return;

            this.estimating = true;
            try {
                const res = await fetch('/solicitud-puntual/estimate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                    body: JSON.stringify({
                        medio_id: this.selected.id,
                        fecha: this.toDisplayDate(this.form.fecha),
                        desde: this.form.desde,
                        hasta: this.form.hasta,
                        modo: this.modoActual(),
                    }),
                });

                const data = await res.json();
                if (!res.ok) throw new Error(data.error || ('respuesta ' + res.status));

                this.estimateData = data;
            } catch (e) {
                this.estimateData = null;
                this.showToast('No se pudo consultar la cobertura: ' + e.message, 'error');
            } finally {
                this.estimating = false;
            }
        },

        usarDia() {
            this.form.desde = '00:00';
            this.form.hasta = '23:59';
            this.estimate();
        },

        async submit() {
            if (!this.selected) return;

            if (!this.estimateData) {
                this.showToast('Consulta la cobertura antes de solicitar.', 'error');
                return;
            }

            this.submitting = true;
            try {
                const res = await fetch('/solicitud-puntual', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                    body: JSON.stringify({
                        medio_id: this.selected.id,
                        fecha: this.toDisplayDate(this.form.fecha),
                        desde: this.form.desde,
                        hasta: this.form.hasta,
                        motivo: this.form.motivo,
                        modo: this.modoActual(),
                    }),
                });

                const data = await res.json();
                if (!res.ok) throw new Error(data.error || ('respuesta ' + res.status));

                this.closeRequest();
                this.showToast(`Solicitud #${data.solicitud.id} registrada con ${data.solicitud.links_total} enlace(s).`, 'success');
                this.loadHistory(1);
            } catch (e) {
                this.showToast('No se pudo registrar la solicitud: ' + e.message, 'error');
            } finally {
                this.submitting = false;
            }
        },

        // ---------------------------------------------------------------
        // Historial
        // ---------------------------------------------------------------
        async loadHistory(page = 1) {
            const params = new URLSearchParams();
            if (this.histSearch) params.set('q', this.histSearch);
            if (this.histEstado) params.set('estado', this.histEstado);
            params.set('sort', this.sort);
            params.set('dir', this.dir);
            params.set('page', page);

            try {
                const res = await fetch(`/solicitud-puntual/historial?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error('respuesta ' + res.status);
                this.history = await res.json();
            } catch (e) {
                this.showToast('No se pudo cargar el historial: ' + e.message, 'error');
            }
        },

        sortBy(column) {
            if (this.sort === column) {
                this.dir = this.dir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort = column;
                this.dir = 'asc';
            }
            this.loadHistory(1);
        },

        pageNumbers() {
            const total = this.history.pages ?? 1;
            const current = this.history.page ?? 1;
            const out = [];
            const from = Math.max(1, current - 2);
            const to = Math.min(total, from + 4);
            for (let p = from; p <= to; p++) out.push(p);
            return out;
        },

        openDetail(s) {
            this.detail = s;
            this.detailOpen = true;
        },

        async revoke(s) {
            try {
                const res = await fetch(`/solicitud-puntual/${s.id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || ('respuesta ' + res.status));
                this.showToast('Solicitud revocada: sus enlaces ya no sirven el material.', 'success');
                this.loadHistory(this.history.page);
            } catch (e) {
                this.showToast('No se pudo revocar: ' + e.message, 'error');
            }
        },

        // ---------------------------------------------------------------
        // Utilidades
        // ---------------------------------------------------------------
        toDisplayDate(iso) {
            if (!iso) return '';
            const [y, m, d] = iso.split('-');
            return `${d}/${m}/${y}`;
        },

        humanBytes(bytes) {
            const n = Number(bytes) || 0;
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
            if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
            return (n / 1073741824).toFixed(2) + ' GB';
        },

        statusLabel(status) {
            return {
                ready: 'vigente',
                partial: 'parcial',
                expired: 'expirada',
                unavailable: 'sin material',
                material_no_indexado: 'sin indexar',
                revoked: 'revocada',
            }[status] || status;
        },

        statusClass(status) {
            return {
                ready: 'bg-green-100 text-green-700',
                partial: 'bg-amber-100 text-amber-700',
                material_no_indexado: 'bg-blue-100 text-blue-700',
                expired: 'bg-slate-100 text-slate-600',
                unavailable: 'bg-amber-100 text-amber-700',
                revoked: 'bg-red-100 text-red-700',
            }[status] || 'bg-slate-100 text-slate-600';
        },

        /**
         * ¿Se puede confirmar la solicitud con la cobertura actual?
         *
         * Un día con material en disco pero sin indexar SÍ se puede pedir: el
         * alta lo indexa y genera los enlaces.
         */
        puedeSolicitar() {
            if (! this.estimateData) return false;

            const estado = this.estimateData.cobertura?.estado;

            return estado === 'completa' || estado === 'parcial' || estado === 'material_no_indexado';
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        },

        showToast(msg, type = 'success') {
            this.toast = { msg, type };
            setTimeout(() => { this.toast = null; }, 5000);
        },
    };
}

/**
 * Guía interactiva del módulo: paso a paso completo.
 *
 * Usa el motor compartido (`public/js/interactive-tour.js`). Los selectores
 * son CSS válidos; si un paso apunta a algo que no esta en pantalla (por
 * ejemplo la recomendación, que solo aparece cuando la franja supera el
 * umbral), el motor lo muestra centrado sin romper el tour.
 *
 * Los pasos que necesitan la pestaña de Medios se aseguran de estar en ella
 * antes de resaltar: `onBeforeStep` no existe en el motor, asi que el paso 1
 * arranca desde la pestaña correcta y el resto son elementos de la misma.
 */
function startPuntualTour() {
    if (typeof TcloudTour === 'undefined') {
        // Sin el motor cargado el boton no debe quedar mudo: se avisa.
        alert('La guía no está disponible en este momento. Recarga la página e intenta de nuevo.');
        return;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Bienvenido a Solicitud Puntual',
                content: 'Aquí <strong>pides material de TV o Radio que no tienes asignado</strong>. ' +
                         'En lugar de pedir un dia entero, puedes pedir una <strong>franja horaria exacta</strong> ' +
                         '(por ejemplo, Caracol del 14 de noviembre de 12:00 a 13:00). ' +
                         'Al final recibes enlaces públicos de solo lectura que vencen en 3 días.<br><br>' +
                         'Te muestro el paso a paso en 10 pasos. Puedes salir cuando quieras con <kbd>Esc</kbd>.',
                icon: 'fa-satellite-dish',
                color: '#6366f1',
                selector: null,
                position: 'center',
            },
            {
                title: 'Paso 1: elige la pestaña Medios',
                content: 'El módulo tiene tres pestañas. <strong>Medios</strong> es donde pides. ' +
                         'En <strong>Mis solicitudes</strong> ves lo que ya pediste. ' +
                         'Si eres administrador, en <strong>Control por cliente</strong> llevas el control contable.',
                icon: 'fa-table-columns',
                color: '#6366f1',
                selector: '[data-puntual-tour="tabs"]',
                position: 'bottom',
            },
            {
                title: 'Paso 2: solo aparece lo que NO tienes',
                content: 'Esta lista es el <strong>complemento</strong> de tus storages asignados. ' +
                         'Si un medio ya lo tienes, no aparece aquí: para ese material entra a <strong>Mis Archivos</strong>. ' +
                         'Así no pides dos veces lo mismo.',
                icon: 'fa-filter',
                color: '#3b82f6',
                selector: '[data-puntual-tour="catalogo"]',
                position: 'bottom',
            },
            {
                title: 'Paso 3: busca y filtra',
                content: 'Escribe el nombre del medio (o la región) en el buscador, o filtra por <strong>TV</strong> ' +
                         'y <strong>Radio</strong>. La búsqueda también está <strong>al pie de la tabla</strong>: ' +
                         'las dos barras hacen lo mismo, usa la que tengas más a mano.',
                icon: 'fa-magnifying-glass',
                color: '#3b82f6',
                selector: '[data-puntual-tour="catalogo"] input[type="search"]',
                position: 'bottom',
            },
            {
                title: 'Paso 4: ordena por cualquier columna',
                content: 'Haz clic en el encabezado de <strong>Medio</strong>, <strong>Región</strong>, ' +
                         '<strong>Tipo</strong> o <strong>Storage</strong> para ordenar. El primer clic ordena de A a Z ' +
                         'y el segundo invierte. El triangulo del encabezado te dice en que direccion estas.',
                icon: 'fa-sort',
                color: '#3b82f6',
                selector: '[data-puntual-tour="catalogo"] thead',
                position: 'bottom',
            },
            {
                title: 'Paso 5: toca Solicitar',
                content: 'Cada fila tiene su boton <strong>Solicitar</strong>. El <strong>número de la izquierda</strong> ' +
                         '(001, 002...) te sirve para referirte a un medio: es continuo entre páginas, ' +
                         'así que "el medio 137" siempre es el mismo.',
                icon: 'fa-hand-pointer',
                color: '#6366f1',
                selector: '[data-puntual-tour="catalogo"] tbody',
                position: 'top',
            },
            {
                title: 'Paso 6: fecha y horario exacto',
                content: 'Elige el día y el rango. Fíjate en el aviso bajo la fecha: te dice si ese día tiene ' +
                         'grabaciones o no, y te ofrece <strong>atajos a los días más recientes</strong>. ' +
                         'Casi ninguna emisora graba el día de hoy todavía, asi que el sistema abre directo ' +
                         'en el último día que sí tiene material.',
                icon: 'fa-clock',
                color: '#8b5cf6',
                selector: '[data-puntual-tour="modal"]',
                position: 'top',
            },
            {
                title: 'Paso 7: consulta la cobertura',
                content: 'Antes de pedir, el sistema comprueba el material <strong>real</strong> (no el catálogo) ' +
                         'y te muestra cuántos archivos hay, cuánto pesan y si falta algun tramo. ' +
                         'Si dice <strong>cobertura completa</strong>, tienes todo el rango. ' +
                         'Si dice <strong>parcial</strong>, te dice exactamente qué hueco hay.',
                icon: 'fa-chart-simple',
                color: '#8b5cf6',
                selector: '[data-puntual-tour="modal"]',
                position: 'top',
            },
            {
                title: 'Paso 8: el día completo sale más barato',
                content: 'Si pides más de <strong>2 horas</strong>, cada tramo es un enlace distinto que vence por ' +
                         'separado. El día completo es <strong>un solo enlace</strong> sobre la carpeta del día. ' +
                         'El sistema te lo recomienda con los números reales, pero <strong>tú decides</strong>: ' +
                         'la recomendación no te obliga.',
                icon: 'fa-lightbulb',
                color: '#f59e0b',
                selector: '[data-puntual-tour="recomendacion"]',
                position: 'top',
            },
            {
                title: 'Paso 9: el motivo es opcional',
                content: 'Puedes escribir para qué necesitas el material. <strong>No es obligatorio</strong>: ' +
                         'si lo dejas vacío, la solicitud se registra igual. Lo que sí queda registrado siempre ' +
                         'es el medio, la fecha, el horario, los archivos y su tamaño.',
                icon: 'fa-comment-dots',
                color: '#10b981',
                selector: '[data-puntual-tour="modal"] textarea',
                position: 'top',
            },
            {
                title: 'Paso 10: sigue tu solicitud en Mis solicitudes',
                content: 'Cada pedido queda con su horario exacto, el detalle de archivos entregados y su tamano. ' +
                         'Aquí ves los <strong>enlaces</strong> de cada archivo y los abres cuando los necesites. ' +
                         'Puedes ordenar por cualquier columna y buscar.',
                icon: 'fa-list-check',
                color: '#10b981',
                selector: '[data-puntual-tour="historial"]',
                position: 'top',
            },
            {
                title: 'Listo: tres cosas que conviene recordar',
                content: '<ul class="text-left list-disc pl-4 space-y-1">' +
                         '<li>Los enlaces <strong>vencen a los 3 días</strong>. Si necesitas el material por más tiempo, descárgalo.</li>' +
                         '<li>La <strong>revocación la hace el administrador</strong>, no el cliente: es parte del control contable.</li>' +
                         '<li>Si un día aparece <strong>sin material</strong>, revisa el aviso de la fecha: casi siempre es que ese día no se grabó.</li>' +
                         '</ul><br>Puedes volver a abrir esta guía cuando quieras con el botón <strong>Guía</strong>.',
                icon: 'fa-circle-check',
                color: '#10b981',
                selector: null,
                position: 'center',
            },
        ],
    });
}
</script>
@endpush
