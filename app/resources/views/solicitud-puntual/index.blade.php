@extends('layouts.app')

@section('title', 'Solicitud Puntual de TV y Radio - Tcloud')

@section('content')
<div class="p-6" x-data="puntualManager()" x-init="init()">

    <div class="mb-6 flex items-start justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Solicitud Puntual de TV y Radio</h1>
            <p class="text-slate-500 mt-0.5">
                Pide una franja horaria o el día completo de un medio que <strong>no</strong> tienes asignado.
                Recibes enlaces de solo lectura con 3 días de vigencia.
            </p>
        </div>
        <button onclick="startPuntualTour()"
                class="flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-sm font-medium transition-colors"
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
    </div>

    <!-- ================= CATÁLOGO ================= -->
    <section x-show="tab === 'medios'" class="mb-8" data-puntual-tour="catalogo">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
            <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                Medios que puedes solicitar
                <span class="ml-1 font-normal normal-case text-slate-400" x-text="'(' + medios.length + ')'"></span>
            </h2>
            <div class="flex items-center gap-2">
                <input type="search" x-model="search" @input.debounce.350ms="loadMedios()"
                       placeholder="Buscar medio, región o canal…"
                       class="w-72 px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <select x-model="kind" @change="loadMedios()"
                        class="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Todos</option>
                    <option value="tv">TV</option>
                    <option value="radio">Radio</option>
                </select>
            </div>
        </div>

        <div x-show="loadingMedios" class="text-sm text-slate-400 py-8 text-center">
            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando medios…
        </div>

        <div x-show="!loadingMedios && medios.length === 0"
             class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
            <i class="fas fa-check-circle text-3xl text-green-400 mb-3"></i>
            <p class="text-slate-600 font-medium">No hay medios por solicitar.</p>
            <p class="text-slate-400 text-sm mt-1">
                Tienes asignados todos los medios del catálogo. Para lo que ya tienes, usa Mis Archivos.
            </p>
        </div>

        <div x-show="!loadingMedios && medios.length > 0"
             class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <template x-for="m in mediosPagina()" :key="m.id">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold text-slate-800 truncate" x-text="m.name"></p>
                            <span class="px-1.5 py-0.5 rounded text-xs font-semibold shrink-0"
                                  :class="m.media_kind === 'tv' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                  x-text="m.media_kind.toUpperCase()"></span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5 truncate"
                           x-show="m.region"
                           :title="m.storage"
                           x-text="m.region"></p>
                    </div>
                    <button @click="openRequest(m)"
                            class="shrink-0 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-medium transition-colors">
                        Solicitar
                    </button>
                </div>
            </template>
        </div>
    </section>

        <!-- Paginador del catálogo -->
        <div x-show="!loadingMedios && medios.length > porPagina" class="mt-4 flex items-center justify-between">
            <span class="text-xs text-slate-500"
                  x-text="'Mostrando ' + (mediosPagina().length ? (paginaMedios - 1) * porPagina + 1 : 0) + '-' + ((paginaMedios - 1) * porPagina + mediosPagina().length) + ' de ' + medios.length"></span>
            <div class="flex items-center gap-1">
                <template x-for="p in paginasMedios()" :key="p">
                    <button @click="paginaMedios = p"
                            class="px-2.5 py-1 rounded-lg text-xs font-medium"
                            :class="p === paginaMedios ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                            x-text="p"></button>
                </template>
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
                                    <button @click="revoke(s)"
                                            x-show="s.status !== 'revoked'"
                                            class="px-2 py-1 rounded-lg text-xs font-medium text-red-600 hover:bg-red-50">
                                        Revocar
                                    </button>
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
                        <input type="date" x-model="form.fecha"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Desde</label>
                        <input type="time" x-model="form.desde" step="1"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Hasta</label>
                        <input type="time" x-model="form.hasta" step="1"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                </div>

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
                               :class="estimateData?.cobertura?.estado === 'completa' ? 'fa-circle-check text-green-600' : (estimateData?.cobertura?.estado === 'parcial' ? 'fa-triangle-exclamation text-amber-600' : 'fa-circle-xmark text-red-600')"></i>
                            <span class="font-semibold text-sm"
                                  :class="estimateData?.cobertura?.estado === 'completa' ? 'text-green-800' : (estimateData?.cobertura?.estado === 'parcial' ? 'text-amber-800' : 'text-red-800')"
                                  x-text="estimateData?.cobertura?.estado === 'completa' ? 'Cobertura completa' : (estimateData?.cobertura?.estado === 'parcial' ? 'Cobertura parcial' : 'Sin material para ese horario')"></span>
                        </div>
                        <span class="text-xs text-slate-500"
                              x-text="estimateData ? estimateData.cobertura.archivos + ' archivos · ' + humanBytes(estimateData.cobertura.bytes) : ''"></span>
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
                <div x-show="estimateData && estimateData.comparacion.recomendado === 'dia'"
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
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Motivo de la solicitud</label>
                    <textarea x-model="form.motivo" rows="2" maxlength="1000"
                              placeholder="Para qué necesitas el material (mínimo 10 caracteres)"
                              class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                    <p class="text-xs text-slate-400 mt-1">Queda registrado en el control de solicitudes.</p>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
                <span class="text-xs text-slate-400" x-show="estimateData?.cobertura?.estado !== 'unavailable'">
                    Se generarán <span x-text="estimateData?.comparacion?.franja?.links"></span> enlace(s) con 3 días de vigencia.
                </span>
                <div class="flex items-center gap-2">
                    <button @click="closeRequest()"
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                    <button @click="submit()"
                            :disabled="submitting || estimateData?.cobertura?.estado === 'unavailable'"
                            class="px-4 py-2 bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-paper-plane mr-1" :class="submitting ? 'fa-spin' : ''"></i>
                        <span x-text="submitting ? 'Enviando…' : 'Solicitar'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

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
                    <p class="text-sm text-slate-700" x-text="detail?.motivo ?? ''"></p>
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

