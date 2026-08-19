@extends('layouts.app')

@section('title', 'Correcciones - Tcloud')

@section('content')
<div class="p-6 pb-32" x-data="correccionesAdmin()" x-init="init()">

    <!-- Toast corrido: aparece en bottom-right (success) o bottom-left (error).
         Así la zona inferior derecha queda libre para el modal principal
         (AI Suggest), y los errores van al otro lado para no ser tapados. -->
    <div x-show="aiSuggestToast.visible && aiSuggestToast.variant === 'success'" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed bottom-4 right-4 z-50 max-w-sm rounded-xl shadow-lg border bg-emerald-50 border-emerald-200 text-emerald-800">
        <div class="px-4 py-3 flex items-start gap-3">
            <i class="fas fa-check-circle text-lg mt-0.5 text-emerald-600"></i>
            <div class="flex-1 text-sm">
                <div class="font-semibold" x-text="aiSuggestToast.title"></div>
                <div class="text-xs mt-0.5 opacity-90" x-text="aiSuggestToast.detail"></div>
            </div>
            <button @click="aiSuggestToast.dismiss()" class="opacity-60 hover:opacity-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <div x-show="aiSuggestToast.visible && aiSuggestToast.variant === 'error'" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed bottom-4 left-4 z-50 max-w-sm rounded-xl shadow-lg border bg-red-50 border-red-200 text-red-800">
        <div class="px-4 py-3 flex items-start gap-3">
            <i class="fas fa-exclamation-circle text-lg mt-0.5 text-red-600"></i>
            <div class="flex-1 text-sm">
                <div class="font-semibold" x-text="aiSuggestToast.title"></div>
                <div class="text-xs mt-0.5 opacity-90" x-text="aiSuggestToast.detail"></div>
            </div>
            <button @click="aiSuggestToast.dismiss()" class="opacity-60 hover:opacity-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Correcciones</h1>
            <p class="text-slate-500 mt-0.5">Diccionario moderado de correcciones del transcriptor</p>
            <div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
                <span>Minería EN↔ES:</span>
                <span class="px-2 py-0.5 rounded-full font-medium"
                      :class="miningStatusBadgeClass"
                      x-text="miningStatusLabel"></span>
                <template x-if="miningStatus?.pending_from_mining > 0">
                    <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium"
                          x-text="(miningStatus?.pending_from_mining ?? 0) + ' pendientes de minería'"></span>
                </template>
            </div>
            <div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
                <span>AI Suggest:</span>
                <span class="px-2 py-0.5 rounded-full font-medium"
                      :class="aiSuggestBadgeClass"
                      x-text="aiSuggestLabel"></span>
                <template x-if="aiSuggestStatus?.pending_from_ai_suggest > 0">
                    <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 font-medium"
                          x-text="(aiSuggestStatus?.pending_from_ai_suggest ?? 0) + ' por aprobar'"></span>
                </template>
            </div>
        </div>
        <!-- Banner de corrida retroactiva en curso (visible aunque el modal esté cerrado; se re-adjunta automáticamente al recargar). -->
        <div x-show="runId && !runFinished" x-cloak
             class="mb-3 px-4 py-2.5 bg-indigo-50 border border-indigo-200 rounded-xl flex items-center gap-3 text-sm text-indigo-800">
            <i class="fas fa-spinner fa-spin"></i>
            <div class="flex-1">
                <div class="font-medium">Re-aplicar en curso</div>
                <div class="flex items-center gap-3 mt-1">
                    <div class="flex-1 h-1.5 bg-indigo-100 rounded-full overflow-hidden max-w-xs">
                        <div class="bg-indigo-600 h-full transition-all" :style="'width:' + runProgressPct + '%'"></div>
                    </div>
                    <span class="text-xs tabular-nums" x-text="runProgressPct + '%'"></span>
                    <span class="text-xs text-indigo-600" x-text="runStatusText"></span>
                </div>
                <div x-show="runStuck" class="mt-1 text-xs text-amber-700">
                    <i class="fas fa-exclamation-triangle"></i>
                    Sin avances desde las <span x-text="runStuckSinceText"></span> — la corrida pudo haberse detenido.
                </div>
            </div>
            <button @click="openApplyView();" class="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-medium">
                Ver
            </button>
        </div>

        <div class="flex gap-2">
            <button @click="openNew()"
                    title="Crear una corrección manualmente (entrada manual al diccionario)"
                    class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-plus"></i> Nueva corrección
            </button>
            <button @click="openApply()"
                    title="Re-aplicar el diccionario aprobado a transcripciones históricas. Corre en background (5 min – varias horas según volumen). Automáticamente corre también para cada nueva transcripción que llegue."
                    class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-sync-alt"></i> Re-aplicar
            </button>

            <!-- Exportar CSV: descarga todas las correcciones (original + corrección) para
                 validar fuera del navegador. El dropdown permite filtrar por estado y
                 búsqueda libre. Hace un GET a /ia/correcciones/export que devuelve
                 text/csv con todas las filas (sin paginar, ideal para validación). -->
            <div class="relative" x-data="{ openExport: false }" @click.outside="openExport = false">
                <div class="flex rounded-xl overflow-hidden shadow-sm border border-slate-200">
                    <a :href="exportCsvUrl({status: 'all'})"
                       download
                       title="Descargar TODAS las correcciones (pending + approved + rejected) en CSV"
                       class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium transition-colors">
                        <i class="fas fa-file-csv text-emerald-600"></i> Exportar CSV
                    </a>
                    <button @click="openExport = !openExport"
                            title="Filtrar el export por estado o búsqueda"
                            class="px-2 bg-slate-50 hover:bg-slate-100 text-slate-500 border-l border-slate-200 transition-colors">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </button>
                </div>
                <div x-show="openExport" x-cloak
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="absolute right-0 mt-2 w-72 bg-white border border-slate-200 rounded-xl shadow-lg p-3 z-20">
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Estado</label>
                    <select x-model="exportFilter.status" class="w-full text-sm border border-slate-300 rounded-lg px-2 py-1.5 mb-2">
                        <option value="all">Todos</option>
                        <option value="pending">Solo pendientes</option>
                        <option value="approved">Solo aprobadas</option>
                        <option value="rejected">Solo rechazadas</option>
                    </select>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Buscar (opcional)</label>
                    <input type="search" x-model="exportFilter.q"
                           placeholder="palabra en original o corrección…"
                           class="w-full text-sm border border-slate-300 rounded-lg px-2 py-1.5 mb-2">
                    <a :href="exportCsvUrl()"
                       download
                       @click="openExport = false"
                       class="flex items-center justify-center gap-2 w-full px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <i class="fas fa-download"></i> Descargar
                    </a>
                    <p class="text-[10px] text-slate-400 mt-2 leading-snug">
                        CSV con columnas: id, status, source, original, corrección, applies_count, propuesto por, aprobado por, fechas, motivo rechazo.
                    </p>
                </div>
            </div>

            <!-- AI Suggest group: 1-click [configurable] + modal full.
                 Los botones rápidos se generan desde el setting
                 'quick_action_windows' (configurable desde AI Settings).
                 Default: [1, 3, 7]. Cada uno lanza el suggester con
                 esa ventana y guarda como pending. -->
            <div class="flex rounded-xl overflow-hidden shadow-sm border border-purple-200">
                <template x-for="(d, idx) in quickActionWindows" :key="'quick-' + d">
                    <button @click="aiSuggestQuickInsert(d)"
                            :disabled="aiSuggest.running"
                            :title="'Lanzar AI Suggest para últimos ' + d + ' día(s) con defaults y guardar como pending'"
                            :class="idx === 0 ? 'bg-purple-600 hover:bg-purple-700 disabled:bg-purple-300 border-r border-purple-500' : 'bg-purple-500 hover:bg-purple-600 disabled:bg-purple-300 border-r border-purple-400'"
                            class="flex items-center gap-2 px-3 py-2 text-white text-sm font-medium transition-colors">
                        <i class="fas" :class="aiSuggestQuick === d ? 'fa-spinner fa-spin' : (idx === 0 ? 'fa-bolt' : 'fa-clock')"></i>
                        <span x-text="aiSuggestQuick === d ? 'Corriendo…' : quickActionLabel(d)"></span>
                    </button>
                </template>
                <button @click="openAiSuggest()"
                        :disabled="aiSuggest.running"
                        title="Abrir modal con preview antes de insertar"
                        class="flex items-center gap-2 px-3 py-2 bg-white hover:bg-slate-50 disabled:bg-slate-100 text-purple-700 text-sm font-medium transition-colors">
                    <i class="fas fa-robot"></i>
                    <span>AI Suggest</span>
                </button>
                {{-- Triage pendientes: 2026-08-18-corrections-coherence-learn-fix-and-pending-triage.
                     Solo aparece si pendingCount > 0. Botón discreto ámbar para
                     no competir visualmente con AI Suggest (morado). --}}
                <button x-show="(pendingCount ?? 0) > 0" x-cloak
                        @click="openTriage()"
                        :disabled="triage.running"
                        title="Aplicar triage en capas a las pendientes (longitud, sin segmento, marca, clasificador)"
                        class="flex items-center gap-2 px-3 py-2 bg-amber-50 hover:bg-amber-100 disabled:bg-slate-100 text-amber-800 text-sm font-medium border border-amber-200 rounded-lg transition-colors">
                    <i class="fas" :class="triage.running ? 'fa-spinner fa-spin' : 'fa-filter'"></i>
                    <span>Triage pendientes</span>
                    <span class="px-1.5 py-0.5 bg-amber-200 text-amber-900 text-[10px] rounded-full" x-text="(pendingCount ?? 0)"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabs + global dictionary counter -->
    <div class="mb-4 flex items-center justify-between gap-3 flex-wrap">
        <div class="flex gap-2">
            <button @click="tab = 'pending'" :class="tab === 'pending' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                Pendientes <span x-show="pendingCount > 0" class="ml-1 px-1.5 py-0.5 bg-red-500 text-white text-[10px] rounded-full" x-text="pendingCount"></span>
            </button>
            <button @click="tab = 'approved'" :class="tab === 'approved' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                Aprobadas <span class="ml-1 px-1.5 py-0.5 bg-emerald-500 text-white text-[10px] rounded-full" x-text="(approvedCount ?? 0)"></span>
            </button>
            <!-- Tab Exclusiones: 2026-08-11-corrections-exclusiones-top-level-tab.
                 Promovido desde subpanel de IA Suggest a top-level. Icono fa-ban (vs fa-shield-halved
                 de Contexto sensible) para diferenciarlos visualmente. Badge morado cuenta activas. -->
            <button @click="switchTab('exclusiones')" :class="tab === 'exclusiones' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-ban mr-1"></i> Exclusiones
                <span x-show="exclusionesActiveFiltered.length > 0" x-cloak class="ml-1 px-1.5 py-0.5 bg-purple-500 text-white text-[10px] rounded-full" x-text="exclusionesActiveFiltered.length"></span>
            </button>
            <!-- Tab Contexto sensible: cambios/2026-08-02-corrections-dictionary-atomicity.
                 Lista correcciones con risk_level != 'low' para revisión manual.
                 El counter se actualiza al cargar la tab via contextAudit endpoint. -->
            <button @click="switchTab('context-sensitive')" :class="tab === 'context-sensitive' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-shield-halved mr-1"></i> Contexto sensible
                <span x-show="contextSensitiveCount > 0" x-cloak class="ml-1 px-1.5 py-0.5 bg-rose-500 text-white text-[10px] rounded-full" x-text="contextSensitiveCount"></span>
            </button>
            <button @click="switchTab('transcription-review')" :class="tab === 'transcription-review' ? 'bg-cyan-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-magnifying-glass-chart mr-1"></i> Revisar transcripciones
            </button>
            <button @click="switchTab('ai-settings')" :class="tab === 'ai-settings' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-robot mr-1"></i> IA Suggest
            </button>
            <button @click="switchTab('ai-suggest-results')" :class="tab === 'ai-suggest-results' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fas fa-list-check mr-1"></i> AI Suggest Results
            </button>
        </div>
        <!-- Contador global del diccionario: aprobadas + rechazadas + total -->
        <div class="text-xs text-slate-500 flex items-center gap-2">
            <i class="fas fa-book-open"></i>
            <span class="font-medium text-slate-700">Diccionario:</span>
            <span><span class="font-semibold text-emerald-700" x-text="(approvedCount ?? 0)"></span> activas</span>
            <span class="text-slate-300">·</span>
            <span><span class="font-semibold text-amber-700" x-text="(pendingCount ?? 0)"></span> pendientes</span>
            <span class="text-slate-300">·</span>
            <span><span class="font-semibold text-slate-500" x-text="(rejectedCount ?? 0)"></span> rechazadas</span>
            <span class="text-slate-300">·</span>
            <span class="text-slate-600 font-semibold" x-text="(totalCount ?? 0)"></span> total
            <template x-if="(rejectedCount ?? 0) > 50">
                <button @click="showRejectedTooltip = !showRejectedTooltip"
                        class="text-amber-600 hover:text-amber-800 ml-1"
                        title="Ver distribución por origen">
                    <i class="fas fa-info-circle"></i>
                </button>
            </template>
        </div>
    </div>

    <!-- Nota informativa: El filtro de marcas aplica también a retroactivo. -->
    <div class="mb-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 flex items-start gap-2"
         x-show="showRejectedTooltip">
        <i class="fas fa-shield-halved mt-0.5"></i>
        <div>
            El AI Suggest filtra marcas automáticamente (defensa en profundidad: prompt + post-filter PHP).
            Si encuentras una corrección aprobada que afecta un nombre de marca, repórtala para revisar manualmente
            — puede provenir de un seed anterior al filtro (bootstrapping-2026-07-29).
        </div>
    </div>

    <!-- Tab Pendientes -->
    <div x-show="tab === 'pending'" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <!-- Filtros: source + búsqueda libre -->
        <div x-show="pending.length > 0" class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-3 flex-wrap">
            <label class="text-xs font-medium text-slate-600">Origen:</label>
            <select x-model="sourceFilter" class="text-sm border border-slate-300 rounded-lg px-3 py-1.5">
                <option value="all">Todos (<span x-text="pending.length"></span>)</option>
                <template x-for="src in sources" :key="src">
                    <option :value="src" x-text="src + ' (' + pending.filter(c => c.source === src).length + ')'"></option>
                </template>
            </select>
            <label class="text-xs font-medium text-slate-600 ml-3">Buscar:</label>
            <input type="search" x-model="pendingSearch" placeholder="wrong o correct…"
                   class="text-sm border border-slate-300 rounded-lg px-3 py-1.5 w-56">
            <span class="ml-auto text-xs text-slate-500" x-show="pendingFiltered.length !== pending.length">
                <span x-text="pendingFiltered.length"></span> visibles / <span x-text="pending.length"></span> totales
            </span>
        </div>
        <div x-show="loadingPending" class="flex items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-brand-400"></i></div>
        <div x-show="!loadingPending && pending.length === 0" class="text-center py-12 text-slate-400">
            <p class="font-medium">No hay correcciones pendientes</p>
        </div>
        <table x-show="!loadingPending && pending.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-3 w-10">
                        <input type="checkbox"
                               @change="toggleAll()"
                               :checked="allSelected"
                               :indeterminate.prop="someSelected && !allSelected"
                               class="rounded border-slate-300 cursor-pointer">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Original</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Corrección</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Contexto</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Proponente</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Fecha</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="c in pendingFiltered" :key="c.id">
                    <tr class="hover:bg-slate-50" :class="selectedIds.has(c.id) ? 'bg-brand-50' : ''">
                        <td class="px-3 py-3">
                            <input type="checkbox"
                                   :checked="selectedIds.has(c.id)"
                                   @change="toggleOne(c.id)"
                                   class="rounded border-slate-300 cursor-pointer">
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="c.wrong_text"></td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="c.correct_text"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-600 max-w-md">
                            <template x-if="c.source_segment && c.source_segment.text_raw">
                                <button @click="openSegmentContext(c)"
                                        class="text-left hover:bg-slate-100 rounded px-2 py-1 -mx-2 inline-block max-w-full"
                                        :title="snippetTitle(c)">
                                    <span x-html="snippetHtml(c)"></span>
                                </button>
                            </template>
                            <template x-if="!c.source_segment || !c.source_segment.text_raw">
                                <span class="text-slate-400 text-xs">—</span>
                            </template>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500" x-text="c.proposed_by?.username || '—'"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500" x-text="formatDate(c.created_at)"></td>
                        <td class="px-4 py-3 text-right">
                            <button @click="approve(c)" class="px-3 py-1 bg-green-50 hover:bg-green-100 text-green-700 text-xs rounded-lg">Aprobar</button>
                            <button @click="openReject(c)" class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-700 text-xs rounded-lg">Rechazar</button>
                            <button @click="openExcludeForPending(c)" class="px-3 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs rounded-lg" title="Convertir en exclusión para que nunca más sea traducida por AI Suggest">
                                <i class="fas fa-shield-halved"></i> Excluir
                            </button>
                            <button @click="openEditPending(c)" class="px-3 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs rounded-lg" title="Editar wrong_text/correct_text antes de aprobar">
                                <i class="fas fa-pen"></i> Editar
                            </button>
                            <button @click="destroyPending(c)" class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-700 text-xs rounded-lg" title="Eliminar (no se contabiliza como rechazada)">
                                <i class="fas fa-trash"></i> Eliminar
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Tab Aprobadas (cargada vía AJAX; corrections-ai-suggest-auto-approve) -->
    <div x-show="tab === 'approved'" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <!-- Filtros: source + búsqueda libre -->
        <div x-show="approved.length > 0" class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-3 flex-wrap">
            <label class="text-xs font-medium text-slate-600">Origen:</label>
            <select x-model="approvedSourceFilter" class="text-sm border border-slate-300 rounded-lg px-3 py-1.5">
                <option value="all">Todos (<span x-text="approved.length"></span>)</option>
                <template x-for="src in approvedSources" :key="'app-' + src">
                    <option :value="src" x-text="src + ' (' + approved.filter(c => c.source === src).length + ')'"></option>
                </template>
            </select>
            <label class="text-xs font-medium text-slate-600 ml-3">Buscar:</label>
            <input type="search" x-model="approvedSearch" placeholder="wrong o correct…"
                   class="text-sm border border-slate-300 rounded-lg px-3 py-1.5 w-56">
            <button @click="loadApproved()" :disabled="loadingApproved" class="ml-2 px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg text-xs">
                <i class="fas" :class="loadingApproved ? 'fa-spinner fa-spin' : 'fa-refresh'"></i>
            </button>
            <span class="ml-auto text-xs text-slate-500" x-show="approvedFiltered.length !== approved.length">
                <span x-text="approvedFiltered.length"></span> visibles / <span x-text="approved.length"></span> totales
            </span>
        </div>
        <!-- Bulk action bar -->
        <div x-show="approvedSelectedIds.size > 0" class="px-4 py-2 bg-brand-50 border-b border-slate-200 flex items-center gap-3 text-sm">
            <span><span x-text="approvedSelectedIds.size"></span> seleccionadas</span>
            <button @click="approvedSelectedIds.clear()" class="text-xs text-slate-500 hover:text-slate-700">Limpiar</button>
            <button @click="openExcludeBulk('approved')" class="ml-auto px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white text-xs rounded-lg">
                <i class="fas fa-shield-halved"></i> Excluir <span x-text="approvedSelectedIds.size"></span>
            </button>
            <button @click="bulkDestroyApproved()" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded-lg">
                Eliminar <span x-text="approvedSelectedIds.size"></span>
            </button>
        </div>
        <div x-show="loadingApproved" class="flex items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-emerald-400"></i></div>
        <div x-show="!loadingApproved && approved.length === 0" class="text-center py-12 text-slate-400">
            <p class="font-medium">No hay correcciones aprobadas</p>
        </div>
        <table x-show="!loadingApproved && approved.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-3 w-10">
                        <input type="checkbox"
                               @change="toggleAllApproved()"
                               :checked="allApprovedSelected"
                               :indeterminate.prop="someApprovedSelected && !allApprovedSelected"
                               class="rounded border-slate-300 cursor-pointer">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Original</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Corrección</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Proponente</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Aprobador</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Aplicaciones</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Origen</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Contexto</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Fecha</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="c in approvedFiltered" :key="c.id">
                    <tr class="hover:bg-slate-50" :class="approvedSelectedIds.has(c.id) ? 'bg-brand-50' : ''">
                        <td class="px-3 py-3">
                            <input type="checkbox"
                                   :checked="approvedSelectedIds.has(c.id)"
                                   @change="approvedSelectedIds.has(c.id) ? approvedSelectedIds.delete(c.id) : approvedSelectedIds.add(c.id)"
                                   class="rounded border-slate-300 cursor-pointer">
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="c.wrong_text"></td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="c.correct_text"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500" x-text="c.proposed_by?.username || '—'"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500" x-text="c.approved_by?.username || '—'"></td>
                        <td class="px-4 py-3 text-sm font-medium text-slate-700">
                            <span class="inline-flex items-center gap-2">
                                <!-- Dot de risk_level (changes/2026-08-02). low=verde, medium=amber, high=rojo. -->
                                <span class="inline-block w-2 h-2 rounded-full"
                                      :class="(c.risk_level === 'high' ? 'bg-rose-500' : (c.risk_level === 'medium' ? 'bg-amber-500' : 'bg-emerald-500'))"
                                      :title="c.risk_level === 'high' ? 'Risk=high: excluida del applyToText() automático' : (c.risk_level === 'medium' ? 'Risk=medium: se aplica pero revisar' : 'Risk=low: segura')"></span>
                                <span x-text="c.applies_count"></span>
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-slate-500" x-text="c.source || '—'"></td>
                        <td class="px-4 py-3 hidden lg:table-cell text-sm text-slate-600 max-w-md">
                            <template x-if="c.source_segment && c.source_segment.text_raw">
                                <button @click="openSegmentContext(c)"
                                        class="text-left hover:bg-slate-100 rounded px-2 py-1 -mx-2 inline-block max-w-full"
                                        :title="snippetTitle(c)">
                                    <span x-html="snippetHtml(c)"></span>
                                </button>
                            </template>
                            <template x-if="!c.source_segment || !c.source_segment.text_raw">
                                <span class="text-slate-400 text-xs">—</span>
                            </template>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell text-sm text-slate-500" x-text="c.approved_at ? new Date(c.approved_at).toISOString().slice(0,10) : '—'"></td>
                        <td class="px-4 py-3 text-right">
                            <button @click="destroyApproved(c.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                            <button @click="openExcludeForApproved(c)" class="text-xs px-2 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded" title="Convertir en exclusión para que nunca más sea traducida por AI Suggest (la corrección aprobada sigue activa)">
                                <i class="fas fa-shield-halved"></i> Excluir
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Tab Contexto sensible (changes/2026-08-02-corrections-dictionary-atomicity).
         Lista correcciones con risk_level IN ('medium', 'high') detectadas por
         el ContextShiftAuditor. Permite revisión manual antes de aplicar el
         applyToText automático (que omite high por default). -->
    <div x-show="tab === 'context-sensitive'" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 bg-amber-50 border-b border-amber-200 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-slate-800">
                    <i class="fas fa-shield-halved text-amber-600 mr-2"></i>Contexto sensible
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Correcciones con risk_level=medium o high (muletillas, falsos amigos, largas con baja freq).
                    <strong>risk=high</strong> NO se aplica automáticamente en el corrector.
                </p>
            </div>
            <div class="flex gap-2">
                <button @click="loadContextSensitive()" :disabled="loadingContextSensitive" class="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg text-xs">
                    <i class="fas" :class="loadingContextSensitive ? 'fa-spinner fa-spin' : 'fa-refresh'"></i> Recargar
                </button>
                <button @click="contextAuditApply()" :disabled="loadingContextSensitive || contextSensitive.length === 0" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 disabled:bg-slate-300 text-white rounded-lg text-xs font-medium">
                    <i class="fas fa-magic"></i> Aplicar sugerencias del auditor
                </button>
            </div>
        </div>

        <!-- Filtros -->
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-3 flex-wrap">
            <label class="text-xs font-medium text-slate-600">Risk:</label>
            <select x-model="contextSensitiveFilter" class="text-sm border border-slate-300 rounded-lg px-3 py-1.5">
                <option value="all">Todos</option>
                <option value="high">Solo high</option>
                <option value="medium">Solo medium</option>
            </select>
            <span class="text-xs text-slate-500 ml-auto">
                <span x-text="contextSensitiveFiltered.length"></span> reglas marcadas
                · <span x-text="contextSensitive.filter(c => c.suggested_risk === 'high').length"></span> high
                · <span x-text="contextSensitive.filter(c => c.suggested_risk === 'medium').length"></span> medium
            </span>
        </div>

        <div x-show="loadingContextSensitive" class="flex items-center justify-center py-12">
            <i class="fas fa-spinner fa-spin text-amber-400"></i>
        </div>
        <div x-show="!loadingContextSensitive && contextSensitive.length === 0" class="text-center py-12 text-slate-400">
            <i class="fas fa-shield-halved text-3xl mb-2"></i>
            <p class="font-medium">No hay correcciones marcadas como context-sensitive</p>
            <p class="text-xs mt-1">El corrector respetará el tono/contexto original sin restricciones.</p>
        </div>
        <table x-show="!loadingContextSensitive && contextSensitive.length > 0" class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Original</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Corrección</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Risk</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Match / razón</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="s in contextSensitiveFiltered" :key="s.id">
                    <tr class="hover:bg-slate-50">
                        <td class="px-3 py-3 text-xs text-slate-500" x-text="s.id"></td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="s.wrong_text || '—'"></td>
                        <td class="px-4 py-3 text-sm text-slate-700" x-text="s.correct_text || '—'"></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded text-xs font-medium"
                                  :class="s.suggested_risk === 'high' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'"
                                  x-text="s.suggested_risk"></span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-slate-500">
                            <div x-text="s.matched"></div>
                            <div class="text-[10px] text-slate-400 mt-0.5" x-text="s.reason"></div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button @click="setRiskLevel(s.id, 'low')" class="text-xs px-2 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded">Aceptar (low)</button>
                            <button @click="destroyApproved(s.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Tab Revisión de transcripciones -->
    <div x-show="tab === 'transcription-review'" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 bg-cyan-50 border-b border-cyan-200 flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h3 class="font-semibold text-slate-800">
                    <i class="fas fa-magnifying-glass-chart text-cyan-600 mr-2"></i>Revisión de transcripciones
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">Compara el texto original del transcriptor con el texto corregido y revisa el contexto real.</p>
            </div>
            <button @click="loadTranscriptionReviews()" :disabled="transcriptionReviewLoading" class="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg text-xs">
                <i class="fas" :class="transcriptionReviewLoading ? 'fa-spinner fa-spin' : 'fa-refresh'"></i> Recargar
            </button>
        </div>

        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-2 flex-wrap">
            <span class="text-xs font-medium text-slate-600 mr-1">Mostrar:</span>
            <button @click="transcriptionReviewMode = 'latest'; loadTranscriptionReviews()"
                    :class="transcriptionReviewMode === 'latest' ? 'bg-cyan-600 text-white' : 'bg-white text-slate-600 border border-slate-300'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium">Últimas 10</button>
            <button @click="transcriptionReviewMode = 'sensitive'; loadTranscriptionReviews()"
                    :class="transcriptionReviewMode === 'sensitive' ? 'bg-amber-600 text-white' : 'bg-white text-slate-600 border border-slate-300'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium">Últimas 10 sensibles</button>
            <span class="ml-auto text-xs text-slate-500" x-text="transcriptionReviews.length + ' transcripciones'" x-show="!transcriptionReviewLoading"></span>
        </div>

        <div x-show="transcriptionReviewLoading" class="flex items-center justify-center py-12">
            <i class="fas fa-spinner fa-spin text-cyan-400"></i>
        </div>
        <div x-show="!transcriptionReviewLoading && transcriptionReviews.length === 0" class="text-center py-12 text-slate-400">
            <i class="fas fa-inbox text-3xl mb-2"></i>
            <p class="font-medium">No hay transcripciones para revisar</p>
            <p class="text-xs mt-1" x-text="transcriptionReviewMode === 'sensitive' ? 'No se encontraron coincidencias de reglas medium/high.' : 'Cuando existan transcripciones terminadas aparecerán aquí.'"></p>
        </div>

        <div x-show="!transcriptionReviewLoading && transcriptionReviews.length > 0" class="divide-y divide-slate-100">
            <template x-for="item in transcriptionReviews" :key="item.id">
                <button @click="openTranscriptionReview(item.id)" class="w-full text-left px-4 py-4 hover:bg-cyan-50/40 transition-colors">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <div class="font-medium text-slate-800 truncate" x-text="item.file_name"></div>
                            <div class="text-xs text-slate-500 mt-1">
                                #<span x-text="item.id"></span> · <span x-text="formatReviewDate(item.finished_at)"></span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-medium"
                              :class="reviewStatusClass(item.review?.status)"
                              x-text="reviewStatusLabel(item.review?.status)"></span>
                    </div>
                    <div class="mt-3 flex gap-2 flex-wrap text-[11px]">
                        <span class="px-2 py-1 rounded bg-slate-100 text-slate-600" x-text="item.segments_count + ' segmentos'"></span>
                        <span class="px-2 py-1 rounded bg-cyan-50 text-cyan-700" x-text="item.changed_segments_count + ' con cambios'"></span>
                        <span x-show="item.sensitive_matches_count > 0" class="px-2 py-1 rounded bg-amber-50 text-amber-700" x-text="item.sensitive_matches_count + ' coincidencias sensibles'"></span>
                    </div>
                </button>
            </template>
        </div>
    </div>

    <!-- Detalle de revisión de transcripción -->
    <div x-show="transcriptionReviewDetail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50" @keydown.escape.window="transcriptionReviewDetail = null">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[92vh] overflow-hidden flex flex-col" @click.outside="transcriptionReviewDetail = null">
            <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-bold text-slate-800 truncate" x-text="transcriptionReviewDetail?.file_name || 'Detalle de transcripción'"></h2>
                    <p class="text-xs text-slate-500 mt-1">Transcripción #<span x-text="transcriptionReviewDetail?.id"></span> · <span x-text="formatReviewDate(transcriptionReviewDetail?.finished_at)"></span></p>
                </div>
                <button @click="transcriptionReviewDetail = null" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
            </div>

            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-2 flex-wrap">
                <span class="text-xs text-slate-500 mr-1">Decisión:</span>
                <template x-for="status in ['correct', 'needs_review', 'ignored']" :key="status">
                    <button @click="saveTranscriptionReview(status)" :disabled="transcriptionReviewSaving"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border"
                            :class="transcriptionReviewDetail?.review?.status === status ? reviewStatusClass(status) : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-100'"
                            x-text="reviewStatusLabel(status)"></button>
                </template>
                <a :href="transcriptionReviewDetail ? '/ia/api-transcriptor/jobs/' + transcriptionReviewDetail.id : '#'
                   " target="_blank" class="ml-auto text-xs text-brand-600 hover:underline"><i class="fas fa-external-link-alt mr-1"></i>Ver SRT completo</a>
            </div>

            <div x-show="transcriptionReviewSaving" class="px-5 py-2 text-xs text-cyan-700 bg-cyan-50">Guardando revisión…</div>
            <div x-show="!transcriptionReviewLoadingDetail && (transcriptionReviewDetail?.changed_segments ?? []).length === 0" class="px-5 py-12 text-center text-slate-400">
                <i class="fas fa-check-circle text-3xl text-emerald-300 mb-2"></i>
                <p class="font-medium">No hay diferencias entre texto original y corregido</p>
            </div>
            <div x-show="transcriptionReviewLoadingDetail" class="py-12 text-center"><i class="fas fa-spinner fa-spin text-cyan-400"></i></div>
            <div x-show="!transcriptionReviewLoadingDetail" class="overflow-y-auto p-5 space-y-5">
                <template x-for="segment in (transcriptionReviewDetail?.changed_segments ?? [])" :key="segment.id">
                    <article class="border border-slate-200 rounded-xl overflow-hidden">
                        <div class="px-4 py-2 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-2 text-xs text-slate-500">
                            <span>Segmento <span x-text="segment.segment_index"></span> · <span x-text="formatSeconds(segment.start_seconds)"></span> – <span x-text="formatSeconds(segment.end_seconds)"></span></span>
                            <span x-show="segment.matches.length === 0" class="text-slate-400">Regla no identificada</span>
                        </div>
                        <div class="grid md:grid-cols-2 gap-px bg-slate-200">
                            <div class="bg-rose-50/50 p-4">
                                <div class="text-[10px] uppercase tracking-wide font-semibold text-rose-700 mb-2">Original del transcriptor</div>
                                <p class="text-sm text-slate-800 whitespace-pre-wrap" x-text="segment.text_raw"></p>
                            </div>
                            <div class="bg-emerald-50/50 p-4">
                                <div class="text-[10px] uppercase tracking-wide font-semibold text-emerald-700 mb-2">Después del diccionario</div>
                                <p class="text-sm text-slate-800 whitespace-pre-wrap" x-text="segment.text"></p>
                            </div>
                        </div>
                        <div x-show="segment.matches.length > 0" class="px-4 py-3 border-t border-slate-200 bg-white">
                            <div class="text-[10px] uppercase tracking-wide font-semibold text-slate-500 mb-2">Reglas relacionadas</div>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="match in segment.matches" :key="match.correction_id">
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs"
                                          :class="match.risk_level === 'high' ? 'bg-rose-100 text-rose-700' : (match.risk_level === 'medium' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700')">
                                        <span x-text="match.wrong_text + ' → ' + match.correct_text"></span>
                                        <span class="opacity-70" x-text="'(' + match.risk_level + ', ' + match.confidence + ')'" ></span>
                                        <button @click.stop="openApprovedRule(match.wrong_text)" class="ml-1 underline opacity-80 hover:opacity-100" title="Buscar esta regla en Aprobadas">ver</button>
                                        <button x-show="match.risk_level !== 'high'" @click.stop="setRiskLevel(match.correction_id, 'high')" class="ml-1 opacity-80 hover:opacity-100" title="Marcar regla como high-risk"><i class="fas fa-shield-halved"></i></button>
                                    </span>
                                </template>
                            </div>
                        </div>
                        <div x-show="segment.previous_segment || segment.next_segment" class="px-4 py-3 border-t border-slate-100 bg-slate-50 text-xs text-slate-500 space-y-1">
                            <div x-show="segment.previous_segment"><span class="font-medium">Anterior:</span> <span x-text="segment.previous_segment?.text"></span></div>
                            <div x-show="segment.next_segment"><span class="font-medium">Siguiente:</span> <span x-text="segment.next_segment?.text"></span></div>
                        </div>
                    </article>
                </template>
            </div>

            <div class="px-5 py-3 border-t border-slate-200 bg-slate-50">
                <label class="block text-xs font-medium text-slate-600 mb-1">Nota de revisión</label>
                <textarea x-model="transcriptionReviewNotes" rows="2" maxlength="5000" placeholder="Observación opcional…" class="w-full text-sm border border-slate-300 rounded-lg px-3 py-2"></textarea>
                <button @click="saveTranscriptionReview(transcriptionReviewDetail?.review?.status || 'needs_review')" :disabled="transcriptionReviewSaving" class="mt-2 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 disabled:bg-slate-300 text-white rounded-lg text-xs font-medium">Guardar nota</button>
            </div>
        </div>
    </div>

    <!-- Tab AI Suggest Results (corrections-ai-suggest-auto-approve) -->
    <div x-show="tab === 'ai-suggest-results'" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 bg-purple-50 border-b border-purple-200 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-slate-800">
                    <i class="fas fa-list-check text-purple-600 mr-2"></i>AI Suggest Results
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Historial de corridas del suggester LLM-powered y las correcciones auto-aprobadas.
                </p>
            </div>
            <button @click="loadAiSuggestResults()" :disabled="loadingAiSuggestResults" class="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg text-xs">
                <i class="fas" :class="loadingAiSuggestResults ? 'fa-spinner fa-spin' : 'fa-refresh'"></i> Recargar
            </button>
        </div>

        <!-- Búsqueda global -->
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-3 flex-wrap">
            <label class="text-xs font-medium text-slate-600">Buscar auto-aprobadas:</label>
            <input type="search" x-model="aiSuggestResultsSearch" placeholder="wrong o correct…"
                   class="text-sm border border-slate-300 rounded-lg px-3 py-1.5 w-72">
            <span class="ml-auto text-xs text-slate-500" x-show="aiSuggestResultsSearch">
                <span x-text="aiSuggestApprovedFiltered.length"></span> visibles / <span x-text="(aiSuggestResults?.approved_list ?? []).length"></span> totales
            </span>
        </div>

        <!-- Resumen de últimas 5 corridas -->
        <div x-show="aiSuggestResults" class="px-4 py-3 border-b border-slate-200">
            <h4 class="text-xs font-semibold text-slate-500 uppercase mb-2">Últimas corridas</h4>
            <table class="w-full text-xs">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Source (lote)</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Última act.</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Aprobadas</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Pendientes</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Rechazadas</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="run in (aiSuggestResults?.runs ?? [])" :key="run.source">
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-slate-700 font-mono text-[11px]" x-text="run.source"></td>
                            <td class="px-3 py-2 text-slate-500" x-text="run.last_run_at ? new Date(run.last_run_at).toISOString().slice(0,16).replace('T',' ') : '—'"></td>
                            <td class="px-3 py-2 text-right text-emerald-700 font-medium" x-text="run.approved_count"></td>
                            <td class="px-3 py-2 text-right text-amber-700 font-medium" x-text="run.pending_count"></td>
                            <td class="px-3 py-2 text-right text-slate-500" x-text="run.rejected_count"></td>
                            <td class="px-3 py-2 text-right text-slate-700" x-text="run.total"></td>
                        </tr>
                    </template>
                    <tr x-show="(aiSuggestResults?.runs ?? []).length === 0">
                        <td colspan="6" class="px-3 py-4 text-center text-slate-400">Sin corridas registradas</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Auto-aprobadas por AI Suggest -->
        <div class="px-4 py-3">
            <h4 class="text-xs font-semibold text-slate-500 uppercase mb-2">Auto-aprobadas (status=approved, source=ai-suggest-*)</h4>
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Original</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Corrección</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase hidden md:table-cell">Lote</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase hidden md:table-cell">Aprobado por</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Apps.</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="c in aiSuggestApprovedFiltered" :key="'app-' + c.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 text-sm text-slate-700" x-text="c.wrong_text"></td>
                            <td class="px-4 py-2 text-sm text-slate-700" x-text="c.correct_text"></td>
                            <td class="px-4 py-2 hidden md:table-cell text-xs text-slate-500 font-mono" x-text="c.source"></td>
                            <td class="px-4 py-2 hidden md:table-cell text-xs text-slate-500" x-text="c.approved_by?.username || '—'"></td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-slate-700" x-text="c.applies_count"></td>
                            <td class="px-4 py-2 text-right">
                                <button @click="destroyApproved(c.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="(aiSuggestApprovedFiltered ?? []).length === 0">
                        <td colspan="6" class="px-3 py-6 text-center text-slate-400">Sin auto-aprobaciones con esa búsqueda.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pendientes del suggester (caso: auto_approve desactivado) -->
        <div class="px-4 py-3 border-t border-slate-200 bg-slate-50">
            <h4 class="text-xs font-semibold text-slate-500 uppercase mb-2">Pendientes del suggester (status=pending, source=ai-suggest-*)</h4>
            <table class="w-full">
                <thead class="bg-slate-100 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Original</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Corrección</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase hidden md:table-cell">Lote</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <template x-for="c in aiSuggestPendingFiltered" :key="'pen-' + c.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 text-sm text-slate-700" x-text="c.wrong_text"></td>
                            <td class="px-4 py-2 text-sm text-slate-700" x-text="c.correct_text"></td>
                            <td class="px-4 py-2 hidden md:table-cell text-xs text-slate-500 font-mono" x-text="c.source"></td>
                        </tr>
                    </template>
                    <tr x-show="(aiSuggestPendingFiltered ?? []).length === 0">
                        <td colspan="3" class="px-3 py-6 text-center text-slate-400">Sin pendientes del suggester.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab IA Suggest (settings: modelo, base_url, defaults — overrides de BD sin redeploy) -->
    <div x-show="tab === 'ai-settings'" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 bg-purple-50 border-b border-purple-200 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-slate-800">
                    <i class="fas fa-robot text-purple-600 mr-2"></i>Suggester LLM — configuración
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Cambios aplican inmediatamente al botón "AI Suggest" y a futuras corridas manuales/CLI.
                </p>
            </div>
            <button @click="loadAiSettings()" :disabled="aiSettings.loading" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs">
                <i class="fas" :class="aiSettings.loading ? 'fa-spinner fa-spin' : 'fa-refresh'"></i> Recargar
            </button>
        </div>

        <div class="p-4 border-b border-slate-200 bg-slate-50 text-xs text-slate-600 flex items-center gap-3 flex-wrap">
            <span class="font-medium">Origen del valor:</span>
            <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">BD (override UI)</span>
            <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800">.env</span>
            <span class="px-2 py-0.5 rounded bg-slate-200 text-slate-700">config literal</span>
            <span class="ml-auto" x-show="aiSettings.hasApiKey === true && aiSettings.apiKeySource === 'override_encrypted'">
                <i class="fas fa-lock text-purple-600"></i> API key cifrada en BD
            </span>
            <span class="ml-auto" x-show="aiSettings.hasApiKey === true && aiSettings.apiKeySource === 'env'">
                <i class="fas fa-key text-emerald-600"></i> LLM_API_KEY presente en .env
            </span>
            <span class="ml-auto" x-show="aiSettings.hasApiKey === false">
                <i class="fas fa-exclamation-triangle text-red-600"></i> API key ausente — el suggester no podrá correr
            </span>
        </div>

        <!-- API key editor: una sola fila fuera del form para que siempre sea editable -->
        <div class="px-4 py-4 border-b border-slate-200 bg-amber-50/40">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[260px]">
                    <label class="text-sm font-medium text-slate-700 flex items-center gap-2">
                        <i class="fas fa-key text-amber-600"></i>
                        API key
                        <span class="text-xs text-slate-500">
                            (origen:
                            <span x-text="aiSettings.apiKeySource === 'override_encrypted' ? 'cifrada en BD' : (aiSettings.apiKeySource === 'env' ? '.env' : 'ninguno')"></span>)
                        </span>
                    </label>
                    <input type="password"
                           x-model="aiSettings.apiKeyInput"
                           placeholder="sk-... (dejar vacío para borrar el override y usar .env)"
                           class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm font-mono focus:ring-2 focus:ring-amber-500 focus:border-amber-500" />
                    <p class="text-xs text-slate-500 mt-1">
                        Se cifra con <code>Crypt::encryptString</code> (APP_KEY de Laravel) antes de almacenarse en SystemSetting.
                        Nunca se loguea. Deja vacío y guarda para borrar el override y volver al .env.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="saveApiKey()" :disabled="aiSettings.savingApiKey"
                            class="px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:bg-amber-300 text-white rounded-lg text-sm">
                        <i class="fas fa-save mr-1"></i> Guardar key
                    </button>
                    <button type="button" @click="clearApiKey()" :disabled="aiSettings.savingApiKey || aiSettings.apiKeySource !== 'override_encrypted'"
                            class="px-3 py-2 bg-slate-200 hover:bg-slate-300 disabled:opacity-50 text-slate-700 rounded-lg text-sm">
                        <i class="fas fa-eraser mr-1"></i> Borrar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modelos personalizados (BYOK, privados, etc.) -->
        <div class="px-4 py-4 border-b border-slate-200 bg-violet-50/40">
            <div class="flex flex-wrap items-start gap-3">
                <div class="flex-1 min-w-[300px]">
                    <label class="text-sm font-medium text-slate-700 flex items-center gap-2">
                        <i class="fas fa-user-plus text-violet-600"></i>
                        Modelos personalizados (BYOK, privados)
                        <span class="text-xs text-slate-500" x-show="customModelIds.length > 0">
                            (<span x-text="customModelIds.length"></span> configurados)
                        </span>
                    </label>
                    <textarea x-model="aiSettings.form.custom_model_ids"
                              @input="markDirty('custom_model_ids')"
                              rows="3"
                              placeholder="ollamacloud/glm-5.2, ollamacloud/llama-3-70b&#10;anthropic/claude-inconnu&#10;minimax/personalizado"
                              class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-1.5 text-xs font-mono focus:ring-2 focus:ring-violet-500 focus:border-violet-500"></textarea>
                    <p class="text-xs text-slate-500 mt-1">
                        IDs adicionales que el admin conoce de su cuenta (BYOK como OllamaCloud, modelos privados, etc.).
                        No aparecen en el <code>/models</code> público de Kilo. Separa por coma o salto de línea.
                        Tras guardar, aparecerán marcados como <strong>Custom</strong> en el buscador de modelos.
                    </p>
                </div>
                <div class="flex flex-col gap-2 min-w-[180px]">
                    <div class="text-xs font-medium text-slate-600">Vista previa:</div>
                    <div class="flex flex-wrap gap-1 max-h-32 overflow-y-auto">
                        <template x-if="customModelIds.length === 0">
                            <span class="text-xs text-slate-400 italic">sin modelos personalizados</span>
                        </template>
                        <template x-for="id in customModelIds" :key="id">
                            <span class="px-2 py-0.5 rounded-full bg-violet-100 text-violet-800 text-[11px] font-mono flex items-center gap-1">
                                <span x-text="id"></span>
                                <button type="button" @click="removeCustomModelId(id); markDirty('custom_model_ids')"
                                        class="opacity-60 hover:opacity-100" title="Quitar">×</button>
                            </span>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botones rápidos: ventanas configurables para los 1-click del header -->
        <div class="px-4 py-4 border-b border-slate-200 bg-emerald-50/40">
            <div class="flex flex-wrap items-start gap-3">
                <div class="flex-1 min-w-[280px]">
                    <label class="text-sm font-medium text-slate-700 flex items-center gap-2">
                        <i class="fas fa-bolt text-emerald-600"></i>
                        Botones rápidos del header
                        <span class="text-xs text-slate-500" x-show="quickActionWindows.length > 0">
                            (<span x-text="quickActionWindows.length"></span> configurados)
                        </span>
                    </label>
                    <input type="text"
                           x-model="aiSettings.form.quick_action_windows"
                           @input="markDirty('quick_action_windows')"
                           placeholder="1, 3, 7, 14"
                           class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm font-mono focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    <p class="text-xs text-slate-500 mt-1">
                        Ventanas en días para los botones 1-click (<i class="fas fa-bolt"></i>) del header.
                        Ej: <code>1, 3, 7</code> muestra botones <strong>Hoy | 3d | 7d</strong>.
                        Rango válido: 1-30 días. Vacío = solo "Hoy". Tras guardar, refrescar el navegador actualiza los botones.
                    </p>
                </div>
                <div class="flex flex-col gap-2 min-w-[180px]">
                    <div class="text-xs font-medium text-slate-600">Vista previa:</div>
                    <div class="flex flex-wrap gap-1">
                        <template x-if="quickActionWindows.length === 0">
                            <span class="text-xs text-slate-400 italic">sin botones (defaults aplicados)</span>
                        </template>
                        <template x-for="d in quickActionWindows" :key="'prev-' + d">
                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-mono flex items-center gap-1">
                                <i class="fas fa-bolt"></i>
                                <span x-text="quickActionLabel(d)"></span>
                                <button type="button" @click="removeQuickActionWindow(d); markDirty('quick_action_windows')"
                                        class="opacity-60 hover:opacity-100" title="Quitar">×</button>
                            </span>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <form @submit.prevent="saveAiSettings()" class="divide-y divide-slate-100">
            <!-- Quitamos "quick_action_windows" del schema-form porque tiene su propia sección arriba.
                 Object.entries() itera [key, spec] tuples; filter descarta la clave.
                 Alpine x-for soporta destructuring. -->
            <template x-for="[key, s] in Object.entries(aiSettings.list).filter(kv => kv[0] !== 'quick_action_windows')" :key="key">
                <div class="px-4 py-3 grid grid-cols-12 gap-3 items-start">
                    <div class="col-span-12 md:col-span-4">
                        <label class="text-sm font-medium text-slate-700" x-text="s.label"></label>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium uppercase"
                                  :class="s.source === 'bd' ? 'bg-emerald-100 text-emerald-800' : (s.source === 'env' ? 'bg-amber-100 text-amber-800' : 'bg-slate-200 text-slate-700')"
                                  x-text="s.source"></span>
                            <span class="text-xs text-slate-500" x-text="s.type + (s.options ? ' (lista)' : (s.min !== undefined ? ' ' + s.min + '..' + s.max : ''))"></span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1" x-text="s.help"></p>
                    </div>

                    <div class="col-span-12 md:col-span-6">
                        <!-- bool: switch -->
                        <template x-if="s.type === 'bool'">
                            <button type="button"
                                    @click="aiSettings.form[key] = !aiSettings.form[key]; markDirty(key);"
                                    :class="aiSettings.form[key] ? 'bg-emerald-500' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors">
                                <span :class="aiSettings.form[key] ? 'translate-x-6' : 'translate-x-1'"
                                      class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                            </button>
                        </template>

                        <!-- Gateway model picker (rich searchable combobox) -->
                        <template x-if="s.type === 'str' && s.options_source === 'gateway'">
                            <div x-data="modelPicker(key)" @click.outside="openPicker = false" class="relative">
                                <div class="flex gap-2 items-stretch">
                                    <!-- Trigger -->
                                    <button type="button" @click="togglePicker()"
                                            :disabled="aiSettings.refreshingModels"
                                            class="flex-1 border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-white text-left flex items-center justify-between min-h-[34px]">
                                        <span class="flex items-center gap-2 truncate">
                                            <span class="font-medium text-slate-800 truncate" x-text="currentModelLabel"></span>
                                            <template x-if="currentModelMeta?.is_free">
                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 font-medium uppercase tracking-wide">Gratis</span>
                                            </template>
                                            <template x-if="currentModelMeta && !currentModelMeta.is_free && currentModelMeta.pricing_prompt_usd_per_mtok !== null">
                                                <span class="text-[10px] text-slate-500"
                                                      x-text="'$' + Number(currentModelMeta.pricing_prompt_usd_per_mtok).toFixed(2) + '/MTok in'"></span>
                                            </template>
                                        </span>
                                        <i class="fas" :class="openPicker ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </button>
                                    <button type="button" @click="refreshGatewayModels(key)"
                                            :disabled="aiSettings.refreshingModels"
                                            title="Volver a listar desde el gateway (1h cache)"
                                            class="px-2 py-1.5 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 text-slate-700 rounded-lg text-xs whitespace-nowrap">
                                        <i class="fas" :class="aiSettings.refreshingModels ? 'fa-spinner fa-spin' : 'fa-sync-alt'"></i>
                                    </button>
                                </div>

                                <!-- Panel -->
                                <div x-show="openPicker" x-cloak
                                     x-transition.opacity
                                     class="absolute left-0 right-0 mt-1 z-40 bg-white border border-slate-200 rounded-xl shadow-2xl max-h-[480px] flex flex-col">
                                    <!-- Search input + filter chips -->
                                    <div class="p-3 border-b border-slate-200 space-y-2">
                                        <div class="relative">
                                            <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                                            <input type="text"
                                                   x-model="search"
                                                   x-ref="search"
                                                   @keydown.escape.stop="openPicker = false"
                                                   @keydown.enter.stop.prevent="selectHighlighted()"
                                                   @keydown.arrow-down.prevent="moveHighlight(1)"
                                                   @keydown.arrow-up.prevent="moveHighlight(-1)"
                                                   placeholder="Buscar por nombre, id o descripción..."
                                                   class="w-full pl-8 pr-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" />
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="button" @click="filterFree = !filterFree"
                                                    :class="filterFree ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                                    class="px-2 py-1 rounded-full text-[11px] font-medium transition-colors">
                                                <i class="fas" :class="filterFree ? 'fa-check-circle' : 'fa-gift'"></i> Solo gratis
                                                <span x-text="'(' + freeCount + ')'"></span>
                                            </button>
                                            <button type="button" x-show="customCount > 0" @click="filterCustom = !filterCustom"
                                                    :class="filterCustom ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                                    class="px-2 py-1 rounded-full text-[11px] font-medium transition-colors">
                                                <i class="fas" :class="filterCustom ? 'fa-check-circle' : 'fa-user-plus'"></i> Solo custom
                                                <span x-text="'(' + customCount + ')'"></span>
                                            </button>
                                            <template x-for="prov in availableProviders" :key="prov">
                                                <button type="button" @click="filterProvider = (filterProvider === prov ? null : prov)"
                                                        :class="filterProvider === prov ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                                        class="px-2 py-1 rounded-full text-[11px] font-medium transition-colors"
                                                        x-text="prov + ' (' + provCounts[prov] + ')'"></button>
                                            </template>
                                            <button type="button" x-show="search || filterFree || filterProvider" @click="resetFilters()"
                                                    class="px-2 py-1 rounded-full text-[11px] text-slate-500 hover:text-slate-700 ml-auto">
                                                <i class="fas fa-times"></i> Limpiar
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Results list -->
                                    <div class="flex-1 overflow-y-auto">
                                        <template x-if="loading">
                                            <div class="p-6 text-center text-slate-400 text-sm">
                                                <i class="fas fa-spinner fa-spin"></i> Cargando modelos del gateway...
                                            </div>
                                        </template>
                                        <template x-if="!loading && filteredModels.length === 0">
                                            <div class="p-6 text-center text-slate-400 text-sm">
                                                Sin resultados. Ajusta la búsqueda o limpia filtros.
                                            </div>
                                        </template>
                                        <template x-for="(m, idx) in filteredModels" :key="m.id">
                                            <button type="button" @click="selectModel(m.id)"
                                                    @mouseenter="highlight = idx"
                                                    :class="highlight === idx ? 'bg-purple-50 border-l-2 border-purple-500' : 'border-l-2 border-transparent hover:bg-slate-50'"
                                                    class="w-full px-3 py-2.5 text-left flex items-start gap-3 transition-colors">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="font-medium text-sm text-slate-800 truncate" x-text="m.name || m.id"></span>
                                                        <template x-if="m.is_free">
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 font-medium uppercase tracking-wide">Gratis</span>
                                                        </template>
                                                        <template x-if="m.is_custom">
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-violet-100 text-violet-800 font-medium uppercase tracking-wide" title="Agregado manualmente — sin metadatos del gateway">⭐ Custom</span>
                                                        </template>
                                                        <template x-if="m.supports_vision">
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-700" title="Soporta visión (imágenes de entrada)">👁 visión</span>
                                                        </template>
                                                        <template x-if="m.supports_tools">
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700" title="Soporta tool calling">🔧 tools</span>
                                                        </template>
                                                        <template x-if="m.supports_reasoning">
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" title="Soporta reasoning">🧠 razon</span>
                                                        </template>
                                                        <template x-if="m.may_train_on_prompts">
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-700" title="⚠️ El proveedor podría entrenar con tus prompts">⚠ entrena</span>
                                                        </template>
                                                    </div>
                                                    <div class="text-[11px] text-slate-500 font-mono truncate" x-text="m.id"></div>
                                                    <div class="flex items-center gap-3 mt-1 text-[11px] text-slate-500">
                                                        <span x-show="m.context_length" x-text="'ctx ' + Math.round(m.context_length/1000) + 'K'"></span>
                                                        <span x-show="m.pricing_prompt_usd_per_mtok !== null && !m.is_free">
                                                            $&nbsp;<span x-text="Number(m.pricing_prompt_usd_per_mtok).toFixed(2)"></span>/MTok in
                                                        </span>
                                                        <span x-show="m.pricing_completion_usd_per_mtok !== null && !m.is_free">
                                                            $&nbsp;<span x-text="Number(m.pricing_completion_usd_per_mtok).toFixed(2)"></span>/MTok out
                                                        </span>
                                                        <span x-show="m.terminalbench_score" class="ml-auto" x-text="'T-Bench ' + (m.terminalbench_score * 100).toFixed(1) + '%'"></span>
                                                    </div>
                                                    <div class="text-[11px] text-slate-600 mt-1 line-clamp-2" x-show="m.description" x-text="m.description"></div>
                                                </div>
                                                <i class="fas fa-check text-purple-600 mt-1" x-show="aiSettings.form[key] === m.id"></i>
                                            </button>
                                        </template>
                                    </div>

                                    <div class="px-3 py-2 border-t border-slate-200 bg-slate-50 text-[11px] text-slate-600 flex items-center justify-between">
                                        <span><span x-text="filteredModels.length"></span> de <span x-text="allModels.length"></span> modelos</span>
                                        <span>↑↓ navegar · Enter seleccionar · Esc cerrar</span>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Static options (no gateway): simple select + reset -->
                        <template x-if="s.type === 'str' && s.options && s.options_source !== 'gateway'">
                            <div class="flex gap-2">
                                <select x-model="aiSettings.form[key]" @change="markDirty(key)"
                                        class="flex-1 border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <template x-for="opt in s.options" :key="opt">
                                        <option :value="opt" x-text="opt"></option>
                                    </template>
                                </select>
                            </div>
                        </template>

                        <!-- str sin options: input text -->
                        <template x-if="s.type === 'str' && !s.options">
                            <input type="text" x-model="aiSettings.form[key]" @input="markDirty(key)"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm font-mono focus:ring-2 focus:ring-purple-500 focus:border-purple-500" />
                        </template>

                        <!-- int: number input -->
                        <template x-if="s.type === 'int'">
                            <input type="number" x-model.number="aiSettings.form[key]" @input="markDirty(key)"
                                   :min="s.min" :max="s.max"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" />
                        </template>

                        <!-- float: number input step 0.05 -->
                        <template x-if="s.type === 'float'">
                            <input type="number" step="0.05" x-model.number="aiSettings.form[key]" @input="markDirty(key)"
                                   :min="s.min" :max="s.max"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" />
                        </template>
                    </div>

                    <div class="col-span-12 md:col-span-2 flex flex-col gap-1">
                        <button type="button" x-show="aiSettings.dirty[key]" @click="resetAiSetting(key)"
                                class="px-2 py-1 text-xs bg-amber-100 hover:bg-amber-200 text-amber-800 rounded-lg">
                            Restaurar
                        </button>
                        <span class="text-[10px] text-slate-400" x-text="'default: ' + String(s.default)"></span>
                    </div>
                </div>
            </template>

            <div class="px-4 py-3 bg-slate-50 flex items-center gap-3">
                <button type="submit" :disabled="aiSettings.saving || Object.keys(aiSettings.dirty).length === 0"
                        class="px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-purple-300 text-white rounded-lg text-sm font-medium">
                    <i class="fas fa-save mr-1"></i>
                    <span x-text="aiSettings.saving ? 'Guardando…' : ('Guardar ' + Object.keys(aiSettings.dirty).length + ' cambios')"></span>
                </button>
                <button type="button" @click="resetAllAiSettings()" class="px-3 py-2 text-amber-700 hover:bg-amber-50 rounded-lg text-sm">
                    Restaurar todo a defaults
                </button>
                <span class="text-xs text-emerald-600" x-show="aiSettings.saveOk" x-transition>
                    <i class="fas fa-check-circle"></i> Guardado — cambios aplican en el próximo request.
                </span>
                <span class="text-xs text-red-600" x-show="aiSettings.saveError" x-text="aiSettings.saveError" x-transition></span>
            </div>
        </form>
    </div>

    <!-- Panel Exclusiones (2026-08-11-corrections-exclusiones-top-level-tab).
         Promovido de subpanel dentro de IA Suggest a pestaña top-level.
         Lista de palabras que el AI Suggest NUNCA va a traducir. -->
    <div x-show="tab === 'exclusiones'" x-cloak class="bg-white rounded-xl shadow-sm border border-purple-200 overflow-hidden">
        <div class="px-4 py-3 bg-purple-50 border-b border-purple-200 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-slate-800">
                    <i class="fas fa-ban text-purple-600 mr-2"></i>Exclusiones
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Palabras o frases que el AI Suggest <strong>nunca</strong> va a traducir (eventos, marcas, nombres propios recurrentes).
                    Cambios aplican en ≤5 min en la próxima corrida (cache TTL). El filtro ya viene combinado con la lista del config (marcas tech/hardware).
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="loadExclusiones()" :disabled="exclusionesLoading" class="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg text-xs">
                    <i class="fas" :class="exclusionesLoading ? 'fa-spinner fa-spin' : 'fa-refresh'"></i> Recargar
                </button>
                <button @click="openExcluirModal()" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-medium">
                    <i class="fas fa-plus mr-1"></i> Agregar exclusión
                </button>
            </div>
        </div>

        <!-- Filtros: búsqueda + toggle archivadas -->
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-3 flex-wrap">
            <label class="text-xs font-medium text-slate-600">Buscar:</label>
            <input type="search" x-model="exclusionesSearch" placeholder="término…"
                   class="text-sm border border-slate-300 rounded-lg px-3 py-1.5 w-56">
            <label class="ml-3 flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
                <input type="checkbox" x-model="exclusionesShowArchived" class="rounded border-slate-300">
                Mostrar archivadas
            </label>
            <span class="ml-auto text-xs text-slate-500">
                <span x-text="exclusionesActiveFiltered.length"></span> activas /
                <span x-text="exclusiones.length"></span> totales
            </span>
        </div>

        <!-- Tabla -->
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Término</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Categoría</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Notas</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Creado por</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Fecha</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="ex in exclusionesFiltered" :key="ex.id">
                    <tr class="hover:bg-slate-50" :class="ex.archived_at ? 'opacity-60' : ''">
                        <td class="px-4 py-3 text-sm font-medium text-slate-800" x-text="ex.term"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs">
                            <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800"
                                  x-text="categoryLabel(ex.category)"></span>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-slate-500" x-text="ex.notes || '—'"></td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-slate-500" x-text="ex.created_by_username"></td>
                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-slate-500" x-text="formatDate(ex.created_at)"></td>
                        <td class="px-4 py-3 text-right">
                            <button x-show="!ex.archived_at" @click="archiveExclusion(ex.id)"
                                    class="text-xs px-2 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded">Archivar</button>
                            <button x-show="ex.archived_at" @click="restoreExclusion(ex.id)"
                                    class="text-xs px-2 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded">Restaurar</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="exclusionesFiltered.length === 0">
                    <td colspan="6" class="px-4 py-8 text-center text-slate-400 text-sm">
                        <span x-show="!exclusionesShowArchived && exclusionesSearch">Sin coincidencias.</span>
                        <span x-show="exclusionesShowArchived && !exclusionesSearch">No hay términos archivados.</span>
                        <span x-show="!exclusionesShowArchived && !exclusionesSearch">No hay exclusiones todavía. Agregá una con el botón de arriba.</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Modal Agregar exclusión -->
        <div x-show="showExcluirModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @keydown.escape.window="showExcluirModal = false">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md" @click.outside="showExcluirModal = false">
                <div class="p-6">
                    <h2 class="text-lg font-bold text-slate-800 mb-1">Agregar exclusión</h2>
                    <p class="text-sm text-slate-500 mb-4">El término nunca será traducido por AI Suggest (caso insensible, multi-palabra OK).</p>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Término *</label>
                            <input type="text" x-model="excluirForm.term" placeholder="ej: Black Friday"
                                   autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500">
                            <p class="mt-1 text-[11px] text-slate-500">Se guarda en minúsculas y sin espacios al borde.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Categoría</label>
                            <select x-model="excluirForm.category" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                <option value="">— sin categoría —</option>
                                <option value="event">Evento (Black Friday, Copa América…)</option>
                                <option value="brand">Marca (Open English, EPM…)</option>
                                <option value="product">Producto (AirPods, Netflix…)</option>
                                <option value="org">Organización (British Council…)</option>
                                <option value="person">Persona (frecuente en emisiones)</option>
                                <option value="other">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Notas (opcional)</label>
                            <textarea x-model="excluirForm.notes" rows="2" placeholder="Contexto / razón"
                                      class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
                        </div>
                        <div x-show="excluirError" class="px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-800" x-text="excluirError"></div>
                    </div>

                    <div class="flex gap-3 mt-5">
                        <button @click="submitExclusion()" :disabled="excluirSaving"
                                class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium">
                            <span x-text="excluirSaving ? 'Guardando…' : 'Guardar'"></span>
                        </button>
                        <button @click="showExcluirModal = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modales de exclusión shortcut (corrections-protected-terms-shortcut).
         IMPORTANTE: viven AFUERA de cualquier panel con x-show="tab === ..."
         porque si no, Alpine no los muestra cuando el admin está en
         Pendientes/Aprobadas y el parent está oculto. -->

    <!-- Modal shortcut: convertir UNA fila de pendientes/aprobadas en exclusión -->
    <div x-show="showExcludeShortcutModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @keydown.escape.window="showExcludeShortcutModal = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md" @click.outside="showExcludeShortcutModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Excluir este término</h2>
                <p class="text-sm text-slate-500 mb-4">
                    El AI Suggest nunca más traducirá este término
                    (la corrección
                    <span x-show="excludeShortcutForm.source === 'pending'">pendiente</span>
                    <span x-show="excludeShortcutForm.source === 'approved'">aprobada</span>
                    sigue su curso aparte).
                </p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Término a excluir *</label>
                        <input type="text" x-model="excludeShortcutForm.term"
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-amber-500">
                        <p class="mt-1 text-[11px] text-slate-500">Se guarda en minúsculas. Editalo si querés normalizar (ej. "Open English" → "open english").</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nota (opcional, ya viene con auditoría)</label>
                        <textarea x-model="excludeShortcutForm.notes" rows="2"
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
                    </div>
                    <div x-show="excludeShortcutError" class="px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-800" x-text="excludeShortcutError"></div>
                </div>

                <div class="flex gap-3 mt-5">
                    <button @click="submitExcludeShortcut()" :disabled="excludeShortcutSaving"
                            class="flex-1 py-2.5 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium">
                        <span x-text="excludeShortcutSaving ? 'Guardando…' : 'Agregar exclusión'"></span>
                    </button>
                    <button @click="showExcludeShortcutModal = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal shortcut bulk: convertir selección completa en exclusiones -->
    <div x-show="showExcludeShortcutBulkModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @keydown.escape.window="showExcludeShortcutBulkModal = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md" @click.outside="showExcludeShortcutBulkModal = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Excluir en lote</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Vas a agregar <span class="font-semibold text-amber-700" x-text="excludeShortcutBulkForm.source === 'pending' ? selectedIds.size : approvedSelectedIds.size"></span>
                    exclusiones dinámicas desde
                    <span x-text="excludeShortcutBulkForm.source === 'pending' ? 'pendientes seleccionadas' : 'aprobadas seleccionadas'"></span>.
                    Las duplicadas se reportan; no se sobreescriben.
                </p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nota compartida</label>
                        <input type="text" x-model="excludeShortcutBulkForm.sharedNote"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"
                               placeholder="ej. Limpieza batch 2026-08-01">
                        <p class="mt-1 text-[11px] text-slate-500">Cada término guardará esta nota como referencia.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                        <input type="checkbox" x-model="excludeShortcutBulkForm.includeIndex" class="rounded border-slate-300">
                        Enumerar notas con índice (#1, #2…) para auditoría
                    </label>
                    <div x-show="excludeShortcutBulkError" class="px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-800" x-text="excludeShortcutBulkError"></div>
                    <div x-show="excludeShortcutBulkResult" class="px-3 py-2 bg-emerald-50 border border-emerald-200 rounded-lg text-xs text-emerald-800">
                        <i class="fas fa-check-circle"></i>
                            <span x-text="excludeShortcutBulkResult?.created"></span> creadas,
                            <span x-text="excludeShortcutBulkResult?.skipped"></span> duplicadas/omitidas,
                            <span x-text="excludeShortcutBulkResult?.archived"></span> archivadas.
                            Las correcciones vinculadas pasaron a status='rejected' con motivo 'moved_to_exclusion'.
                    </div>
                </div>

                <div class="flex gap-3 mt-5">
                    <button @click="submitExcludeBulk()" :disabled="excludeShortcutBulkSaving"
                            class="flex-1 py-2.5 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium">
                        <span x-text="excludeShortcutBulkSaving ? 'Guardando…' : 'Excluir todo'"></span>
                    </button>
                    <button @click="showExcludeShortcutBulkModal = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky bottom action bar (bulk) -->
    <div x-show="selectedIds.size > 0"
         x-transition.opacity
         class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 shadow-2xl z-30">
        <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-slate-700">
                    <span x-text="selectedIds.size"></span> de
                    <span x-text="pendingFiltered.length"></span> seleccionadas
                </span>
                <button @click="clearSelection()" class="text-xs text-slate-500 hover:text-slate-700 underline">
                    Limpiar selección
                </button>
            </div>
            <div class="flex gap-2">
                <button @click="confirmBulkApprove()"
                        class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-medium">
                    ✓ Aprobar <span x-text="selectedIds.size"></span>
                </button>
                <button @click="openBulkReject()"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-medium">
                    ✗ Rechazar <span x-text="selectedIds.size"></span>
                </button>
                <button @click="openExcludeBulk('pending')"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-sm font-medium">
                    <i class="fas fa-shield-halved"></i> Excluir <span x-text="selectedIds.size"></span>
                </button>
                <button @click="openBulkDestroyPending()"
                        class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-xl text-sm font-medium"
                        title="Eliminar (NO se contabiliza como rechazada; NO reversible)">
                    <i class="fas fa-trash"></i> Eliminar <span x-text="selectedIds.size"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast de undo (bottom-left, separado del sticky bar) -->
    <div x-show="undoToast.visible"
         x-transition.opacity.duration.300ms
         class="fixed bottom-32 left-6 z-40 max-w-md">
        <div class="bg-slate-800 text-white rounded-xl shadow-2xl px-4 py-3 flex items-center gap-3">
            <i class="fas" :class="undoToast.icon"></i>
            <div class="flex-1">
                <div class="text-sm font-medium" x-text="undoToast.title"></div>
                <div class="text-xs text-slate-300" x-text="undoToast.detail"></div>
            </div>
            <button @click="performUndo()"
                    class="px-3 py-1.5 bg-brand-500 hover:bg-brand-600 text-white rounded-lg text-sm font-medium"
                    x-show="undoToast.bulkActionId !== null && !undoToast.expired">
                Deshacer
            </button>
            <span class="text-xs text-slate-400 font-mono" x-show="!undoToast.expired" x-text="undoToast.countdown"></span>
            <span class="text-xs text-red-300" x-show="undoToast.expired">expirado</span>
        </div>
    </div>

    <!-- Modal Nueva corrección -->
    <div x-cloak x-show="showNew" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showNew = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-5">Nueva corrección</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Texto incorrecto</label>
                        <input type="text" x-model="form.wrong" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Corrección</label>
                        <input type="text" x-model="form.correct" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="saveNew()" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium">Guardar (aprobada)</button>
                    <button @click="showNew = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Rechazo individual -->
    <div x-cloak x-show="showReject" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showReject = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Rechazar corrección</h2>
                <p class="text-sm text-slate-500 mb-5" x-text="rejectItem ? (rejectItem.wrong_text + ' → ' + rejectItem.correct_text) : ''"></p>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Motivo (opcional)</label>
                    <textarea x-model="rejectReason" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none"></textarea>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="confirmReject()" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium">Rechazar</button>
                    <button @click="showReject = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Rechazo en lote -->
    <div x-cloak x-show="showBulkReject" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showBulkReject = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Rechazar en lote</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Vas a rechazar <span class="font-semibold text-slate-700" x-text="selectedIds.size"></span> correcciones. El motivo se aplicará a todas.
                </p>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Motivo común (opcional)</label>
                    <textarea x-model="bulkRejectReason" rows="3" placeholder="Ej: falso positivo en word-boundary" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none"></textarea>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="confirmBulkReject()" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium">
                        Rechazar <span x-text="selectedIds.size"></span> correcciones
                    </button>
                    <button @click="showBulkReject = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Eliminar pendiente individual (corrections-pending-edit-delete) -->
    <div x-cloak x-show="destroyForm.open" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="destroyForm = { open: false, item: null }">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Eliminar sugerencia</h2>
                <p class="text-sm text-slate-500 mb-5" x-text="destroyForm.item ? (destroyForm.item.wrong_text + ' → ' + destroyForm.item.correct_text) : ''"></p>
                <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-800 mb-5">
                    Esta sugerencia <strong>no es una corrección</strong>: es ruido del flujo origen (miner o AI Suggest). Se eliminará sin dejar rastro en <code>rejected</code> y no contará como rechazada en las estadísticas.
                </div>
                <div class="flex gap-3">
                    <button @click="confirmDestroyPending()" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-medium">Eliminar</button>
                    <button @click="destroyForm = { open: false, item: null }" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Eliminar pendientes en lote (corrections-pending-edit-delete) -->
    <div x-cloak x-show="showBulkDestroy" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showBulkDestroy = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Eliminar en lote</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Vas a eliminar <span class="font-semibold text-slate-700" x-text="selectedIds.size"></span> sugerencias pendientes.
                </p>
                <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-800 mb-5">
                    Esta acción <strong>NO se puede deshacer</strong> y <strong>NO se contabiliza como rechazada</strong>. Solo aplica a ruido del miner/AI Suggest; no a correcciones que se quieran auditar.
                </div>
                <div class="flex gap-3">
                    <button @click="confirmBulkDestroyPending()" class="flex-1 py-2.5 bg-red-700 hover:bg-red-800 text-white rounded-xl text-sm font-medium">
                        Eliminar <span x-text="selectedIds.size"></span> sugerencias
                    </button>
                    <button @click="showBulkDestroy = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Contexto del segmento (changes/2026-08-12-corrections-pending-segment-context).
         Se abre al hacer click en el snippet de la columna "Contexto" de Pendientes/Aprobadas.
         Muestra el text_raw del segmento origen (con wrong_text marcado en rojo) y, si el
         diccionario ya aplicó la corrección, el text post-corrección (con correct_text
         marcado en verde). Header con timecode + segmento # + link a la transcripción. -->
    <div x-cloak x-show="segmentContext.open"
         class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
         x-transition
         @keydown.escape.window="closeSegmentContext()">
        <div class="bg-white rounded-2xl w-full max-w-2xl shadow-2xl flex flex-col max-h-[92vh]"
             @click.outside="closeSegmentContext()">
            <div class="px-6 pt-6 pb-4 border-b border-slate-100">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800">Contexto del segmento</h2>
                        <p class="text-sm text-slate-500 mt-1">
                            <span class="font-mono bg-rose-50 text-rose-700 px-1.5 py-0.5 rounded" x-text="segmentContext.correction?.wrong_text"></span>
                            <i class="fas fa-arrow-right text-slate-300 mx-1 text-xs"></i>
                            <span class="font-mono bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded" x-text="segmentContext.correction?.correct_text"></span>
                        </p>
                    </div>
                    <button @click="closeSegmentContext()" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="overflow-y-auto px-6 py-5 flex-1">
                <template x-if="segmentContext.loading">
                    <div class="py-12 text-center text-slate-400">
                        <i class="fas fa-spinner fa-spin text-cyan-400 text-xl"></i>
                        <p class="text-xs mt-3">Cargando segmento…</p>
                    </div>
                </template>

                <template x-if="!segmentContext.loading && segmentContext.data && segmentContext.data.notFound">
                    <div class="py-10 text-center">
                        <i class="fas fa-circle-info text-3xl text-slate-300"></i>
                        <p class="text-sm text-slate-600 mt-3">Esta corrección no tiene segmento origen (no se enlazó cuando fue creada).</p>
                    </div>
                </template>

                <template x-if="!segmentContext.loading && segmentContext.data && !segmentContext.data.notFound && segmentContext.data.segment">
                    <div class="space-y-4">
                        <div class="flex items-center gap-3 text-xs text-slate-500 flex-wrap">
                            <span class="px-2 py-1 bg-slate-100 rounded font-mono">
                                <span x-text="formatHms(segmentContext.data.segment.start_seconds)"></span>
                                →
                                <span x-text="formatHms(segmentContext.data.segment.end_seconds)"></span>
                            </span>
                            <span>Segmento #<span x-text="segmentContext.data.segment.segment_index"></span></span>
                            <template x-if="segmentContext.data.transcription">
                                <a :href="'/ia/api-transcriptor/' + segmentContext.data.transcription.id"
                                   class="ml-auto text-brand-600 hover:text-brand-700 hover:underline">
                                    <i class="fas fa-external-link-alt mr-1"></i>
                                    <span x-text="segmentContext.data.transcription.file_name"></span>
                                </a>
                            </template>
                        </div>

                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase tracking-wide">Original (transcriptor)</label>
                            <p class="text-sm bg-slate-50 rounded-lg px-3 py-2 leading-relaxed"
                               style="max-height: 60vh; overflow-y: auto"
                               x-html="highlightedRaw()"></p>
                        </div>

                        <template x-if="segmentContext.data.segment.text && segmentContext.data.segment.text_raw && segmentContext.data.segment.text !== segmentContext.data.segment.text_raw">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase tracking-wide">Corregido (diccionario aplicado)</label>
                                <p class="text-sm bg-emerald-50 rounded-lg px-3 py-2 leading-relaxed"
                                   style="max-height: 60vh; overflow-y: auto"
                                   x-html="highlightedText()"></p>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="px-6 py-4 border-t border-slate-100 flex gap-3">
                <button @click="closeSegmentContext()"
                        class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Editar corrección pendiente (corrections-pending-edit-delete) -->
    <div x-cloak x-show="editForm.open" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="editForm.open = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Editar corrección pendiente</h2>
                <p class="text-sm text-slate-500 mb-4">Corrige <code>wrong_text</code> o <code>correct_text</code> antes de aprobar. El <code>wrong_normalized</code> se recalcula automáticamente.</p>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Texto incorrecto</label>
                        <input type="text" x-model="editForm.wrong" maxlength="500" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Corrección</label>
                        <input type="text" x-model="editForm.correct" maxlength="500" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase tracking-wide">Origen</label>
                            <p class="text-xs text-slate-700" x-text="editForm.item?.source || '—'"></p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase tracking-wide">Propuesto por</label>
                            <p class="text-xs text-slate-700" x-text="editForm.item?.proposed_by?.username || '—'"></p>
                        </div>
                    </div>
                    <button @click="editForm.open = false; openContext(editForm.item)"
                            class="w-full py-2 bg-cyan-50 hover:bg-cyan-100 text-cyan-700 rounded-lg text-xs font-medium">
                        <i class="fas fa-quote-left mr-1"></i> Ver dónde aparece en transcripciones
                    </button>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="saveEditPending()" :disabled="!editForm.wrong.trim() || !editForm.correct.trim()" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl text-sm font-medium">Guardar cambios</button>
                    <button @click="editForm.open = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ejemplos en transcripciones (corrections-context-examples).
         Responde "¿dónde aparece esto realmente?" antes de aprobar: sin esto el
         admin moderaba una regla que toca ~20M de segmentos viendo solo el par
         de palabras. Los ejemplos se buscan en vivo al abrir (0,2-7 s), nunca
         al pintar la tabla. -->
    <div x-cloak x-show="contextModal.open" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition
         @keydown.escape.window="closeContext()">
        <div class="bg-white rounded-2xl w-full max-w-3xl max-h-[92vh] shadow-2xl flex flex-col" @click.outside="closeContext()">
            <div class="px-6 pt-6 pb-4 border-b border-slate-100">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800">Ejemplos en transcripciones</h2>
                        <p class="text-sm text-slate-500 mt-1">
                            <span class="font-mono bg-rose-50 text-rose-700 px-1.5 py-0.5 rounded" x-text="contextModal.item?.wrong_text"></span>
                            <i class="fas fa-arrow-right text-slate-300 mx-1 text-xs"></i>
                            <span class="font-mono bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded" x-text="contextModal.item?.correct_text"></span>
                        </p>
                    </div>
                    <button @click="closeContext()" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
            </div>

            <div class="overflow-y-auto px-6 py-5 flex-1">
                <div x-show="contextModal.loading" class="py-12 text-center text-slate-400">
                    <i class="fas fa-spinner fa-spin text-cyan-400 text-xl"></i>
                    <p class="text-xs mt-3">Buscando en las transcripciones…</p>
                    <p class="text-[11px] mt-1 text-slate-300">Puede tardar unos segundos la primera vez.</p>
                </div>

                <template x-if="!contextModal.loading && contextModal.data?.status === 'too_short'">
                    <div class="py-10 text-center">
                        <i class="fas fa-magnifying-glass-minus text-3xl text-slate-300"></i>
                        <p class="text-sm text-slate-600 mt-3 font-medium">Término demasiado corto para buscar</p>
                        <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                            La búsqueda por índice necesita al menos 3 caracteres. Con menos, recorrer los
                            segmentos costaría un escaneo completo de la tabla, así que no se ejecuta.
                        </p>
                    </div>
                </template>

                <template x-if="!contextModal.loading && contextModal.data?.status === 'no_matches'">
                    <div class="py-10 text-center">
                        <i class="fas fa-inbox text-3xl text-slate-300"></i>
                        <p class="text-sm text-slate-600 mt-3 font-medium">Sin apariciones donde la regla dispare</p>
                        <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                            No se encontró ningún segmento en el que esta corrección cambie algo. Puede ser ruido
                            del miner, o que el término aparezca siempre pegado a otra palabra.
                        </p>
                    </div>
                </template>

                <template x-if="!contextModal.loading && contextModal.data?.status === 'timeout'">
                    <div class="py-10 text-center">
                        <i class="fas fa-hourglass-end text-3xl text-amber-300"></i>
                        <p class="text-sm text-slate-600 mt-3 font-medium">La búsqueda tardó demasiado</p>
                        <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                            Se canceló para no cargar la base de datos. Vuelve a intentarlo en un momento.
                        </p>
                        <button @click="openContext(contextModal.item)" class="mt-4 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium">
                            <i class="fas fa-rotate-right mr-1"></i> Reintentar
                        </button>
                    </div>
                </template>

                <template x-if="!contextModal.loading && contextModal.data?.status === 'ok'">
                    <div class="space-y-4">
                        <!-- El 87% del diccionario aprobado está en cuarentena y NO se
                             aplica. Sin este aviso la vista previa se lee como si el
                             cambio ya estuviera ocurriendo en producción. -->
                        <div x-show="contextModal.data.rule_state === 'quarantined'"
                             class="flex gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-800">
                            <i class="fas fa-shield-halved mt-0.5"></i>
                            <span>
                                Esta regla está en <strong>cuarentena</strong> (risk=high): el corrector
                                <strong>no la aplica</strong>. Lo de abajo es lo que pasaría si se reactivara.
                            </span>
                        </div>
                        <div x-show="contextModal.data.rule_state === 'not_approved'"
                             class="flex gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-600">
                            <i class="fas fa-hourglass-half mt-0.5"></i>
                            <span>Todavía sin aprobar: lo de abajo es lo que pasaría si la apruebas.</span>
                        </div>
                        <div class="space-y-4">
                            <template x-for="ex in contextModal.data.examples" :key="ex.segment_id">
                                <div class="border border-slate-200 rounded-xl overflow-hidden">
                                    <div class="flex items-center gap-3 px-3 py-2 bg-slate-50 border-b border-slate-100 text-xs">
                                        <span class="font-mono text-slate-500 whitespace-nowrap">
                                            <i class="far fa-clock mr-1"></i><span x-text="ex.start_label"></span> → <span x-text="ex.end_label"></span>
                                        </span>
                                        <a :href="'/ia/api-transcriptor/jobs/' + ex.transcription_id" target="_blank"
                                           class="text-cyan-700 hover:text-cyan-900 hover:underline truncate"
                                           title="Abrir la transcripción completa en otra pestaña">
                                            <i class="fas fa-file-audio mr-1"></i><span x-text="ex.file_name"></span>
                                        </a>
                                        <span class="ml-auto text-slate-400 whitespace-nowrap">seg. #<span x-text="ex.segment_index"></span></span>
                                    </div>
                                    <div class="p-3 space-y-2.5">
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-400 mb-1 uppercase tracking-wide">Como lo transcribió</label>
                                            <p class="text-sm text-slate-700 leading-relaxed" x-html="highlightMatches(ex.text_raw, contextModal.item?.wrong_text, 'bg-rose-100 text-rose-800 px-0.5 rounded font-semibold')"></p>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-400 mb-1 uppercase tracking-wide">Cómo quedaría con esta regla</label>
                                            <p class="text-sm text-slate-700 leading-relaxed" x-html="highlightMatches(ex.preview, contextModal.item?.correct_text, 'bg-emerald-100 text-emerald-800 px-0.5 rounded font-semibold')"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <p class="text-xs text-slate-400 text-center pt-1">
                            <span x-text="contextModal.data.examples.length"></span>
                            <span x-text="contextModal.data.examples.length === 1 ? 'ejemplo' : 'ejemplos'"></span>,
                            uno por transcripción<span x-show="contextModal.data.truncated">, de una muestra parcial</span>.
                        </p>
                    </div>
                </template>
            </div>

            <div class="px-6 py-4 border-t border-slate-100">
                <button @click="closeContext()" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Re-aplicar (dual-mode: launch vs progress-view).
         (corrections-retroactive-progress-modal) -->
    <div x-cloak x-show="showApply" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showApply = false">
            <div class="p-6">
                <!-- Vista launch: solo cuando NO hay run vivo.
                     Si runId está set, abrimos vista de progreso. -->
                <div x-show="!runId">
                    <h2 class="text-lg font-bold text-slate-800 mb-1">Re-aplicar correcciones</h2>
                    <p class="text-sm text-slate-500 mb-4">Se reaplicará el diccionario aprobado a los segmentos seleccionados. La operación corre en background.</p>

                    <div x-show="!applying && !runFinished" class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-2">Alcance temporal</label>
                        <select x-model="applyScope" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500">
                            <option value="all">Todos los históricos (puede tardar horas)</option>
                            <option value="1">Último día (más rápido, riesgo de falsos positivos en corrección)</option>
                            <option value="3">Últimos 3 días</option>
                            <option value="7" selected>Últimos 7 días (recomendado)</option>
                            <option value="14">Últimos 14 días</option>
                            <option value="30">Últimos 30 días</option>
                            <option value="90">Últimos 90 días</option>
                        </select>
                        <p class="mt-2 text-xs text-slate-500">
                            <template x-if="applyScope === 'all'">
                                <span>Re-aplica a TODOS los segmentos del corpus. Use con precaución.</span>
                            </template>
                            <template x-if="applyScope !== 'all'">
                                <span>Solo segmentos creados en los últimos <span x-text="applyScope"></span> días. Más rápido y seguro para probar reglas nuevas.</span>
                            </template>
                        </p>
                    </div>

                    <div x-show="applying || runFinished" class="mb-4">
                        <div class="flex justify-between text-xs text-slate-600 mb-1">
                            <span x-text="runStatusText"></span>
                            <span x-text="runProgress"></span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden">
                            <div class="bg-brand-600 h-2 transition-all" :style="'width: ' + runProgressPct + '%'"></div>
                        </div>
                        <div x-show="runStuck" class="mt-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
                            <i class="fas fa-exclamation-triangle"></i>
                            Sin avances desde las <span x-text="runStuckSinceText"></span> — la corrida pudo haberse detenido (kill -9, OOM, error de PHP). La barra seguirá viva por si retoma sola.
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button @click="runApply()" :disabled="applying || runFinished" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium disabled:opacity-50">
                            <span x-text="applying ? 'Aplicando...' : (runFinished ? 'Terminado' : 'Confirmar y aplicar')"></span>
                        </button>
                        <button @click="closeApply()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium" x-show="!applying">Cerrar</button>
                    </div>
                    <div x-show="applyResult" class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800" x-text="applyResult"></div>
                    <div x-show="applyError" class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800" x-text="applyError"></div>
                </div>

                <!-- Vista progreso: cuando hay run vivo (banner activo).
                     No se muestra dropdown ni botón "Confirmar y aplicar". -->
                <div x-show="runId && !runFinished">
                    <h2 class="text-lg font-bold text-slate-800 mb-1">
                        <i class="fas fa-spinner fa-spin text-indigo-500 mr-1"></i>Progreso en vivo
                    </h2>
                    <p class="text-sm text-slate-500 mb-3">
                        Esta corriendo en background. Podés cerrar este modal — el banner del header sigue mostrándolo.
                    </p>
                    <div class="mb-4">
                        <div class="flex justify-between text-xs text-slate-600 mb-1">
                            <span x-text="runStatusText || 'En cola…'"></span>
                            <span x-text="runProgress"></span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                            <div class="bg-indigo-600 h-full transition-all" :style="'width: ' + runProgressPct + '%'"></div>
                        </div>
                        <div class="mt-2 text-xs text-slate-500 text-right" x-text="runProgressPct + '%'"></div>
                    </div>
                    <div x-show="runStuck" class="mb-3 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
                        <i class="fas fa-exclamation-triangle"></i>
                        Sin avances desde las <span x-text="runStuckSinceText"></span> — la corrida pudo haberse detenido (kill -9, OOM, error de PHP).
                    </div>
                    <div class="flex gap-3">
                        <button @click="refreshApplyNow()" class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-medium">
                            <i class="fas fa-rotate"></i> Refrescar estado
                        </button>
                        <button @click="closeApply()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Suggest modal (corrections-ai-suggest-context-aware) -->
    <div x-show="aiSuggest.modal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @keydown.escape.window="closeAiSuggest()">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-800">
                        <i class="fas fa-robot text-purple-600 mr-2"></i>Sugerencias IA (EN↔ES)
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Escanea transcripciones con LLM y propone nuevas correcciones.
                        <strong>Defensa-en-profundidad</strong>: el system prompt <em>y</em> el post-filter PHP descartan marcas, modelos, etc. Nunca propone cambiar "Microsoft", "Dionato", etc.
                        <strong>Aplica a futuras transcripciones</strong> automáticamente al aprobarlas; para histórico usá <strong>Re-aplicar</strong>.
                    </p>
                </div>
                <button @click="closeAiSuggest()" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap items-center gap-3 text-sm">
                <label class="font-medium text-slate-600">Ventana:</label>
                <select x-model.number="aiSuggest.days" class="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
                    <!-- Generado dinámicamente desde quickActionWindows (mismos botones que header). -->
                    <template x-for="d in quickActionWindows" :key="'vw-' + d">
                        <option :value="d" x-text="quickActionLabel(d)"></option>
                    </template>
                    <!-- Fallback mientras quickActionWindows aún no se cargó. -->
                    <template x-if="quickActionWindows.length === 0">
                        <option value="1">Hoy (1d)</option>
                    </template>
                </select>
                <label class="font-medium text-slate-600">Muestra:</label>
                <select x-model.number="aiSuggest.sample" class="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                    <option value="1000">1000</option>
                </select>
                <div class="ml-auto flex gap-2">
                    <button @click="runAiSuggest(false)" :disabled="aiSuggest.running" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 disabled:opacity-50 text-slate-700 rounded-lg text-sm">
                        <i class="fas fa-search"></i> Solo previsualizar
                    </button>
                    <button @click="runAiSuggest(true)" :disabled="aiSuggest.running || !aiSuggest.result || aiSuggest.inserted" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 disabled:bg-purple-300 text-white rounded-lg text-sm">
                        <i class="fas fa-save"></i> Insertar como pending
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4">
                <div x-show="aiSuggest.running" class="flex flex-col items-center justify-center py-12 text-slate-500">
                    <i class="fas fa-spinner fa-spin text-3xl text-purple-400 mb-3"></i>
                    <p class="text-sm">Llamando al LLM... usualmente 5-30s.</p>
                </div>

                <div x-show="!aiSuggest.running && aiSuggest.error" class="p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <span x-text="aiSuggest.error"></span>
                </div>

                <div x-show="!aiSuggest.running && aiSuggest.result && !aiSuggest.error">
                    <div class="grid grid-cols-3 gap-3 mb-4 text-sm">
                        <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2">
                            <div class="text-xs text-emerald-700 font-medium">Insertados</div>
                            <div class="text-2xl font-bold text-emerald-900" x-text="aiSuggest.insertedCount ?? 0"></div>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                            <div class="text-xs text-amber-700 font-medium">Rechazados (marcas)</div>
                            <div class="text-2xl font-bold text-amber-900" x-text="(aiSuggest.result?.rejected_by_filter ?? []).length"></div>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <div class="text-xs text-slate-700 font-medium">Segments</div>
                            <div class="text-2xl font-bold text-slate-900" x-text="aiSuggest.result?.segments_processed ?? 0"></div>
                        </div>
                    </div>

                    <div x-show="(aiSuggest.result?.candidates ?? []).length > 0" class="border border-slate-200 rounded-lg overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Original</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Corrección</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase hidden md:table-cell">Razón</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Freq</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="c in (aiSuggest.result?.candidates ?? [])" :key="c.wrong">
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-3 py-2 text-slate-800" x-text="c.wrong"></td>
                                        <td class="px-3 py-2 text-slate-800" x-text="c.correct"></td>
                                        <td class="px-3 py-2 text-xs text-slate-500 hidden md:table-cell" x-text="c.reason"></td>
                                        <td class="px-3 py-2 text-right text-xs text-slate-700 font-medium" x-text="c.freq"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div x-show="(aiSuggest.result?.candidates ?? []).length === 0" class="text-center py-12 text-slate-400">
                        <i class="fas fa-check-circle text-3xl text-emerald-300 mb-2"></i>
                        <p class="text-sm">No se detectaron candidatos nuevos.</p>
                    </div>
                </div>
            </div>

            <div class="px-6 py-3 border-t border-slate-200 bg-slate-50 rounded-b-2xl text-xs text-slate-500 flex items-center justify-between">
                <span>
                    Coste estimado: ~<span x-text="(aiSuggest.sample * 0.0003).toFixed(4)"></span> USD por corrida
                </span>
                <span x-text="aiSuggest.result?.source ?? ''"></span>
            </div>
        </div>
    </div>

    {{-- Triage pendientes: confirmación inicial + progreso + reporte.
         Cambios 2026-08-18. Dos modales: `triage.modal` (confirmación) y
         `triage.progress` (reporte en vivo). --}}
    <div x-show="triage.modal" x-cloak class="fixed inset-0 z-40 bg-black/40 flex items-center justify-center px-4" @click.self="triage.modal = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-3">Triage de pendientes</h3>
            <p class="text-sm text-slate-600 mb-4">
                Aplicar 6 capas de descarte a las <span class="font-semibold text-amber-700" x-text="pendingCount"></span> correcciones pendientes:
            </p>
            <ul class="text-xs text-slate-500 list-disc list-inside space-y-1 mb-4">
                <li>Longitud >4 palabras o sin segmento origen → descartar</li>
                <li>Duplicado contra approved → descartar</li>
                <li>Marca / nombre propio → descartar</li>
                <li>EnEsRuleClassifier NOISE / QUARANTINE → descartar</li>
                <li>Variantes KEEP → opcionalmente auto-aprobar con undo 5 min</li>
                <li>Contexto recalentado para supervivientes</li>
            </ul>
            <label class="flex items-center gap-2 mb-2 text-sm text-slate-700">
                <input type="checkbox" x-model="triage.dryRun" class="rounded">
                <span>Dry-run (no escribe en la BD)</span>
            </label>
            <label class="flex items-center gap-2 mb-4 text-sm text-slate-700">
                <input type="checkbox" x-model="triage.autoApproveKeep" :disabled="triage.dryRun" class="rounded">
                <span>Auto-aprobar variantes KEEP (con undo de 5 min)</span>
            </label>
            <div class="flex justify-end gap-2 mt-2">
                <button @click="triage.modal = false" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg text-sm">Cancelar</button>
                <button @click="startTriage()" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium">
                    <i class="fas fa-play mr-1"></i>
                    Iniciar triage
                </button>
            </div>
        </div>
    </div>

    <div x-show="triage.progress" x-cloak class="fixed inset-0 z-40 bg-black/40 flex items-center justify-center px-4" @click.self="if (!triage.running) closeTriageProgress()">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-800">
                    <i class="fas" :class="triage.running ? 'fa-spinner fa-spin text-amber-600' : 'fa-check-circle text-emerald-600'" class="mr-2"></i>
                    <span x-text="triage.running ? 'Triage en curso…' : 'Triage terminado'"></span>
                </h3>
                <button x-show="!triage.running" @click="closeTriageProgress()" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div x-show="triage.error" class="mb-4 p-3 bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-lg">
                <i class="fas fa-exclamation-triangle mr-2"></i><span x-text="triage.error"></span>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-4 text-center">
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                    <div class="text-2xl font-bold text-amber-800" x-text="triage.survivorsForReview ?? 0"></div>
                    <div class="text-xs text-amber-700 mt-1">Para revisión humana</div>
                </div>
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3">
                    <div class="text-2xl font-bold text-emerald-800" x-text="triage.autoApproveCandidates ?? 0"></div>
                    <div class="text-xs text-emerald-700 mt-1">Auto-aprobadas (KEEP)</div>
                </div>
            </div>

            <div class="border border-slate-200 rounded-lg overflow-hidden mb-4">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-[10px]">
                        <tr>
                            <th class="px-3 py-2 text-left">#</th>
                            <th class="px-3 py-2 text-left">Capa</th>
                            <th class="px-3 py-2 text-right">Descartadas</th>
                            <th class="px-3 py-2 text-right">Supervivientes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(layer, idx) in triage.layers" :key="layer.name + idx">
                            <tr>
                                <td class="px-3 py-2 text-slate-500" x-text="(idx+1)"></td>
                                <td class="px-3 py-2 text-slate-700" x-text="layer.name"></td>
                                <td class="px-3 py-2 text-right text-slate-700" x-text="layer.discarded ?? 0"></td>
                                <td class="px-3 py-2 text-right text-slate-700" x-text="layer.survivors ?? (layer.survivors_keep != null ? (layer.survivors_keep + layer.survivors_review) : (layer.warmed ?? '–'))"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between gap-3 text-sm">
                <a :href="`{{ url('/ia/correcciones/export') }}`" target="_blank"
                   class="text-brand-600 hover:text-brand-800">
                    <i class="fas fa-download mr-1"></i>Descargar CSV (export actual)
                </a>
                <div class="flex gap-2">
                    <button x-show="triage.bulkActionId && triage.undoExpiresAt && new Date(triage.undoExpiresAt) > new Date()"
                            @click="undoTriage()"
                            class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs rounded-lg">
                        <i class="fas fa-undo mr-1"></i>Deshacer auto-aprobadas
                    </button>
                    <button x-show="!triage.running" @click="closeTriageProgress()" class="px-4 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs rounded-lg">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function correccionesAdmin() {
    return {
        tab: 'pending',
        pending: [],
        loadingPending: false,
        pendingCount: {{ $pendingCount }},
        pendingSearch: '',
        // Approved: cargado vía AJAX (corrections-ai-suggest-auto-approve)
        approved: [],
        loadingApproved: false,
        approvedSearch: '',
        approvedSourceFilter: 'all',
        approvedSources: [],
        // AI suggest results sub-tab (corrections-ai-suggest-auto-approve)
        aiSuggestResults: null,
        loadingAiSuggestResults: false,
        aiSuggestResultsSearch: '',
        // Exclusiones dinámicas (corrections-protected-terms-admin)
        exclusiones: [],
        exclusionesLoading: false,
        exclusionesSearch: '',
        exclusionesShowArchived: false,
        showExcluirModal: false,
        excluirSaving: false,
        excluirError: '',
        excluirForm: { term: '', category: '', notes: '' },
        // Atajos de exclusión en tablas de pendientes/aprobadas (corrections-protected-terms-shortcut)
        showExcludeShortcutModal: false,
        excludeShortcutSaving: false,
        excludeShortcutError: '',
        excludeShortcutForm: { term: '', notes: '', source: 'pending' }, // 'pending' | 'approved'
        showExcludeShortcutBulkModal: false,
        excludeShortcutBulkSaving: false,
        excludeShortcutBulkError: '',
        excludeShortcutBulkResult: null,
        excludeShortcutBulkForm: { sharedNote: 'Limpieza batch', includeIndex: true, source: 'pending' },
        // Global dictionary counter (controlador → vista, server-rendered)
        approvedCount: {{ $approvedCount ?? 0 }},
        rejectedCount: {{ $rejectedCount ?? 0 }},
        totalCount: {{ $totalCount ?? 0 }},
        showRejectedTooltip: false,
        // Ejemplos en transcripciones (corrections-context-examples)
        contextModal: { open: false, loading: false, item: null, data: null },
        // Contexto sensible (changes/2026-08-02-corrections-dictionary-atomicity)
        contextSensitive: [],
        loadingContextSensitive: false,
         contextSensitiveFilter: 'all',
        get contextSensitiveCount() {
            return this.contextSensitive ? this.contextSensitive.length : 0;
        },
        get contextSensitiveFiltered() {
            if (!this.contextSensitive) return [];
            if (this.contextSensitiveFilter === 'all') return this.contextSensitive;
             return this.contextSensitive.filter(s => s.suggested_risk === this.contextSensitiveFilter);
         },
         // Revisión manual de transcripciones terminadas.
         transcriptionReviews: [],
         transcriptionReviewMode: 'requested',
         transcriptionReviewLoading: false,
         transcriptionReviewLoadingDetail: false,
         transcriptionReviewDetail: null,
         transcriptionReviewSaving: false,
         transcriptionReviewNotes: '',
        // Export CSV (original + corrección). El botón "Exportar CSV" del header
        // descarga todo; el dropdown permite filtrar por estado y búsqueda libre.
        exportFilter: { status: 'all', q: '' },
        exportCsvUrl(extra = {}) {
            const params = new URLSearchParams();
            const status = (extra && extra.status) || this.exportFilter.status || 'all';
            const q = (extra && extra.q !== undefined ? extra.q : (this.exportFilter.q || ''));
            if (status && status !== 'all') params.set('status', status);
            if (q) params.set('q', q);
            const qs = params.toString();
            return '/ia/correcciones/export' + (qs ? '?' + qs : '');
        },
        // Bulk selection (pending)
        selectedIds: new Set(),
        sourceFilter: 'all',
        sources: [],
        // Bulk selection (approved)
        approvedSelectedIds: new Set(),
        // Modales
        showNew: false,
        showReject: false,
        showApply: false,
        showBulkReject: false,
        rejectItem: null,
        rejectReason: '',
        bulkRejectReason: '',
        // Editar pendiente (corrections-pending-edit-delete)
        editForm: { open: false, item: null, wrong: '', correct: '' },
        destroyForm: { open: false, item: null },
        showBulkDestroy: false,
        bulkDestroyReason: '',
        // Contexto del segmento origen (changes/2026-08-12-corrections-pending-segment-context)
        segmentContext: { open: false, loading: false, correction: null, data: null },
        // Re-aplicar
        applying: false,
        applyResult: '',
        applyError: '',
        applyScope: '7',
        runId: null,
        runStatusText: '',
        runProgress: '',
        runProgressPct: 0,
        runFinished: false,
        runStuck: false,
        runStuckSinceText: '',
        runPollTimer: null,
        runStuckTimer: null,
        // Miner EN↔ES status (cargado en init vía GET /mining-status)
        miningStatus: null,
        // AI suggester status (cargado en init vía GET /ai-suggest-status)
        aiSuggestStatus: null,
        // AI suggester modal state (corrections-ai-suggest-context-aware)
        aiSuggest: {
            modal: false,
            running: false,
            days: 1,
            sample: 200,
            result: null,
            error: null,
            inserted: false,
            insertedCount: 0,
        },
        // AI suggester 1-click (header quick buttons)
        aiSuggestQuick: null, // null | int (qué ventana está corriendo)
        aiSuggestToast: {
            visible: false,
            title: '',
            detail: '',
            variant: 'success', // success | error
            timer: null,
            show(title, detail, variant) {
                if (this.timer) clearTimeout(this.timer);
                this.visible = true;
                this.title = title;
                this.detail = detail;
                this.variant = variant || 'success';
                this.timer = setTimeout(() => { this.visible = false; }, 6000);
            },
            dismiss() {
                this.visible = false;
                if (this.timer) { clearTimeout(this.timer); this.timer = null; }
            },
        },
        showToast(variant, title, detail) {
            this.aiSuggestToast.show(title, detail, variant);
        },
        // Triage en capas de pendientes (cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage)
        triage: {
            modal: false,        // modal de confirmación inicial
            progress: false,     // modal de progreso
            running: false,
            runId: null,
            dryRun: true,
            autoApproveKeep: false,
            report: null,        // resultado final al cerrar el modal de progreso
            error: null,
            pollTimer: null,
            layers: [],          // copia para mostrar en UI sin tocar `report`
            survivorsForReview: 0,
            autoApproveCandidates: 0,
            bulkActionId: null,
            undoExpiresAt: null,
        },
        // AI settings tab state (2026-08-01 UI settings)
        aiSettings: {
            list: {},
            form: {},
            dirty: {},
            loading: false,
            saving: false,
            savingApiKey: false,
            refreshingModels: false,
            hasApiKey: null,
            apiKeySource: null, // 'override_encrypted' | 'env' | 'none'
            apiKeyInput: '',
            saveOk: false,
            saveError: '',
        },
        // Ventanas configuradas para los botones rápidos del header
        // (default 1, 3, 7). Editables en AI Settings → "Botones rápidos".
        quickActionWindows: [1, 3, 7],

        // === Custom model IDs (BYOK / privados del admin) ===
        // Parsea el textarea CSV en una lista sin duplicados.
        get customModelIds() {
            const raw = (this.aiSettings.form?.custom_model_ids) || '';
            if (!raw.trim()) return [];
            const parts = raw.split(/[\s,]+/).filter(s => s.trim() !== '');
            const seen = new Set();
            const out = [];
            for (const p of parts) {
                const k = p.trim().toLowerCase();
                if (seen.has(k) || !p.trim()) continue;
                seen.add(k);
                out.push(p.trim());
            }
            return out;
        },

        removeCustomModelId(id) {
            const list = this.customModelIds.filter(x => x !== id);
            this.aiSettings.form.custom_model_ids = list.join(', ');
        },

        // === Quick action windows (botones 1-click del header) ===
        get quickActionWindowsFromForm() {
            const raw = (this.aiSettings.form?.quick_action_windows) || '';
            if (!raw.trim()) return [];
            const parts = raw.split(/[\s,]+/).filter(s => s.trim() !== '');
            const seen = new Set();
            const out = [];
            for (const p of parts) {
                const v = parseInt(p.trim(), 10);
                if (!Number.isFinite(v) || v < 1 || v > 90) continue;
                if (seen.has(v)) continue;
                seen.add(v);
                out.push(v);
            }
            return out.sort((a, b) => a - b);
        },

        removeQuickActionWindow(days) {
            const list = this.quickActionWindowsFromForm.filter(x => x !== days);
            this.aiSettings.form.quick_action_windows = list.join(', ');
        },
        // Undo toast
        undoToast: {
            visible: false,
            title: '',
            detail: '',
            icon: '',
            bulkActionId: null,
            expiresAt: null,
            expired: false,
            countdown: '',
        },
        undoTickInterval: null,
        form: { wrong: '', correct: '' },

        async init() {
            await this.loadPending();
            await this.loadApproved();
            await this.loadMiningStatus();
            await this.loadAiSuggestStatus();
            // Re-attach a una corrida retroactiva en curso al recargar la página
            // (best-effort; 204 = no hay activa).
            this.attachToActiveRun();
            // Cargar ventanas configuradas (botones rápidos del header).
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (Array.isArray(data.quick_action_windows)) {
                        this.quickActionWindows = data.quick_action_windows;
                    }
                }
            } catch (e) {
                // No crítico; defaults aplican (1,3,7).
            }
        },

        async switchTab(name) {
            this.tab = name;
            if (name === 'ai-settings' && Object.keys(this.aiSettings.list).length === 0) {
                await this.loadAiSettings();
            }
            if (name === 'exclusiones' && this.exclusiones.length === 0) {
                await this.loadExclusiones();
            }
            if (name === 'ai-suggest-results' && !this.aiSuggestResults) {
                await this.loadAiSuggestResults();
            }
            if (name === 'context-sensitive' && this.contextSensitive.length === 0) {
                await this.loadContextSensitive();
            }
            if (name === 'transcription-review') {
                await this.loadTranscriptionReviews();
            }
        },

        async loadPending() {
            this.loadingPending = true;
            try {
                const res = await apiFetch('/ia/correcciones/pending', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    this.pending = await res.json();
                    this.pendingCount = this.pending.length;
                    // Extraer sources únicos
                    const srcSet = new Set();
                    this.pending.forEach(c => { if (c.source) srcSet.add(c.source); });
                    this.sources = Array.from(srcSet).sort();
                }
            } finally { this.loadingPending = false; }
        },

        async loadMiningStatus() {
            try {
                const res = await apiFetch('/ia/correcciones/mining-status', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    this.miningStatus = await res.json();
                }
            } catch (e) {
                // No crítico: el badge simplemente quedará en estado "—".
            }
        },

        async loadAiSuggestStatus() {
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-status', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    this.aiSuggestStatus = await res.json();
                }
            } catch (e) {
                // No crítico.
            }
        },

        async loadApproved() {
            // Cargado vía AJAX al init() para que la pestaña Aprobadas soporte
            // búsqueda libre, filtro por source y bulk delete sin render server-side.
            this.loadingApproved = true;
            try {
                const res = await apiFetch('/ia/correcciones/approved', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    this.approved = await res.json();
                    this.approvedCount = this.approved.length;
                    // Sources únicos para el dropdown de filtro.
                    const srcSet = new Set();
                    this.approved.forEach(c => { if (c.source) srcSet.add(c.source); });
                    this.approvedSources = Array.from(srcSet).sort();
                }
            } catch (e) {
                // No crítico; el admin puede recargar manualmente.
            } finally { this.loadingApproved = false; }
        },

        async loadContextSensitive() {
            // Changes/2026-08-02: carga la lista de correcciones con risk != low.
            this.loadingContextSensitive = true;
            try {
                const res = await apiFetch('/ia/correcciones/context-audit', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    this.contextSensitive = data.suggestions || [];
                }
            } catch (e) {
                console.warn('No se pudo cargar contexto sensible', e);
            } finally {
                this.loadingContextSensitive = false;
            }
        },

        async loadTranscriptionReviews() {
            this.transcriptionReviewLoading = true;
            try {
                const params = new URLSearchParams({ mode: this.transcriptionReviewMode });
                const res = await apiFetch('/ia/correcciones/transcription-review?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('No se pudo cargar la cola de revisión');
                const data = await res.json();
                this.transcriptionReviews = data.items || [];
            } catch (e) {
                this.transcriptionReviews = [];
                this.showToast('error', 'Revisión de transcripciones', e.message || 'Fallo de red');
            } finally {
                this.transcriptionReviewLoading = false;
            }
        },

        transcriptionReviewModeHelp() {
            return ({
                requested: 'Solicitadas: trabajos terminados ordenados por cuándo se generaron. Los trabajos queued o processing se revisan desde API Transcriptor.',
                completed: 'Finalizadas: trabajos terminados ordenados por cuándo quedaron disponibles. Un trabajo antiguo puede aparecer aquí si terminó después del backlog.',
                sensitive: 'Sensibles: trabajos terminados con coincidencias de reglas medium/high, ordenados por finalización.',
            })[this.transcriptionReviewMode] || '';
        },

        hasQueueWait(item) {
            if (!item?.created_at || !item?.finished_at) return false;
            const waitMs = new Date(item.finished_at).getTime() - new Date(item.created_at).getTime();
            return Number.isFinite(waitMs) && waitMs >= 24 * 60 * 60 * 1000;
        },

        /**
         * Ejemplos de dónde dispara una corrección (corrections-context-examples).
         *
         * El backend cachea el resultado, así que la segunda apertura de la misma
         * corrección es inmediata. Los timeouts no se cachean, así que el botón
         * de reintento vuelve a llamar aquí sin más.
         */
        async openContext(c) {
            this.contextModal = { open: true, loading: true, item: c, data: null };
            try {
                const res = await apiFetch('/ia/correcciones/' + c.id + '/contexto', {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('No se pudieron cargar los ejemplos');
                this.contextModal.data = await res.json();
            } catch (e) {
                this.showToast('error', 'Ejemplos en transcripciones', e.message || 'Fallo de red');
                this.contextModal.open = false;
            } finally {
                this.contextModal.loading = false;
            }
        },

        closeContext() {
            this.contextModal = { open: false, loading: false, item: null, data: null };
        },

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        /**
         * Resalta todas las apariciones de `target` en `text`, escapando SIEMPRE
         * antes de insertar el <mark> (el texto viene de transcripciones, no es
         * de confianza).
         *
         * Las fronteras se definen con \p{L}\p{N} igual que isWordCharAt() en
         * CorrectionService, para que lo resaltado coincida con lo que el
         * corrector reemplazaría de verdad. Si el navegador no soporta lookbehind
         * se degrada a coincidencia por substring: peor resaltado, nunca un error.
         */
        highlightMatches(text, target, markClass) {
            const raw = String(text ?? '');
            const needle = String(target ?? '');
            if (!needle) return this.escapeHtml(raw);

            const escaped = needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            let re;
            try {
                re = new RegExp('(?<![\\p{L}\\p{N}])' + escaped + '(?![\\p{L}\\p{N}])', 'giu');
            } catch (e) {
                re = new RegExp(escaped, 'gi');
            }

            let out = '', last = 0, m;
            while ((m = re.exec(raw)) !== null) {
                out += this.escapeHtml(raw.slice(last, m.index));
                out += '<mark class="' + markClass + '">' + this.escapeHtml(m[0]) + '</mark>';
                last = m.index + m[0].length;
                if (m[0].length === 0) re.lastIndex++;
            }
            return out + this.escapeHtml(raw.slice(last));
        },

        /**
         * Render del snippet de ~100 chars del text_raw del segmento origen,
         * centrado en `wrong_text` con <mark> rojo. Cambios/2026-08-12.
         */
        snippetHtml(c) {
            const seg = c && c.source_segment;
            const raw = seg && seg.text_raw ? String(seg.text_raw) : '';
            const wrong = c && c.wrong_text ? String(c.wrong_text) : '';
            if (!raw) return '';
            if (!wrong) {
                return this.escapeHtml(raw.slice(0, 100)) + (raw.length > 100 ? '…' : '');
            }
            if (!raw.toLowerCase().includes(wrong.toLowerCase())) {
                return this.escapeHtml(raw.slice(0, 100)) + (raw.length > 100 ? '…' : '');
            }
            const idx = raw.toLowerCase().indexOf(wrong.toLowerCase());
            const wrongLen = wrong.length;
            const padding = Math.max(0, Math.floor((100 - wrongLen) / 2));
            const start = Math.max(0, idx - padding);
            const end = Math.min(raw.length, idx + wrongLen + padding);
            const prefix = start > 0 ? '…' : '';
            const suffix = end < raw.length ? '…' : '';
            const before = raw.slice(start, idx);
            const match = raw.slice(idx, idx + wrongLen);
            const after = raw.slice(idx + wrongLen, end);
            return prefix
                + this.escapeHtml(before)
                + '<mark class="bg-red-100 text-red-800 px-0.5 rounded font-semibold">'
                + this.escapeHtml(match)
                + '</mark>'
                + this.escapeHtml(after)
                + suffix;
        },

        /**
         * Tooltip del snippet: avisa cuando el wrong_text no aparece textualmente
         * en el segmento para que el admin entienda por qué no hay highlight.
         */
        snippetTitle(c) {
            const seg = c && c.source_segment;
            const raw = seg && seg.text_raw ? String(seg.text_raw) : '';
            const wrong = c && c.wrong_text ? String(c.wrong_text) : '';
            if (!raw) return '';
            if (wrong && !raw.toLowerCase().includes(wrong.toLowerCase())) {
                return 'La corrección no aparece textualmente en este segmento';
            }
            return 'Click para ver el segmento completo';
        },

        /**
         * Modal "Contexto del segmento": carga el detalle del source_segment
         * vía GET /ia/correcciones/{id}/source-segment.
         */
        async openSegmentContext(c) {
            this.segmentContext = { open: true, loading: true, correction: c, data: null };
            try {
                const res = await apiFetch('/ia/correcciones/' + c.id + '/source-segment', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    this.segmentContext.data = await res.json();
                } else if (res.status === 404) {
                    this.segmentContext.data = { notFound: true };
                } else {
                    const d = await res.json().catch(() => ({}));
                    this.showToast('error', 'Contexto del segmento', d.error || 'Error al cargar segmento');
                    this.segmentContext.open = false;
                    return;
                }
            } catch (e) {
                this.showToast('error', 'Contexto del segmento', e.message || 'Error de red');
                this.segmentContext.open = false;
                return;
            }
            this.segmentContext.loading = false;
        },

        closeSegmentContext() {
            this.segmentContext = { open: false, loading: false, correction: null, data: null };
        },

        formatHms(seconds) {
            const s = Math.floor(Number(seconds) || 0);
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const sec = s % 60;
            return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
        },

        highlightedRaw() {
            const c = this.segmentContext.correction;
            const seg = this.segmentContext.data && this.segmentContext.data.segment;
            if (!c || !seg) return '';
            return this.highlightInText(seg.text_raw, c.wrong_text, 'red');
        },

        highlightedText() {
            const c = this.segmentContext.correction;
            const seg = this.segmentContext.data && this.segmentContext.data.segment;
            if (!c || !seg) return '';
            return this.highlightInText(seg.text, c.correct_text, 'green');
        },

        highlightInText(text, target, color) {
            const raw = String(text ?? '');
            const needle = String(target ?? '');
            if (!raw) return '';
            if (!needle) return this.escapeHtml(raw);
            const idx = raw.toLowerCase().indexOf(needle.toLowerCase());
            if (idx === -1) return this.escapeHtml(raw);
            const colors = {
                red: 'bg-red-100 text-red-800 px-0.5 rounded font-semibold',
                green: 'bg-emerald-100 text-emerald-800 px-0.5 rounded font-semibold',
            };
            const cls = colors[color] || colors.red;
            return this.escapeHtml(raw.slice(0, idx))
                + '<mark class="' + cls + '">'
                + this.escapeHtml(raw.slice(idx, idx + needle.length))
                + '</mark>'
                + this.escapeHtml(raw.slice(idx + needle.length));
        },

        async openTranscriptionReview(id) {
            this.transcriptionReviewDetail = null;
            this.transcriptionReviewNotes = '';
            this.transcriptionReviewLoadingDetail = true;
            try {
                const res = await apiFetch('/ia/correcciones/transcription-review/' + id, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('No se pudo cargar el detalle');
                const detail = await res.json();
                this.transcriptionReviewDetail = detail;
                this.transcriptionReviewNotes = detail.review?.notes || '';
            } catch (e) {
                this.showToast('error', 'Detalle de transcripción', e.message || 'Fallo de red');
            } finally {
                this.transcriptionReviewLoadingDetail = false;
            }
        },

        async saveTranscriptionReview(status) {
            if (!this.transcriptionReviewDetail || !['correct', 'needs_review', 'ignored'].includes(status)) return;
            this.transcriptionReviewSaving = true;
            try {
                const res = await apiFetch('/ia/correcciones/transcription-review/' + this.transcriptionReviewDetail.id, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ status, notes: this.transcriptionReviewNotes || null }),
                });
                if (!res.ok) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.message || 'No se pudo guardar la revisión');
                }
                const data = await res.json();
                this.transcriptionReviewDetail.review = data.review;
                const row = this.transcriptionReviews.find(item => item.id === this.transcriptionReviewDetail.id);
                if (row) row.review = data.review;
                this.showToast('success', 'Revisión guardada', 'La decisión quedó registrada sin modificar el diccionario.');
            } catch (e) {
                this.showToast('error', 'Revisión', e.message || 'Fallo de red');
            } finally {
                this.transcriptionReviewSaving = false;
            }
        },

        formatReviewDate(value) {
            if (!value) return '—';
            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
        },

        formatSeconds(value) {
            const total = Math.max(0, Math.floor(Number(value) || 0));
            const hours = Math.floor(total / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = total % 60;
            return [hours, minutes, seconds].map(n => String(n).padStart(2, '0')).join(':');
        },

        reviewStatusLabel(status) {
            return ({ pending: 'Pendiente', correct: 'Correcta', needs_review: 'Necesita revisión', ignored: 'Ignorada' })[status] || 'Pendiente';
        },

        reviewStatusClass(status) {
            return ({
                pending: 'bg-slate-100 text-slate-600 border-slate-200',
                correct: 'bg-emerald-100 text-emerald-700 border-emerald-200',
                needs_review: 'bg-amber-100 text-amber-700 border-amber-200',
                ignored: 'bg-slate-200 text-slate-600 border-slate-300',
            })[status] || 'bg-slate-100 text-slate-600 border-slate-200';
        },

        openApprovedRule(wrongText) {
            this.transcriptionReviewDetail = null;
            this.tab = 'approved';
            this.approvedSearch = wrongText || '';
        },

        async contextAuditApply() {
            // Aplica las sugerencias del auditor a la BD (solo pisa risk_level='low').
            if (!confirm('¿Aplicar las sugerencias del ContextShiftAuditor? Solo se modificarán correcciones con risk_level=low (no pisa overrides manuales).')) return;
            this.loadingContextSensitive = true;
            try {
                const res = await apiFetch('/ia/correcciones/context-audit', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.showToast('success', 'Sugerencias aplicadas', `${data.updated} correcciones marcadas (${data.skipped_manual} overrides respetados).`);
                    await this.loadContextSensitive();
                    await this.loadApproved();
                } else {
                    this.showToast('error', 'Error', 'No se pudieron aplicar las sugerencias.');
                }
            } catch (e) {
                this.showToast('error', 'Error', e.message || 'Fallo de red');
            } finally {
                this.loadingContextSensitive = false;
            }
        },

        async setRiskLevel(id, newRisk) {
            // Override manual: cambia risk_level de una corrección específica.
            // Llama al endpoint atómico PATCH /correcciones/{id}/risk-level.
            if (!confirm(`¿Cambiar risk_level a '${newRisk}' para la corrección #${id}?`)) return;
            try {
                const res = await apiFetch(`/ia/correcciones/${id}/risk-level`, {
                    method: 'PATCH',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ risk_level: newRisk }),
                });
                if (res.ok) {
                    // Quitar la fila de la lista local sin esperar al reload: si
                    // el override es a 'low' ya no debe aparecer como "contexto
                    // sensible" (el endpoint GET /context-audit también la filtra
                    // por seguridad). Esto evita la confusión de "le di Aceptar
                    // y sigue ahí".
                    this.contextSensitive = this.contextSensitive.filter(s => s.id !== id);
                    this.showToast('success', 'Risk actualizado', `Corrección #${id} → ${newRisk}`);
                    await this.loadContextSensitive();
                    await this.loadApproved();
                } else {
                    this.showToast('error', 'Error', `HTTP ${res.status}`);
                }
            } catch (e) {
                this.showToast('error', 'Error', e.message || 'Fallo de red');
            }
        },

        async loadAiSuggestResults() {
            this.loadingAiSuggestResults = true;
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-results', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    this.aiSuggestResults = await res.json();
                }
            } catch (e) {
                // No crítico.
            } finally { this.loadingAiSuggestResults = false; }
        },

        // ===== Exclusiones dinámicas (corrections-protected-terms-admin) =====
        async loadExclusiones() {
            this.exclusionesLoading = true;
            try {
                const res = await apiFetch('/ia/correcciones/protected-terms', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    this.exclusiones = data.items ?? [];
                }
            } catch (e) {
                // No crítico.
            } finally { this.exclusionesLoading = false; }
        },

        openExcluirModal() {
            this.excluirForm = { term: '', category: '', notes: '' };
            this.excluirError = '';
            this.excluirSaving = false;
            this.showExcluirModal = true;
        },

        async submitExclusion() {
            const term = (this.excluirForm.term || '').trim();
            if (!term) { this.excluirError = 'El término es obligatorio.'; return; }
            this.excluirSaving = true;
            this.excluirError = '';
            try {
                const res = await apiFetch('/ia/correcciones/protected-terms', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({
                        term: term,
                        category: this.excluirForm.category || null,
                        notes: this.excluirForm.notes || null,
                    }),
                });
                if (res.ok || res.status === 201) {
                    this.showExcluirModal = false;
                    await this.loadExclusiones();
                } else {
                    const d = await res.json();
                    this.excluirError = d.error || Object.values(d.errors || {})[0]?.[0] || 'Error al guardar.';
                }
            } catch (e) {
                this.excluirError = 'Error de red al guardar.';
            } finally {
                this.excluirSaving = false;
            }
        },

        async archiveExclusion(id) {
            if (!confirm('¿Archivar este término? Dejará de bloquear al AI Suggest en cuanto se invalide el cache.')) return;
            try {
                const res = await apiFetch('/ia/correcciones/protected-terms/' + id, {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });
                if (res.ok || res.status === 204) {
                    await this.loadExclusiones();
                } else {
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'Error al archivar.');
                }
            } catch (e) { alert('Error de red.'); }
        },

        async restoreExclusion(id) {
            try {
                const res = await apiFetch('/ia/correcciones/protected-terms/' + id + '/restore', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });
                if (res.ok || res.status === 204) {
                    await this.loadExclusiones();
                } else {
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'Error al restaurar.');
                }
            } catch (e) { alert('Error de red.'); }
        },

        categoryLabel(cat) {
            const map = {
                event: 'Evento',
                brand: 'Marca',
                product: 'Producto',
                org: 'Organización',
                person: 'Persona',
                other: 'Otro',
            };
            return map[cat] || (cat ? cat : '—');
        },

        // ===== Atajos de exclusión desde tablas (corrections-protected-terms-shortcut) =====
        openExcludeForPending(c) {
            const term = (c.wrong_text || '').trim();
            this.excludeShortcutForm = {
                term: term,
                notes: `Agregada desde pendientes — corrección #${c.id}: ${term} → ${c.correct_text || ''}`,
                source: 'pending',
                correctionId: c.id,
            };
            this.excludeShortcutError = '';
            this.excludeShortcutSaving = false;
            this.showExcludeShortcutModal = true;
        },

        openExcludeForApproved(c) {
            const term = (c.wrong_text || '').trim();
            this.excludeShortcutForm = {
                term: term,
                notes: `Agregada desde aprobadas — corrección #${c.id}: ${term} → ${c.correct_text || ''}`,
                source: 'approved',
                correctionId: c.id,
            };
            this.excludeShortcutError = '';
            this.excludeShortcutSaving = false;
            this.showExcludeShortcutModal = true;
        },

        async submitExcludeShortcut() {
            const term = (this.excludeShortcutForm.term || '').trim();
            if (!term) {
                this.excludeShortcutError = 'El término es obligatorio.';
                return;
            }
            this.excludeShortcutSaving = true;
            this.excludeShortcutError = '';
            const source = this.excludeShortcutForm.source;
            const body = {
                term: term,
                notes: this.excludeShortcutForm.notes || null,
            };
            if (this.excludeShortcutForm.correctionId) {
                body.correction_id = this.excludeShortcutForm.correctionId;
            }
            try {
                const res = await apiFetch('/ia/correcciones/protected-terms', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify(body),
                });
                if (res.ok || res.status === 201) {
                    const d = await res.json().catch(() => ({}));
                    const archived = (d.archived || []).length;
                    this.showExcludeShortcutModal = false;
                    // Refrescar la lista desde donde se originó la fila.
                    if (source === 'pending') await this.loadPending();
                    else await this.loadApproved();
                    // Refrescar exclusiones si la pestaña está abierta.
                    if (this.tab === 'exclusiones') await this.loadExclusiones();
                    // Decidir contador y mensaje del toast.
                    const createdCount = (d.item ? 1 : 0) + (Array.isArray(d.created) ? d.created.length : 0);
                    const detail = archived > 0
                        ? `Corrección #${d.archived[0]?.correction_id || '?'} archivada con motivo 'moved_to_exclusion'.`
                        : `'${term}' ya no será traducida por AI Suggest.`;
                    const title = archived > 0
                        ? `Exclusión agregada + 1 corrección archivada`
                        : 'Exclusión agregada';
                    this.aiSuggestToast.show(title, detail, 'success');
                } else {
                    const d = await res.json().catch(() => ({}));
                    this.excludeShortcutError = d.error || Object.values(d.errors || {})[0]?.[0] || 'Error al guardar.';
                }
            } catch (e) {
                this.excludeShortcutError = 'Error de red al guardar.';
            } finally {
                this.excludeShortcutSaving = false;
            }
        },

        openExcludeBulk(source) {
            this.excludeShortcutBulkForm.source = source;
            this.excludeShortcutBulkForm.sharedNote = 'Limpieza batch ' + new Date().toISOString().slice(0, 10);
            this.excludeShortcutBulkForm.includeIndex = true;
            this.excludeShortcutBulkError = '';
            this.excludeShortcutBulkResult = null;
            this.showExcludeShortcutBulkModal = true;
        },

        async submitExcludeBulk() {
            const source = this.excludeShortcutBulkForm.source;
            const ids = source === 'pending'
                ? Array.from(this.selectedIds)
                : Array.from(this.approvedSelectedIds);
            const rows = source === 'pending' ? this.pending : this.approved;
            const sharedNote = this.excludeShortcutBulkForm.sharedNote || '';
            const includeIndex = this.excludeShortcutBulkForm.includeIndex;

            const terms = ids.map((id, idx) => {
                const row = rows.find(r => r.id === id);
                if (!row) return null;
                const note = includeIndex ? `${sharedNote} — #${idx + 1}` : sharedNote;
                return {
                    term: (row.wrong_text || '').trim(),
                    notes: note,
                    correction_id: id, // Vincula cada exclusión con su corrección (corrections-archive-on-exclude).
                };
            }).filter(t => t && t.term);

            if (terms.length === 0) {
                this.excludeShortcutBulkError = 'No hay términos válidos en la selección.';
                return;
            }
            this.excludeShortcutBulkSaving = true;
            this.excludeShortcutBulkError = '';
            this.excludeShortcutBulkResult = null;
            try {
                const res = await apiFetch('/ia/correcciones/protected-terms', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ terms: terms }),
                });
                const d = await res.json().catch(() => ({}));
                if (res.ok || res.status === 201 || res.status === 207) {
                    const created = (d.created || []).length;
                    const skipped = (d.skipped || []).length;
                    const archived = (d.archived || []).length;
                    this.excludeShortcutBulkResult = { created, skipped, archived, terms: d.created || [] };
                    // Refrescar lista origen Y limpiarla.
                    if (source === 'pending') {
                        await this.loadPending();
                        this.selectedIds.clear();
                    } else {
                        await this.loadApproved();
                        this.approvedSelectedIds.clear();
                    }
                    if (this.tab === 'exclusiones') await this.loadExclusiones();
                    // Mensaje diferenciado: bulk con archivado cuenta clean-up completo.
                    const title = (created > 0 || archived > 0)
                        ? `${created} creada(s), ${skipped} duplicada(s), ${archived} archivada(s)`
                        : 'Sin cambios';
                    const detail = created > 0
                        ? 'Aplican en ≤5 min en la próxima corrida AI Suggest.'
                        : 'No se archivó nada — todas las exclusiones eran duplicadas.';
                    this.aiSuggestToast.show(title, detail, created > 0 ? 'success' : 'error');
                    // Cerrar modal después de breve delay.
                    setTimeout(() => { this.showExcludeShortcutBulkModal = false; this.excludeShortcutBulkResult = null; }, 2500);
                } else {
                    this.excludeShortcutBulkError = d.error || 'Error al guardar.';
                }
            } catch (e) {
                this.excludeShortcutBulkError = 'Error de red al guardar.';
            } finally {
                this.excludeShortcutBulkSaving = false;
            }
        },

        get aiSuggestLabel() {
            const last = this.aiSuggestStatus?.last_ai_suggest_at;
            if (!last) return '—';
            const date = new Date(last);
            if (Number.isNaN(date.getTime())) return '—';
            const ageHours = (Date.now() - date.getTime()) / 3_600_000;
            const timeStr = date.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
            if (ageHours < 1) return 'Última: hace <1h (' + timeStr + ')';
            return 'Última: hace ' + Math.floor(ageHours) + 'h (' + timeStr + ')';
        },

        get aiSuggestBadgeClass() {
            const last = this.aiSuggestStatus?.last_ai_suggest_at;
            if (!last) return 'bg-slate-100 text-slate-500';
            const ageHours = (Date.now() - new Date(last).getTime()) / 3_600_000;
            if (ageHours < 12) return 'bg-green-100 text-green-700';
            if (ageHours <= 24) return 'bg-yellow-100 text-yellow-700';
            return 'bg-red-100 text-red-700';
        },

        get miningStatusLabel() {
            const last = this.miningStatus?.last_mining_at;
            if (!last) return '—';
            const date = new Date(last);
            if (Number.isNaN(date.getTime())) return '—';
            return 'Última: ' + date.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
        },

        get miningStatusBadgeClass() {
            const last = this.miningStatus?.last_mining_at;
            if (!last) return 'bg-slate-100 text-slate-500';
            const ageDays = (Date.now() - new Date(last).getTime()) / 86_400_000;
            if (ageDays < 7) return 'bg-green-100 text-green-700';
            if (ageDays <= 30) return 'bg-yellow-100 text-yellow-700';
            return 'bg-red-100 text-red-700';
        },

        // ===== Filtered lists (computed) =====
        _matchesSearch(item, term) {
            if (!term) return true;
            const t = term.trim().toLowerCase();
            if (!t) return true;
            const w = (item.wrong_text || '').toLowerCase();
            const c = (item.correct_text || '').toLowerCase();
            return w.includes(t) || c.includes(t);
        },
        get pendingFiltered() {
            return this.pending
                .filter(c => this.sourceFilter === 'all' || c.source === this.sourceFilter)
                .filter(c => this._matchesSearch(c, this.pendingSearch));
        },
        get approvedFiltered() {
            return this.approved
                .filter(c => this.approvedSourceFilter === 'all' || c.source === this.approvedSourceFilter)
                .filter(c => this._matchesSearch(c, this.approvedSearch));
        },
        get aiSuggestApprovedFiltered() {
            const list = (this.aiSuggestResults?.approved_list ?? []);
            return list.filter(c => this._matchesSearch(c, this.aiSuggestResultsSearch));
        },
        get aiSuggestPendingFiltered() {
            const list = (this.aiSuggestResults?.pending_list ?? []);
            return list.filter(c => this._matchesSearch(c, this.aiSuggestResultsSearch));
        },
        get exclusionesActiveFiltered() {
            return this.exclusiones.filter(e => !e.archived_at);
        },
        get exclusionesFiltered() {
            const t = (this.exclusionesSearch ?? '').trim().toLowerCase();
            return this.exclusiones
                .filter(e => this.exclusionesShowArchived || !e.archived_at)
                .filter(e => !t || (e.term || '').toLowerCase().includes(t))
                .sort((a, b) => {
                    // Activas primero (archived_at null), luego por más reciente.
                    if (!!a.archived_at !== !!b.archived_at) return a.archived_at ? 1 : -1;
                    return (b.id - a.id);
                });
        },

        // ===== Bulk selection (pending) =====
        get allSelected() {
            return this.pendingFiltered.length > 0
                && this.pendingFiltered.every(c => this.selectedIds.has(c.id));
        },
        get someSelected() {
            return this.pendingFiltered.some(c => this.selectedIds.has(c.id));
        },
        toggleAll() {
            if (this.allSelected) {
                this.pendingFiltered.forEach(c => this.selectedIds.delete(c.id));
            } else {
                this.pendingFiltered.forEach(c => this.selectedIds.add(c.id));
            }
        },
        toggleOne(id) {
            if (this.selectedIds.has(id)) this.selectedIds.delete(id);
            else this.selectedIds.add(id);
        },
        clearSelection() { this.selectedIds.clear(); },

        // ===== Bulk selection (approved) =====
        get approvedLocalList() {
            // Aproximación: si tab === 'approved' cargamos aprobados via DOM, pero para checkboxes usamos set manual.
            // Para simplicidad: el select-all de approved solo funciona sobre los que ya están seleccionados manualmente.
            return Array.from(this.approvedSelectedIds);
        },
        get allApprovedSelected() {
            return this.approvedFiltered.length > 0
                && this.approvedFiltered.every(c => this.approvedSelectedIds.has(c.id));
        },
        get someApprovedSelected() {
            return this.approvedFiltered.some(c => this.approvedSelectedIds.has(c.id));
        },
        toggleAllApproved() {
            if (this.allApprovedSelected) {
                this.approvedFiltered.forEach(c => this.approvedSelectedIds.delete(c.id));
            } else {
                this.approvedFiltered.forEach(c => this.approvedSelectedIds.add(c.id));
            }
        },

        // ===== Approve individual =====
        async approve(c) {
            const res = await apiFetch('/ia/correcciones/' + c.id + '/approve', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) { await this.loadPending(); window.location.reload(); }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },

        // ===== Approved: destroy individual + bulk (corrections-ai-suggest-auto-approve) =====
        async destroyApproved(id) {
            if (!confirm('¿Eliminar esta corrección aprobada? Dejará de aplicar a SRT nuevos.')) return;
            try {
                const res = await apiFetch('/ia/correcciones/' + id, {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });
                if (res.ok) {
                    this.approvedSelectedIds.delete(id);
                    await this.loadApproved();
                    await this.loadPending(); // por si quedó orphan en otras vistas
                    if (this.tab === 'ai-suggest-results' && this.aiSuggestResults) {
                        await this.loadAiSuggestResults();
                    }
                } else {
                    const d = await res.json();
                    alert(d.error || 'Error al eliminar.');
                }
            } catch (e) {
                alert('Error de red al eliminar.');
            }
        },
        async bulkDestroyApproved() {
            const ids = Array.from(this.approvedSelectedIds);
            if (ids.length === 0) return;
            if (!confirm(`¿Eliminar ${ids.length} correcciones aprobadas? Dejarán de aplicar a SRT nuevos.`)) return;
            try {
                const res = await apiFetch('/ia/correcciones/bulk-destroy', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ ids }),
                });
                if (res.ok) {
                    this.approvedSelectedIds.clear();
                    await this.loadApproved();
                    if (this.tab === 'ai-suggest-results' && this.aiSuggestResults) {
                        await this.loadAiSuggestResults();
                    }
                } else {
                    const d = await res.json();
                    alert(d.error || 'Error al eliminar en lote.');
                }
            } catch (e) {
                alert('Error de red al eliminar.');
            }
        },

        openReject(c) { this.rejectItem = c; this.rejectReason = ''; this.showReject = true; },
        async confirmReject() {
            const res = await apiFetch('/ia/correcciones/' + this.rejectItem.id + '/reject', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ rejected_reason: this.rejectReason }),
            });
            if (res.ok) { this.showReject = false; await this.loadPending(); }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },

        // ===== Editar pendiente (corrections-pending-edit-delete) =====
        openEditPending(c) {
            this.editForm = { open: true, item: c, wrong: c.wrong_text, correct: c.correct_text };
        },
        async saveEditPending() {
            const id = this.editForm.item?.id;
            if (!id) return;
            const wrong = (this.editForm.wrong || '').trim();
            const correct = (this.editForm.correct || '').trim();
            if (!wrong || !correct) { alert('Texto incorrecto y corrección no pueden estar vacíos.'); return; }
            try {
                const res = await apiFetch('/ia/correcciones/' + id, {
                    method: 'PATCH', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ wrong_text: wrong, correct_text: correct }),
                });
                if (res.ok) {
                    const d = await res.json();
                    this.editForm.open = false;
                    if (d.correction && d.correction.status === 'merged') {
                        this.selectedIds.delete(id);
                        await this.loadPending();
                    } else if (d.correction) {
                        const idx = this.pending.findIndex(p => p.id === id);
                        if (idx >= 0) this.pending[idx] = d.correction;
                    }
                } else {
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'Error al editar.');
                }
            } catch (e) {
                alert('Error de red al editar.');
            }
        },
        async destroyPending(c) {
            this.destroyForm = { open: true, item: c };
        },
        async confirmDestroyPending() {
            const c = this.destroyForm.item;
            if (!c) return;
            this.destroyForm = { open: false, item: null };
            try {
                const res = await apiFetch('/ia/correcciones/' + c.id, {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });
                if (res.ok) {
                    this.selectedIds.delete(c.id);
                    await this.loadPending();
                } else {
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'Error al eliminar.');
                }
            } catch (e) {
                alert('Error de red al eliminar.');
            }
        },
        openBulkDestroyPending() {
            if (this.selectedIds.size === 0) return;
            this.bulkDestroyReason = '';
            this.showBulkDestroy = true;
        },
        async confirmBulkDestroyPending() {
            const ids = Array.from(this.selectedIds);
            if (ids.length === 0) { this.showBulkDestroy = false; return; }
            this.showBulkDestroy = false;
            try {
                const res = await apiFetch('/ia/correcciones/bulk-destroy-pending', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ ids }),
                });
                if (res.ok) {
                    const data = await res.json().catch(() => ({}));
                    this.clearSelection();
                    await this.loadPending();
                    if (data.errors && data.errors.length > 0) {
                        alert(`Eliminadas: ${data.deleted}. Errores: ${data.errors.length} (algunos items no estaban en estado pendiente).`);
                    }
                } else {
                    const d = await res.json().catch(() => ({}));
                    alert(d.error || 'Error al eliminar en lote.');
                }
            } catch (e) {
                alert('Error de red al eliminar.');
            }
        },

        // ===== Bulk approve =====
        async confirmBulkApprove() {
            if (this.selectedIds.size === 0) return;
            const ids = Array.from(this.selectedIds);
            try {
                const res = await apiFetch('/ia/correcciones/bulk-approve', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ ids }),
                });
                if (res.ok) {
                    const d = await res.json();
                    this.showUndoToast(d, 'bulk_approve');
                    this.clearSelection();
                    await this.loadPending();
                } else {
                    const d = await res.json();
                    alert(d.error || 'Error');
                }
            } catch (e) {
                alert('Error de red al aprobar en lote.');
            }
        },

        // ===== Bulk reject =====
        openBulkReject() {
            this.bulkRejectReason = '';
            this.showBulkReject = true;
        },
        async confirmBulkReject() {
            if (this.selectedIds.size === 0) return;
            const ids = Array.from(this.selectedIds);
            try {
                const res = await apiFetch('/ia/correcciones/bulk-reject', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ ids, rejected_reason: this.bulkRejectReason || null }),
                });
                if (res.ok) {
                    const d = await res.json();
                    this.showBulkReject = false;
                    this.showUndoToast(d, 'bulk_reject');
                    this.clearSelection();
                    await this.loadPending();
                } else {
                    const d = await res.json();
                    alert(d.error || 'Error');
                }
            } catch (e) {
                alert('Error de red al rechazar en lote.');
            }
        },

        // ===== Bulk destroy approved =====
        async bulkDestroyApproved() {
            if (this.approvedSelectedIds.size === 0) return;
            if (!confirm(`Vas a eliminar ${this.approvedSelectedIds.size} correcciones aprobadas. Esta acción NO se puede deshacer. ¿Continuar?`)) return;
            const ids = Array.from(this.approvedSelectedIds);
            try {
                const res = await apiFetch('/ia/correcciones/bulk-destroy', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ ids }),
                });
                if (res.ok) {
                    const d = await res.json();
                    this.approvedSelectedIds.clear();
                    window.location.reload();
                } else {
                    const d = await res.json();
                    alert(d.error || 'Error');
                }
            } catch (e) {
                alert('Error de red al eliminar.');
            }
        },

        // ===== Undo toast =====
        showUndoToast(result, action) {
            const messages = {
                bulk_approve: `${result.approved} aprobadas, ${result.merged} consolidadas${result.errors?.length ? `, ${result.errors.length} con error` : ''}`,
                bulk_reject:  `${result.rejected} rechazadas${result.errors?.length ? `, ${result.errors.length} con error` : ''}`,
                bulk_destroy: `${result.deleted} eliminadas (no reversible)`,
            };
            const icons = {
                bulk_approve: 'fa-check-circle text-green-400',
                bulk_reject:  'fa-times-circle text-red-400',
                bulk_destroy: 'fa-trash text-orange-400',
            };

            this.undoToast = {
                visible: true,
                title: 'Acción completada',
                detail: messages[action],
                icon: icons[action],
                bulkActionId: result.bulk_action_id,
                expiresAt: result.undo_expires_at ? new Date(result.undo_expires_at) : null,
                expired: result.undo_expires_at === null,
                countdown: result.undo_expires_at ? this.formatCountdown(new Date(result.undo_expires_at)) : '',
            };

            if (this.undoTickInterval) clearInterval(this.undoTickInterval);
            if (result.undo_expires_at) {
                this.undoTickInterval = setInterval(() => {
                    const remaining = this.formatCountdown(this.undoToast.expiresAt);
                    this.undoToast.countdown = remaining;
                    if (new Date() >= this.undoToast.expiresAt) {
                        this.undoToast.expired = true;
                        clearInterval(this.undoTickInterval);
                        this.undoTickInterval = null;
                        setTimeout(() => { this.undoToast.visible = false; }, 3000);
                    }
                }, 1000);
            } else {
                // bulk_destroy: hide después de 5s
                setTimeout(() => { this.undoToast.visible = false; }, 5000);
            }
        },

        async performUndo() {
            const id = this.undoToast.bulkActionId;
            if (!id) return;
            if (this.undoTickInterval) { clearInterval(this.undoTickInterval); this.undoTickInterval = null; }
            try {
                const res = await apiFetch('/ia/correcciones/undo/' + id, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });
                if (res.ok) {
                    const d = await res.json();
                    this.undoToast = {
                        visible: true,
                        title: 'Acción revertida',
                        detail: `${d.items_restored} correcciones restauradas a pending`,
                        icon: 'fa-undo text-brand-400',
                        bulkActionId: null,
                        expiresAt: null,
                        expired: false,
                        countdown: '',
                    };
                    await this.loadPending();
                    setTimeout(() => { this.undoToast.visible = false; }, 4000);
                } else {
                    const d = await res.json();
                    this.undoToast = {
                        visible: true,
                        title: 'No se pudo revertir',
                        detail: d.error || 'Error',
                        icon: 'fa-exclamation-triangle text-red-400',
                        bulkActionId: null,
                        expired: false,
                    };
                    setTimeout(() => { this.undoToast.visible = false; }, 4000);
                }
            } catch (e) {
                this.undoToast = {
                    visible: true,
                    title: 'Error de red al revertir',
                    detail: e.message,
                    icon: 'fa-exclamation-triangle text-red-400',
                    bulkActionId: null,
                    expired: false,
                };
            }
        },

        formatCountdown(expiresAt) {
            if (!expiresAt) return '';
            const seconds = Math.max(0, Math.round((expiresAt - new Date()) / 1000));
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        },

        // ===== New / single destroy / apply =====
        openNew() { this.form = { wrong: '', correct: '' }; this.showNew = true; },
        async saveNew() {
            const res = await apiFetch('/ia/correcciones', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showNew = false; window.location.reload(); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || 'Error'); }
        },
        async destroyApproved(id) {
            if (!confirm('¿Eliminar esta corrección aprobada?')) return;
            const res = await apiFetch('/ia/correcciones/' + id, {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) window.location.reload();
        },
        openApply() {
            this.applyResult = ''; this.applyError = '';
            this.runId = null; this.runStatusText = ''; this.runProgress = '';
            this.runProgressPct = 0; this.runFinished = false;
            this.runStuck = false; this.runStuckSinceText = '';
            if (this.runStuckTimer) { clearTimeout(this.runStuckTimer); this.runStuckTimer = null; }
            if (this.runPollTimer) { clearInterval(this.runPollTimer); this.runPollTimer = null; }
            this.showApply = true;
        },
        openApplyView() {
            // (corrections-retroactive-progress-modal) Abre el modal con vista de
            // progreso cuando hay run vivo (banner activo). NO resetea runId ni
            // el estado — es solo para ver el detalle y refrescar manualmente.
            this.showApply = true;
            this.applyResult = ''; this.applyError = '';
        },
        async refreshApplyNow() {
            // Refrescar estado al instante desde el modal de progreso.
            await this.pollRun();
        },
        closeApply() {
            // Si la corrida ya terminó, sí limpiamos el intervalo y refrescamos la página.
            // Si sigue viva, NO limpiamos — el banner del header sigue mostrando el progreso
            // y el poll sigue activo aunque el modal esté cerrado.
            if (this.runFinished) {
                if (this.runPollTimer) { clearInterval(this.runPollTimer); this.runPollTimer = null; }
                this.showApply = false;
                window.location.reload();
            } else {
                this.showApply = false;
            }
        },
        async runApply() {
            this.applying = true; this.applyResult = ''; this.applyError = '';
            const body = {};
            if (this.applyScope && this.applyScope !== 'all') {
                body.days_back = parseInt(this.applyScope, 10);
            }
            try {
                const res = await apiFetch('/ia/correcciones/apply-retroactive', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify(body),
                });
                if (res.status === 409) {
                    const d = await res.json();
                    if (d.runId) {
                        this.applyError = '';
                        this.attachToRun(d.runId);
                        return;
                    }
                    this.applyError = d.error || 'Ya hay una corrida en curso.';
                    this.applying = false;
                    return;
                }
                if (res.ok || res.status === 202) {
                    const d = await res.json();
                    this.runId = d.runId;
                    const scopeMsg = d.days_back ? ` (últimos ${d.days_back} días)` : ' (todos)';
                    this.runStatusText = 'Iniciando…' + scopeMsg;
                    this.pollRun();
                    this.runPollTimer = setInterval(() => this.pollRun(), 2000);
                } else {
                    // CSRF mismatch (419) es el caso típico cuando el admin borra
                    // cookies / la sesión expira. Distinguimos del resto.
                    if (res.status === 419) {
                        this.applyError = 'Tu sesión expiró. Recargá la página (Ctrl+Shift+R) y volvé a intentarlo.';
                    } else {
                        const d = await res.json().catch(() => ({}));
                        this.applyError = d.error || `Error HTTP ${res.status}`;
                    }
                    this.applying = false;
                }
            } catch (e) {
                this.applyError = 'Error de red al lanzar la corrida.';
                this.applying = false;
            }
        },
        async pollRun() {
            if (!this.runId) return;
            try {
                const res = await apiFetch('/ia/correcciones/apply-retroactive/' + this.runId, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) {
                    this.applyError = 'No se pudo leer el estado de la corrida.';
                    if (this.runPollTimer) { clearInterval(this.runPollTimer); this.runPollTimer = null; }
                    this.applying = false;
                    return;
                }
                const d = await res.json();
                this.runStatusText = this.statusLabel(d.status);

                // processed es el conteo real por chunk que escribe el comando
                // nuevo. Fallback a updated para corridas viejas / dry-run que
                // solo lo escriben al final (degradación amable — no rompe).
                const done = (typeof d.processed === 'number') ? d.processed
                    : ((typeof d.updated === 'number') ? d.updated : 0);
                const total = (typeof d.total === 'number') ? d.total : 0;
                this.runProgress = total > 0
                    ? `${done.toLocaleString('es-CO')} / ${total.toLocaleString('es-CO')} segmentos`
                    : `${done.toLocaleString('es-CO')} segmentos`;
                this.runProgressPct = total > 0
                    ? Math.min(100, Math.round((done / total) * 100))
                    : (d.status === 'done' ? 100 : 0);

                // Stuck detection: status=running Y heartbeat > 3 min.
                // No matamos el polling: una corrida viva retoma sola cuando
                // el próximo chunk renueve last_progress_at.
                if (d.status === 'running' && typeof d.last_progress_at === 'string') {
                    const last = Date.parse(d.last_progress_at);
                    const ageMs = isNaN(last) ? 0 : (Date.now() - last);
                    if (ageMs > 180000) {
                        if (!this.runStuck) {
                            this.runStuck = true;
                            this.runStuckSinceText = new Date(last).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
                        }
                    } else if (this.runStuck) {
                        this.runStuck = false;
                        this.runStuckSinceText = '';
                    }
                } else if (this.runStuck) {
                    this.runStuck = false;
                    this.runStuckSinceText = '';
                }

                if (d.status === 'done') {
                    this.runFinished = true; this.applying = false;
                    this.applyResult = `${d.updated.toLocaleString('es-CO')} segmentos actualizados`;
                    if (this.runPollTimer) { clearInterval(this.runPollTimer); this.runPollTimer = null; }
                    this.runStuck = false;
                } else if (d.status === 'error') {
                    this.runFinished = true; this.applying = false;
                    this.applyError = d.error_message || 'La corrida falló.';
                    if (this.runPollTimer) { clearInterval(this.runPollTimer); this.runPollTimer = null; }
                    if (this.runStuckTimer) { clearTimeout(this.runStuckTimer); this.runStuckTimer = null; }
                    this.runStuck = false;
                }
            } catch (e) { /* silenciar para reintento */ }
        },
        statusLabel(status) {
            return ({ queued: 'En cola…', running: 'Procesando…', done: 'Terminada', error: 'Falló' })[status] || (status || 'desconocido');
        },
        attachToRun(runId) {
            this.runId = runId;
            this.applying = true;
            this.runFinished = false;
            this.runStuck = false; this.runStuckSinceText = '';
            this.applyResult = ''; this.applyError = '';
            this.runStatusText = 'Re-adjuntando…';
            this.pollRun();
            if (this.runPollTimer) clearInterval(this.runPollTimer);
            this.runPollTimer = setInterval(() => this.pollRun(), 2000);
        },
        async attachToActiveRun() {
            // Llamado desde init(): si hay una corrida viva, nos re-adjuntamos.
            // Si no, 204 del backend y no hacemos nada.
            try {
                const res = await apiFetch('/ia/correcciones/apply-retroactive-active', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.status === 200) {
                    const d = await res.json();
                    this.runId = d.runId;
                    this.runStatusText = this.statusLabel(d.status);
                    this.runProgress = '';
                    this.runProgressPct = 0;
                    this.runFinished = false;
                    this.pollRun();
                    this.runPollTimer = setInterval(() => this.pollRun(), 2000);
                }
                // 204: no hay activa — ignorar.
            } catch (e) { /* re-attach best-effort */ }
        },

        // ===== AI Suggest modal (corrections-ai-suggest-context-aware) =====
        openAiSuggest() {
            this.aiSuggest.modal = true;
            this.aiSuggest.result = null;
            this.aiSuggest.error = null;
            this.aiSuggest.inserted = false;
            this.aiSuggest.insertedCount = 0;
        },

        // ===== Triage pendientes (2026-08-18-corrections-coherence-learn-fix-and-pending-triage) =====
        openTriage() {
            this.triage.modal = true;
            this.triage.dryRun = true;
            this.triage.autoApproveKeep = false;
            this.triage.error = null;
            this.triage.report = null;
            this.triage.layers = [];
        },

        async startTriage() {
            this.triage.error = null;
            this.triage.modal = false;
            this.triage.progress = true;
            this.triage.running = true;
            this.triage.layers = [];
            try {
                const res = await fetch('{{ url("/ia/correcciones/triage-pending") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({
                        dry_run: this.triage.dryRun,
                        auto_approve_keep: this.triage.autoApproveKeep,
                        max: 10000,
                    }),
                });
                const json = await res.json();
                if (!res.ok) {
                    this.triage.running = false;
                    this.triage.error = json.error || ('HTTP ' + res.status);
                    this.triage.progress = false;
                    return;
                }
                this.triage.runId = json.run_id;
                this.triage.layers = json.layers || [];
                this.triage.survivorsForReview = json.survivors_for_review || 0;
                this.triage.autoApproveCandidates = json.auto_approve_candidates || 0;
                this.triage.bulkActionId = json.bulk_action_id || null;
                this.triage.undoExpiresAt = json.undo_expires_at || null;

                if (json.status === 'done') {
                    // Corrida muy corta, ya terminó.
                    this.finishTriage(json);
                } else {
                    // Polling cada 2s.
                    this.triage.pollTimer = setInterval(() => this.pollTriage(), 2000);
                }
            } catch (e) {
                this.triage.running = false;
                this.triage.error = e.message || 'Error de red';
                this.triage.progress = false;
            }
        },

        async pollTriage() {
            if (!this.triage.runId) return;
            try {
                const res = await fetch(`{{ url("/ia/correcciones/triage-pending") }}/${encodeURIComponent(this.triage.runId)}`, {
                    headers: { 'Accept': 'application/json' },
                });
                const json = await res.json();
                if (!res.ok) {
                    // Probablemente expiró el cache; paramos polling.
                    if (this.triage.pollTimer) { clearInterval(this.triage.pollTimer); this.triage.pollTimer = null; }
                    return;
                }
                this.triage.layers = json.layers || [];
                this.triage.survivorsForReview = json.survivors_for_review || 0;
                this.triage.autoApproveCandidates = json.auto_approve_candidates || 0;
                if (json.bulk_action_id) { this.triage.bulkActionId = json.bulk_action_id; }
                if (json.undo_expires_at) { this.triage.undoExpiresAt = json.undo_expires_at; }
                if (json.status === 'done' || json.status === 'error') {
                    if (this.triage.pollTimer) { clearInterval(this.triage.pollTimer); this.triage.pollTimer = null; }
                    this.finishTriage(json);
                }
            } catch (e) { /* tolerate transient */ }
        },

        finishTriage(json) {
            this.triage.running = false;
            this.triage.report = json;
            this.triage.progress = false;
            // Refrescar la lista de pendientes y el contador del header.
            this.fetchPending();
            if (this.triage.bulkActionId && this.triage.undoExpiresAt) {
                const exp = new Date(this.triage.undoExpiresAt);
                this.showToast('success',
                    `Triage: ${this.triage.autoApproveCandidates} auto-aprobadas`,
                    `Deshacer disponible hasta ${exp.toLocaleTimeString()}`
                );
            } else {
                this.showToast('success',
                    `Triage aplicado: ${this.triage.survivorsForReview} para revisión`,
                    'Recargá la vista de pendientes para verlas.'
                );
            }
        },

        async undoTriage() {
            if (!this.triage.bulkActionId) return;
            if (!confirm('¿Deshacer el auto-approve del triage?')) return;
            try {
                const res = await fetch(`{{ url("/ia/correcciones/undo") }}/${encodeURIComponent(this.triage.bulkActionId)}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                });
                const json = await res.json();
                if (res.ok) {
                    this.showToast('success', 'Triage deshecho', 'Las correcciones volvieron a pending.');
                    this.triage.bulkActionId = null;
                    this.fetchPending();
                } else {
                    this.showToast('error', 'No se pudo deshacer', json.error || ('HTTP ' + res.status));
                }
            } catch (e) {
                this.showToast('error', 'No se pudo deshacer', e.message);
            }
        },

        closeTriageProgress() {
            if (this.triage.pollTimer) { clearInterval(this.triage.pollTimer); this.triage.pollTimer = null; }
            this.triage.progress = false;
            this.triage.running = false;
        },

        closeAiSuggest() {
            // Permitir cerrar incluso durante un insert en vuelo: el finally
            // del runAiSuggest igual resetea `running` y limpia el toast.
            // Sin esto, un insert "stuck" bloquea el modal indefinidamente.
            this.aiSuggest.modal = false;
            // Si se acaban de insertar, refrescar el listado de pending y
            // el status (para que aparezca el nuevo badge "X pendientes").
            if (this.aiSuggest.inserted) {
                this.loadPending();
                this.loadAiSuggestStatus();
            }
        },

        async runAiSuggest(insert) {
            if (this.aiSuggest.running) return;
            this.aiSuggest.running = true;
            this.aiSuggest.error = null;
            this.aiSuggest.inserted = !!insert;
            this.aiSuggest.result = null;
            this.aiSuggest.insertedCount = 0;

            // Safety net: si el POST tarda más de esto, forzamos reset y
            // mostramos error de timeout. Defensa ante cuelgues del gateway
            // o del browser que dejaban el botón "thinking" indefinidamente.
            let timedOut = false;
            const timeoutMs = 120000; // 2 min — el suggester normalmente <60s.
            const timeoutHandle = setTimeout(() => {
                timedOut = true;
                this.aiSuggest.running = false;
                this.aiSuggest.error = `Timeout (>${Math.round(timeoutMs/1000)}s) sin respuesta del gateway.`;
                this.aiSuggest.inserted = false;
            }, timeoutMs);

            try {
                // Flujo "Insertar" después de preview: NO re-llama al LLM.
                // Reusa los candidatos ya mostrados en el modal y los persiste
                // directamente. Tarda <1s típico en lugar de 5-30s del LLM.
                if (insert && this.aiSuggest.result?.candidates?.length > 0) {
                    const saved = await this.runAiSuggestSave(this.aiSuggest.result.candidates, this.aiSuggest.result.source);
                    if (timedOut) return;
                    this.aiSuggest.insertedCount = saved.inserted_count ?? 0;
                    if (this.aiSuggest.insertedCount > 0) {
                        const source = saved.source ?? 'ai-suggest';
                        const skipped = saved.skipped_duplicate ?? 0;
                        this.closeAiSuggest();
                        this.aiSuggestToast.show(
                            `AI Suggest insert OK`,
                            `Insertados: ${this.aiSuggest.insertedCount} · ` +
                            `Saltados (duplicados): ${skipped} · Source: ${source}. ` +
                            `Pendientes ya en la lista.`,
                            'success',
                        );
                    } else {
                        // 0 insertados: ya estaban todos en pending o approved.
                        this.aiSuggest.result = {
                            candidates: [],
                            rejected_by_filter: [],
                            segments_processed: this.aiSuggest.result.segments_processed ?? 0,
                            cached_today: this.aiSuggest.result.cached_today ?? 0,
                            source: (saved.source ?? '') + ' (todos ya en diccionario)',
                        };
                    }
                    return;
                }

                // Flujo dry-run o 1-click (sin preview): llamar al LLM.
                const res = await apiFetch('/ia/correcciones/ai-suggest-now', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        days: parseInt(this.aiSuggest.days),
                        sample: parseInt(this.aiSuggest.sample),
                        insert: !!insert,
                    }),
                });
                const data = await res.json();
                if (timedOut) return; // Ya marcamos error arriba.
                if (!res.ok) {
                    const errMsg = data.detail
                        ? `${data.error || ('HTTP ' + res.status)}: ${data.detail}`
                        : (data.error || ('HTTP ' + res.status));
                    this.aiSuggest.error = `HTTP ${res.status} — ${errMsg}`;
                    this.aiSuggest.inserted = false;
                    return;
                }
                if (data.error) {
                    const errMsg = data.detail ? `${data.error}: ${data.detail}` : data.error;
                    this.aiSuggest.error = `Error — ${errMsg}`;
                    this.aiSuggest.inserted = false;
                    return;
                }
                if (insert && !this.aiSuggest.result) {
                    // Caso raro: insert=true pero no hay preview previo. Caer
                    // al handler equivalente del endpoint original.
                    this.aiSuggest.insertedCount = data.inserted_count ?? 0;
                    if (this.aiSuggest.insertedCount > 0) {
                        this.closeAiSuggest();
                        this.aiSuggestToast.show(
                            'AI Suggest insert OK',
                            `Insertados: ${this.aiSuggest.insertedCount} · Source: ${data.source ?? 'ai-suggest'}.`,
                            'success',
                        );
                    } else {
                        this.aiSuggest.result = {
                            candidates: data.candidates ?? [],
                            rejected_by_filter: data.rejected_by_filter ?? [],
                            segments_processed: data.segments_processed ?? 0,
                            cached_today: data.cached_today ?? 0,
                            source: (data.source ?? '') + ' (0 insertados)',
                        };
                    }
                } else {
                    // Modo dry-run: llenar resultados en el modal.
                    this.aiSuggest.result = {
                        candidates: data.candidates ?? [],
                        rejected_by_filter: data.rejected_by_filter ?? [],
                        segments_processed: data.segments_processed ?? 0,
                        cached_today: data.cached_today ?? 0,
                        source: data.source ?? '',
                    };
                }
            } catch (e) {
                if (!timedOut) {
                    this.aiSuggest.error = 'Error de red: ' + (e?.message || e);
                    this.aiSuggest.inserted = false;
                }
            } finally {
                clearTimeout(timeoutHandle);
                this.aiSuggest.running = false;
            }
        },

        async runAiSuggestSave(candidates, source) {
            // POST que persiste los candidatos ya previsualizados.
            // Diseñado para responder en <500ms (sin LLM, sin muestrear BD).
            const res = await apiFetch('/ia/correcciones/ai-suggest-save', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    candidates,
                    source: source || ('ai-suggest-' + new Date().toISOString().slice(0, 10)),
                }),
            });
            const data = await res.json();
            if (!res.ok) {
                const errMsg = data.detail ? `${data.error || res.status}` : (data.error || ('HTTP ' + res.status));
                this.aiSuggest.error = `Save: ${errMsg}`;
                this.aiSuggest.inserted = false;
                throw new Error(errMsg);
            }
            if (data.error) {
                this.aiSuggest.error = data.error;
                this.aiSuggest.inserted = false;
                throw new Error(data.error);
            }
            return data;
        },

        // ===== AI Suggest 1-click quick action (Hoy / 3d) =====

        /**
         * Lanza el suggester sin modal: defaults configurados, días del
         * argumento, insert=true (gasto controlado). Toast efímero al final.
         */
        quickActionLabel(days) {
            // 1 → 'Hoy' (caso humano legible), resto → 'Nd' corto.
            if (days === 1) return 'Hoy';
            if (days === 7) return '7d';
            if (days === 14) return '14d';
            if (days === 30) return '30d';
            return days + 'd';
        },

        async aiSuggestQuickInsert(days) {
            if (this.aiSuggest.running) return;
            this.aiSuggest.running = true;
            this.aiSuggestQuick = days;
            this.aiSuggest.error = null;

            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-now', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        days: parseInt(days),
                        sample: parseInt(this.aiSettings.form.sample_size ?? 200),
                        insert: true,
                    }),
                });
                const data = await res.json();
                if (!res.ok) {
                    // Mostrar error + detail cuando está disponible
                    // (502 trae 'error: LLM upstream falló' + 'detail: HTTP 400 ...').
                    const errMsg = data.detail
                        ? `${data.error || ('HTTP ' + res.status)}: ${data.detail}`
                        : (data.error || ('HTTP ' + res.status));
                    this.aiSuggestToast.show('Error ' + res.status, errMsg, 'error');
                    return;
                }
                if (data.error) {
                    const errMsg = data.detail ? `${data.error}: ${data.detail}` : data.error;
                    this.aiSuggestToast.show('Error', errMsg, 'error');
                    return;
                }
                const detail = (
                    `Insertados: ${data.inserted_count ?? 0} · ` +
                    `Rechazados: ${data.rejected_by_filter ?? 0} · ` +
                    `Segments: ${data.segments_processed ?? 0} · ` +
                    `Source: ${data.source ?? '?'}`
                );
                this.aiSuggestToast.show(`AI Suggest ${days}d OK`, detail, 'success');
                await this.loadPending();
                await this.loadAiSuggestStatus();
            } catch (e) {
                this.aiSuggestToast.show('Error de red', e?.message || String(e), 'error');
            } finally {
                this.aiSuggest.running = false;
                this.aiSuggestQuick = null;
            }
        },

        // ===== AI Settings tab (2026-08-01 UI settings) =====

        async loadAiSettings() {
            this.aiSettings.loading = true;
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.aiSettings.list = data.settings || {};
                    this.aiSettings.hasApiKey = data.has_api_key;
                    this.aiSettings.apiKeySource = data.api_key_source || 'none';
                    this.aiSettings.form = {};
                    for (const key of Object.keys(this.aiSettings.list)) {
                        this.aiSettings.form[key] = this.aiSettings.list[key].value;
                    }
                    this.aiSettings.dirty = {};
                }
            } finally {
                this.aiSettings.loading = false;
            }
        },

        async saveApiKey() {
            if (this.aiSettings.savingApiKey) return;
            const value = (this.aiSettings.apiKeyInput || '').trim();
            if (value === '' && this.aiSettings.apiKeySource === 'env') {
                // No hay nada que guardar — el .env sigue siendo la fuente.
                this.aiSuggestToast.show('Info', 'No se ingresó valor; el .env sigue activo.', 'success');
                return;
            }
            this.aiSettings.savingApiKey = true;
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings/api-key', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ api_key: value }),
                });
                const data = await res.json();
                if (!res.ok) {
                    this.aiSuggestToast.show('Error', data.error || ('HTTP ' + res.status), 'error');
                    return;
                }
                this.aiSettings.apiKeySource = data.api_key_source;
                this.aiSettings.hasApiKey = data.has_api_key;
                this.aiSettings.apiKeyInput = '';
                this.aiSuggestToast.show(
                    data.cleared ? 'API key borrada' : 'API key guardada',
                    data.cleared
                        ? 'Volviendo al .env (si está configurado).'
                        : 'Cifrada en SystemSetting. Aplica al próximo request.',
                    'success',
                );
                // Si se guardó una key nueva, forzar refetch del modelo
                // (la lista estaba vacía sin auth) y refrescar settings.
                if (!data.cleared) {
                    await this.refreshGatewayModels('model');
                }
            } catch (e) {
                this.aiSuggestToast.show('Error de red', e?.message || String(e), 'error');
            } finally {
                this.aiSettings.savingApiKey = false;
            }
        },

        async clearApiKey() {
            if (this.aiSettings.savingApiKey) return;
            if (!confirm('¿Borrar la API key cifrada de la BD? El suggester volverá al .env (si está configurado).')) return;
            this.aiSettings.apiKeyInput = '';
            await this.saveApiKey();
        },

        async refreshGatewayModels(key) {
            this.aiSettings.refreshingModels = true;
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings/refresh-models', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) {
                    this.aiSuggestToast.show('Error', 'No se pudo refrescar la lista de modelos.', 'error');
                    return;
                }
                const data = await res.json();
                const models = data.available_models || [];
                const ids = models.map(m => m.id);
                if (this.aiSettings.list[key]) {
                    this.aiSettings.list[key].options = ids;
                    this.aiSettings.list[key].options_meta = models;
                }
                this.aiSuggestToast.show(
                    'Modelos refrescados',
                    ids.length + ' modelos disponibles en el gateway',
                    'success',
                );
            } catch (e) {
                this.aiSuggestToast.show('Error', e?.message || String(e), 'error');
            } finally {
                this.aiSettings.refreshingModels = false;
            }
        },

        // Sub-componente inline para el picker de modelos con búsqueda + filtros.
        // Se invoca desde el template via x-data="modelPicker(key)".
        modelPicker(key) {
            return {
                key: key,
                openPicker: false,
                search: '',
                highlight: 0,
                filterFree: false,
                filterCustom: false,
                filterProvider: null,
                togglePicker() {
                    this.openPicker = !this.openPicker;
                    if (this.openPicker) {
                        setTimeout(() => this.$refs.search?.focus(), 50);
                    }
                },
                get allModels() {
                    const list = this.aiSettings.list?.[key];
                    if (!list) return [];
                    return list.options_meta || list.options || [];
                },
                get currentModelMeta() {
                    const selId = this.aiSettings.form?.[key];
                    return this.allModels.find(m => m.id === selId) || null;
                },
                get currentModelLabel() {
                    const meta = this.currentModelMeta;
                    if (!meta) return (this.aiSettings.form?.[key]) || '— seleccionar —';
                    return meta.name || meta.id;
                },
                get filteredModels() {
                    const q = (this.search || '').toLowerCase().trim();
                    return this.allModels.filter(m => {
                        if (this.filterFree && !m.is_free) return false;
                        if (this.filterCustom && !m.is_custom) return false;
                        if (this.filterProvider && m.provider !== this.filterProvider) return false;
                        if (!q) return true;
                        const hay = ((m.name || '') + ' ' + (m.id || '') + ' ' + (m.description || '') + ' ' + (m.provider || '')).toLowerCase();
                        return hay.includes(q);
                    });
                },
                get availableProviders() {
                    const set = new Set();
                    for (const m of this.allModels) {
                        if (m.provider) set.add(m.provider);
                    }
                    return Array.from(set).sort();
                },
                get provCounts() {
                    const out = {};
                    for (const m of this.allModels) {
                        const p = m.provider || '_other';
                        out[p] = (out[p] || 0) + 1;
                    }
                    return out;
                },
                get freeCount() {
                    return this.allModels.filter(m => m.is_free).length;
                },
                get customCount() {
                    return this.allModels.filter(m => m.is_custom).length;
                },
                get loading() {
                    return this.aiSettings.refreshingModels && this.allModels.length === 0;
                },
                resetFilters() {
                    this.search = '';
                    this.filterFree = false;
                    this.filterCustom = false;
                    this.filterProvider = null;
                },
                selectModel(id) {
                    this.aiSettings.form[key] = id;
                    this.markDirty(key);
                    this.openPicker = false;
                },
                moveHighlight(delta) {
                    const max = this.filteredModels.length - 1;
                    if (max < 0) return;
                    let next = this.highlight + delta;
                    if (next < 0) next = max;
                    if (next > max) next = 0;
                    this.highlight = next;
                    this.$nextTick(() => {
                        const el = this.$refs.search?.closest('.relative')?.querySelectorAll('button[type="button"]')?.[this.highlight + 2];
                        el?.scrollIntoView({ block: 'nearest' });
                    });
                },
                selectHighlighted() {
                    const m = this.filteredModels[this.highlight];
                    if (m) this.selectModel(m.id);
                },
            };
        },

        markDirty(key) {
            this.aiSettings.dirty[key] = true;
            this.aiSettings.saveOk = false;
            this.aiSettings.saveError = '';
        },

        async resetAiSetting(key) {
            if (!confirm('Restaurar ' + key + ' al default de .env?')) return;
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings', {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ keys: [key] }),
                });
                if (res.ok) {
                    await this.loadAiSettings();
                }
            } catch (e) {
                this.aiSettings.saveError = 'Error: ' + (e?.message || e);
            }
        },

        async resetAllAiSettings() {
            if (!confirm('Restaurar TODOS los valores al default de .env?')) return;
            this.aiSettings.saving = true;
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings', {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ keys: [] }),
                });
                if (res.ok) {
                    await this.loadAiSettings();
                    this.aiSettings.saveOk = true;
                }
            } catch (e) {
                this.aiSettings.saveError = 'Error: ' + (e?.message || e);
            } finally {
                this.aiSettings.saving = false;
            }
        },

        async saveAiSettings() {
            if (Object.keys(this.aiSettings.dirty).length === 0) return;
            this.aiSettings.saving = true;
            this.aiSettings.saveError = '';
            this.aiSettings.saveOk = false;
            const payload = {};
            for (const k of Object.keys(this.aiSettings.dirty)) {
                payload[k] = this.aiSettings.form[k];
            }
            try {
                const res = await apiFetch('/ia/correcciones/ai-suggest-settings', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ values: payload }),
                });
                const data = await res.json();
                if (!res.ok) {
                    this.aiSettings.saveError = data.error || 'HTTP ' + res.status;
                    return;
                }
                if (data.errors) {
                    this.aiSettings.saveError = Object.entries(data.errors).map(([k, v]) => k + ': ' + v[0]).join('; ');
                    return;
                }
                this.aiSettings.list = data.settings || {};
                // Reset dirty para los guardados, mantener los que no se enviaron.
                this.aiSettings.dirty = {};
                this.aiSettings.saveOk = true;
                // Si cambió quick_action_windows, refrescar botones del header.
                if (Object.keys(payload).includes('quick_action_windows')) {
                    const fresh = (this.aiSettings.form.quick_action_windows || '').split(/[\s,]+/).filter(s => s.trim()).map(s => parseInt(s, 10)).filter(v => Number.isFinite(v) && v >= 1 && v <= 90);
                    this.quickActionWindows = fresh.length ? fresh.sort((a, b) => a - b) : [1, 3, 7];
                }
                setTimeout(() => { this.aiSettings.saveOk = false; }, 4000);
            } catch (e) {
                this.aiSettings.saveError = 'Error de red: ' + (e?.message || e);
            } finally {
                this.aiSettings.saving = false;
            }
        },

        formatDate(d) { return d ? new Date(d).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) : '—'; },
    };
}
</script>
@endpush
@endsection
