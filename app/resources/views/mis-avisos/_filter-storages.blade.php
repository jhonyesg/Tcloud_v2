{{-- Dropdown multi-selección de emisoras (storages con acceso).
    Parámetro: $scope ('liveFilters'|'historyFilters') — ruta del estado Alpine. --}}
<div class="relative" x-data="{ open: false }">
    <button type="button" @click="open = !open"
            class="flex items-center gap-2 border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
        <i class="fas fa-tower-broadcast text-xs text-slate-400"></i>
        <span x-text="{{ $scope }}.storage_ids.length === 0
            ? 'Todas las emisoras'
            : {{ $scope }}.storage_ids.length + ' emisora(s)'"></span>
        <i class="fas fa-chevron-down text-[10px] text-slate-400"></i>
    </button>
    <div x-show="open" @click.outside="open = false" x-transition.opacity
         class="absolute z-30 mt-1 w-72 max-h-72 overflow-y-auto bg-white border border-slate-200 rounded-xl shadow-lg p-2">
        <label class="flex items-center gap-2.5 px-2 py-1.5 text-xs text-slate-500 hover:bg-slate-50 rounded cursor-pointer">
            <input type="checkbox"
                   :checked="{{ $scope }}.storage_ids.length === 0"
                   @change="{{ $scope }}.storage_ids = []"
                   class="w-3.5 h-3.5 accent-brand-600">
            Todas (sin filtro)
        </label>
        <div class="border-t border-slate-100 my-1"></div>
        <template x-for="st in storages" :key="st.id">
            <label class="flex items-center gap-2.5 px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50 rounded cursor-pointer">
                <input type="checkbox" :value="st.id"
                       :checked="{{ $scope }}.storage_ids.includes(st.id)"
                       @change="toggleFilterStorage('{{ $scope }}', st.id, $event.target.checked)"
                       class="w-3.5 h-3.5 accent-brand-600">
                <span x-text="st.name"></span>
            </label>
        </template>
        <div x-show="storages.length === 0" class="px-2 py-2 text-xs text-slate-400">Sin emisoras con acceso.</div>
    </div>
</div>
