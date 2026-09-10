@extends('layouts.app')

@section('title', 'Mis Avisos - Tcloud')

@section('content')
@php
    $adminPreviewingId = (int) (session('admin_previewing_id') ?? 0);
    $adminPreviewingUser = $adminPreviewingId > 0 ? App\Models\User::find($adminPreviewingId) : null;
    // El proyecto guarda la sesión con claves planas (user_role), no con `user`.
    $isSessionAdmin = session('user_role') === 'admin';
@endphp

{{-- Impulsado por el middleware AdminPreviewSwap: cuando hay impersonación
     activa, expone el id al JS para que apiFetch() lo propague en las
     sub-llamadas (ver change mis-avisos-admin-preview). --}}
<script>
    window.__adminPreviewUserId = {{ $adminPreviewingId ?: 'null' }};
    // Patch transparente: si hay preview activo y la URL es de mis-avisos,
    // añadí ?as_user=X para que el middleware vuelva a impersonar en
    // cada sub-request JSON. Esto evita tocar los 21 call sites de apiFetch().
    (function () {
        if (!window.__adminPreviewUserId) return;
        if (window.__adminPreviewWired) return;
        window.__adminPreviewWired = true;
        var origApiFetch = window.apiFetch;
        if (typeof origApiFetch !== 'function') return;
        window.apiFetch = function (url, options) {
            options = options || {};
            var sid = window.__adminPreviewUserId;
            if (sid && typeof url === 'string' && url.indexOf('/mis-avisos') === 0 && url.indexOf('as_user=') === -1) {
                var sep = url.indexOf('?') >= 0 ? '&' : '?';
                url = url + sep + 'as_user=' + encodeURIComponent(sid);
            }
            return origApiFetch(url, options);
        };
    })();
</script>

