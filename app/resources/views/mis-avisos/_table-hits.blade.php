{{-- Tabla compartida de coincidencias. Parámetros: $mode ('live'|'history').
    Consume el estado Alpine del componente raíz (liveRows / historyRows). --}}
{{-- Paginación superior (idéntica a la inferior — un solo partial) --}}
@include('mis-avisos._pagination', ['mode' => $mode, 'position' => 'top'])

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Fecha / hora</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Emisora</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Archivo</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Keyword</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap" title="Veces que la keyword aparece en toda esta grabación">Apariciones</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Minuto</th>
                <th class="py-2.5 pr-3 font-medium">Contexto</th>
                <th class="py-2.5 font-medium text-right whitespace-nowrap">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <template x-for="row in ({{ $mode === 'live' ? 'liveRows' : 'historyRows' }})" :key="row.id">
                <tr class="hover:bg-slate-50/60 align-top">
                    <td class="py-3 pr-3 whitespace-nowrap text-xs text-slate-500"
                        x-text="(row.matched_at || '').replace('T',' ').slice(0, 16)"></td>
                    <td class="py-3 pr-3 whitespace-nowrap text-xs text-slate-600"
                        x-text="row.storage || '—'"></td>
                    <td class="py-3 pr-3 max-w-[220px]">
                        <span class="text-slate-600 break-words" :class="row.can_view_file && row.file_id ? 'cursor-pointer hover:text-brand-700' : ''"
                              x-text="row.filename"></span>
                    </td>
                    <td class="py-3 pr-3 whitespace-nowrap">
                        <span class="px-2 py-0.5 bg-brand-50 text-brand-700 rounded text-xs" x-text="row.keyword"></span>
                    </td>
                    <td class="py-3 pr-3 whitespace-nowrap">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-flex items-center justify-center min-w-[2.2rem] px-2 py-1 rounded-lg text-sm font-bold"
                                  :class="row.occurrences_in_media > 1 ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-500'"
                                  :title="row.occurrences_in_media + ' apariciones de la keyword en esta grabación'"
                                  x-text="'×' + row.occurrences_in_media"></span>
                            <span class="text-[11px] text-slate-400 leading-tight"
                                  x-text="row.occurrences > 1 ? ('×' + row.occurrences + ' aquí') : ''"></span>
                        </div>
                    </td>
                    <td class="py-3 pr-3 whitespace-nowrap">
                        <span class="font-mono text-xs text-slate-600" x-text="row.minute_label"></span>
                    </td>
                    <td class="py-3 pr-3 text-slate-600 min-w-[200px] max-w-[380px]">
                        <span class="line-clamp-2" x-text="row.snippet"></span>
                    </td>
                    <td class="py-3 whitespace-nowrap text-right">
                        <div class="inline-flex items-center gap-1.5">
                    <button @click="openTranscript(row, { autoplay: true })"
                            class="px-2.5 py-1.5 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium transition-all hover:shadow-lg hover:-translate-y-0.5 active:scale-95"
                            :title="'Ver el archivo con su transcripción desde el minuto ' + row.minute_label">
                                <i class="fas fa-play mr-1"></i>Ver
                            </button>
                            <button x-show="row.can_clip" @click="openClipFromRow(row)"
                                    class="px-2.5 py-1.5 text-xs bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium transition-all hover:shadow-lg hover:-translate-y-0.5 active:scale-95"
                                    title="Generar corte del medio desde este minuto">
                                <i class="fas fa-scissors mr-1"></i>Editor
                            </button>
                            <template x-if="row.can_view_file && row.file_id">
                                <a :href="filesDeepLink(row)" target="_blank" rel="noopener"
                                   class="px-2.5 py-1.5 text-xs bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition-all hover:shadow-lg hover:-translate-y-0.5 active:scale-95"
                                   title="Abrir en Mis Archivos, en la carpeta de las grabaciones de este medio">
                                    <i class="fas fa-folder-open mr-1"></i>Archivos
                                </a>
                            </template>
                        </div>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    {{-- Estado vacío --}}
    <div x-show="{{ $mode === 'live' ? 'liveRows.length === 0' : 'historySearched && historyRows.length === 0' }}"
         class="text-center py-10 text-slate-400">
        <i class="fas text-3xl mb-2 block text-slate-200"
           :class="{{ $mode === 'live' ? "'fa-satellite-dish'" : "'fa-folder-open'" }}"></i>
        <p class="font-medium">{{ $mode === 'live' ? 'Sin coincidencias todavía hoy' : 'Sin resultados para esos filtros' }}</p>
        <p class="text-sm mt-1">{{ $mode === 'live' ? 'Aquí aparecen en cuanto tus palabras se mencionen.' : 'Prueba ampliar el rango de fechas o cambiar los filtros.' }}</p>
    </div>
</div>

{{-- Paginación inferior (idéntica a la superior — un solo partial) --}}
@include('mis-avisos._pagination', ['mode' => $mode, 'position' => 'bottom'])
