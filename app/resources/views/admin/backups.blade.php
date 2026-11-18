@extends('layouts.app')

@section('title', 'Backup de produccion - Tcloud')

@section('content')
<div class="p-6" x-data="backupAdmin()" x-init="init()">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Backup de produccion</h1>
            <p class="text-slate-500 mt-0.5">
                Declara que carpeta del arbol de produccion corresponde a cada storage de origen.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="refresh()"
                    class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-rotate" :class="loading ? 'fa-spin' : ''"></i>
                <span>Actualizar</span>
            </button>
            <button @click="openScan()" x-show="missingCount > 0"
                    class="flex items-center gap-2 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-magnifying-glass-location"></i>
                <span>Escanear rutas rotas</span>
                <span class="px-1.5 py-0.5 rounded bg-white/25 text-xs font-semibold" x-text="missingCount"></span>
            </button>
            <button @click="openCreate()" data-backup-tour="assign"
                    class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">
                <i class="fas fa-link"></i>
                <span>Asignar carpeta</span>
            </button>
            <button @click="startBackupTour()"
                    class="flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-sm font-medium transition-colors"
                    title="Guía interactiva">
                <i class="fas fa-map-marked-alt"></i>
                <span class="hidden sm:inline">Guía</span>
            </button>
        </div>
    </div>

    <!-- Toast -->
    <div x-cloak x-show="toast" x-transition
         :class="toast?.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
         class="fixed top-4 right-4 z-50 px-4 py-3 rounded-xl text-white shadow-lg text-sm font-medium">
        <span x-text="toast?.msg"></span>
    </div>

    <!-- Aviso: montaje caido -->
    <div x-show="mountDown" x-cloak
         class="mb-4 flex items-start gap-3 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800">
        <i class="fas fa-triangle-exclamation mt-0.5"></i>
        <div class="text-sm">
            <p class="font-semibold">El montaje de red del arbol de backup esta caido.</p>
            <p class="mt-0.5">No se puede leer el arbol ni verificar carpetas. El export NFS esta en modo <code>hard</code>: un acceso al disco bloquea las peticiones.</p>
        </div>
    </div>

    <!-- Cobertura -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div data-backup-tour="coverage-folders" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Carpetas del arbol</p>
            <p class="mt-2 text-3xl font-bold text-slate-800" x-text="folders.length"></p>
            <p class="mt-1 text-xs text-slate-500 truncate" x-text="rootLabel"></p>
        </div>

        <div data-backup-tour="coverage-mapped" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Con origen declarado</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600" x-text="mappedFolders"></p>
            <p class="mt-1 text-xs text-slate-500" x-text="orphanFolders.length + ' sin origen (huérfanas)'"></p>
        </div>

        <div data-backup-tour="coverage-sources" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Storages de origen</p>
            <p class="mt-2 text-3xl font-bold text-slate-800" x-text="rows.length"></p>
            <p class="mt-1 text-xs text-slate-500" x-text="unmappedSources + ' sin backup asignado'"></p>
        </div>

        <div data-backup-tour="coverage-broken" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Vínculos inválidos</p>
            <p class="mt-2 text-3xl font-bold" :class="brokenSources > 0 ? 'text-red-600' : 'text-slate-800'"
               x-text="brokenSources"></p>
            <p class="mt-1 text-xs text-slate-500">Destino borrado o no local</p>
        </div>
    </div>

    <!-- Carpetas huerfanas -->
    <div data-backup-tour="orphans" x-show="orphanFolders.length > 0" x-cloak class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200">
        <button @click="orphansOpen = !orphansOpen" class="w-full flex items-center justify-between px-5 py-4 text-left">
            <div>
                <h2 class="text-sm font-semibold text-slate-800">Carpetas del árbol sin storage de origen</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Existen en el arbol de produccion pero ningun storage de origen las reclama. El cliente no puede pedirlas.
                </p>
            </div>
            <i class="fas fa-chevron-down text-slate-400 transition-transform" :class="orphansOpen ? 'rotate-180' : ''"></i>
        </button>
        <div x-show="orphansOpen" class="px-5 pb-4 border-t border-slate-100">
            <div class="flex flex-wrap gap-2 pt-3">
                <template x-for="folder in orphanFolders" :key="folder">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-medium">
                        <i class="fas fa-folder-open text-[10px]"></i>
                        <span x-text="folder"></span>
                        <template x-if="suggestionFor(folder)">
                            <span class="text-amber-600/80" x-text="'→ ' + suggestionFor(folder)"></span>
                        </template>
                    </span>
                </template>
            </div>
            <p class="mt-3 text-xs text-slate-400">
                La flecha es una <strong>sugerencia</strong> por nombre compartido: no escribe nada. Declara el vinculo a mano.
            </p>
        </div>
    </div>

    <!-- Listado de storages -->
    <div data-backup-tour="listing" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
            <div data-backup-tour="search" class="relative flex-1 min-w-[220px]">
                <input type="text" x-model="search" placeholder="Buscar storage o ruta..."
                       class="w-full border border-slate-300 rounded-lg pl-9 pr-4 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-sm"></i>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" x-model="onlyUnmapped" class="rounded border-slate-300">
                Solo sin mapear
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" x-model="onlyContainers" class="rounded border-slate-300">
                Solo los que listan el disco
            </label>
            <div class="flex items-center gap-2 text-xs">
                <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-medium"
                      x-text="mappedCount + ' mapeados'"></span>
                <span x-show="brokenCount > 0" class="px-2 py-1 rounded-lg bg-red-50 text-red-700 font-medium"
                      x-text="brokenCount + ' inválidos'"></span>
                <span class="px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-medium"
                      x-text="containerCount + ' listan el disco'"></span>
                <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-500 font-medium"
                      x-text="unmappedCount + ' sin mapear'"></span>
            </div>
            <span class="text-xs text-slate-400" x-text="filtered().length + ' de ' + rows.length"></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <button @click="toggleSort('name')" class="inline-flex items-center gap-1 hover:text-brand-600 transition-colors">
                                Storage de origen
                                <i class="fas fa-sort text-[10px]" :class="sortIcon('name')"></i>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <button @click="toggleSort('folder')" class="inline-flex items-center gap-1 hover:text-brand-600 transition-colors">
                                Carpeta de backup
                                <i class="fas fa-sort text-[10px]" :class="sortIcon('folder')"></i>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">
                            <button @click="toggleSort('destination')" class="inline-flex items-center gap-1 hover:text-brand-600 transition-colors">
                                Árbol destino
                                <i class="fas fa-sort text-[10px]" :class="sortIcon('destination')"></i>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <button @click="toggleSort('state')" class="inline-flex items-center gap-1 hover:text-brand-600 transition-colors">
                                Estado
                                <i class="fas fa-sort text-[10px]" :class="sortIcon('state')"></i>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in sorted()" :key="row.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-slate-800" x-text="row.name"></p>
                                <p class="text-xs text-slate-400 truncate max-w-sm" x-text="row.base_path"></p>
                                <p x-show="row.is_container" class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[11px] font-medium">
                                    <i class="fas fa-layer-group text-[10px]"></i>Lista el disco: su contenido son emisoras, no un canal
                                </p>
                                <p x-show="row.path_state === 'missing'" class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-50 text-red-700 text-[11px] font-medium">
                                    <i class="fas fa-triangle-exclamation text-[10px]"></i>La ruta base no existe en el disco
                                </p>
                                <p x-show="row.path_state === 'mount_down'" class="mt-0.5 text-[11px] text-amber-600">
                                    <i class="fas fa-plug-circle-xmark mr-0.5"></i>Montaje de red caído
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <template x-if="row.folder">
                                    <code class="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded" x-text="row.folder"></code>
                                </template>
                                <template x-if="!row.folder">
                                    <span class="text-xs text-slate-400">— sin asignar —</span>
                                </template>
                                <template x-if="row.state === 'unmapped' && row.suggestion">
                                    <p class="mt-1 text-[11px] text-slate-400" x-text="'sugerencia: ' + row.suggestion"></p>
                                </template>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell">
                                <span class="text-xs text-slate-600" x-text="row.destination || '—'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs font-medium"
                                      :class="row.state === 'mapped' ? 'bg-emerald-50 text-emerald-700' : (row.state === 'broken' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500')">
                                    <i class="fas text-[10px]"
                                       :class="row.state === 'mapped' ? 'fa-check-circle' : (row.state === 'broken' ? 'fa-triangle-exclamation' : 'fa-minus-circle')"></i>
                                    <span x-text="row.state === 'mapped' ? 'Mapeado' : (row.state === 'broken' ? 'Vínculo inválido' : 'Sin mapear')"></span>
                                </span>
                                <p x-show="row.state === 'broken' && row.reason" class="mt-1 text-[11px] text-red-600" x-text="row.reason"></p>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button @click="openEdit(row)"
                                        class="px-3 py-1.5 bg-brand-50 hover:bg-brand-100 text-brand-700 text-xs font-medium rounded-lg transition-colors">
                                    <i class="fas fa-pen mr-1"></i><span x-text="row.state === 'mapped' ? 'Cambiar' : 'Asignar'"></span>
                                </button>
                                <button @click="remove(row)"
                                        x-show="row.state !== 'unmapped'"
                                        class="ml-1 px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-medium rounded-lg transition-colors">
                                    <i class="fas fa-unlink mr-1"></i>Quitar
                                </button>
                                <button @click="openRepair(row)"
                                        x-show="row.path_state === 'missing'"
                                        class="ml-1 px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-medium rounded-lg transition-colors"
                                        title="Buscar este material en los otros discos">
                                    <i class="fas fa-magnifying-glass-location mr-1"></i>Buscar
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="sorted().length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-slate-400">
                            Ningún storage coincide con el filtro.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div x-cloak x-show="showForm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto" @click.away="showForm = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1"
                    x-text="form.source_storage_id ? 'Cambiar carpeta de backup' : 'Asignar carpeta de backup'"></h2>
                <p class="text-sm text-slate-500 mb-5">El vinculo NO se deduce de nombres: se declara aqui.</p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Storage de origen</label>
                        <select x-model.number="form.source_storage_id" @change="loadFolders()"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                            <option value="">— elige un storage —</option>
                            <template x-for="row in rows" :key="row.id">
                                <option :value="row.id" x-text="row.name + (row.folder ? '  →  ' + row.folder : '')"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Árbol de backup</label>
                        <select x-model.number="form.backup_storage_id" @change="loadFolders()"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                            <option value="">— elige el árbol destino —</option>
                            <template x-for="dest in destinations" :key="dest.id">
                                <option :value="dest.id" x-text="dest.name + '  (' + dest.base_path + ')'"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Carpeta</label>
                        <select x-model="form.backup_folder_name" :disabled="!form.backup_storage_id"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none disabled:bg-slate-50 disabled:text-slate-400">
                            <option value="">— elige la carpeta del árbol —</option>
                            <template x-for="folder in folders" :key="folder.name">
                                <option :value="folder.name"
                                        x-text="folder.name + '  (' + folder.days + ' días' + (folder.last_day ? ', último ' + folder.last_day : '') + ')' + (folder.mapped_to ? '  · ya asignada a ' + folder.mapped_to.name : '')"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-slate-400" x-show="folders.length === 0 && form.backup_storage_id">
                            No se pudieron leer las carpetas del árbol.
                        </p>
                    </div>

                    <div x-show="error" x-cloak class="p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
                        <i class="fas fa-circle-exclamation mr-1.5"></i><span x-text="error"></span>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="save()" :disabled="saving"
                            class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-save mr-1.5" x-show="!saving"></i>
                        <i class="fas fa-spinner fa-spin mr-1.5" x-show="saving"></i>
                        Guardar
                    </button>
                    <button @click="showForm = false"
                            class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: escaneo masivo de rutas rotas -->
    <div x-cloak x-show="showScan" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-3xl shadow-2xl max-h-[90vh] overflow-y-auto" @click.away="showScan = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Escanear rutas rotas</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Se busca el material de cada storage cuya ruta base no existe en los demás discos.
                    El escaneo no escribe nada: vos elegís la ruta y la aplicás.
                </p>

                <div x-show="scanLoading" class="flex items-center justify-center py-10 gap-3">
                    <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
                    <span class="text-sm text-slate-500">Recorriendo los discos...</span>
                </div>

                <div x-show="!scanLoading && scanError" x-cloak class="p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
                    <i class="fas fa-circle-exclamation mr-1.5"></i><span x-text="scanError"></span>
                </div>

                <div x-show="!scanLoading && !scanError && scanRows.length === 0" class="py-10 text-center text-sm text-slate-400">
                    <i class="fas fa-circle-check text-3xl mb-3 block text-emerald-300"></i>
                    Ningún storage tiene la ruta base rota.
                </div>

                <div x-show="!scanLoading && !scanError" class="space-y-4">
                    <template x-for="row in scanRows" :key="row.id">
                        <div class="border border-slate-200 rounded-xl p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-800" x-text="row.name"></p>
                                    <p class="text-xs text-slate-400 font-mono break-all" x-text="row.base_path"></p>
                                </div>
                                <span class="shrink-0 text-xs px-2 py-0.5 rounded-lg font-medium"
                                      :class="row.candidates.length > 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'"
                                      x-text="row.candidates.length > 0 ? row.candidates.length + ' candidato(s)' : 'sin candidatos'"></span>
                            </div>

                            <template x-if="row.candidates.length === 0">
                                <p class="mt-3 text-xs text-slate-400">
                                    No se encontró esa carpeta en ningún disco: el material ya no está.
                                </p>
                            </template>

                            <div x-show="row.candidates.length > 0" class="mt-3 space-y-2">
                                <template x-for="c in row.candidates" :key="c.path">
                                    <label class="flex items-start gap-3 p-2.5 rounded-lg border cursor-pointer transition-colors"
                                           :class="scanSelected[row.id] === c.path ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'">
                                        <input type="radio" :name="'scan-' + row.id" x-model="scanSelected[row.id]" :value="c.path" class="mt-1">
                                        <span class="flex-1 min-w-0">
                                            <span class="block text-xs text-slate-700 font-mono break-all" x-text="c.path"></span>
                                            <span class="block mt-0.5 text-[11px] text-slate-500" x-text="c.reasons.join(' · ')"></span>
                                            <span x-show="c.claimed_by" class="block mt-0.5 text-[11px] text-amber-600"
                                                  x-text="'Ya lo declara: ' + c.claimed_by.name"></span>
                                        </span>
                                        <span class="text-xs font-semibold text-brand-600 shrink-0" x-text="c.score"></span>
                                    </label>
                                </template>
                                <button @click="applyScan(row)" :disabled="!scanSelected[row.id] || scanSaving === row.id"
                                        class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                    <i class="fas fa-check mr-1" x-show="scanSaving !== row.id"></i>
                                    <i class="fas fa-spinner fa-spin mr-1" x-show="scanSaving === row.id"></i>
                                    Actualizar esta ruta
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end mt-6">
                    <button @click="showScan = false"
                            class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: buscar el material en los otros discos -->
    <div x-cloak x-show="showRepair" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
        <div class="bg-white rounded-2xl w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto" @click.away="showRepair = false">
            <div class="p-6">
                <h2 class="text-lg font-bold text-slate-800 mb-1">Buscar el material en los otros discos</h2>
                <p class="text-sm text-slate-500 mb-4">
                    La ruta base de este storage no existe. Se busca la misma carpeta en los demás discos.
                    <span class="block mt-1 font-mono text-xs text-slate-400" x-text="repairStorage ? repairStorage.base_path : ''"></span>
                </p>

                <div x-show="repairLoading" class="flex items-center justify-center py-10">
                    <i class="fas fa-spinner fa-spin text-brand-400 text-xl"></i>
                </div>

                <div x-show="!repairLoading && repairCandidates.length === 0" class="py-10 text-center text-sm text-slate-400">
                    <i class="fas fa-folder-minus text-3xl mb-3 block text-slate-200"></i>
                    No se encontro esa carpeta en ningun disco.
                </div>

                <div x-show="!repairLoading && repairCandidates.length > 0" class="space-y-2">
                    <template x-for="c in repairCandidates" :key="c.path">
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                               :class="repairSelected === c.path ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" x-model="repairSelected" :value="c.path" class="mt-1">
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm text-slate-700 font-mono break-all" x-text="c.path"></span>
                                <span class="block mt-1 text-xs text-slate-500" x-text="c.reasons.join(' · ')"></span>
                                <span x-show="c.claimed_by" class="block mt-1 text-xs text-amber-600"
                                      x-text="'Ya lo declara: ' + c.claimed_by.name"></span>
                            </span>
                            <span class="text-xs font-semibold text-brand-600 shrink-0" x-text="c.score"></span>
                        </label>
                    </template>
                </div>

                <div x-show="repairError" x-cloak class="mt-4 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
                    <i class="fas fa-circle-exclamation mr-1.5"></i><span x-text="repairError"></span>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="applyRepair()" :disabled="!repairSelected || repairSaving"
                            class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-save mr-1.5" x-show="!repairSaving"></i>
                        <i class="fas fa-spinner fa-spin mr-1.5" x-show="repairSaving"></i>
                        Actualizar ruta
                    </button>
                    <button @click="showRepair = false"
                            class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script src="/js/interactive-tour.js?v=20"></script>