@push('scripts')
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

        history: { data: [], total: 0, page: 1, pages: 1 },
        histSearch: '',
        histEstado: '',

        modalOpen: false,
        selected: null,
        form: { fecha: '', desde: '12:00', hasta: '13:00', motivo: '' },
        estimateData: null,
        estimating: false,
        submitting: false,

        detailOpen: false,
        detail: null,

        sort: 'creada',
        dir: 'desc',

        toast: null,

        init() {
            this.loadMedios();
            this.loadHistory(1);
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
         * Medios de la página actual.
         *
         * El catálogo tiene cientos de medios: sin paginar la pantalla es un
         * muro de tarjetas y no hay forma de ubicar nada.
         */
        mediosPagina() {
            const desde = (this.paginaMedios - 1) * this.porPagina;
            return this.medios.slice(desde, desde + this.porPagina);
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
            this.form = { fecha: this.defaultDate(), desde: '12:00', hasta: '13:00', motivo: '' };
            this.modalOpen = true;
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
                expired: 'expirada',
                unavailable: 'sin material',
                revoked: 'revocada',
            }[status] || status;
        },

        statusClass(status) {
            return {
                ready: 'bg-green-100 text-green-700',
                expired: 'bg-slate-100 text-slate-600',
                unavailable: 'bg-amber-100 text-amber-700',
                revoked: 'bg-red-100 text-red-700',
            }[status] || 'bg-slate-100 text-slate-600';
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
 * Guía interactiva del módulo.
 *
 * Usa el motor compartido (`public/js/interactive-tour.js`): `TcloudTour.start({ steps })`.
 * Los selectores son CSS válidos: un selector inválido no rompe el tour, el
 * paso se muestra centrado.
 */
function startPuntualTour() {
    if (typeof TcloudTour === 'undefined') {
        return;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Solicitud puntual de TV y Radio',
                content: 'Aquí pides material de un medio que <strong>no</strong> tienes asignado. ' +
                         'El sistema comprueba que el material cubra el horario exacto que pides y te entrega ' +
                         'enlaces de solo lectura que vencen a los 3 días.',
                icon: 'fa-satellite-dish',
                color: '#6366f1',
                selector: null,
                position: 'center',
            },
            {
                title: 'Los medios que puedes pedir',
                content: 'Esta lista es el <strong>complemento</strong> de lo que tienes asignado: lo que ya tienes ' +
                         'no aparece, porque para eso está Mis Archivos. Busca por nombre o filtra entre TV y Radio.',
                icon: 'fa-tv',
                color: '#3b82f6',
                selector: '[data-puntual-tour="catalogo"]',
                position: 'bottom',
            },
            {
                title: 'Elige fecha y horario',
                content: 'Selecciona el día y el rango exacto. Al consultar la cobertura ves cuántos archivos hay, ' +
                         'cuánto pesan y si hay huecos: la comprobación se hace contra el material real, no contra un catálogo.',
                icon: 'fa-clock',
                color: '#8b5cf6',
                selector: '[data-puntual-tour="modal"]',
                position: 'top',
            },
            {
                title: 'El día completo sale más barato',
                content: 'Si pides más de dos horas, el sistema te recomienda el día completo: una franja larga genera ' +
                         'un enlace por cada archivo y cada uno vence por separado, mientras que el día es un solo enlace ' +
                         'sobre la carpeta del día. La recomendación no te obliga: tú decides.',
                icon: 'fa-lightbulb',
                color: '#f59e0b',
                selector: '[data-puntual-tour="recomendacion"]',
                position: 'top',
            },
            {
                title: 'Tus solicitudes',
                content: 'Cada solicitud queda registrada con el horario exacto, los archivos entregados y su tamaño: ' +
                         'es el control de lo que has pedido. Puedes ordenar por cualquier columna, buscar y revocar ' +
                         'cuando ya no necesites los enlaces.',
                icon: 'fa-list-check',
                color: '#10b981',
                selector: '[data-puntual-tour="historial"]',
                position: 'top',
            },
        ],
    });
}
</script>
@endpush
