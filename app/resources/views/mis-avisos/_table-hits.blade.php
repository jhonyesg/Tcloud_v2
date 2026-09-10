{{-- Tabla compartida de coincidencias. Parámetros: $mode ('live'|'history').
     Vista AGRUPADA por (archivo + keyword) con accordion (ver change add-history-grouped-view).
     El backend sigue devolviendo un row por cada match real — la agrupación
     sucede en el getter displayHistoryRows / displayLiveRows del componente padre.
     Estado default: colapsado (1 fila resumen por archivo+keyword); el chevron
     abre la lista de menciones reales con su minuto + snippet + botones de acción.

     change 2026-09-10-mis-avisos-program-date-filter: la columna "Fecha" histórica
     (basada en matched_at) pasa a llamarse "Detectado" y se le agrega una columna
     "Programa" (recorded_at) como primaria. colspan dinámico sube de 8 a 9. --}}
@include('mis-avisos._pagination', ['scope' => $mode, 'position' => 'top'])

<div class="overflow-x-auto">
    <table class="w-full text-sm">
    <thead>
        <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap" style="width: 2.2rem"></th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'recorded_at')" :class="sortHeaderClass('{{ $mode }}Filters', 'recorded_at') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por fecha del programa">
                    Programa
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'recorded_at') + ' ' + sortIconClass('{{ $mode }}Filters', 'recorded_at')"></i>
                </button>
            </th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap text-slate-400">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'matched_at')" :class="sortHeaderClass('{{ $mode }}Filters', 'matched_at') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por fecha de detección">
                    Detectado
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'matched_at') + ' ' + sortIconClass('{{ $mode }}Filters', 'matched_at')"></i>
                </button>
            </th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'storage')" :class="sortHeaderClass('{{ $mode }}Filters', 'storage') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por medio">
                    Emisora
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'storage') + ' ' + sortIconClass('{{ $mode }}Filters', 'storage')"></i>
                </button>
            </th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'filename')" :class="sortHeaderClass('{{ $mode }}Filters', 'filename') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por archivo">
                    Archivo
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'filename') + ' ' + sortIconClass('{{ $mode }}Filters', 'filename')"></i>
                </button>
            </th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'keyword')" :class="sortHeaderClass('{{ $mode }}Filters', 'keyword') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por keyword">
                    Keyword
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'keyword') + ' ' + sortIconClass('{{ $mode }}Filters', 'keyword')"></i>
                </button>
            </th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap" title="Total de veces que la keyword aparece en toda esta grabación">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'occurrences')" :class="sortHeaderClass('{{ $mode }}Filters', 'occurrences') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por apariciones">
                    Apariciones
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'occurrences') + ' ' + sortIconClass('{{ $mode }}Filters', 'occurrences')"></i>
                </button>
            </th>
            <th class="py-2.5 pr-3 font-medium whitespace-nowrap">
                <button type="button" @click="setSort('{{ $mode }}Filters', 'snippet')" :class="sortHeaderClass('{{ $mode }}Filters', 'snippet') + ' inline-flex items-center gap-1.5 transition-colors'" title="Ordenar por contexto">
                    Contexto
                    <i class="fas text-[10px]" :class="sortIcon('{{ $mode }}Filters', 'snippet') + ' ' + sortIconClass('{{ $mode }}Filters', 'snippet')"></i>
                </button>
            </th>
            <th class="py-2.5 font-medium text-right whitespace-nowrap">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            {{-- Fila resumen + detail anidado en la misma fila (colspan dinámico).
                 Cuando está expandido, la fila "crece" mostrando el listado
                 de menciones. Esto evita el problema de tener dos x-for paralelos
                 que ponían todos los details al final del tbody. --}}
            <template x-for="g in (activeTab === 'live' ? displayLiveRows : displayHistoryRows)" :key="'g-' + g.key">
                <tr class="align-top"
                    :class="isGroupExpanded(g.key) ? 'bg-amber-50/30' : 'hover:bg-slate-50/60'">
                    <td :colspan="isGroupExpanded(g.key) ? 9 : 1" class="py-3 pr-2 whitespace-nowrap align-top">
                        <button x-show="!isGroupExpanded(g.key)"
                                @click="toggleGroupExpansion(g.key)"
                                :title="'Ver las ' + g.hits.length + ' menciones'"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-md hover:bg-amber-100 text-slate-500 hover:text-amber-700 transition-colors">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </button>
                    </td>
                    {{-- change 2026-09-10-mis-avisos-program-date-filter: ahora
                         se muestran DOS fechas: "Programa" (recorded_at, principal)
                         y "Detectado" (matched_at, secundario, estilo atenuado). --}}
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 whitespace-nowrap align-top"
                        x-text="(g.first_recorded_at || '').replace('T',' ').slice(0, 16) || '—'"></td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 whitespace-nowrap text-[10px] text-slate-400 align-top"
                        x-text="(g.first_matched_at || '').replace('T',' ').slice(0, 16) || '—'"></td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 whitespace-nowrap text-xs text-slate-600 align-top"
                        x-text="g.storage || '—'"></td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 max-w-[420px] align-top">
                        {{-- change mis-avisos-media-kind-indicator (G5): badge TV/Radio al inicio del filename.
                             El filename con `break-words` (en vez de truncate) se
                             wrap-a-2-lineas automáticamente; el `title` muestra el
                             nombre completo al hover. Sin truncar = sin
                             elipsis feos en medio del nombre del archivo.
                             `shrink-0` en el badge evita que se aplaste. --}}
                        <span class="text-slate-600 flex items-start gap-1.5"
                              :class="g.can_view_file && g.file_id ? 'cursor-pointer hover:text-brand-700' : ''"
                              @click="g.can_view_file && g.file_id ? Alpine.store('transcriptViewer').openRow({ ...g, id: g.first_id, start_seconds: g.first_start_seconds, segment_id: g.first_segment_id }, { autoplay: true }) : null">
                            <template x-if="g.first_media_kind === 'tv'">
                                <span class="shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-md bg-gradient-to-br from-violet-500 to-indigo-600 text-white text-[10px] shadow-sm shadow-violet-500/20 transition-transform hover:scale-110 hover:shadow-violet-500/40 hover:shadow-md"
                                      title="Video / TV">
                                    <i class="fas fa-tv"></i>
                                </span>
                            </template>
                            <template x-if="g.first_media_kind === 'radio'">
                                <span class="shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-md bg-gradient-to-br from-amber-500 to-orange-500 text-white text-[10px] shadow-sm shadow-amber-500/20 transition-transform hover:scale-110 hover:shadow-amber-500/40 hover:shadow-md"
                                      title="Audio / Radio">
                                    <i class="fas fa-radio"></i>
                                </span>
                            </template>
                            <span class="break-words min-w-0" :title="g.filename" x-text="g.filename"></span>
                        </span>
                    </td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 whitespace-nowrap align-top">
                        <span class="px-2 py-0.5 bg-brand-50 text-brand-700 rounded text-xs" x-text="g.keyword"></span>
                    </td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 whitespace-nowrap align-top">
                        <span class="inline-flex items-center justify-center min-w-[2.4rem] px-2 py-1 rounded-lg text-sm font-bold"
                              :class="g.occurrences_in_media > 1 ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-500'"
                              :title="g.occurrences_in_media + ' apariciones de la keyword en toda esta grabación'"
                              x-text="'×' + g.occurrences_in_media"></span>
                    </td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 pr-3 text-slate-600 min-w-[200px] max-w-[380px] align-top">
                        <span class="line-clamp-2 italic text-slate-500 text-sm"
                              x-text="'…' + (g.first_snippet || '').slice(0, 110) + (g.first_snippet && g.first_snippet.length > 110 ? '…' : '')"></span>
                        <span class="text-xs text-slate-400 ml-2 font-mono"
                              x-text="g.first_minute_label || ''"></span>
                    </td>
                    <td x-show="!isGroupExpanded(g.key)" class="py-3 whitespace-nowrap text-right align-top">
                        <div class="inline-flex items-center gap-1.5">
                            <button @click="Alpine.store('transcriptViewer').openRow({ ...g, id: g.first_id, start_seconds: g.first_start_seconds, segment_id: g.first_segment_id }, { autoplay: true })"
                                    class="px-2.5 py-1.5 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium"
                                    :title="'Ver la primera mención (minuto ' + g.first_minute_label + ')'">
                                <i class="fas fa-play mr-1"></i>Ver
                            </button>
                            <button x-show="g.can_clip" @click="openClipFromRow({ ...g, id: g.first_id, start_seconds: g.first_start_seconds })"
                                    class="px-2.5 py-1.5 text-xs bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium"
                                    title="Generar corte del medio desde este minuto">
                                <i class="fas fa-scissors mr-1"></i>Editor
                            </button>
                            <template x-if="g.can_view_file && g.file_id">
                                <a :href="filesDeepLink({ ...g, start_seconds: g.first_start_seconds })" target="_blank" rel="noopener"
                                   class="px-2.5 py-1.5 text-xs bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium"
                                   title="Abrir en Mis Archivos, en la carpeta de las grabaciones de este medio">
                                    <i class="fas fa-folder-open mr-1"></i>Archivos
                                </a>
                            </template>
                        </div>
                    </td>

                    {{-- Vista expandida dentro de la MISMA fila (colspan=8). Solo se
                         renderiza cuando isGroupExpanded(g.key)===true. La fila
                         "crece" en altura para mostrar las menciones reales. --}}