<div class="p-6" x-data="misAvisosPage()">

    {{-- Banner: solo cuando hay impersonación activa. Pegado al inicio del
         `<div x-data>` para que ocupe la parte superior de la página.
         El dropdown se hidrata con fetch() al montar el componente. --}}
    @if ($adminPreviewingId > 0 && $adminPreviewingUser)
        <div class="mb-4 px-4 py-3 bg-amber-50 border border-amber-300 rounded-xl text-sm flex flex-wrap items-center gap-3"
             data-tour="admin-preview-banner">
            <span class="inline-flex items-center gap-2 font-medium text-amber-900">
                <i class="fas fa-eye text-amber-600"></i>
                Viendo como
                <strong x-text="impersonatingName" class="text-amber-900">{{ $adminPreviewingUser->username }}</strong>
            </span>
            <span class="text-amber-700 text-xs italic">Estás accediendo al módulo como si fueras este cliente.</span>
            <form action="/mis-avisos" method="GET" class="flex items-center gap-2 ml-auto">
                <label class="text-xs text-amber-800">Cambiar:</label>
                <select name="as_user" @change="$event.target.form.submit()" x-model="selectedAsUser"
                        class="border border-amber-300 bg-white rounded px-2 py-1 text-xs focus:ring-2 focus:ring-amber-500 outline-none min-w-[10rem]">
                    <option value="{{ $adminPreviewingId }}" selected>{{ $adminPreviewingUser->username }}</option>
                    <template x-for="u in impersonatableUsers" :key="'banner-' + u.id">
                        <option :value="u.id" x-text="u.username"></option>
                    </template>
                </select>
                <noscript>
                    <button type="submit" class="text-xs px-2 py-1 bg-amber-200 text-amber-900 rounded">Cambiar</button>
                </noscript>
            </form>
            <a href="/mis-avisos" class="text-xs px-3 py-1.5 bg-amber-200 hover:bg-amber-300 text-amber-900 rounded font-medium">
                Volver a mi cuenta
            </a>
        </div>
    @elseif ($isSessionAdmin)
        {{-- Banner discreto: admin logueado pero sin impersonación activa,
             ofrece un acceso rápido al selector. --}}
        <details class="mb-4 border border-slate-200 rounded-xl bg-white" data-tour="admin-preview-trigger">
            <summary class="px-4 py-2.5 text-sm text-slate-600 cursor-pointer flex items-center gap-2 hover:bg-slate-50">
                <i class="fas fa-eye text-slate-400"></i>
                <span>Viendo tu propia cuenta (admin). ¿Ver como un cliente?</span>
                <i class="fas fa-chevron-down text-xs ml-auto text-slate-400"></i>
            </summary>
            <div class="px-4 py-3 border-t border-slate-200">
                <form action="/mis-avisos" method="GET" class="flex items-center gap-2 flex-wrap">
                    <select name="as_user" x-model="selectedAsUser"
                            class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 outline-none min-w-[12rem]">
                        <option value="">Selecciona un cliente…</option>
                        <template x-for="u in impersonatableUsers" :key="'disc-' + u.id">
                            <option :value="u.id" x-text="u.username"></option>
                        </template>
                    </select>
                    <button type="submit" class="text-xs px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded font-medium">
                        Ver como cliente
                    </button>
                </form>
            </div>
        </details>
    @endif

    {{-- Toasts: feedback no-destructivo para todas las acciones (reemplaza alert()) --}}
    <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none" aria-live="polite">
        <template x-for="t in toasts" :key="t.id">
            <div :class="t.kind === 'error' ? 'bg-red-50 border-red-200 text-red-700' : t.kind === 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-slate-800 text-white border-slate-700'"
                 class="pointer-events-auto px-4 py-2.5 rounded-lg border shadow-md text-sm flex items-start gap-2 max-w-sm transition-opacity"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0">
                <i class="fas mt-0.5" :class="t.kind === 'error' ? 'fa-circle-xmark text-red-500' : t.kind === 'success' ? 'fa-circle-check text-green-500' : 'fa-circle-info text-slate-400'"></i>
                <span x-text="t.text"></span>
                <button @click="dismissToast(t.id)" class="ml-2 opacity-60 hover:opacity-100" title="Cerrar"><i class="fas fa-xmark"></i></button>
            </div>
        </template>
    </div>

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Mis Avisos</h1>
            <p class="text-slate-500 mt-0.5">Tus palabras clave rastreadas en los medios que contrataste</p>
        </div>
        <a href="/mis-avisos/corrections/mine" class="text-sm text-brand-600 hover:underline whitespace-nowrap">Mis propuestas de corrección</a>
    </div>

    @if(! $moduleEnabled)
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-6">
        <i class="fas fa-info-circle text-amber-500 text-2xl mb-2 block"></i>
        <p class="font-medium text-amber-800">El administrador aún no te ha activado este módulo.</p>
    </div>
    @endif

    {{-- Tabs --}}
    <div class="flex gap-1 border-b border-slate-200 mb-6 overflow-x-auto" data-tour="tabs-bar">
        <template x-for="tab in ['live','history','keywords','prefs']" :key="tab">
            <button @click="switchTab(tab)"
                class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap flex items-center gap-2"
                :class="tab === activeTab ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'">
                <i class="fas text-sm" :class="tabIcons[tab]"></i>
                <span x-text="tabLabels[tab]"></span>
            </button>
        </template>
        <button onclick="startMisAvisosTour()" class="ml-auto flex items-center gap-1.5 text-xs text-slate-400 hover:text-brand-600 px-2" title="Guía interactiva">
            <i class="fas fa-circle-question"></i> Guía
        </button>
    </div>

    {{-- ═══ TAB: Keywords (split-screen: lista izquierda / alcance derecha) ═══ --}}
    <div x-show="activeTab === 'keywords'" x-cloak>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Izquierda: lista de keywords --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-4" data-tour="quota-counter">
                    <h2 class="text-sm font-semibold text-slate-700"><i class="fas fa-key mr-1.5 text-brand-500"></i>Mis palabras clave</h2>
                    <span class="text-sm font-medium text-slate-600"><span x-text="used" x-cloak></span> / <span x-text="quota" x-cloak></span></span>
                </div>
                <div class="flex gap-2 mb-3">
                    <input type="text" x-model="newKeyword" placeholder="palabra o frase" @keydown.enter="addKeyword()"
                           :disabled="used >= quota || quota === 0"
                           class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none disabled:opacity-50"
                           :title="used >= quota ? 'Cupo alcanzado' : ''">
                    <select x-model="newKeywordCategoryId" :disabled="used >= quota || quota === 0"
                            title="Categoría opcional al crear"
                            class="border border-slate-300 rounded-lg px-2 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none disabled:opacity-50">
                        <option value="">Sin categoría</option>
                        <template x-for="cat in categories" :key="'new-' + cat.id">
                            <option :value="cat.id" x-text="cat.is_admin ? cat.name + ' (admin)' : cat.name"></option>
                        </template>
                    </select>
                    <button @click="addKeyword()" :disabled="used >= quota || quota === 0"
                            class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Agregar</button>
                </div>

                {{-- Filtro por categoría (add-keyword-categories) --}}
                <div class="flex items-center gap-2 mb-3 flex-wrap">
                    <button @click="selectCategory('all')"
                            :class="activeCategory === 'all' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50'"
                            class="text-xs px-3 py-1.5 rounded-full border">Todas <span class="ml-1 opacity-70" x-text="keywords.length"></span></button>
                    <button @click="selectCategory('none')"
                            :class="activeCategory === 'none' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50'"
                            class="text-xs px-3 py-1.5 rounded-full border">Sin categoría <span class="ml-1 opacity-70" x-text="uncategorizedCount()"></span></button>
                    <template x-for="cat in categories" :key="'pill-' + cat.id">
                        <div class="inline-flex items-center rounded-full border overflow-hidden"
                             :style="'border-color:' + cat.color_hex + ';background:' + (activeCategory === cat.id ? cat.color_hex : '#fff')">
                            <button @click="selectCategory(cat.id)"
                                    :style="'color:' + (activeCategory === cat.id ? '#fff' : cat.color_hex)"
                                    class="text-xs px-3 py-1.5 font-medium"
                                    :title="cat.is_admin ? cat.name + ' (administrada)' : cat.name">
                                <span x-text="cat.name"></span>
                                <span class="ml-1 opacity-70" x-text="cat.keywords_count"></span>
                            </button>
                            <template x-if="!cat.is_admin">
                                <div class="flex items-stretch border-l"
                                     :style="'border-color:' + cat.color_hex">
                                    <button @click.stop="openEditCategory(cat)" :title="'Editar ' + cat.name"
                                            :style="'color:' + (activeCategory === cat.id ? '#fff' : cat.color_hex)"
                                            class="px-1.5 py-1.5 hover:bg-black/5 text-xs">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button x-show="pendingDeleteCatId !== cat.id" @click.stop="askDeleteCategory(cat)" :title="'Eliminar ' + cat.name"
                                            :style="'color:' + (activeCategory === cat.id ? '#fff' : cat.color_hex)"
                                            class="px-1.5 py-1.5 hover:bg-red-100 text-xs">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button x-show="pendingDeleteCatId === cat.id" @click.stop="confirmDeleteCategory(cat)" :title="'Confirmar eliminación'"
                                            class="px-2 py-1.5 bg-red-600 text-white text-xs font-medium">
                                        Borrar
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>
                    <button @click="showNewCategoryForm = !showNewCategoryForm"
                            class="text-xs px-3 py-1.5 rounded-full border border-dashed border-slate-400 text-slate-600 hover:bg-slate-50">
                        <i class="fas fa-plus mr-1"></i>Nueva
                    </button>
                    <button @click="openCategoriesManager()"
                            class="text-xs px-3 py-1.5 rounded-full border border-slate-300 text-slate-700 hover:bg-slate-50 bg-white"
                            :data-tour="'categories-manager'"
                            :title="ownCategoriesCount() > 0 ? 'Tienes ' + ownCategoriesCount() + ' categoría(s) propia(s). Editar o eliminar.' : 'Crear, renombrar y eliminar categorías (incluyendo las administradas)'">
                        <i class="fas fa-tags mr-1"></i>
                        Organizar categorías
                        <span x-show="ownCategoriesCount() > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-brand-100 text-brand-700 text-[10px] font-medium" x-text="ownCategoriesCount()"></span>
                    </button>
                </div>

                <div x-show="pendingDeleteCatId !== null" x-cloak class="mb-3 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 flex items-center justify-between gap-3">
                    <span>
                        <i class="fas fa-triangle-exclamation mr-1"></i>
                        Si borrás <strong x-text="(categories.find(c => c.id === pendingDeleteCatId) || {}).name"></strong>,
                        las <span x-text="pendingDeleteCatKeywordsCount" x-cloak></span> keyword(s) asignadas vuelven a <em>Sin categoría</em>.
                    </span>
                    <div class="flex gap-2">
                        <button @click="cancelDeleteCategory()" class="text-xs px-2 py-1 hover:bg-red-100 rounded">Cancelar</button>
                        <button @click="confirmDeleteCategory(categories.find(c => c.id === pendingDeleteCatId))" class="text-xs px-2 py-1 bg-red-600 text-white rounded">Sí, borrar</button>
                    </div>
                </div>

                <div x-show="showNewCategoryForm" x-cloak class="mb-3 p-3 border border-slate-200 rounded-lg bg-slate-50">
                    <div class="flex gap-2 items-center">
                        <input type="text" x-model="newCategoryName" placeholder="Nombre de tu categoría" maxlength="80"
                               class="flex-1 border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                        <input type="color" x-model="newCategoryColor" class="h-9 w-12 border border-slate-300 rounded cursor-pointer" title="Color">
                        <button @click="addCategory()" :disabled="!newCategoryName.trim() || addingCategory" class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Crear</button>
                        <button @click="showNewCategoryForm = false; newCategoryName = ''" class="px-3 py-1.5 text-slate-600 hover:bg-slate-200 rounded-lg text-sm">Cancelar</button>
                    </div>
                    <div class="text-xs text-slate-500 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>Las categorías que crees son privadas. El matching del transcriptor sigue trabajando por texto igual que antes; la categoría solo te sirve para filtrar y organizar esta lista.
                    </div>
                </div>

                <div x-show="filteredKeywords.length === 0" class="text-sm text-slate-400 py-4 text-center">
                    <span x-show="keywords.length === 0">Sin palabras clave. Agrega la primera para empezar a rastrear.</span>
                    <span x-show="keywords.length > 0 && filteredKeywords.length === 0">Ninguna palabra en este filtro.</span>
                </div>
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <template x-for="kw in filteredKeywords" :key="kw.id">
                        <div @click="selectKeyword(kw)"
                             class="p-3 border rounded-lg cursor-pointer transition-colors"
                             :class="selectedKeywordId === kw.id ? 'border-brand-600 bg-brand-50/60' : 'border-slate-200 hover:bg-slate-50'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm text-slate-700 font-medium" x-text="kw.text"></span>
                                    <div class="text-xs text-slate-400 mt-0.5" x-text="scopeLabel(kw)"></div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="scopeBadgeClass(kw)" :title="scopeBadgeTitle(kw)"></span>
                                    <select @click.stop @change="updateKeywordCategory(kw, $event.target.value); $event.target.blur()"
                                            :disabled="savingCategoryIds.has(kw.id)"
                                            class="text-xs border border-slate-300 rounded px-2 py-1 focus:ring-2 focus:ring-brand-500 outline-none max-w-[150px] disabled:opacity-60"
                                            title="Categoría">
                                        <option value="" :selected="kw.category_id == null">Sin categoría</option>
                                        <template x-for="cat in categories" :key="'kwsel-' + kw.id + '-' + cat.id">
                                            <option :value="cat.id" :selected="Number(kw.category_id) === Number(cat.id)" x-text="cat.is_admin ? cat.name + ' (admin)' : cat.name"></option>
                                        </template>
                                    </select>
                                    <span x-show="savingCategoryIds.has(kw.id)" class="text-xs text-slate-400" title="Guardando">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </span>
                                    <button @click.stop="removeKeyword(kw.id)" class="text-xs px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded">Eliminar</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="mt-2 text-xs text-slate-500" x-show="keywords.length > 0">
                    <span x-text="classifiedCount()"></span> clasificadas de <span x-text="keywords.length"></span>.
                </div>
            </div>

            {{-- Derecha: alcance de la keyword seleccionada --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6" x-show="selectedKeyword()" x-cloak>
                <h2 class="text-sm font-semibold text-slate-700 mb-1">
                    <i class="fas fa-tower-cell mr-1.5 text-brand-500"></i>
                    Dónde rastrear <span class="text-brand-700" x-text="'&quot;' + (selectedKeyword()?.text || '') + '&quot;'"></span>
                </h2>
                <p class="text-xs text-slate-500 mb-4">Marca los medios donde debe buscarse esta palabra. Sin marcar ninguno = se rastrea en todos tus medios.</p>

                <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100">
                    <span class="text-xs text-slate-500" x-text="scopeSummaryText()"></span>
                    <div class="flex gap-2">
                        <button @click="markAllStorages(true)" class="text-xs px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg">Marcar todos</button>
                        <button @click="markAllStorages(false)" class="text-xs px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-600 rounded-lg">Desmarcar todos</button>
                    </div>
                </div>

                <div class="space-y-2 max-h-72 overflow-y-auto mb-4">
                    <template x-for="st in storages" :key="st.id">
                        <label class="flex items-center gap-3 p-2.5 border rounded-lg cursor-pointer transition-colors"
                               :class="draftScope.includes(st.id) ? 'border-brand-500 bg-brand-50/50' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="checkbox" :value="st.id"
                                   :checked="draftScope.includes(st.id)"
                                   @change="toggleDraft(st.id, $event.target.checked)"
                                   class="w-4 h-4 accent-brand-600">
                            <i class="fas fa-tower-broadcast text-xs" :class="draftScope.includes(st.id) ? 'text-brand-600' : 'text-slate-300'"></i>
                            <span class="text-sm text-slate-700 flex-1" x-text="st.name"></span>
                            <span class="text-xs font-medium" :class="draftScope.includes(st.id) ? 'text-brand-700' : 'text-slate-400'"
                                  x-text="draftScope.includes(st.id) ? 'Activo' : 'Inactivo'"></span>
                        </label>
                    </template>
                    <div x-show="storages.length === 0" class="text-xs text-slate-400 py-3 text-center">
                        No tienes medios con acceso concedido. Pide al administrador activar el acceso a transcripciones.
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button @click="saveScope()" :disabled="!scopeDirty()" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Guardar alcance</button>
                    <button @click="revertScope()" :disabled="!scopeDirty()" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700 disabled:opacity-40">Cancelar</button>
                    <span x-show="scopeDirty()" class="text-xs text-amber-600"><i class="fas fa-triangle-exclamation mr-1"></i>Cambios sin guardar</span>
                </div>
            </div>

            {{-- Estado vacío del panel derecho --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6" x-show="!selectedKeyword() && keywords.length > 0" x-cloak>
                <div class="text-center py-10 text-slate-400">
                    <i class="fas fa-hand-pointer text-3xl mb-3 block text-slate-200"></i>
                    <p class="text-sm font-medium">Selecciona una palabra a la izquierda</p>
                    <p class="text-xs mt-1">y define en cuáles medios se rastrea.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ TAB: En vivo (tabla con filtros + paginación) ═══ --}}
    <div x-show="activeTab === 'live'" x-cloak>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Coincidencias de hoy</h2>
                <span class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full" :class="livePolling ? 'bg-green-500 animate-pulse' : 'bg-slate-300'"></span>
                    <span x-text="livePolling ? 'en vivo' : 'pausado'"></span>
                </span>
            </div>

            {{-- Filtros del feed en vivo (mismos filtros que el histórico) --}}
            <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-end gap-2 bg-slate-50/60">
                <div class="w-full sm:w-80 shrink-0">
                    <label class="text-xs text-slate-500 block mb-1">Buscar (mín. 3 caracteres)</label>
                    <input type="text" x-model="liveFilters.q" @keydown.enter="applyLiveFilters()"
                           placeholder="término libre en las transcripciones..."
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>

                {{-- change mis-avisos-media-kind-indicator (G4): filtro rápido TV/Radio.
                     Ubicado al inicio del filter row (justo después del input de búsqueda)
                     para quedar en la IZQUIERDA SUPERIOR en ambos tabs. Mismo orden
                     relativo que en Histórico. --}}
                <div class="inline-flex border border-slate-300 rounded-lg overflow-hidden text-sm">
                    <button type="button" @click="setMediaFilter('liveFilters', 'all')"
                            :class="liveFilters.media_type === 'all' ? 'bg-slate-800 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'"
                            class="px-3 py-2 font-medium transition-colors">
                        Todas
                    </button>
                    <button type="button" @click="setMediaFilter('liveFilters', 'tv')"
                            :class="liveFilters.media_type === 'tv' ? 'bg-gradient-to-r from-violet-500 to-indigo-600 text-white shadow-sm' : 'bg-white text-violet-700 hover:bg-violet-50'"
                            class="px-3 py-2 font-medium border-l border-slate-300 flex items-center gap-1.5 transition-colors">
                        <i class="fas fa-tv text-xs"></i> TV
                    </button>
                    <button type="button" @click="setMediaFilter('liveFilters', 'radio')"
                            :class="liveFilters.media_type === 'radio' ? 'bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-sm' : 'bg-white text-amber-700 hover:bg-amber-50'"
                            class="px-3 py-2 font-medium border-l border-slate-300 flex items-center gap-1.5 transition-colors">
                        <i class="fas fa-radio text-xs"></i> Radio
                    </button>
                </div>

                @include('mis-avisos._filter-storages', ['scope' => 'liveFilters'])
                <select x-model.number="liveFilters.keyword_id" @change="applyLiveFilters()"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-600 bg-white outline-none">
                    <option value="0">Todas mis keywords</option>
                    <template x-for="kw in keywords" :key="kw.id">
                        <option :value="kw.id" x-text="kw.text"></option>
                    </template>
                </select>
                <button @click="applyLiveFilters()" class="px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">Filtrar</button>
                <button x-show="liveHasFilters()" @click="clearLiveFilters()"
                        class="px-3 py-2 border border-slate-300 hover:bg-white text-slate-500 rounded-lg text-sm">Limpiar</button>
            </div>

            {{-- Aviso de coincidencias nuevas mientras se navega otra página --}}
            <div x-show="newLiveCount > 0" x-cloak class="px-4 py-2 bg-green-50 border-b border-green-100 text-xs text-green-700 flex items-center justify-between">
                <span><span x-text="newLiveCount"></span> coincidencia(s) nueva(s) mientras veías esta página</span>
                <button @click="goPage('live', 1); newLiveCount = 0" class="underline font-medium">Ir a la primera página</button>
            </div>

            @include('mis-avisos._table-hits', ['mode' => 'live'])
        </div>
    </div>

    {{-- ═══ TAB: Histórico (tabla con filtros + paginación) ═══ --}}
    <div x-show="activeTab === 'history'" x-cloak>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex flex-wrap items-end gap-3 mb-4">
                <div class="w-full sm:w-80 shrink-0">
                    <label class="text-xs text-slate-500 block mb-1">Buscar (mín. 3 caracteres)</label>
                    <input type="text" x-model="historyFilters.q" @keydown.enter="searchHistory(1)"
                           placeholder="término libre en las transcripciones..."
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>

                {{-- change mis-avisos-media-kind-indicator (G4): filtro rápido TV/Radio.
                     Misma posición que en En vivo: justo después del input de búsqueda
                     para quedar al inicio de la primera línea del filter row (o al
                     inicio de la segunda línea cuando el row wrappea por tener más
                     elementos). --}}
                <div class="inline-flex border border-slate-300 rounded-lg overflow-hidden text-sm">
                    <button type="button" @click="setMediaFilter('historyFilters', 'all')"
                            :class="historyFilters.media_type === 'all' ? 'bg-slate-800 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'"
                            class="px-3 py-2 font-medium transition-colors">
                        Todas
                    </button>
                    <button type="button" @click="setMediaFilter('historyFilters', 'tv')"
                            :class="historyFilters.media_type === 'tv' ? 'bg-gradient-to-r from-violet-500 to-indigo-600 text-white shadow-sm' : 'bg-white text-violet-700 hover:bg-violet-50'"
                            class="px-3 py-2 font-medium border-l border-slate-300 flex items-center gap-1.5 transition-colors">
                        <i class="fas fa-tv text-xs"></i> TV
                    </button>
                    <button type="button" @click="setMediaFilter('historyFilters', 'radio')"
                            :class="historyFilters.media_type === 'radio' ? 'bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-sm' : 'bg-white text-amber-700 hover:bg-amber-50'"
                            class="px-3 py-2 font-medium border-l border-slate-300 flex items-center gap-1.5 transition-colors">
                        <i class="fas fa-radio text-xs"></i> Radio
                    </button>
                </div>

                <div>
                    <label class="text-xs text-slate-500 block mb-1">Desde</label>
                    <input type="date" x-model="historyFilters.from" @input="activeDateShortcut = null" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs text-slate-500 block mb-1">Hasta</label>
                    <input type="date" x-model="historyFilters.to" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                {{-- change 2026-09-10-mis-avisos-program-date-filter: el cliente
                     elige por qué fecha filtra. Default 'program' (fecha del
                     programa). El tooltip explica la diferencia con un ejemplo
                     concreto del caso Monitoreoalpunto / Omar Perez. --}}
                <div class="pb-0.5">
                    <label class="text-xs text-slate-500 block mb-1">Filtrar por</label>
                    <div class="inline-flex rounded-lg border border-slate-300 overflow-hidden" role="group" aria-label="Campo de fecha">
                        <button type="button"
                                @click="historyFilters.date_field = 'program'; searchHistory(1)"
                                :class="historyFilters.date_field === 'program'
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-white text-slate-600 hover:bg-slate-50'"
                                class="px-3 py-2 text-xs font-medium transition-colors"
                                title="Fecha del programa: cuándo se emitió el medio (p. ej. WinSport del 7 sep a las 14:30). Recomendado."
                                aria-pressed="true">
                            <i class="fas fa-tv mr-1"></i>Programa
                        </button>
                        <button type="button"
                                @click="historyFilters.date_field = 'detected'; searchHistory(1)"
                                :class="historyFilters.date_field === 'detected'
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-white text-slate-600 hover:bg-slate-50'"
                                class="px-3 py-2 text-xs font-medium transition-colors border-l border-slate-300"
                                title="Fecha de detección: cuándo el sistema encontró la keyword (puede ser horas o días después). Útil para auditoría interna."
                                aria-pressed="false">
                            <i class="fas fa-search mr-1"></i>Detección
                        </button>
                    </div>
                </div>
                {{-- Atajos de fecha: un clic llena Desde/Hasta y busca --}}
                <div class="flex items-center gap-1 pb-0.5">
                    <template x-for="shortcut in dateShortcuts" :key="shortcut.label">
                        <button @click="applyDateShortcut(shortcut)"
                                class="px-2.5 py-2 rounded-lg text-xs font-medium transition-all border"
                                :class="isShortcutActive(shortcut)
                                    ? 'bg-brand-600 text-white border-brand-600 shadow'
                                    : 'border-slate-300 text-slate-600 hover:bg-brand-50 hover:border-brand-300'"
                                x-text="shortcut.label"
                                :title="shortcut.title"></button>
                    </template>
                </div>
                @include('mis-avisos._filter-storages', ['scope' => 'historyFilters'])
                <div>
                    <label class="text-xs text-slate-500 block mb-1">Keyword</label>
                    <select x-model.number="historyFilters.keyword_id"
                            class="border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-600 bg-white outline-none">
                        <option value="0">Todas mis keywords</option>
                        <template x-for="kw in keywords" :key="kw.id">
                            <option :value="kw.id" x-text="kw.text"></option>
                        </template>
                    </select>
                </div>
                <button @click="searchHistory(1)" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium">Buscar</button>
                <button @click="requestExport()" :disabled="exportBusy || !!activeExport"
                        class="px-4 py-2 border border-brand-600 text-brand-700 hover:bg-brand-50 rounded-lg text-sm font-medium disabled:opacity-50"
                        x-text="exportButtonLabel"></button>
            </div>
            <p class="text-xs text-slate-400 mb-4">Rango máximo: 60 días. Solo verás medios que contrataste. La exportación usa exactamente estos filtros.</p>

            <div x-show="activeExport" x-cloak class="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-800">
                <i class="fas fa-file-export mr-1"></i>
                <span x-text="exportStatusText"></span>
                <template x-if="activeExport && activeExport.status === 'ready'">
                    <span class="ml-2 flex gap-2 inline">
                        <a :href="activeExport.download_url" class="underline font-medium">Descargar</a>
                        <button @click="emailExport()" class="underline">Enviar a mi correo</button>
                    </span>
                </template>
            </div>
            <div x-show="historyError" x-cloak class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700" x-text="historyError"></div>

            @include('mis-avisos._table-hits', ['mode' => 'history'])
        </div>
    </div>

    {{-- ═══ TAB: Preferencias ═══ --}}
    <div x-show="activeTab === 'prefs'" x-cloak>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700 mb-1">¿Cada cuánto quieres recibir tus avisos por correo?</h2>
            <p class="text-sm text-slate-500 mb-4">Los avisos agrupan las coincidencias del día. Lo que no quepa en tu cupo diario sale en el resumen del día siguiente.</p>

            <div x-show="pendingReposition > 0" class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
                <i class="fas fa-clock mr-1"></i> Tienes <strong x-text="pendingReposition"></strong> coincidencia(s) retenida(s) por tu cupo diario de correos. Saldrán en el resumen de mañana.
            </div>

            <div class="space-y-2 mb-4">
                <template x-for="opt in frequencyOptions" :key="opt.minutes">
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                           :class="frequency === opt.minutes ? 'border-brand-600 bg-brand-50/50' : 'border-slate-200 hover:bg-slate-50'">
                        <input type="radio" name="frequency" :value="opt.minutes" x-model.number="frequency" class="mt-0.5 accent-brand-600">
                        <div class="flex-1">
                            <div class="text-sm font-medium text-slate-700" x-text="opt.label"></div>
                            <div class="text-xs text-slate-500" x-text="opt.hint"></div>
                            <div class="text-xs mt-1" :class="opt.minutes <= 5 ? 'text-amber-600' : 'text-slate-400'"
                                 x-text="opt.minutes <= 5
                                    ? '⚠ Con ' + opt.label.toLowerCase() + ' podrías recibir ~' + (projection[opt.minutes] ?? 0) + ' correos/semana según tu actividad reciente. Tu cupo diario es ' + emailsQuota + '; al alcanzarlo, el resto sale en el resumen del día siguiente.'
                                    : '~' + (projection[opt.minutes] ?? 0) + ' correos/semana con tu actividad de los últimos 7 días.'"></div>
                        </div>
                    </label>
                </template>
            </div>
            <button @click="saveFrequency()" :disabled="frequency === originalFrequency"
                    class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">Guardar preferencia</button>
        </div>
    </div>

    @include('mis-avisos._correction-modal')
    @include('components.transcript-viewer')

    {{-- Modal: editar categoría propia --}}
    <div x-show="editingCategory !== null" x-cloak
         class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="editingCategory = null">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6" @click.outside="editingCategory = null">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Editar categoría</h3>
            <p class="text-xs text-slate-500 mb-4">Cambia el nombre o el color. Solo vos podés modificar tus categorías.</p>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Nombre</label>
                    <input type="text" x-model="editCategoryForm.name" maxlength="80"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" x-model="editCategoryForm.color_hex"
                               class="h-10 w-16 border border-slate-300 rounded cursor-pointer">
                        <input type="text" x-model="editCategoryForm.color_hex" maxlength="7"
                               pattern="^#[0-9A-Fa-f]{6}$"
                               class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-brand-500 outline-none">
                    </div>
                </div>
                <div x-show="editCategoryError" x-cloak
                     class="px-3 py-2 bg-red-50 border border-red-200 rounded text-sm text-red-700"
                     x-text="editCategoryError"></div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
                <button @click="editingCategory = null" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg text-sm">Cancelar</button>
                <button @click="saveEditedCategory()" :disabled="savingEditCategory || !editCategoryForm.name.trim()"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                    Guardar cambios
                </button>
            </div>
        </div>
    </div>

    {{-- Modal: Organizar categorías (panel dedicado de gestión, descubrible) --}}
    <div x-show="showCategoriesManager" x-cloak
         class="fixed inset-0 bg-slate-900/50 z-40 flex items-center justify-center p-4"
         @keydown.escape.window="showCategoriesManager = false">
        <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full" @click.outside="showCategoriesManager = false">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-800">Mis categorías</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Las categorías con badge <span class="px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-medium">admin</span> fueron creadas por el administrador; no podés renombrarlas ni borrarlas. Sí podés <em>usarlas</em> para clasificar tus palabras clave.
                    </p>
                </div>
                <button @click="showCategoriesManager = false" class="text-slate-400 hover:text-slate-600" title="Cerrar"><i class="fas fa-xmark text-lg"></i></button>
            </div>

            <div class="px-6 py-3 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                <div class="text-xs text-slate-600">
                    <span class="font-medium" x-text="categories.length"></span> categorías visibles para vos
                    (<span x-text="ownCategoriesCount()"></span> propias · <span x-text="categories.length - ownCategoriesCount()"></span> administradas)
                </div>
                <button @click="showCategoriesManager = false; showNewCategoryForm = true"
                        class="text-xs px-3 py-1.5 border border-dashed border-brand-300 text-brand-700 hover:bg-brand-50 rounded-lg">
                    <i class="fas fa-plus mr-1"></i>Nueva categoría
                </button>
            </div>

            <div class="max-h-[60vh] overflow-y-auto">
                <template x-for="cat in categories" :key="'mgmt-' + cat.id">
                    <div class="px-6 py-3 border-b border-slate-100 flex items-center gap-3 hover:bg-slate-50">
                        <span class="inline-block w-6 h-6 rounded-md border border-slate-200 shadow-inner" :style="'background:' + cat.color_hex"></span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-slate-800 truncate" x-text="cat.name"></span>
                                <span x-show="cat.is_admin" class="px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-medium">admin</span>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5">
                                <span x-text="cat.keywords_count"></span> keyword(s) clasificada(s)
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <template x-if="!cat.is_admin">
                                <div class="flex items-center gap-1.5">
                                    <button @click="openEditCategory(cat); showCategoriesManager = false"
                                            class="text-xs px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded font-medium">
                                        <i class="fas fa-pen mr-1"></i>Editar
                                    </button>
                                    <button @click="askDeleteCategory(cat); showCategoriesManager = false"
                                            :disabled="cat.keywords_count > 0 && cat.keywords_count < 50"
                                            class="text-xs px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 rounded font-medium disabled:opacity-30 disabled:cursor-not-allowed">
                                        <i class="fas fa-trash mr-1"></i>Eliminar
                                    </button>
                                </div>
                            </template>
                            <template x-if="cat.is_admin">
                                <span class="text-xs text-slate-400 italic">Solo usable</span>
                            </template>
                        </div>
                    </div>
                </template>

                <div x-show="categories.length === 0" class="p-8 text-center text-sm text-slate-400">
                    <i class="fas fa-folder-open text-2xl mb-2 block text-slate-300"></i>
                    Aún no hay categorías visibles para vos.
                </div>
            </div>

            <div class="px-6 py-3 border-t border-slate-200 bg-slate-50 rounded-b-xl text-xs text-slate-500">
                <i class="fas fa-info-circle mr-1"></i>
                Las keywords que tengan una categoría que borres vuelven automáticamente a <strong>Sin categoría</strong>. Eso NO afecta el matching del transcriptor, que sigue siendo por texto.
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function misAvisosPage() {
    return {
        activeTab: 'live',
        // mis-avisos-admin-preview: estado del banner.
        isSessionAdmin: {{ $isSessionAdmin ? 'true' : 'false' }},
        adminPreviewingId: {{ $adminPreviewingId ?: 'null' }},
        impersonatingName: @json($adminPreviewingUser?->username ?? ''),
        impersonatableUsers: [],
        selectedAsUser: '{{ (string) $adminPreviewingId }}',
        tabLabels: { keywords: 'Palabras clave', live: 'En vivo', history: 'Histórico', prefs: 'Preferencias' },
        tabIcons: { keywords: 'fa-key', live: 'fa-tower-broadcast', history: 'fa-clock-rotate-left', prefs: 'fa-sliders' },
        // Keywords (carga inicial server-side; el JS la re-hidrata con scope)
        used: {{ $used }}, quota: {{ $quota }},
        keywords: <?php
            $keywordsPayload = $user->userKeywords->map(function ($k) use ($keywordCategories) {
                return [
                    'id' => $k->id,
                    'text' => $k->text,
                    'storage_ids' => [],
                    'category_id' => isset($keywordCategories[$k->id]) ? (int) $keywordCategories[$k->id] : null,
                ];
            })->values();
            echo json_encode($keywordsPayload);
        ?>,
        // Categorías (add-keyword-categories): admin base ∪ propias.
        categories: @json($categories),
        activeCategory: 'all',
        showNewCategoryForm: false,
        newCategoryName: '',
        newCategoryColor: '#4654a8',
        newKeywordCategoryId: '',
        addingCategory: false,
        // Edición de categoría propia.
        editingCategory: null, // {id, name, color_hex, owner_scope, owner_id, keywords_count, ...} cuando se está editando
        editCategoryForm: { name: '', color_hex: '#4654a8' },
        editCategoryError: '',
        savingEditCategory: false,
        // Borrado de categoría propia: pedir confirmación inline antes de enviar.
        pendingDeleteCatId: null,
        pendingDeleteCatKeywordsCount: 0,
        deletingCategory: false,
        // Panel dedicado "Organizar categorías" — descubrible aunque no tengas propias.
        showCategoriesManager: false,
        // Toasts no-destructivos (reemplazan alert()).
        toasts: [], toastSeq: 0,
        // Palabras guardando su categoría en este momento (mostramos feedback).
        savingCategoryIds: new Set(),
        storages: @json($accessibleStorages),
        newKeyword: '',
        selectedKeywordId: null, draftScope: [],
        // En vivo (tabla con filtros + paginación server-side)
        liveRows: [], livePage: 1, liveLastPage: 1, liveTotal: 0, livePerPage: 25,
        liveFilters: { q: '', storage_ids: [], keyword_id: 0, media_type: 'all' },
        // change mis-avisos-media-kind-indicator (Fase UI extra): ordenamiento
        // client-side por columna. Cada tab tiene su propio sort state (no
        // compartido). Click alterna asc/desc dentro de la misma columna.
        liveSort: { column: 'matched_at', direction: 'desc' },
        newLiveCount: 0, liveTimer: null, livePolling: false,
        // Histórico
        // change 2026-09-10-mis-avisos-program-date-filter: el cliente elige
        // por qué fecha filtra. Default 'program' (fecha del programa). Si el
        // backend recibe un valor inválido, él mismo cae al default.
        historyFilters: { q: '', from: '', to: '', storage_ids: [], keyword_id: 0, media_type: 'all', date_field: 'program' },
        historySort: { column: 'recorded_at', direction: 'desc' },
        historyRows: [], historyPage: 1, historyLastPage: 1, historyTotal: 0, historyPerPage: 25,
        historySearched: false, historyError: '',
        // Agrupado por (archivo, keyword) con accordion. Cada grupo expone
        // `hits: [row, ...]` con todas las menciones reales que el backend
        // devolvió en la página actual. La fila resumen muestra keyword +
        // conteo total + primera mención; el expand revela cada mención por
        // separado (cada una con su minuto + snippet y los botones Ver/Editor).
        expandedGroups: new Set(),
        // Export
        activeExport: null, exportBusy: false, exportPoll: null,
        // Preferencias
        frequencyOptions: [
            { minutes: 1, label: 'Cada minuto', hint: 'Aviso casi inmediato al detectarse una coincidencia' },
            { minutes: 5, label: 'Cada 5 minutos', hint: 'Muy frecuente; solo para palabras poco comunes' },
            { minutes: 15, label: 'Cada 15 minutos', hint: '' },
            { minutes: 20, label: 'Cada 20 minutos', hint: '' },
            { minutes: 30, label: 'Cada 30 minutos', hint: 'Recomendado para empezar' },
            { minutes: 50, label: 'Cada 50 minutos', hint: '' },
            { minutes: 60, label: 'Cada hora', hint: 'Equilibrado' },
            { minutes: 240, label: '6 veces al día', hint: '' },
            { minutes: 480, label: '3 veces al día', hint: '' },
            { minutes: 1440, label: '1 vez al día', hint: 'Un resumen diario con todo el día' },
        ],
        frequency: 30, originalFrequency: 30, projection: {}, emailsQuota: 0, pendingReposition: 0,

        init() {
            this.hydrateHistoryFromUrl();
            this.loadKeywords();
            this.loadPreferences();
            this.startLive();
            this.$watch('activeTab', (t) => { if (t === 'live') this.startLive(); else this.stopLive(); });
            // Banner de previsualización admin: hidratar lista impersonable solo si hay sesión admin.
            this.loadImpersonatableUsers();
        },
        async loadImpersonatableUsers() {
            if (!this.isSessionAdmin) return;
            try {
                const res = await fetch('/admin/preview/impersonatable-users', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const d = await res.json();
                    this.impersonatableUsers = (d.users || []).map(u => ({
                        id: Number(u.id), username: u.username, name: u.name,
                    }));
                    // Si hay un impersonating activo, deja su name visible.
                    if (this.adminPreviewingId && this.impersonatableUsers.length > 0) {
                        const match = this.impersonatableUsers.find(u => u.id === this.adminPreviewingId);
                        if (match) this.impersonatingName = match.username;
                    }
                }
            } catch (e) { /* silent — banner es opcional */ }
        },

        csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        // Toasts: feedback no-destructivo (reemplazo de alert()). Por defecto auto-dismiss a 2.6s.
        pushToast(text, kind = 'success', ttlMs = 2600) {
            const id = ++this.toastSeq;
            this.toasts.push({ id, text, kind });
            if (ttlMs > 0) {
                setTimeout(() => this.dismissToast(id), ttlMs);
            }
            return id;
        },
        dismissToast(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
        headers(json = true) {
            const h = { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() };
            if (json) h['Content-Type'] = 'application/json';
            return h;
        },

        switchTab(t) { this.activeTab = t; if (t === 'history' && !this.historySearched) this.searchHistory(1); },

        // ── Categorías (add-keyword-categories) ──
        get filteredKeywords() {
            if (this.activeCategory === 'all') return this.keywords;
            if (this.activeCategory === 'none') return this.keywords.filter(k => !k.category_id);
            const targetId = Number(this.activeCategory);
            return this.keywords.filter(k => Number(k.category_id) === targetId);
        },
        uncategorizedCount() {
            return this.keywords.filter(k => !k.category_id).length;
        },
        classifiedCount() {
            return this.keywords.filter(k => k.category_id).length;
        },
        selectCategory(idOrAll) {
            this.activeCategory = idOrAll;
        },
        async refreshCategories() {
            try {
                const res = await apiFetch('/mis-avisos/categories', {
                    credentials: 'same-origin',
                    headers: this.headers(false),
                });
                if (res.ok) {
                    const d = await res.json();
                    this.categories = (d.categories || []).map(c => ({
                        ...c,
                        id: Number(c.id),
                        keywords_count: Number(c.keywords_count || 0),
                    }));
                }
            } catch (e) { /* silent */ }
        },
        async addCategory() {
            const name = (this.newCategoryName || '').trim();
            if (!name) return;
            this.addingCategory = true;
            try {
                const res = await apiFetch('/mis-avisos/categories', {
                    method: 'POST', credentials: 'same-origin', headers: this.headers(),
                    body: JSON.stringify({ name, color_hex: this.newCategoryColor }),
                });
                const d = await res.json().catch(() => ({}));
                if (res.ok) {
                    this.categories.push({ ...d.category, id: Number(d.category.id), keywords_count: 0 });
                    this.newCategoryName = '';
                    this.newCategoryColor = '#4654a8';
                    this.showNewCategoryForm = false;
                    this.pushToast('Categoría creada', 'success', 1800);
                } else {
                    let msg = 'No se pudo crear la categoría';
                    try { const d = await res.json(); msg = d.error || msg; } catch (_) {}
                    this.pushToast(msg, 'error', 4000);
                }
            } finally {
                this.addingCategory = false;
            }
        },
        async updateKeywordCategory(kw, value) {
            const newId = value === '' ? null : Number(value);
            const previous = kw.category_id ?? null;
            kw.category_id = newId;
            this.savingCategoryIds.add(kw.id);
            try {
                const res = await apiFetch('/mis-avisos/keywords/' + kw.id, {
                    method: 'PATCH', credentials: 'same-origin', headers: this.headers(),
                    body: JSON.stringify({ category_id: newId }),
                });
                if (!res.ok) {
                    kw.category_id = previous;
                    let msg = 'No se pudo cambiar la categoría';
                    try { const d = await res.json(); msg = d.error || msg; } catch (_) {}
                    this.pushToast(msg, 'error', 4000);
                } else {
                    await this.refreshCategories();
                    // El guardado real fue silencioso: feedback efímero y discreto.
                    this.pushToast('Categoría guardada', 'success', 1600);
                }
            } catch (e) {
                kw.category_id = previous;
                this.pushToast('Error de red al guardar la categoría', 'error', 4000);
            } finally {
                this.savingCategoryIds.delete(kw.id);
            }
        },

        // ── Editar y borrar categorías del cliente ──
        openCategoriesManager() {
            this.showCategoriesManager = true;
        },
        ownCategoriesCount() {
            return this.categories.filter(c => !c.is_admin).length;
        },
        openEditCategory(cat) {
            // El cliente solo puede editar las suyas (no las administradas). Es
            // una salvaguarda UX: si por carrera asincrónica la categoría ya
            // no aparece, simplemente no abrimos el modal.
            if (!cat || cat.is_admin) return;
            this.editingCategory = cat;
            this.editCategoryForm = { name: cat.name, color_hex: cat.color_hex };
            this.editCategoryError = '';
        },
        async saveEditedCategory() {
            if (!this.editingCategory) return;
            this.savingEditCategory = true;
            this.editCategoryError = '';
            try {
                const res = await apiFetch('/mis-avisos/categories/' + this.editingCategory.id, {
                    method: 'PATCH', credentials: 'same-origin', headers: this.headers(),
                    body: JSON.stringify({ name: this.editCategoryForm.name, color_hex: this.editCategoryForm.color_hex }),
                });
                if (res.ok) {
                    this.editingCategory = null;
                    await this.refreshCategories();
                    this.pushToast('Categoría actualizada', 'success', 1800);
                } else {
                    let msg = 'No se pudo guardar la categoría';
                    try { const d = await res.json(); msg = d.error || msg; } catch (_) {}
                    this.editCategoryError = msg;
                }
            } catch (e) {
                this.editCategoryError = 'Error de red';
            } finally {
                this.savingEditCategory = false;
            }
        },
        async askDeleteCategory(cat) {
            if (!cat || cat.is_admin) return;
            this.pendingDeleteCatId = cat.id;
            // Calculamos el conteo de keywords que van a quedar sin categoría
            // en el momento de pedir confirmación (estado actual del cliente).
            try {
                this.pendingDeleteCatKeywordsCount = this.keywords.filter(k => Number(k.category_id) === Number(cat.id)).length;
            } catch (e) {
                this.pendingDeleteCatKeywordsCount = cat.keywords_count ?? 0;
            }
        },
        cancelDeleteCategory() {
            this.pendingDeleteCatId = null;
            this.pendingDeleteCatKeywordsCount = 0;
        },
        async confirmDeleteCategory(cat) {
            if (!cat || this.deletingCategory) return;
            this.deletingCategory = true;
            try {
                const res = await apiFetch('/mis-avisos/categories/' + cat.id, {
                    method: 'DELETE', credentials: 'same-origin', headers: this.headers(false),
                });
                if (res.ok) {
                    // Limpia las keywords locales que la tenían apuntada.
                    for (const kw of this.keywords) {
                        if (Number(kw.category_id) === Number(cat.id)) kw.category_id = null;
                    }
                    this.pendingDeleteCatId = null;
                    this.pendingDeleteCatKeywordsCount = 0;
                    await this.refreshCategories();
                    this.pushToast('Categoría eliminada', 'success', 1800);
                } else {
                    let msg = 'No se pudo eliminar la categoría';
                    try { const d = await res.json(); msg = d.error || msg; } catch (_) {}
                    this.pushToast(msg, 'error', 4000);
                }
            } catch (e) {
                this.pushToast('Error de red', 'error', 4000);
            } finally {
                this.deletingCategory = false;
            }
        },

        // ── Split-screen de alcance ──
        selectedKeyword() { return this.keywords.find(k => k.id === this.selectedKeywordId) || null; },
        selectKeyword(kw) {
            // Bloque defensivo contra confirm() espurio: si todavía no hay
            // selección previa (carga inicial o auto-select de loadKeywords),
            // nunca se trata de cambios "sin guardar".
            const isFirstSelection = this.selectedKeywordId === null || this.selectedKeywordId === undefined;
            const switchingToDifferent = !isFirstSelection && this.selectedKeywordId !== kw.id;
            if (switchingToDifferent && this.scopeDirty() && !confirm('Hay cambios sin guardar en el alcance anterior. ¿Descartarlos?')) return;
            this.selectedKeywordId = kw.id;
            // Sin filas de scope = "todos mis medios": el editor muestra todos marcados.
            this.draftScope = (kw.storage_ids && kw.storage_ids.length > 0)
                ? [...kw.storage_ids]
                : this.storages.map(s => s.id);
        },
        toggleDraft(id, checked) {
            if (checked) { if (!this.draftScope.includes(id)) this.draftScope.push(id); }
            else this.draftScope = this.draftScope.filter(x => x !== id);
        },
        markAllStorages(on) { this.draftScope = on ? this.storages.map(s => s.id) : []; },
        scopeDirty() {
            const kw = this.selectedKeyword();
            if (!kw) return false;
            // Normalización semántica: kw.storage_ids=[] es equivalente a
            // draftScope cubriendo todos los storages actuales (y al revés).
            // Si las dos representaciones describen "todos mis medios" el
            // estado es limpio aunque textualmente difieran.
            const totalIds = this.storages.map(s => Number(s.id));
            const totalSet = new Set(totalIds);
            const draftSet = new Set(this.draftScope.map(Number));
            const savedSet = new Set((kw.storage_ids || []).map(Number));

            // Caso A: saved vacío Y draft = total → equivalente a "todos".
            if (savedSet.size === 0 && this.sameSet(draftSet, totalSet)) return false;
            // Caso B: saved = total Y draft = total (incluye el caso B' donde
            // saved tiene exactamente todos los storages aunque esté poblado).
            if (this.sameSet(savedSet, totalSet) && this.sameSet(draftSet, totalSet)) return false;

            // Resto: comparar sets sin importar orden.
            if (savedSet.size !== draftSet.size) return true;
            for (const v of savedSet) if (!draftSet.has(v)) return true;
            return false;
        },
        sameSet(a, b) {
            if (a.size !== b.size) return false;
            for (const v of a) if (!b.has(v)) return false;
            return true;
        },
        revertScope() {
            const kw = this.selectedKeyword();
            if (!kw) return;
            this.draftScope = (kw.storage_ids && kw.storage_ids.length > 0) ? [...kw.storage_ids] : this.storages.map(s => s.id);
        },
        scopeSummaryText() {
            const kw = this.selectedKeyword();
            if (!kw) return '';
            const n = this.draftScope.length;
            if (n === 0) return 'Ningún medio marcado';
            if (n === this.storages.length) return 'Todos tus medios (' + n + ')';
            return n + ' de ' + this.storages.length + ' medios';
        },
        scopeBadgeClass(kw) {
            if (!kw.storage_ids || kw.storage_ids.length === 0) return 'bg-green-500';
            if (kw.storage_ids.length >= this.storages.length && this.storages.length > 0) return 'bg-green-500';
            return 'bg-amber-500';
        },
        scopeBadgeTitle(kw) {
            if (!kw.storage_ids || kw.storage_ids.length === 0) return 'Rastreando en todos tus medios';
            return 'Rastreando en ' + kw.storage_ids.length + ' de ' + this.storages.length + ' medios';
        },

        scopeLabel(kw) {
            if (!kw.storage_ids || kw.storage_ids.length === 0) return '· todos mis medios';
            const names = kw.storage_ids.map(id => (this.storages.find(s => s.id === id) || {}).name || '').filter(Boolean);
            return '· ' + (names.length ? names.join(', ') : 'medios seleccionados');
        },

        async loadKeywords() {
            // Hidratar el alcance real (scopes guardados) de cada keyword.
            const res = await apiFetch('/mis-avisos/storages', { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.storages = d.storages || [];
                const scopes = d.scopes || {};
                for (const kw of this.keywords) {
                    // Sin filas de scope = "todos mis medios" (default).
                    kw.storage_ids = scopes[kw.id] || [];
                }
                // Auto-seleccionar la primera keyword para mostrar el split de una vez.
                if (this.keywords.length > 0 && !this.selectedKeywordId) this.selectKeyword(this.keywords[0]);
            }
        },
        async loadStorages() { /* reemplazado: storages llegan en loadKeywords() */ },

        // ── Feed en vivo con filtros + paginación ──
        liveHasFilters() {
            const f = this.liveFilters;
            return !!(f.q.trim() || f.keyword_id || f.storage_ids.length > 0 || f.media_type !== 'all');
        },
        applyLiveFilters() {
            this.livePage = 1; this.liveTotal = 0; this.newLiveCount = 0;
            this.pollLive();
        },
        // change mis-avisos-media-kind-indicator (G4): toggle del filtro TV/Radio.
        setMediaFilter(scope, value) {
            this[scope].media_type = value;
            if (scope === 'liveFilters') this.applyLiveFilters();
            else this.searchHistory(1);
        },
        // change mis-avisos-media-kind-indicator (extra): ordenamiento de tabla
        // por columna. State independiente por tab (liveSort vs historySort).
        // Click: si es la misma columna, alterna asc/desc; si es otra, parte en asc.
        setSort(scope, column) {
            const key = scope === 'historyFilters' ? 'historySort' : 'liveSort';
            const cur = this[key];
            if (cur.column === column) {
                cur.direction = cur.direction === 'asc' ? 'desc' : 'asc';
            } else {
                cur.column = column;
                cur.direction = 'asc';
            }
            // Forzar invalidación del getter: en Alpine 3 la mutación in-place de
            // un objeto no siempre dispara la recálculo del computed. Asignar la
            // propiedad (Proxy.set) es lo que garantiza la reactividad.
            this[key] = { ...cur };
        },
        // Aplica el sort actual sobre el array de groups.
        _sortedGroups(groups, sort) {
            if (!Array.isArray(groups) || groups.length === 0) return groups;
            const dir = sort.direction === 'asc' ? 1 : -1;
            const col = sort.column;
            return [...groups].sort((a, b) => {
                const av = this.groupSortValue(a, col);
                const bv = this.groupSortValue(b, col);
                if (av === bv) return 0;
                if (av < bv) return -1 * dir;
                return 1 * dir;
            });
        },
        groupSortValue(g, col) {
            switch (col) {
                case 'storage':    return (g.storage || '').toString().toLowerCase();
                case 'filename':   return (g.filename || '').toString().toLowerCase();
                case 'keyword':    return (g.keyword || '').toString().toLowerCase();
                case 'occurrences': return Number(g.occurrences_in_media || 0);
                case 'snippet':    return (g.first_snippet || '').toString().toLowerCase();
                case 'recorded_at': return g.first_recorded_at || '';
                case 'matched_at':
                default:           return g.first_matched_at || '';
            }
        },
        // Icono de sort: 'fa-sort' (inactivo), 'fa-arrow-up' (asc), 'fa-arrow-down' (desc).
        sortIcon(scope, col) {
            const sort = scope === 'historyFilters' ? this.historySort : this.liveSort;
            if (sort.column !== col) return 'fa-sort';
            return sort.direction === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down';
        },
        // Color de la flecha: gris-300 inactivo, brand-600 activo.
        sortIconClass(scope, col) {
            const sort = scope === 'historyFilters' ? this.historySort : this.liveSort;
            return sort.column === col
                ? (sort.direction === 'asc' ? 'text-violet-600' : 'text-amber-600')
                : 'text-slate-300';
        },
        // Color del header: slate-700 cuando activo, slate-500 normal.
        sortHeaderClass(scope, col) {
            const sort = scope === 'historyFilters' ? this.historySort : this.liveSort;
            return sort.column === col
                ? 'text-slate-800 font-semibold'
                : 'text-slate-500 hover:text-slate-800';
        },
        historyHasFilters() {
            const f = this.historyFilters;
            return !!(f.q.trim() || f.from || f.to || f.keyword_id || f.storage_ids.length > 0 || f.media_type !== 'all');
        },
        clearHistoryFilters() {
            // change 2026-09-10-mis-avisos-program-date-filter: reset
            // preserva el toggle en default 'program' (no lo borra).
            this.historyFilters = { q: '', from: '', to: '', storage_ids: [], keyword_id: 0, media_type: 'all', date_field: 'program' };
            this.activeDateShortcut = null;
            this.searchHistory(1);
        },
        clearLiveFilters() {
            this.liveFilters = { q: '', storage_ids: [], keyword_id: 0, media_type: 'all' };
            this.applyLiveFilters();
        },
        toggleFilterStorage(scope, id, checked) {
            const list = this[scope].storage_ids;
            if (checked) { if (!list.includes(id)) list.push(id); }
            else this[scope].storage_ids = list.filter(x => x !== id);
            if (scope === 'liveFilters') this.applyLiveFilters();
        },
        pageList(current, last) {
            if (!last || last <= 7) return Array.from({ length: Math.max(1, last || 1) }, (_, i) => i + 1);
            const pages = [1];
            const start = Math.max(2, current - 2), end = Math.min(last - 1, current + 2);
            if (start > 2) pages.push('…');
            for (let p = start; p <= end; p++) pages.push(p);
            if (end < last - 1) pages.push('…');
            pages.push(last);
            return pages;
        },
        setPerPage(mode, n) {
            const per = [25, 50, 100, 500].includes(+n) ? +n : 25;
            if (mode === 'live') { this.livePerPage = per; this.livePage = 1; this.pollLive(); }
            else { this.historyPerPage = per; this.searchHistory(1); }
        },
        goPage(mode, page) {
            if (mode === 'live') { this.livePage = Math.max(1, page); this.newLiveCount = 0; this.pollLive(); }
            else this.searchHistory(page);
        },

        startLive() {
            this.livePolling = true;
            this.pollLive();
            this.liveTimer = setInterval(() => this.pollLive(), 20000);
        },
        stopLive() {
            this.livePolling = false;
            if (this.liveTimer) clearInterval(this.liveTimer);
        },

        // ── Agrupación por (archivo, keyword) para Histórico/En vivo ──
        // Reduce las filas planas del backend en una sola entrada por grupo.
        // La fila resumen muestra el total agregado; al expandir se ven las
        // menciones reales individualmente. Esto mejora mucho la densidad
        // visual cuando el cliente busca palabras mencionadas muchas veces.
        // El `mode` se antepone a la key para que el expand/collapse no se
        // filtre entre las dos tablas (live vs history).
        get displayHistoryRows() {
            // Destructuramos explícitamente para que Alpine detecte cada prop
            // como dependencia y re-evalúe al cambiar la sort.
            const sortCol = this.historySort.column;
            const sortDir = this.historySort.direction;
            return this._sortedGroups(
                this._groupRows(this.historyRows, 'history'),
                { column: sortCol, direction: sortDir }
            );
        },
        get displayLiveRows() {
            const sortCol = this.liveSort.column;
            const sortDir = this.liveSort.direction;
            return this._sortedGroups(
                this._groupRows(this.liveRows, 'live'),
                { column: sortCol, direction: sortDir }
            );
        },
        _groupRows(rows, mode = '') {
            const byKey = new Map();
            for (const r of (rows || [])) {
                // file_id puede ser null; usamos 0 para agrupar los "sin
                // archivo" en un solo bloque al fondo.
                const fid = r.file_id ?? 0;
                const kw = (r.keyword || '').toString();
                const baseKey = fid + '::' + kw;
                const key = mode ? mode + ':' + baseKey : baseKey;
                if (!byKey.has(key)) {
                    byKey.set(key, {
                        key: key,
                        file_id: r.file_id,
                        filename: r.filename,
                        file_url: r.file_url,
                        storage: r.storage,
                        storage_id: r.storage_id,
                        parent_id: r.parent_id,
                        transcription_id: r.transcription_id,
                        keyword: r.keyword,
                        // occurrences_in_media ya viene consistente por fila
                        // (mismo valor para el mismo file_id+keyword). Si el
                        // backend lo omite en una fila, usar el máximo.
                        occurrences_in_media: r.occurrences_in_media || 1,
                        can_view_file: r.can_view_file,
                        can_clip: r.can_clip,
                        // Para el resumen en la fila padre usamos los campos
                        // de la PRIMERA mención que entró (típicamente la
                        // más reciente, dado el orderByDesc del backend).
                        first_id: r.id,
                        first_matched_at: r.matched_at,
                        // change 2026-09-10-mis-avisos-program-date-filter:
                        // fecha del programa (recorded_at) por grupo.
                        first_recorded_at: r.recorded_at,
                        first_minute_label: r.minute_label,
                        first_start_seconds: r.start_seconds,
                        first_segment_id: r.segment_id,
                        first_snippet: r.snippet,
                        // change mis-avisos-media-kind-indicator: tipo de medio de
                        // la primera mención (todas comparten mime_type porque
                        // comparten archivo).
                        first_media_kind: r.media_kind || 'other',
                        hits: [],
                    });
                }
                const g = byKey.get(key);
                g.hits.push({
                    id: r.id,
                    matched_at: r.matched_at,
                    minute_label: r.minute_label,
                    start_seconds: r.start_seconds,
                    segment_id: r.segment_id,
                    snippet: r.snippet,
                    occurrences: r.occurrences,
                    // change mis-avisos-media-kind-indicator: media_kind por hit
                    // para que el sub-panel expandido pueda mostrar su ícono.
                    media_kind: r.media_kind || 'other',
                    filename: r.filename,
                });
            }
            // Ordenar por la mención más reciente del grupo DESC.
            return Array.from(byKey.values()).sort((a, b) => {
                const ta = String(a.first_matched_at || ''),
                    tb = String(b.first_matched_at || '');
                return tb.localeCompare(ta);
            });
        },
        isGroupExpanded(key) { return this.expandedGroups && this.expandedGroups.has(key); },
        toggleGroupExpansion(key) {
            if (!this.expandedGroups) this.expandedGroups = new Set();
            if (this.expandedGroups.has(key)) this.expandedGroups.delete(key);
            else this.expandedGroups.add(key);
        },
        async pollLive() {
            try {
                const f = this.liveFilters;
                const params = new URLSearchParams();
                params.set('page', this.livePage);
                params.set('per_page', this.livePerPage);
                if (f.q.trim()) params.set('q', f.q.trim());
                if (f.keyword_id) params.set('keyword_id', f.keyword_id);
                f.storage_ids.forEach(id => params.append('storage_ids[]', id));
                // change mis-avisos-media-kind-indicator (G4): propaga media_type al feed.
                if (f.media_type && f.media_type !== 'all') params.set('media_type', f.media_type);
                const res = await apiFetch('/mis-avisos/feed?' + params.toString(), { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
                if (res.ok) {
                    const d = await res.json();
                    // Badge de nuevas solo si el cliente está en otra página.
                    if (d.total > this.liveTotal && this.liveTotal > 0 && this.livePage > 1) {
                        this.newLiveCount += d.total - this.liveTotal;
                    }
                    this.liveRows = d.data;
                    this.livePage = d.current_page;
                    this.liveLastPage = d.last_page;
                    this.liveTotal = d.total;
                }
            } catch (e) { /* silencio: el siguiente ciclo reintenta */ }
        },

        // Atajos de fecha (horario Colombia): llenan Desde/Hasta y buscan.
        dateShortcuts: [
            { label: 'Hoy', title: 'Coincidencias de hoy', days: 0 },
            { label: 'Ayer', title: 'Solo el día de ayer', days: 1, onlyThatDay: true },
            { label: '3 días', title: 'Últimos 3 días (incluye hoy)', days: 3 },
            { label: '7 días', title: 'Últimos 7 días (incluye hoy)', days: 7 },
        ],
        activeDateShortcut: null,
        fmtDate(d) {
            const y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), dd = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${dd}`;
        },
        shortcutRange(shortcut) {
            // Semántica: "Ayer" = SOLO ese día; los rangos acumulan hasta hoy.
            const now = new Date();
            const today = this.fmtDate(now);
            if (shortcut.onlyThatDay) {
                const y = new Date(now); y.setDate(y.getDate() - 1);
                return { from: this.fmtDate(y), to: this.fmtDate(y) };
            }
            if (shortcut.days === 0) return { from: today, to: today };
            const s = new Date(now); s.setDate(s.getDate() - (shortcut.days - 1));
            return { from: this.fmtDate(s), to: today };
        },
        isShortcutActive(shortcut) {
            if (this.activeDateShortcut !== shortcut.label) return false;
            const r = this.shortcutRange(shortcut);
            return this.historyFilters.from === r.from && this.historyFilters.to === r.to;
        },
        applyDateShortcut(shortcut) {
            const { from, to } = this.shortcutRange(shortcut);
            this.historyFilters.from = from;
            this.historyFilters.to = to;
            this.activeDateShortcut = shortcut.label;
            this.searchHistory(1);
        },
        async searchHistory(page) {
            this.historyError = '';
            const f = this.historyFilters;
            const params = new URLSearchParams();
            params.set('page', page);
            params.set('per_page', this.historyPerPage);
            if (f.q) params.set('q', f.q);
            if (f.from) params.set('from', f.from);
            if (f.to) params.set('to', f.to);
            if (f.keyword_id) params.set('keyword_id', f.keyword_id);
            f.storage_ids.forEach(id => params.append('storage_ids[]', id));
            // change mis-avisos-media-kind-indicator (G4): propaga media_type al histórico.
            if (f.media_type && f.media_type !== 'all') params.set('media_type', f.media_type);
            // change 2026-09-10-mis-avisos-program-date-filter: propaga date_field.
            if (f.date_field) params.set('date_field', f.date_field);
            this.syncHistoryUrl(params);
            const res = await apiFetch('/mis-avisos/history?' + params.toString(), { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.historyRows = d.data; this.historyPage = d.current_page;
                this.historyLastPage = d.last_page; this.historyTotal = d.total;
                this.historySearched = true;
            } else {
                const d = await res.json();
                this.historyError = d.error || 'Error al buscar';
                this.historyRows = [];
            }
        },
        syncHistoryUrl(params) {
            const clean = new URLSearchParams();
            for (const [k, v] of params) { if (k !== 'page' && v) clean.append(k, v); }
            const qs = clean.toString();
            history.replaceState(null, '', '/mis-avisos' + (qs ? '?' + qs : ''));
        },
        hydrateHistoryFromUrl() {
            const sp = new URLSearchParams(location.search);
            if (!sp.toString()) return false;
            const f = this.historyFilters;
            f.q = sp.get('q') || '';
            f.from = sp.get('from') || '';
            f.to = sp.get('to') || '';
            f.keyword_id = parseInt(sp.get('keyword_id') || '0', 10) || 0;
            f.storage_ids = sp.getAll('storage_ids[]').map(Number).filter(Boolean);
            // change 2026-09-10-mis-avisos-program-date-filter: persistir el
            // toggle en la URL para deep-linking. Validar contra whitelist;
            // cualquier valor fuera de la lista cae al default 'program'.
            const df = sp.get('date_field');
            f.date_field = (df === 'program' || df === 'detected') ? df : 'program';
            this.activeTab = 'history';
            this.$nextTick(() => this.searchHistory(1));
            return true;
        },

        async saveScope() {
            const kw = this.selectedKeyword();
            if (!kw) return;
            // "Sin filas = todos": si marcó todos, guardar vacío (semántica default).
            const all = this.draftScope.length === this.storages.length && this.storages.length > 0;
            const payload = all ? [] : this.draftScope;
            const res = await apiFetch('/mis-avisos/keywords/' + kw.id + '/scope', {
                method: 'PUT', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify({ storage_ids: payload }),
            });
            if (res.ok) {
                kw.storage_ids = payload;
                this.draftScope = all ? this.storages.map(s => s.id) : [...payload];
            } else { const d = await res.json(); alert(d.error || 'Error'); }
        },

        get exportButtonLabel() {
            if (this.exportBusy) return 'Preparando...';
            if (this.activeExport && ['queued','processing'].includes(this.activeExport.status)) return 'Generando...';
            return 'Exportar CSV';
        },
        get exportStatusText() {
            const s = this.activeExport?.status;
            if (s === 'queued' || s === 'processing') return 'Generando tu archivo; en unos segundos estará listo.';
            if (s === 'ready') return 'Listo: ' + (this.activeExport.rows_count ?? 0) + ' coincidencia(s). El enlace expira automáticamente.';
            if (s === 'failed') return 'La exportación falló. Intenta de nuevo.';
            return '';
        },
        async requestExport() {
            this.exportBusy = true; this.historyError = '';
            const res = await apiFetch('/mis-avisos/exports', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify(this.historyFilters),
            });
            if (res.ok) {
                const d = await res.json();
                this.activeExport = { id: d.export_id, status: 'queued', rows_count: 0, download_url: null };
                this.pollExport();
            } else {
                const d = await res.json();
                this.historyError = d.error || 'No se pudo iniciar la exportación';
            }
            this.exportBusy = false;
        },
        async pollExport() {
            if (!this.activeExport) return;
            const res = await apiFetch('/mis-avisos/exports/' + this.activeExport.id, { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.activeExport = d;
                if (['queued','processing'].includes(d.status)) {
                    this.exportPoll = setTimeout(() => this.pollExport(), 3000);
                }
            }
        },
        async emailExport() {
            if (!this.activeExport) return;
            const res = await apiFetch('/mis-avisos/exports/' + this.activeExport.id + '/email', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
            });
            if (res.ok) { const d = await res.json(); alert('Enlace enviado a ' + d.sent + ' de ' + d.total + ' correos registrados.'); }
            else { const d = await res.json(); alert(d.error || 'Error al enviar'); }
        },

        async loadPreferences() {
            const res = await apiFetch('/mis-avisos/preferences', { method: 'GET', credentials: 'same-origin', headers: this.headers(false) });
            if (res.ok) {
                const d = await res.json();
                this.frequency = this.originalFrequency = d.alert_frequency_minutes;
                this.projection = d.projection || {};
                this.emailsQuota = d.emails_quota;
                this.pendingReposition = d.pending_reposition;
            }
        },
        async saveFrequency() {
            const res = await apiFetch('/mis-avisos/preferences', {
                method: 'PUT', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify({ alert_frequency_minutes: this.frequency }),
            });
            if (res.ok) { this.originalFrequency = this.frequency; this.loadPreferences(); }
            else { const d = await res.json(); alert(d.error || 'Error'); }
        },

        // ── Visor de transcripción (mentions-viewer) ──
        // Toda la lógica (estado + métodos + render de segmentos) vive en
        // Alpine.store('transcriptViewer'), registrado en layouts/app.blade.php.
        // Aquí solo quedan los redireccionamientos al editor de corte desde una
        // fila de la tabla o desde el modal (openClipFromRow / openClipFromAnchor).
        filesDeepLink(row) {
            if (!row.file_id) return '/files';
            const p = new URLSearchParams();
            if (row.storage_id) p.set('storage_id', row.storage_id);
            if (row.parent_id) p.set('folder', row.parent_id);
            p.set('highlight_file', row.file_id);
            return '/files?' + p.toString();
        },

        // ── Corte: redirige al editor unificado en /files ──
        // El editor vive SOLO en /files/index.blade.php (un solo lugar para
        // mantener; si se ajusta el editor se ajusta para todos los módulos).
        buildClipDeepLink(fileId, storageId, start, end) {
            if (!fileId || !storageId) return null;
            const params = new URLSearchParams();
            params.set('storage_id', storageId);
            params.set('clip_file', fileId);
            if (start !== null && start !== undefined && !Number.isNaN(start)) {
                params.set('clip_start', Math.max(0, Math.floor(start)));
            }
            if (end !== null && end !== undefined && !Number.isNaN(end)) {
                params.set('clip_end', Math.max(0, Math.ceil(end)));
            }
            return '/files?' + params.toString();
        },
        openClipFromRow(row) {
            if (!row.can_clip || !row.file_id || !row.storage_id) return;
            const url = this.buildClipDeepLink(
                row.file_id,
                row.storage_id,
                row.start_seconds ?? 0,
                row.end_seconds ?? row.start_seconds ?? 0
            );
            if (url) window.location.href = url;
        },
        openClipFromAnchor() {
            // El estado vive ahora en Alpine.store('transcriptViewer'): usamos
            // su helper openClipFromAnchor que ya arma el deep-link con los
            // segmentos visibles y navega al editor unificado de /files.
            const tv = window.Alpine && window.Alpine.store('transcriptViewer');
            if (tv && typeof tv.openClipFromAnchor === 'function') {
                tv.openClipFromAnchor();
            }
        },

        // Métodos heredados (keywords + correcciones)
        async addKeyword() {
            if (!this.newKeyword || this.used >= this.quota) return;
            const payload = { text: this.newKeyword };
            if (this.newKeywordCategoryId !== '') payload.category_id = Number(this.newKeywordCategoryId);
            const res = await apiFetch('/mis-avisos/keywords', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify(payload),
            });
            if (res.ok) {
                const d = await res.json();
                this.keywords.push({
                    id: d.keyword.id,
                    text: d.keyword.text,
                    storage_ids: [],
                    category_id: d.category_id ?? null,
                });
                this.used = d.used;
                this.newKeyword = '';
                this.newKeywordCategoryId = '';
                await this.refreshCategories();
                this.pushToast('Palabra agregada', 'success', 1600);
            } else {
                let msg = 'No se pudo agregar la palabra';
                try { const d = await res.json(); msg = d.error || msg; } catch (_) {}
                this.pushToast(msg, 'error', 4000);
            }
        },
        async removeKeyword(id) {
            const res = await apiFetch('/mis-avisos/keywords/' + id, {
                method: 'DELETE', credentials: 'same-origin', headers: this.headers(false),
            });
            if (res.ok) {
                this.keywords = this.keywords.filter(k => k.id !== id);
                this.used = Math.max(0, this.used - 1);
                await this.refreshCategories();
            }
        },
        showModal: false,
        form: { wrong_text: '', correct_text: '', segment_id: null },
        openCorrection(opts) {
            this.form.wrong_text = opts.wrong || '';
            this.form.correct_text = '';
            this.form.segment_id = opts.segmentId || null;
            this.showModal = true;
        },
        async submitCorrection() {
            const res = await apiFetch('/mis-avisos/corrections', {
                method: 'POST', credentials: 'same-origin', headers: this.headers(),
                body: JSON.stringify(this.form),
            });
            if (res.ok) { this.showModal = false; alert('Propuesta enviada para revisión'); }
            else { const d = await res.json(); alert(Object.values(d.errors || {})[0]?.[0] || d.error || 'Error'); }
        },
    };
}
</script>
@endpush
{{-- Tour interactivo (patrón interactive-tour.js) --}}
<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startMisAvisosTour() {
    TcloudTour.start({
        steps: [
            {
                title: 'Mis Avisos — tu rastreo de menciones',
                content: 'Este módulo vigila tus palabras clave en las transcripciones de los medios que contrataste. ' +
                         'Todo lo que ves aquí respeta el acceso concedido por el administrador.',
                icon: 'fa-bell',
                color: '#4654a8',
                selector: null,
                position: 'center',
            },
            {
                title: 'En vivo',
                content: 'Las coincidencias de hoy aparecen aquí automáticamente, sin recargar la página. ' +
                         'Cada una muestra el medio, el minuto exacto y un fragmento del texto.',
                icon: 'fa-satellite-dish',
                color: '#16a34a',
                selector: null,
                position: 'center',
            },
            {
                title: 'Histórico de 60 días',
                content: 'Busca libremente dentro de las transcripciones de tus medios y exporta los resultados a CSV. ' +
                         'La búsqueda cubre hasta 60 días atrás. Puedes enviarte el archivo a tu correo cuando lo necesites.',
                icon: 'fa-folder-open',
                color: '#0ea5e9',
                selector: null,
                position: 'center',
            },
            {
                title: 'Palabras clave y su alcance',
                content: 'Crea y administra tus palabras (el límite lo define tu plan). ' +
                         'Al seleccionar una palabra, a la derecha eliges en qué medios se busca: sin marcar ninguno se rastrea en todos tus medios.',
                icon: 'fa-key',
                color: '#4654a8',
                selector: null,
                position: 'center',
            },
            {
                title: 'Preferencias de aviso',
                content: 'Tú decides cada cuánto recibir correos: de cada minuto a un resumen diario. ' +
                         'La proyección usa tu actividad real. Al agotar tu cupo diario, lo pendiente sale en el resumen del día siguiente — nunca se pierde.',
                icon: 'fa-sliders',
                color: '#f59e0b',
                selector: null,
                position: 'center',
            },
        ],
    });
}
</script>
@endsection