<script>
/**
 * Guía interactiva del módulo de Backup.
 *
 * Explica la pieza que el operador no puede deducir mirando la pantalla: el
 * vínculo "storage de origen → carpeta del árbol" se DECLARA y solo entonces
 * aparece la acción "Solicitar backup" en Mis Archivos. Los pasos apuntan a
 * anclas `data-backup-tour` para no depender de clases Tailwind.
 */
function startBackupTour() {
    function anchor(name) {
        return document.querySelector('[data-backup-tour="' + name + '"]');
    }
    function scrollTo(el) {
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        return el;
    }

    TcloudTour.start({
        steps: [
            {
                title: 'Backup de producción',
                content: 'Esta pantalla administra el vínculo entre un <strong>storage de origen</strong> (los canales) ' +
                         'y su <strong>carpeta en el árbol de producción</strong>. No se copia nada: se declara ' +
                         'qué carpeta corresponde a qué canal, y con eso el cliente puede pedir el material de un día.',
                icon: 'fa-box-archive',
                color: '#0f766e',
                selector: null,
                position: 'center',
            },
            {
                title: 'El vínculo NO se deduce del nombre',
                content: 'Los nombres de los dos lados no coinciden: <span class="font-mono">09 Redmas</span> ↔ ' +
                         '<span class="font-mono">05_Redmas</span>, <span class="font-mono">05 Institucional</span> ↔ ' +
                         '<span class="font-mono">22_Institucional_BK</span>. Por eso el vínculo se declara a mano y ' +
                         'es la única fuente de verdad. Una carpeta no puede estar asignada a dos orígenes.',
                icon: 'fa-circle-info',
                color: '#0f766e',
                selector: function () { return anchor('listing'); },
                position: 'top',
                onShow: function () { scrollTo(anchor('listing')); },
            },
            {
                title: 'Carpetas del árbol',
                content: 'Cuántas carpetas de canal se leyeron <strong>del disco</strong> en el árbol de producción. ' +
                         'Esta lectura es de NFS: si el montaje está caído la pantalla avisa y no intenta leer nada ' +
                         '(el export es <code>hard</code> y un acceso bloquearía las peticiones).',
                icon: 'fa-folder-tree',
                color: '#0891b2',
                selector: function () { return anchor('coverage-folders'); },
                position: 'bottom',
                onShow: function () { scrollTo(anchor('coverage-folders')); },
            },
            {
                title: 'Cobertura del vínculo',
                content: 'Tres números para saber dónde estás: <strong>con origen declarado</strong> (carpetas ya ' +
                         'vinculadas), <strong>storages de origen</strong> (canales del sistema y cuántos no tienen ' +
                         'backup asignado) y <strong>vínculos inválidos</strong> (destino borrado o que dejó de ser local).',
                icon: 'fa-chart-simple',
                color: '#0891b2',
                selector: function () { return anchor('coverage-mapped'); },
                position: 'bottom',
                onShow: function () { scrollTo(anchor('coverage-mapped')); },
            },
            {
                title: 'Carpetas sin origen',
                content: 'Carpetas que existen en el árbol pero ningún canal las reclama. El cliente no puede pedirlas. ' +
                         'La flecha que aparece al lado es una <strong>sugerencia por nombre compartido</strong>: ' +
                         'no escribe nada, solo ayuda a decidir. El vínculo se declara a mano.',
                icon: 'fa-folder-open',
                color: '#d97706',
                selector: function () { return anchor('orphans'); },
                position: 'top',
                onShow: function () { scrollTo(anchor('orphans')); },
            },
            {
                title: 'Buscar y filtrar',
                content: 'Busca por nombre de storage o por ruta, y usa <strong>Solo sin mapear</strong> para atacar ' +
                         'justo los canales que todavía no tienen backup. Los contadores de la derecha se recalculan con el filtro.',
                icon: 'fa-magnifying-glass',
                color: '#475569',
                selector: function () { return anchor('search'); },
                position: 'bottom',
                onShow: function () { scrollTo(anchor('search')); },
            },
            {
                title: 'Asignar una carpeta',
                content: 'Con <strong>Asignar carpeta</strong> eliges el storage de origen, el árbol destino y la carpeta. ' +
                         'El sistema valida que la carpeta exista <strong>en disco</strong>, que no esté ya asignada a otro ' +
                         'canal y que el origen no viva dentro del árbol. Un vínculo inválido se rechaza con el motivo.',
                icon: 'fa-link',
                color: '#7c3aed',
                selector: function () { return anchor('assign'); },
                position: 'bottom',
                onShow: function () { scrollTo(anchor('assign')); },
            },
            {
                title: 'Qué ve el cliente',
                content: 'Una vez declarado el vínculo, en <strong>Mis Archivos</strong> la carpeta-día ' +
                         '(<span class="font-mono">DDMMYYYY</span>) de ese canal muestra la acción ' +
                         '<strong>Solicitar backup</strong>. La solicitud genera un enlace público de solo lectura con ' +
                         '2 días de vigencia, y el cliente lo ve en su historial de <em>Backups</em>. ' +
                         'Revocar la solicitud en Mis Archivos es la única forma de desactivar ese enlace.',
                icon: 'fa-user',
                color: '#0f766e',
                selector: null,
                position: 'center',
            },
            {
                title: 'Guía Completada',
                content: 'Ya sabes cómo se conecta un canal con su carpeta de producción y por qué solo algunos canales ' +
                         'tienen la acción de backup: les falta declarar el vínculo. Repite esta guía con el botón morado.',
                icon: 'fa-check-circle',
                color: '#16a34a',
                selector: null,
                position: 'center',
            },
        ],
    });
}

