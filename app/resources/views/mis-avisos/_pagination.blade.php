{{-- Paginación compartida (arriba y abajo). Parámetros: $mode ('live'|'history').
    Idéntica en ambas posiciones — un solo lugar que mantener. --}}
<div class="flex items-center justify-between gap-2 flex-wrap" :class="''">
    <span class="text-xs text-slate-500"
          x-text="'Página ' + ({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) + ' de ' + ({{ $mode === 'live' ? 'liveLastPage' : 'historyLastPage' }}) + ' · ' + ({{ $mode === 'live' ? 'liveTotal' : 'historyTotal' }}) + ' coincidencia(s)'"></span>
    <div class="flex items-center gap-1.5">
        <button @click="goPage('{{ $mode }}', ({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) - 1)"
                :disabled="({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) <= 1"
                class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                title="Página anterior">
            <i class="fas fa-chevron-left text-[10px]"></i>
        </button>
        <template x-for="p in pageList({{ $mode === 'live' ? 'livePage' : 'historyPage' }}, {{ $mode === 'live' ? 'liveLastPage' : 'historyLastPage' }})" :key="'{{ $mode }}-{{ $position }}-' + p">
            <button x-show="p !== '…'" @click="goPage('{{ $mode }}', p)"
                    class="min-w-[2.2rem] px-2 py-1.5 rounded-lg text-sm font-medium transition-all border"
                    :class="p === ({{ $mode === 'live' ? 'livePage' : 'historyPage' }})
                        ? 'bg-brand-600 text-white border-brand-600 shadow'
                        : 'border-slate-200 text-slate-600 hover:bg-brand-50 hover:border-brand-300'"
                    x-text="p"></button>
        </template>
        <button @click="goPage('{{ $mode }}', ({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) + 1)"
                :disabled="({{ $mode === 'live' ? 'livePage' : 'historyPage' }}) >= ({{ $mode === 'live' ? 'liveLastPage' : 'historyLastPage' }})"
                class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                title="Página siguiente">
            <i class="fas fa-chevron-right text-[10px]"></i>
        </button>
        <select @change="setPerPage('{{ $mode }}', $event.target.value)"
                class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm outline-none hover:border-brand-300 transition-colors"
                title="Resultados por página">
            <template x-for="n in [25, 50, 100, 500]" :key="'{{ $mode }}-pp-{{ $position }}-' + n">
                <option :value="n" x-text="n + ' / pág.'" :selected="({{ $mode === 'live' ? 'livePerPage' : 'historyPerPage' }}) === n"></option>
            </template>
        </select>
    </div>
</div>