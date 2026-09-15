{{-- Dropdown multi-selección de medios (storages con acceso al cliente).
    Parámetro: $scope ('liveFilters'|'historyFilters') — ruta del estado Alpine. --}}
<div class="relative" x-data="{
        open: false,
        q: '',
        normalize(s) {
            return (s || '').toString().toLowerCase()
                .normalize('NFD')
                .replace(/\p{Diacritic}/gu, '');
        },
        filteredStorages() {
            const needle = this.normalize(this.q.trim());
            if (!needle) return this.storages;
            return this.storages.filter(st => this.normalize(st.name).includes(needle));
        }
     }">
    <button type="button"
            @click="open = !open; if (open) { q = ''; $nextTick(() => $refs.q?.focus()); }"
            class="flex items-center gap-2 border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
        <i class="fas fa-photo-film text-xs text-slate-400"></i>
        <span x-text="{{ $scope }}.storage_ids.length === 0
            ? 'Todos los medios'
            : {{ $scope }}.storage_ids.length + ' medio(s)'"></span>
        <i class="fas fa-chevron-down text-[10px] text-slate-400"></i>
    </button>
    <div x-show="open"
         @click.outside="open = false; q = ''"
         @keydown.escape.stop="open = false; q = ''"
         x-transition.opacity
         class="absolute z-30 mt-1 w-72 max-h-72 overflow-y-auto bg-white border border-slate-200 rounded-xl shadow-lg p-2">
        <label class="flex items-center gap-2.5 px-2 py-1.5 text-xs text-slate-500 hover:bg-slate-50 rounded cursor-pointer">
            <input type="checkbox"
                   :checked="{{ $scope }}.storage_ids.length === 0"
                   @change="{{ $scope }}.storage_ids = []; q = ''"
                   class="w-3.5 h-3.5 accent-brand-600">
            Todas (sin filtro)
        </label>
        <div class="border-t border-slate-100 my-1"></div>
        <div class="sticky top-0 bg-white z-10 pb-1">
            <div class="relative">
                <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] text-slate-400 pointer-events-none"></i>
                <input type="search"
                       x-model="q"
                       x-ref="q"
                       x-init="open && $nextTick(() => $refs.q?.focus())"
                       placeholder="Filtrar medios…"
                       aria-label="Filtrar medios"
                       @keydown.escape.stop="open = false; q = ''"
                       @click.stop
                       class="w-full border border-slate-200 rounded-lg pl-7 pr-7 py-1.5 text-sm text-slate-600 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none placeholder:text-slate-400">
                <button type="button"
                        x-show="q.length > 0"
                        @click="q = ''; $nextTick(() => $refs.q?.focus())"
                        aria-label="Limpiar filtro"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-[11px]"></i>
                </button>
            </div>
        </div>
        <div class="border-t border-slate-100 my-1"></div>
        <template x-for="st in filteredStorages()" :key="st.id">
            <label class="flex items-center gap-2.5 px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50 rounded cursor-pointer">
                <input type="checkbox" :value="st.id"
                       :checked="{{ $scope }}.storage_ids.includes(st.id)"
                       @change="toggleFilterStorage('{{ $scope }}', st.id, $event.target.checked)"
                       class="w-3.5 h-3.5 accent-brand-600">
                <span x-text="st.name"></span>
            </label>
        </template>
        <div x-show="storages.length === 0" class="px-2 py-2 text-xs text-slate-400">Sin medios con acceso.</div>
        <div x-show="storages.length > 0 && filteredStorages().length === 0"
             class="px-2 py-2 text-xs text-slate-400">
            Sin coincidencias para «<span x-text="q"></span>»
        </div>
    </div>
</div>