function backupAdmin() {
    return {
        rows: @json($rows),
        destinations: @json($destinations),
        folders: @json($folders),
        orphans: @json($coverage['folders_orphan']),
        suggestions: @json($coverage['suggestions']),
        root: @json($tree['root']),
        mountDown: @json($tree['mount_down']),

        search: '',
        onlyUnmapped: false,
        onlyContainers: false,
        sortKey: '',
        sortDir: 'asc',
        showRepair: false,
        showScan: false,
        scanRows: [],
        scanSelected: {},
        scanLoading: false,
        scanSaving: null,
        scanError: '',
        repairStorage: null,
        repairCandidates: [],
        repairSelected: '',
        repairLoading: false,
        repairSaving: false,
        repairError: '',
        orphansOpen: false,
        showForm: false,
        saving: false,
        loading: false,
        toast: null,
        error: '',
        form: { source_storage_id: '', backup_storage_id: '', backup_folder_name: '' },

        init() {
            this.rootId = this.root ? this.root.id : '';
        },

        get rootLabel() {
            return this.root ? this.root.name : 'sin árbol declarado';
        },

        get mappedFolders() {
            return this.folders.filter(f => f.mapped_to).length;
        },

        get orphanFolders() {
            return this.orphans;
        },

        get unmappedSources() {
            return this.rows.filter(r => r.state === 'unmapped').length;
        },

        get mappedCount() {
            return this.rows.filter(r => r.state === 'mapped').length;
        },

        get brokenCount() {
            return this.rows.filter(r => r.state === 'broken').length;
        },

        get unmappedCount() {
            return this.rows.filter(r => r.state === 'unmapped').length;
        },

        get containerCount() {
            return this.rows.filter(r => r.is_container).length;
        },

        get missingCount() {
            return this.rows.filter(r => r.path_state === 'missing').length;
        },

        get brokenSources() {
            return this.rows.filter(r => r.state === 'broken').length;
        },

        /**
         * Ordena por la columna elegida. El orden por defecto del servidor
         * (primero lo ya declarado) se preserva en los empates: el sort es
         * estable sobre esa base.
         */
        sorted() {
            const rows = this.filtered();
            if (!this.sortKey) return rows;

            const dir = this.sortDir === 'asc' ? 1 : -1;
            const rank = { mapped: 0, broken: 1, unmapped: 2 };

            return rows.slice().sort((a, b) => {
                const av = this.sortValue(a, this.sortKey);
                const bv = this.sortValue(b, this.sortKey);
                if (av === bv) return 0;
                return (av > bv ? 1 : -1) * dir;
            });
        },

        sortValue(row, key) {
            if (key === 'state') {
                const rank = { mapped: 0, broken: 1, unmapped: 2 };
                return rank[row.state] ?? 9;
            }
            if (key === 'name' || key === 'folder' || key === 'destination') {
                return String(row[key] || '').toLowerCase();
            }
            return '';
        },

        toggleSort(key) {
            if (this.sortKey === key) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                return;
            }
            this.sortKey = key;
            this.sortDir = 'asc';
        },

        sortIcon(key) {
            if (this.sortKey !== key) return 'fa-sort';
            return this.sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
        },

        /**
         * Escanea TODOS los storages con ruta base inexistente y muestra, para
         * cada uno, los candidatos hallados en los demás discos.
         */
        async openScan() {
            this.scanRows = [];
            this.scanSelected = {};
            this.scanError = '';
            this.showScan = true;
            this.scanLoading = true;

            try {
                const res = await apiFetch('/admin/backups/repair-scan?limit=3', {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.scanError = data.message || 'No se pudo escanear.';
                    return;
                }
                this.scanRows = data.storages || [];
                this.scanRows.forEach(r => {
                    if (r.candidates && r.candidates.length > 0) {
                        this.scanSelected[r.id] = r.candidates[0].path;
                    }
                });
            } catch (e) {
                this.scanError = 'No se pudo escanear.';
            } finally {
                this.scanLoading = false;
            }
        },

        async applyScan(row) {
            const path = this.scanSelected[row.id];
            if (!path) return;

            this.scanSaving = row.id;
            try {
                const res = await apiFetch(`/admin/backups/${row.id}/repair`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ path: path }),
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    this.showToast(data.message || 'Ruta actualizada', 'success');
                    window.location.reload();
                } else {
                    this.scanError = data.message || 'No se pudo actualizar la ruta.';
                }
            } catch (e) {
                this.scanError = 'No se pudo actualizar la ruta.';
            } finally {
                this.scanSaving = null;
            }
        },

        async openRepair(row) {
            this.repairStorage = row;
            this.repairCandidates = [];
            this.repairSelected = '';
            this.repairError = '';
            this.showRepair = true;
            this.repairLoading = true;

            try {
                const res = await apiFetch(`/admin/backups/${row.id}/repair-candidates`, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.repairError = data.message || 'No se pudo buscar el material.';
                    return;
                }
                this.repairCandidates = data.candidates || [];
                if (this.repairCandidates.length > 0) {
                    this.repairSelected = this.repairCandidates[0].path;
                }
            } catch (e) {
                this.repairError = 'No se pudo buscar el material.';
            } finally {
                this.repairLoading = false;
            }
        },

        async applyRepair() {
            if (!this.repairStorage || !this.repairSelected) return;

            this.repairSaving = true;
            this.repairError = '';

            try {
                const res = await apiFetch(`/admin/backups/${this.repairStorage.id}/repair`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ path: this.repairSelected }),
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    this.showToast(data.message || 'Ruta actualizada', 'success');
                    window.location.reload();
                } else {
                    this.repairError = data.message || 'No se pudo actualizar la ruta.';
                }
            } catch (e) {
                this.repairError = 'No se pudo actualizar la ruta.';
            } finally {
                this.repairSaving = false;
            }
        },

        filtered() {
            const q = this.search.trim().toLowerCase();
            return this.rows.filter(r => {
                if (this.onlyUnmapped && r.state !== 'unmapped') return false;
                if (this.onlyContainers && !r.is_container) return false;
                if (!q) return true;
                return r.name.toLowerCase().includes(q)
                    || (r.base_path || '').toLowerCase().includes(q)
                    || (r.folder || '').toLowerCase().includes(q);
            });
        },

        suggestionFor(folder) {
            const s = this.suggestions[folder];
            return s ? s.name : null;
        },

        showToast(msg, type) {
            this.toast = { msg, type };
            setTimeout(() => { this.toast = null; }, 4000);
        },

        openCreate() {
            this.form = { source_storage_id: '', backup_storage_id: this.rootId || '', backup_folder_name: '' };
            this.error = '';
            this.showForm = true;
            this.loadFolders();
        },

        openEdit(row) {
            this.form = {
                source_storage_id: row.id,
                backup_storage_id: this.rootId || '',
                backup_folder_name: row.folder || '',
            };
            this.error = '';
            this.showForm = true;
            this.loadFolders();
        },

        async loadFolders() {
            this.folders = [];
            this.error = '';
            if (!this.form.backup_storage_id) return;

            this.loading = true;
            try {
                const res = await apiFetch(`/admin/backups/folders?root=${this.form.backup_storage_id}`, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.error = data.message || 'No se pudo leer el árbol de backup.';
                    return;
                }
                this.folders = data.folders;
                this.root = data.root;
            } catch (e) {
                this.error = 'No se pudo leer el árbol de backup.';
            } finally {
                this.loading = false;
            }
        },

        async save() {
            this.error = '';
            if (!this.form.source_storage_id || !this.form.backup_storage_id || !this.form.backup_folder_name) {
                this.error = 'Elige el storage de origen, el árbol y la carpeta.';
                return;
            }

            this.saving = true;
            try {
                const res = await apiFetch('/admin/backups', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify(this.form),
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    this.showForm = false;
                    this.showToast(data.message || 'Vínculo declarado', 'success');
                    window.location.reload();
                } else {
                    this.error = data.message || 'No se pudo declarar el vínculo.';
                }
            } catch (e) {
                this.error = 'No se pudo declarar el vínculo.';
            } finally {
                this.saving = false;
            }
        },

        async remove(row) {
            if (!confirm(`¿Quitar el vínculo de backup de "${row.name}"?`)) return;

            const res = await apiFetch(`/admin/backups/${row.id}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                this.showToast(data.message || 'Vínculo eliminado', 'success');
                window.location.reload();
            } else {
                this.showToast(data.message || 'No se pudo eliminar el vínculo.', 'error');
            }
        },

        async refresh() {
            this.loading = true;
            try {
                const res = await apiFetch(`/admin/backups/folders?root=${this.rootId || ''}&fresh=1`, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    this.folders = data.folders;
                    this.showToast('Árbol de backup actualizado', 'success');
                } else {
                    this.showToast(data.message || 'No se pudo actualizar', 'error');
                }
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush
