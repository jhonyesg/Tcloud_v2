{{-- Paginación compartida (arriba y abajo). Parámetros:
     $scope   - nombre del scope Alpine ('live' | 'history' | 'users')
     $position - 'top' o 'bottom' (solo afecta las keys de x-for)
     Lee {{ $scope }}Page / {{ $scope }}LastPage / {{ $scope }}Total / {{ $scope }}PerPage
     Llama goPage('{{ $scope }}', n) y setPerPage('{{ $scope }}', n).
     Idéntica en ambas posiciones — un solo lugar que mantener. --}}
<div class="flex items-center justify-between gap-2 flex-wrap" :class="''">
    <span class="text-xs text-slate-500"
          x-text="'Página ' + {{ $scope }}Page + ' de ' + {{ $scope }}LastPage + ' · ' + {{ $scope }}Total + ' ' + '{{ $scope === 'users' ? 'cliente(s)' : 'coincidencia(s)' }}'"></span>
    <div class="flex items-center gap-1.5">
        <button @click="goPage('{{ $scope }}', {{ $scope }}Page - 1)"
                :disabled="{{ $scope }}Page <= 1"
                class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                title="Página anterior">
            <i class="fas fa-chevron-left text-[10px]"></i>
        </button>
        <template x-for="p in pageList({{ $scope }}Page, {{ $scope }}LastPage)" :key="'{{ $scope }}-{{ $position }}-' + p">
            <button x-show="p !== '…'" @click="goPage('{{ $scope }}', p)"
                    class="min-w-[2.2rem] px-2 py-1.5 rounded-lg text-sm font-medium transition-all border"
                    :class="p === {{ $scope }}Page
                        ? 'bg-brand-600 text-white border-brand-600 shadow'
                        : 'border-slate-200 text-slate-600 hover:bg-brand-50 hover:border-brand-300'"
                    x-text="p"></button>
        </template>
        <button @click="goPage('{{ $scope }}', {{ $scope }}Page + 1)"
                :disabled="{{ $scope }}Page >= {{ $scope }}LastPage"
                class="inline-flex items-center justify-center w-9 py-1.5 rounded-lg text-sm font-medium transition-all border border-slate-300 text-slate-600 hover:bg-brand-50 hover:text-brand-700 disabled:opacity-30 disabled:pointer-events-none"
                title="Página siguiente">
            <i class="fas fa-chevron-right text-[10px]"></i>
        </button>
        <select @change="setPerPage('{{ $scope }}', $event.target.value)"
                class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm outline-none hover:border-brand-300 transition-colors"
                title="Resultados por página">
            <template x-for="n in [25, 50, 100{{ $scope !== 'users' ? ', 500' : '' }}]" :key="'{{ $scope }}-pp-{{ $position }}-' + n">
                <option :value="n" x-text="n + ' / pág.'" :selected="{{ $scope }}PerPage === n"></option>
            </template>
        </select>
    </div>
</div>
