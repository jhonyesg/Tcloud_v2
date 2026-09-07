{{-- Tabla compartida de coincidencias. Parámetros: $mode ('live'|'history').
    Consume el estado Alpine del componente raíz (liveRows / historyRows). --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Fecha / hora</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Emisora</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Archivo</th>
                <th class="py-2.5 pr-3 font-medium whitespace-nowrap">Keyword</th>
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
                    <td class="py-3 pr-3 whitespace-nowrap font-mono text-xs text-slate-600" x-text="row.minute_label"></td>
                    <td class="py-3 pr-3 text-slate-600 min-w-[200px] max-w-[380px]">
                        <span class="line-clamp-2" x-text="row.snippet"></span>
                    </td>
                    <td class="py-3 whitespace-nowrap text-right">
                        <div class="inline-flex items-center gap-1.5">
                            <button @click="openTranscript(row, { autoplay: true })"
                                    class="px-2.5 py-1.5 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium"
                                    :title="'Ver el archivo con su transcripción desde el minuto ' + row.minute_label">
                                <i class="fas fa-play mr-1"></i>Ver
                            </button>
                            <button x-show="row.can_clip" @click="openClipFromRow(row)"
                                    class="px-2.5 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg"
                                    title="Generar corte del medio desde este minuto">
                                <i class="fas fa-scissors mr-1"></i>Editor
                            </button>
                            <template x-if="row.can_view_file && row.file_id">
                                <a :href="filesDeepLink(row)" target="_blank" rel="noopener"
                                   class="px-2.5 py-1.5 text-xs border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg"
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

{{-- Paginación server-side compartida --}}
<div class="flex justify-between items-center mt-4"
     x-show="({{ $mode === 'live' ? 'liveLastPage' : 'historyLastPage' }}) > 1">
    <button @click="goPage('{{ $mode }}', ({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) - 1)"
            :disabled="({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) <= 1"
            class="text-sm text-brand-600 hover:underline disabled:opacity-30">← Anterior</button>
    <span class="text-xs text-slate-500"
          x-text="'Página ' + ({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) + ' de ' + ({{ $mode === 'live' ? 'liveLastPage' : 'historyLastPage' }}) + ' · ' + ({{ $mode === 'live' ? 'liveTotal' : 'historyTotal' }}) + ' coincidencia(s)'"></span>
    <button @click="goPage('{{ $mode }}', ({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) + 1)"
            :disabled="({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) >= ({{ $mode === 'live' ? 'liveLastPage' : 'historyLastPage' }})"
            class="text-sm text-brand-600 hover:underline disabled:opacity-30">Siguiente →</button>
</div>