<td x-show="isGroupExpanded(g.key)" colspan="9" class="px-4 py-3 align-top">
                        <div class="mb-2 flex items-center justify-between">
                            <div class="text-xs text-slate-600 inline-flex items-center gap-1">
                                {{-- change 2026-09-10-mis-avisos-program-date-filter: ícono TV/Radio en el header del panel expandido --}}
                                <template x-if="g.first_media_kind === 'tv'">
                                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-md bg-gradient-to-br from-violet-500 to-indigo-600 text-white text-[10px] shadow-sm shadow-violet-500/20 shrink-0" title="Video / TV">
                                        <i class="fas fa-tv"></i>
                                    </span>
                                </template>
                                <template x-if="g.first_media_kind === 'radio'">
                                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-md bg-gradient-to-br from-amber-500 to-orange-500 text-white text-[10px] shadow-sm shadow-amber-500/20 shrink-0" title="Audio / Radio">
                                        <i class="fas fa-radio"></i>
                                    </span>
                                </template>
                                <span class="font-medium ml-1" x-text="g.filename"></span>
                                <span class="mx-1 text-slate-400">·</span>
                                <span class="px-2 py-0.5 bg-brand-50 text-brand-700 rounded text-xs" x-text="g.keyword"></span>
                                <span class="mx-1 text-slate-400">·</span>
                                <span class="inline-flex items-center justify-center min-w-[2.4rem] px-2 py-1 rounded-lg text-sm font-bold"
                                      :class="g.occurrences_in_media > 1 ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-500'"
                                      x-text="'×' + g.occurrences_in_media"></span>
                                <span class="ml-2 text-slate-600 font-mono" x-text="'Programa: ' + ((g.first_recorded_at || '').replace('T',' ').slice(0, 16) || '—')"></span>
                                <span class="ml-2 text-slate-400 font-mono text-[10px]" x-text="'Detectado: ' + ((g.first_matched_at || '').replace('T',' ').slice(0, 16) || '—')"></span>
                            </div>
                            <button @click="toggleGroupExpansion(g.key)"
                                    class="text-xs px-2 py-1 text-slate-500 hover:bg-amber-100 hover:text-amber-700 rounded">
                                <i class="fas fa-chevron-up"></i>
                                <span class="ml-1">Cerrar</span>
                            </button>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-white overflow-hidden">
                            <div class="px-3 py-2 text-xs font-semibold text-amber-800 border-b border-amber-100 bg-amber-50/50">
                                <i class="fas fa-list-ul mr-1"></i>
                                Las <span x-text="g.hits.length"></span> menciones de "<strong x-text="g.keyword"></strong>" en esta grabación:
                            </div>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="(hit, idx) in g.hits" :key="'hit-' + g.key + '-' + hit.id">
                                    <li class="flex flex-wrap items-center gap-3 px-3 py-2.5 text-xs text-slate-600">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-100 text-amber-700 font-bold text-xs"
                                              x-text="(idx + 1)"></span>
                                        <span class="font-mono text-slate-500" x-text="hit.minute_label"></span>
                                        {{-- change mis-avisos-media-kind-indicator: badge TV/Radio por hit --}}
                                        <template x-if="hit.media_kind === 'tv'">
                                            <span class="inline-flex items-center justify-center w-4 h-4 rounded bg-gradient-to-br from-violet-500 to-indigo-600 text-white text-[8px] shadow-sm shadow-violet-500/20 shrink-0" title="Video / TV">
                                                <i class="fas fa-tv"></i>
                                            </span>
                                        </template>
                                        <template x-if="hit.media_kind === 'radio'">
                                            <span class="inline-flex items-center justify-center w-4 h-4 rounded bg-gradient-to-br from-amber-500 to-orange-500 text-white text-[8px] shadow-sm shadow-amber-500/20 shrink-0" title="Audio / Radio">
                                                <i class="fas fa-radio"></i>
                                            </span>
                                        </template>
                                        <span class="inline-flex items-center gap-1 text-slate-400 italic" x-show="hit.occurrences > 1">
                                            ×<span x-text="hit.occurrences"></span> en este segmento
                                        </span>
                                        <span class="flex-1 min-w-[200px] text-slate-700" x-text="hit.snippet"></span>
                                        <button @click="Alpine.store('transcriptViewer').openRow({ ...g, id: hit.id, start_seconds: hit.start_seconds, segment_id: hit.segment_id }, { autoplay: true })"
                                                class="px-2.5 py-1 text-[11px] bg-brand-600 hover:bg-brand-700 text-white rounded font-medium"
                                                :title="'Ver el minuto ' + hit.minute_label">
                                            <i class="fas fa-play mr-1"></i>Ver
                                        </button>
                                    </li>
                                </template>
                            </ul>
                            <div class="px-3 py-2 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2">
                                <button @click="Alpine.store('transcriptViewer').openRow({ ...g, id: g.first_id, start_seconds: g.first_start_seconds, segment_id: g.first_segment_id }, { autoplay: true })"
                                        class="px-2.5 py-1.5 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium">
                                    <i class="fas fa-play mr-1"></i>Reproducir primera mención
                                </button>
                                <template x-if="g.can_clip">
                                    <button @click="openClipFromRow({ ...g, id: g.first_id, start_seconds: g.first_start_seconds })"
                                            class="px-2.5 py-1.5 text-xs bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium">
                                        <i class="fas fa-scissors mr-1"></i>Cortar
                                    </button>
                                </template>
                            </div>
                        </div>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    {{-- Estado vacío --}}
    <div x-show="{{ $mode === 'live' ? 'displayLiveRows.length === 0' : 'historySearched && displayHistoryRows.length === 0' }}"
         class="text-center py-10 text-slate-400">
        <i class="fas text-3xl mb-2 block text-slate-200"
           :class="{{ $mode === 'live' ? "'fa-satellite-dish'" : "'fa-folder-open'" }}"></i>
        <p class="font-medium">{{ $mode === 'live' ? 'Sin coincidencias todavía hoy' : 'Sin resultados para esos filtros' }}</p>
        <p class="text-sm mt-1">{{ $mode === 'live' ? 'Aquí aparecen en cuanto tus palabras se mencionen.' : 'Prueba ampliar el rango de fechas o cambiar los filtros.' }}</p>
    </div>
</div>

@include('mis-avisos._pagination', ['scope' => $mode, 'position' => 'bottom'])
