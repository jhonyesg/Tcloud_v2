@extends('layouts.app')

@section('title', 'Compartidos - Tcloud')

@php($hardThreshold = (int) config('shares.hard_confirmation_threshold', 25))

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="sharesApp()" x-init="init()" x-cloak data-hard-confirm-threshold="{{ $hardThreshold }}">
    <div class="flex justify-between items-start gap-3 mb-4 sm:mb-6">
        <div class="min-w-0">
            <h1 class="text-lg sm:text-2xl font-bold text-gray-800">Mis Recursos Compartidos</h1>
            <p class="text-xs sm:text-sm text-gray-500 mt-0.5">Gestiona, filtra y depura tus enlaces de acceso</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <button @click="startSharesTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-lg transition-colors text-sm" title="Guía interactiva">
                <i class="fas fa-map-marked-alt text-xs"></i>
                <span class="hidden sm:inline">Guía</span>
            </button>
            <button @click="loadShares()" :disabled="loading" class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50 disabled:opacity-50">
                <i :class="loading ? 'fas fa-spinner fa-spin' : 'fas fa-sync-alt'" class="text-xs"></i>
                <span class="hidden sm:inline">Actualizar</span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5" x-show="counters.total > 0">
        <template x-for="card in statCards" :key="card.key">
            <div class="bg-white rounded-lg border px-4 py-3">
                <p class="text-xs text-gray-400 mb-0.5" x-text="card.label"></p>
                <p class="text-xl sm:text-2xl font-bold" :class="card.color" x-text="counters[card.key] || 0"></p>
            </div>
        </template>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-4 sm:px-5 py-3 border-b border-gray-100 space-y-3">
            <div class="flex items-center gap-2 flex-wrap">
                <div class="relative flex-1 min-w-48">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                    <input x-model="filters.q" @input.debounce.350ms="applyFilters()" type="text" placeholder="Buscar por nombre o ruta..."
                           class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200">
                </div>
                <select x-model="filters.permission" @change="applyFilters()" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 text-gray-600">
                    <option value="">Todos los permisos</option>
                    <option value="read">Solo lectura</option>
                    <option value="write">Escritura</option>
                    <option value="upload">Subida</option>
                    <option value="full">Acceso completo</option>
                </select>
                <select x-model="filters.status" @change="applyFilters()" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 text-gray-600">
                    <option value="">Todos los vencimientos</option>
                    <option value="active">Activos</option>
                    <option value="expired">Expirados</option>
                    <option value="never">Sin vencimiento</option>
                </select>
                <select x-model="filters.availability" @change="applyFilters()" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 text-gray-600">
                    <option value="">Todos los archivos</option>
                    <option value="available">Disponibles</option>
                    <option value="missing">Archivo no disponible</option>
                    <option value="unknown">No verificados</option>
                </select>
                <button @click="clearFilters()" class="px-3 py-1.5 text-sm text-gray-500 border border-gray-200 rounded-lg hover:bg-gray-50" title="Limpiar filtros">
                    <i class="fas fa-eraser mr-1"></i> Limpiar
                </button>
            </div>

            <div class="flex items-end gap-2 flex-wrap text-xs text-gray-500">
                <label class="flex flex-col gap-1">
                    <span>Creado desde</span>
                    <input type="date" x-model="filters.created_from" @change="applyFilters()" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-600">
                </label>
                <label class="flex flex-col gap-1">
                    <span>Creado hasta</span>
                    <input type="date" x-model="filters.created_to" @change="applyFilters()" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-600">
                </label>
                <label class="flex flex-col gap-1">
                    <span>Expira desde</span>
                    <input type="date" x-model="filters.expires_from" @change="applyFilters()" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-600">
                </label>
                <label class="flex flex-col gap-1">
                    <span>Expira hasta</span>
                    <input type="date" x-model="filters.expires_to" @change="applyFilters()" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-600">
                </label>
                <span class="sm:ml-auto pb-1 text-gray-400" x-text="resultLabel()"></span>
            </div>
        </div>

        <div class="px-4 sm:px-5 py-2.5 border-b border-gray-100 flex items-center gap-2 flex-wrap text-xs text-gray-600">
            <span class="font-medium text-gray-500">Acciones globales:</span>
            <button @click="verifyAllFiltered()" :disabled="bulkLoading || !meta.total" class="px-3 py-1.5 rounded-lg border border-blue-200 text-blue-700 hover:bg-blue-100 disabled:opacity-40">
                <i class="fas fa-search mr-1"></i> Verificar disponibilidad de los resultados
            </button>
            <button @click="quickCleanupExpired()" :disabled="bulkLoading || !counters.expired" class="px-3 py-1.5 rounded-lg bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-40">
                <i class="fas fa-hourglass-end mr-1"></i> Depurar expirados
                <span class="ml-1 px-1.5 rounded-full bg-amber-500/80 text-[10px]" x-text="counters.expired || 0"></span>
            </button>
            <button @click="quickCleanupMissing()" :disabled="bulkLoading || !counters.missing" class="px-3 py-1.5 rounded-lg bg-orange-600 text-white hover:bg-orange-700 disabled:opacity-40">
                <i class="fas fa-broom mr-1"></i> Depurar no disponibles
                <span class="ml-1 px-1.5 rounded-full bg-orange-500/80 text-[10px]" x-text="counters.missing || 0"></span>
            </button>
            <span class="text-gray-400 ml-auto" x-text="bulkLoading ? 'Procesando…' : 'Los archivos nunca se eliminan, solo los enlaces'"></span>
        </div>

        <div x-show="selectedCount() > 0" class="px-4 sm:px-5 py-2.5 bg-blue-50 border-b border-blue-100 flex items-center gap-3 flex-wrap">
            <span class="text-sm text-blue-700 font-medium" x-text="selectedCount() + ' seleccionado' + (selectedCount() !== 1 ? 's' : '')"></span>
            <button x-show="!allMatching && pageSelectionComplete() && meta.total > shares.length" @click="selectAllMatching()" class="text-xs text-blue-700 underline">
                Seleccionar los <span x-text="meta.total"></span> resultados filtrados
            </button>
            <span x-show="allMatching" class="text-xs text-blue-700">Todos los resultados filtrados están seleccionados</span>
            <div class="ml-auto flex items-center gap-2">
                <button @click="verifySelected()" :disabled="bulkLoading" class="px-3 py-1.5 text-xs rounded-lg border border-blue-200 text-blue-700 hover:bg-blue-100 disabled:opacity-50">
                    <i class="fas fa-search mr-1"></i> Verificar disponibilidad
                </button>
                <button @click="openBulkPreview()" :disabled="bulkLoading" class="px-3 py-1.5 text-xs rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50">
                    <i class="fas fa-trash-alt mr-1"></i> Depurar seleccionados
                </button>
                <button @click="clearSelection()" :disabled="bulkLoading" class="text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
            </div>
        </div>

        <div x-show="loading" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl text-indigo-400 mb-3 block"></i>
            <p class="text-sm">Cargando recursos compartidos...</p>
        </div>

        <div x-show="!loading && errorMessage" class="p-10 text-center text-red-500 text-sm">
            <i class="fas fa-exclamation-triangle text-xl mb-2 block"></i>
            <p x-text="errorMessage"></p>
            <button @click="loadShares()" class="mt-3 px-3 py-1.5 border border-red-200 rounded-lg hover:bg-red-50">Reintentar</button>
        </div>

        <div x-show="!loading && !errorMessage && shares.length === 0" class="py-16 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
                <i class="fas fa-share-alt text-2xl text-gray-400"></i>
            </div>
            <p class="font-medium text-gray-600" x-text="hasFilters() ? 'Ningún resultado con los filtros aplicados' : 'No tienes recursos compartidos'"></p>
            <p class="text-sm text-gray-400 mt-1" x-show="!hasFilters()">Ve al módulo de Archivos, abre un recurso y genera un enlace compartido.</p>
        </div>

        <div x-show="!loading && !errorMessage && shares.length > 0" class="overflow-x-auto">
            <table class="w-full text-sm hidden sm:table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase tracking-wide">
                        <th class="px-4 py-3 text-left w-10">
                            <input type="checkbox" :checked="pageSelectionComplete()" :indeterminate.prop="pageSelectionPartial()" @change="togglePageSelection()" :disabled="bulkLoading" class="rounded text-indigo-600">
                        </th>
                        <th class="px-4 py-3 text-left"><button @click="toggleSort('name')" class="hover:text-gray-700">Recurso <i :class="sortIcon('name')"></i></button></th>
                        <th class="px-4 py-3 text-left">Enlace</th>
                        <th class="px-4 py-3 text-left"><button @click="toggleSort('permission')" class="hover:text-gray-700">Permiso <i :class="sortIcon('permission')"></i></button></th>
                        <th class="px-4 py-3 text-left"><button @click="toggleSort('status')" class="hover:text-gray-700">Estado <i :class="sortIcon('status')"></i></button></th>
                        <th class="px-4 py-3 text-left"><button @click="toggleSort('expires_at')" class="hover:text-gray-700">Expira <i :class="sortIcon('expires_at')"></i></button></th>
                        <th class="px-4 py-3 text-center"><button @click="toggleSort('accesses')" class="hover:text-gray-700">Accesos <i :class="sortIcon('accesses')"></i></button></th>
                        <th class="px-4 py-3 text-left"><button @click="toggleSort('created_at')" class="hover:text-gray-700">Creado <i :class="sortIcon('created_at')"></i></button></th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="share in shares" :key="share.id">
                        <tr class="hover:bg-gray-50 transition-colors" :class="share.expiry_status === 'expired' ? 'opacity-70' : ''">
                            <td class="px-4 py-3.5"><input type="checkbox" :checked="selectedIds.includes(share.id)" @change="toggleSelection(share.id)" :disabled="bulkLoading" class="rounded text-indigo-600"></td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span class="text-lg flex-shrink-0" x-html="fileIcon(share.file)"></span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 truncate max-w-[190px]" :title="share.file?.name || 'Recurso eliminado'" x-text="share.file?.name || 'Recurso eliminado'"></p>
                                        <p class="text-xs text-gray-400 truncate max-w-[190px]" x-text="share.file?.path ? filePath(share.file.path) : ''"></p>
                                    </div>
                                    <span x-show="share.has_password" title="Protegido con contraseña" class="text-amber-400"><i class="fas fa-lock text-xs"></i></span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-1.5">
                                    <code class="text-xs text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono truncate max-w-[120px]" :title="share.public_url" x-text="'/s/' + share.token.slice(0, 10) + '...' "></code>
                                    <button @click="copyLink(share)" class="text-gray-400 hover:text-indigo-600" title="Copiar enlace"><i class="fas fa-copy text-xs"></i></button>
                                </div>
                            </td>
                            <td class="px-4 py-3.5"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" :class="permissionClass(share.permissions)" x-text="permLabel(share.permissions)"></span></td>
                            <td class="px-4 py-3.5"><div class="flex flex-col gap-1"><span class="inline-flex items-center gap-1 text-xs font-medium" :class="expiryClass(share)"><span class="w-1.5 h-1.5 rounded-full bg-current"></span><span x-text="expiryLabel(share)"></span></span><span class="text-[11px]" :class="availabilityClass(share)" x-text="availabilityLabel(share)"></span></div></td>
                            <td class="px-4 py-3.5 text-xs" :class="share.expiry_status === 'expired' ? 'text-red-400' : 'text-gray-500'" x-text="share.expires_at ? formatDate(share.expires_at) : 'Sin vencimiento'"></td>
                            <td class="px-4 py-3.5 text-center text-xs text-gray-600"><i class="fas fa-eye text-gray-300 mr-1"></i><span x-text="share.access_logs_count || 0"></span></td>
                            <td class="px-4 py-3.5 text-xs text-gray-400" x-text="formatDate(share.created_at)"></td>
                            <td class="px-4 py-3.5 text-right"><div class="flex justify-end gap-1"><button @click="openLink(share)" class="px-2 py-1 rounded-lg bg-gray-50 text-gray-600 hover:bg-gray-100 text-xs" title="Abrir"><i class="fas fa-external-link-alt"></i></button><button @click="confirmDelete(share)" class="px-2 py-1 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 text-xs" title="Revocar"><i class="fas fa-trash-alt"></i></button></div></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <div class="sm:hidden divide-y divide-gray-100">
                <template x-for="share in shares" :key="share.id">
                    <div class="p-4" :class="share.expiry_status === 'expired' ? 'opacity-70' : ''">
                        <div class="flex items-start gap-3 mb-3"><input type="checkbox" :checked="selectedIds.includes(share.id)" @change="toggleSelection(share.id)" :disabled="bulkLoading" class="mt-1 rounded text-indigo-600"><span class="text-xl" x-html="fileIcon(share.file)"></span><div class="flex-1 min-w-0"><p class="font-medium text-gray-800 text-sm truncate" x-text="share.file?.name || 'Recurso eliminado'"></p><p class="text-xs text-gray-400 truncate" x-text="share.file?.path || ''"></p></div></div>
                        <div class="flex items-center justify-between gap-2 text-xs mb-3"><span class="px-2 py-0.5 rounded-full" :class="permissionClass(share.permissions)" x-text="permLabel(share.permissions)"></span><span :class="expiryClass(share)" x-text="expiryLabel(share)"></span><span :class="availabilityClass(share)" x-text="availabilityLabel(share)"></span></div>
                        <div class="grid grid-cols-2 gap-1 text-xs text-gray-500 mb-3"><span>Expira: <strong x-text="share.expires_at ? formatDate(share.expires_at) : 'Nunca'"></strong></span><span>Creado: <strong x-text="formatDate(share.created_at)"></strong></span><span>Accesos: <strong x-text="share.access_logs_count || 0"></strong></span></div>
                        <div class="flex gap-2"><button @click="copyLink(share)" class="flex-1 py-2 rounded-lg bg-indigo-50 text-indigo-600 text-xs"><i class="fas fa-link mr-1"></i>Copiar enlace</button><button @click="openLink(share)" class="px-3 py-2 rounded-lg bg-gray-50 text-gray-600 text-xs"><i class="fas fa-external-link-alt"></i></button><button @click="confirmDelete(share)" class="px-3 py-2 rounded-lg bg-red-50 text-red-500 text-xs"><i class="fas fa-trash-alt"></i></button></div>
                    </div>
                </template>
            </div>
        </div>

        <div x-show="!loading && !errorMessage && meta.last_page > 1" class="px-4 sm:px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-3 text-xs text-gray-500">
            <span x-text="'Página ' + meta.current_page + ' de ' + meta.last_page"></span>
            <div class="flex gap-1"><button @click="changePage(meta.current_page - 1)" :disabled="meta.current_page <= 1 || loading" class="px-2.5 py-1.5 border rounded-lg disabled:opacity-40"><i class="fas fa-chevron-left"></i></button><button @click="changePage(meta.current_page + 1)" :disabled="meta.current_page >= meta.last_page || loading" class="px-2.5 py-1.5 border rounded-lg disabled:opacity-40"><i class="fas fa-chevron-right"></i></button></div>
        </div>
    </div>

    <div x-cloak x-show="bulkModal.show" x-transition.opacity class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @keydown.escape.window="!bulkModal.loading && closeBulkModal()">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden" x-transition.scale.origin.center>
            <div class="px-6 pt-6 pb-4">
                <div class="flex items-start gap-3">
                    <div class="w-11 h-11 rounded-full flex items-center justify-center flex-shrink-0" :class="bulkModal.kind === 'cleanup-missing' ? 'bg-orange-100 text-orange-600' : (bulkModal.kind === 'cleanup-expired' ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600')">
                        <i :class="bulkModal.kind === 'cleanup-missing' ? 'fas fa-broom' : (bulkModal.kind === 'cleanup-expired' ? 'fas fa-hourglass-end' : 'fas fa-trash-alt')"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-semibold text-gray-900 text-base" x-text="bulkModal.kind === 'cleanup-missing' ? 'Depurar enlaces no disponibles' : (bulkModal.kind === 'cleanup-expired' ? 'Depurar enlaces expirados' : 'Depuración permanente')"></h3>
                        <p class="text-sm text-gray-500 mt-1" x-text="bulkModal.kind === 'cleanup-missing' ? 'Vas a revocar todos los enlaces cuyo archivo ya no está disponible en disco. Los archivos no se eliminan.' : (bulkModal.kind === 'cleanup-expired' ? 'Vas a revocar los enlaces cuya fecha de vencimiento ya pasó. Los archivos no se eliminan y los registros de acceso también se depuran.' : 'Vas a revocar los enlaces seleccionados. Los archivos no se eliminan, pero los enlaces no se pueden recuperar.')"></p>
                    </div>
                </div>
            </div>

            <div class="px-6 pb-4">
                <div class="rounded-xl border border-gray-200 bg-gray-50/60 px-4 py-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Total a revocar</div>
                        <div class="text-2xl font-bold text-gray-900" x-text="bulkModal.count"></div>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <div class="flex items-center justify-between"><dt class="text-gray-500">Expirados</dt><dd class="font-semibold text-gray-700" x-text="(bulkModal.summary.expired || 0)"></dd></div>
                        <div class="flex items-center justify-between"><dt class="text-gray-500">Sin vencimiento</dt><dd class="font-semibold text-amber-600" x-text="(bulkModal.summary.permanent || 0)"></dd></div>
                        <div class="flex items-center justify-between"><dt class="text-gray-500">Archivo no disponible</dt><dd class="font-semibold text-orange-600" x-text="(bulkModal.summary.missing || 0)"></dd></div>
                        <div class="flex items-center justify-between"><dt class="text-gray-500">No verificados</dt><dd class="font-semibold text-gray-500" x-text="(bulkModal.summary.unknown || 0)"></dd></div>
                    </dl>
                    <div class="mt-3 pt-3 border-t border-gray-200" x-show="bulkModal.summary.permissions">
                        <div class="text-xs text-gray-500 mb-1">Distribución por permiso</div>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="[perm, count] in Object.entries(bulkModal.summary.permissions || {})" :key="perm">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-white border border-gray-200 text-xs text-gray-700">
                                    <span x-text="permLabel(perm)"></span>
                                    <span class="font-semibold text-gray-900" x-text="count"></span>
                                </span>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-6 pb-6" x-show="requiresTypedConfirmation()">
                <label class="block text-xs font-medium text-gray-600 mb-1.5" :for="'confirm-' + bulkModal.kind">
                    Para confirmar, escribe <strong class="text-gray-900 font-mono">ELIMINAR</strong> en mayúsculas
                </label>
                <input :id="'confirm-' + bulkModal.kind" type="text" x-model="bulkModal.confirmText" @keydown.enter="bulkModal.confirmText === 'ELIMINAR' && confirmBulkDelete()" autocomplete="off" spellcheck="false"
                       class="w-full px-3 py-2 text-sm border rounded-lg font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-red-300 focus:border-red-400 uppercase"
                       :class="bulkModal.confirmText && bulkModal.confirmText !== 'ELIMINAR' ? 'border-red-300 bg-red-50' : 'border-gray-300'"
                       placeholder="ELIMINAR" />
                <p class="mt-1.5 text-xs" :class="bulkModal.confirmText && bulkModal.confirmText !== 'ELIMINAR' ? 'text-red-600' : 'text-gray-400'" x-text="bulkModal.confirmText && bulkModal.confirmText !== 'ELIMINAR' ? 'Escribe exactamente ELIMINAR para continuar.' : 'Esta palabra protege contra clics accidentales.'"></p>
            </div>

            <div class="px-6 pb-6 pt-1 bg-gray-50/60 border-t border-gray-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="closeBulkModal()" :disabled="bulkModal.loading" class="px-4 py-2 text-sm font-medium border border-gray-300 rounded-lg text-gray-700 hover:bg-white focus:outline-none focus:ring-2 focus:ring-gray-300 disabled:opacity-50">
                    Cancelar
                </button>
                <button @click="confirmBulkDelete()" :disabled="bulkModal.loading || !canConfirmBulk()" class="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50 focus:outline-none focus:ring-2"
                        :class="bulkModal.kind === 'cleanup-missing' ? 'bg-orange-600 hover:bg-orange-700 focus:ring-orange-300' : (bulkModal.kind === 'cleanup-expired' ? 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-300' : 'bg-red-600 hover:bg-red-700 focus:ring-red-300')">
                    <i x-show="bulkModal.loading" class="fas fa-spinner fa-spin mr-1.5"></i>
                    <i x-show="!bulkModal.loading" class="fas mr-1.5" :class="bulkModal.kind === 'cleanup-missing' ? 'fa-broom' : (bulkModal.kind === 'cleanup-expired' ? 'fa-hourglass-end' : 'fa-trash-alt')"></i>
                    <span x-text="bulkModal.loading ? 'Revocando…' : (bulkModal.kind === 'cleanup-missing' ? 'Depurar no disponibles' : (bulkModal.kind === 'cleanup-expired' ? 'Depurar expirados' : 'Eliminar definitivamente'))"></span>
                </button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 flex items-center gap-2.5 px-4 py-2.5 rounded-xl shadow-lg text-sm font-medium text-white" :class="toast.success ? 'bg-green-600' : 'bg-red-600'"><i :class="toast.success ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i><span x-text="toast.message"></span></div>
</div>

<script>
function sharesApp() {
    return {
        shares: [], loading: false, errorMessage: '', page: 1,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
        counters: { total: 0, expired: 0, permanent: 0, missing: 0, unknown: 0 },
        filters: { q: '', permission: '', status: '', availability: '', created_from: '', created_to: '', expires_from: '', expires_to: '' },
        sort: 'created_at', direction: 'desc', selectedIds: [], allMatching: false, bulkLoading: false,
        bulkModal: { show: false, loading: false, count: 0, summary: {}, kind: 'selection', confirmText: '' },
        toast: { show: false, message: '', success: true, _timer: null },
        statCards: [
            { key: 'total', label: 'Total', color: 'text-gray-800' },
            { key: 'expired', label: 'Expirados', color: 'text-red-500' },
            { key: 'permanent', label: 'Sin vencimiento', color: 'text-amber-500' },
            { key: 'missing', label: 'No disponibles', color: 'text-orange-500' },
            { key: 'unknown', label: 'No verificados', color: 'text-gray-500' },
        ],

        init() { this.loadShares(); },

        queryParams(includePage = true) {
            const params = new URLSearchParams();
            Object.entries(this.filters).forEach(([key, value]) => { if (value) params.set(key, value); });
            params.set('sort', this.sort); params.set('direction', this.direction); params.set('per_page', this.meta.per_page || 25);
            if (includePage) params.set('page', this.page);
            return params;
        },

        async loadShares() {
            this.loading = true; this.errorMessage = '';
            try {
                const res = await apiFetch('/shares?' + this.queryParams().toString(), { credentials: 'include', headers: { 'Accept': 'application/json' } });
                const payload = await res.json();
                if (!res.ok) throw new Error(payload.error || 'Error ' + res.status);
                this.shares = payload.data || []; this.meta = payload.meta || this.meta; this.counters = payload.counters || this.counters;
            } catch (e) { this.errorMessage = 'No se pudieron cargar los compartidos: ' + e.message; }
            finally { this.loading = false; }
        },

        applyFilters() { this.page = 1; this.clearSelection(); this.loadShares(); },
        clearFilters() { this.filters = { q: '', permission: '', status: '', availability: '', created_from: '', created_to: '', expires_from: '', expires_to: '' }; this.sort = 'created_at'; this.direction = 'desc'; this.applyFilters(); },
        hasFilters() { return Object.values(this.filters).some(Boolean); },
        resultLabel() { return `${this.meta.total || 0} resultado${this.meta.total === 1 ? '' : 's'}`; },
        changePage(page) { if (page >= 1 && page <= this.meta.last_page) { this.page = page; this.clearSelection(); this.loadShares(); } },
        toggleSort(field) { if (this.sort === field) this.direction = this.direction === 'asc' ? 'desc' : 'asc'; else { this.sort = field; this.direction = 'asc'; } this.applyFilters(); },
        sortIcon(field) { return this.sort === field ? (this.direction === 'asc' ? 'fas fa-arrow-up ml-1' : 'fas fa-arrow-down ml-1') : 'fas fa-sort ml-1 text-gray-300'; },

        selectedCount() { return this.allMatching ? (this.meta.total || 0) : this.selectedIds.length; },
        pageSelectionComplete() { return this.shares.length > 0 && this.shares.every(s => this.selectedIds.includes(s.id)); },
        pageSelectionPartial() { return this.shares.some(s => this.selectedIds.includes(s.id)) && !this.pageSelectionComplete(); },
        togglePageSelection() { if (this.pageSelectionComplete()) this.selectedIds = this.selectedIds.filter(id => !this.shares.some(s => s.id === id)); else this.selectedIds = [...new Set([...this.selectedIds, ...this.shares.map(s => s.id)])]; this.allMatching = false; },
        toggleSelection(id) { this.allMatching = false; this.selectedIds = this.selectedIds.includes(id) ? this.selectedIds.filter(item => item !== id) : [...this.selectedIds, id]; },
        selectAllMatching() { this.allMatching = true; this.selectedIds = this.shares.map(s => s.id); },
        clearSelection() { this.selectedIds = []; this.allMatching = false; },

        selectionBody() { const body = Object.fromEntries(this.queryParams(false).entries()); if (this.allMatching) body.all_matching = true; else body.ids = this.selectedIds; return body; },
        async openBulkPreview() {
            if (!this.selectedCount()) return;
            const count = this.selectedCount();
            const maxSafe = 200;
            if (count > maxSafe) {
                this.showToast(false, `La selección actual es de ${count} enlaces. Aplica filtros para reducir antes de depurar (máximo recomendado: ${maxSafe}).`);
                return;
            }
            this.bulkLoading = true;
            try {
                const res = await apiFetch('/shares/bulk-preview', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.selectionBody()) });
                const payload = await res.json(); if (!res.ok) throw new Error(payload.error || 'Error ' + res.status);
                this.bulkModal = { show: true, loading: false, count: payload.count || 0, summary: payload.summary || {}, kind: 'selection', confirmText: '' };
            } catch (e) { this.showToast(false, e.message); } finally { this.bulkLoading = false; }
        },
        closeBulkModal() {
            if (this.bulkModal.loading) return;
            this.bulkModal = { show: false, loading: false, count: 0, summary: {}, kind: 'selection', confirmText: '' };
        },
        requiresTypedConfirmation() {
            const hardThreshold = parseInt((this.$root.dataset.hardConfirmThreshold || '25'), 10);
            return this.bulkModal.count >= hardThreshold;
        },
        canConfirmBulk() {
            if (!this.bulkModal.count) return false;
            if (!this.requiresTypedConfirmation()) return true;
            return this.bulkModal.confirmText === 'ELIMINAR';
        },
        async confirmBulkDelete() {
            if (!this.bulkModal.count || !this.canConfirmBulk()) return;
            const kind = this.bulkModal.kind;
            this.bulkModal.loading = true; this.bulkLoading = true;
            try {
                const body = (kind === 'cleanup-missing' || kind === 'cleanup-expired')
                    ? { ...Object.fromEntries(this.queryParams(false).entries()), [kind === 'cleanup-missing' ? 'availability' : 'status']: kind === 'cleanup-missing' ? 'missing' : 'expired', all_matching: true, confirm_count: this.bulkModal.count }
                    : { ...this.selectionBody(), confirm_count: this.bulkModal.count };
                const res = await apiFetch('/shares/bulk-delete', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
                const payload = await res.json(); if (!res.ok) throw new Error(payload.error || 'Error ' + res.status);
                this.bulkModal = { show: false, loading: false, count: 0, summary: {}, kind: 'selection', confirmText: '' };
                this.clearSelection();
                this.showToast(true, `${payload.deleted_count || 0} enlace(s) depurado(s) · ${payload.omitted_count || 0} omitidos`); await this.loadShares();
            } catch (e) { this.showToast(false, e.message); } finally { this.bulkModal.loading = false; this.bulkLoading = false; }
        },
        async verifySelected() {
            if (!this.selectedCount()) return;
            this.bulkLoading = true;
            try {
                const res = await apiFetch('/shares/availability/verify', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.selectionBody()) });
                const payload = await res.json(); if (!res.ok) throw new Error(payload.error || 'Error ' + res.status);
                this.clearSelection(); this.showToast(true, `${payload.available || 0} disponibles, ${payload.missing || 0} ausentes, ${payload.unknown || 0} no verificados`); await this.loadShares();
            } catch (e) { this.showToast(false, e.message); } finally { this.bulkLoading = false; }
        },
        async verifyAllFiltered() {
            if (!this.meta.total) return;
            const total = this.meta.total;
            const batchSize = 50;
            this.bulkLoading = true;
            this.showToast(true, `Iniciando verificación en lotes de ${batchSize} (${total} enlace(s))…`);
            let processed = 0, available = 0, missing = 0, unknown = 0;
            let cursor = null;
            try {
                do {
                    const body = Object.fromEntries(this.queryParams(false).entries());
                    body.all_matching = true;
                    body.limit = batchSize;
                    if (cursor !== null) body.after_id = cursor;
                    const res = await apiFetch('/shares/availability/verify', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
                    const payload = await res.json();
                    if (!res.ok) throw new Error(payload.error || 'Error ' + res.status);
                    processed += payload.checked || 0;
                    available += payload.available || 0;
                    missing += payload.missing || 0;
                    unknown += payload.unknown || 0;
                    this.showToast(true, `Verificados ${processed}/${total}: ${available} disponibles, ${missing} ausentes, ${unknown} no verificados`);
                    cursor = payload.has_more ? payload.next_cursor : null;
                } while (cursor !== null);
                await this.loadShares();
                this.showToast(true, `Listo: ${available} disponibles, ${missing} ausentes, ${unknown} no verificados`);
            } catch (e) { this.showToast(false, e.message); } finally { this.bulkLoading = false; }
        },
        async quickCleanupMissing() {
            if (!this.counters.missing) return;
            const count = this.counters.missing;
            const maxSafe = 200;
            if (count > maxSafe) {
                this.showToast(false, `Hay ${count} enlaces no disponibles. Aplica filtros para reducir antes de depurar (máximo recomendado: ${maxSafe}).`);
                return;
            }
            const body = Object.fromEntries(this.queryParams(false).entries());
            body.availability = 'missing';
            this.bulkLoading = true;
            try {
                const previewRes = await apiFetch('/shares/bulk-preview', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ ...body, all_matching: true }) });
                const preview = await previewRes.json(); if (!previewRes.ok) throw new Error(preview.error || 'No se pudo previsualizar');
                if (!preview.count) { this.bulkLoading = false; this.showToast(true, 'No hay enlaces no disponibles para depurar'); return; }
                this.bulkModal = { show: true, loading: false, count: preview.count, summary: preview.summary || {}, kind: 'cleanup-missing', confirmText: '' };
            } catch (e) { this.showToast(false, e.message); } finally { this.bulkLoading = false; }
        },
        async quickCleanupExpired() {
            if (!this.counters.expired) return;
            const count = this.counters.expired;
            const maxSafe = 200;
            if (count > maxSafe) {
                this.showToast(false, `Hay ${count} enlaces expirados. Aplica filtros para reducir antes de depurar (máximo recomendado: ${maxSafe}).`);
                return;
            }
            const body = Object.fromEntries(this.queryParams(false).entries());
            body.status = 'expired';
            this.bulkLoading = true;
            try {
                const previewRes = await apiFetch('/shares/bulk-preview', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ ...body, all_matching: true }) });
                const preview = await previewRes.json(); if (!previewRes.ok) throw new Error(preview.error || 'No se pudo previsualizar');
                if (!preview.count) { this.bulkLoading = false; this.showToast(true, 'No hay enlaces expirados para depurar'); return; }
                this.bulkModal = { show: true, loading: false, count: preview.count, summary: preview.summary || {}, kind: 'cleanup-expired', confirmText: '' };
            } catch (e) { this.showToast(false, e.message); } finally { this.bulkLoading = false; }
        },
        bulkSummaryLabel() { const s = this.bulkModal.summary || {}; return `${s.expired || 0} expirados, ${s.missing || 0} no disponibles, ${s.unknown || 0} no verificados`; },
        confirmDelete(share) { this.selectedIds = [share.id]; this.allMatching = false; this.openBulkPreview(); },

        copyLink(share) { navigator.clipboard.writeText(share.public_url).then(() => this.showToast(true, 'Enlace copiado')).catch(() => this.showToast(false, 'No se pudo copiar el enlace')); },
        openLink(share) { window.open(share.public_url, '_blank', 'noopener'); },
        showToast(success, message) { clearTimeout(this.toast._timer); this.toast = { show: true, success, message, _timer: setTimeout(() => this.toast.show = false, 3500) }; },
        permLabel(value) { return { read: 'Lectura', write: 'Escritura', upload: 'Subida', full: 'Completo' }[value] || value; },
        permissionClass(value) { return { read: 'bg-gray-100 text-gray-700', write: 'bg-blue-100 text-blue-700', upload: 'bg-amber-100 text-amber-700', full: 'bg-green-100 text-green-700' }[value] || 'bg-gray-100 text-gray-700'; },
        expiryLabel(share) { return { active: 'Activo', expired: 'Expirado', never: 'Sin vencimiento' }[share.expiry_status] || 'No verificado'; },
        expiryClass(share) { return { active: 'text-green-600', expired: 'text-red-500', never: 'text-amber-600' }[share.expiry_status] || 'text-gray-500'; },
        availabilityLabel(share) { return { available: 'Archivo disponible', missing: 'Archivo no disponible', unknown: 'Archivo no verificado' }[share.file?.availability_state || 'unknown']; },
        availabilityClass(share) { return { available: 'text-green-600', missing: 'text-orange-600', unknown: 'text-gray-400' }[share.file?.availability_state || 'unknown']; },
        formatDate(raw) { if (!raw) return ''; return new Date(raw).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }); },
        filePath(path) { const parts = (path || '').split('/').filter(Boolean); return parts.length <= 1 ? '/' : '/' + parts.slice(0, -1).join('/'); },
        fileIcon(file) { if (!file) return '<i class="fas fa-question text-gray-300"></i>'; if (file.is_folder) return '<i class="fas fa-folder text-yellow-400"></i>'; const mime = file.mime_type || ''; if (mime.startsWith('image/')) return '<i class="fas fa-file-image text-blue-400"></i>'; if (mime.startsWith('video/')) return '<i class="fas fa-file-video text-purple-400"></i>'; if (mime.startsWith('audio/')) return '<i class="fas fa-file-audio text-pink-400"></i>'; if (mime === 'application/pdf') return '<i class="fas fa-file-pdf text-red-400"></i>'; return '<i class="fas fa-file text-gray-400"></i>'; },
        startSharesTour() { if (window.TcloudTour) TcloudTour.start({ steps: [{ title: 'Mis Recursos Compartidos', content: 'Administra tus enlaces con filtros por fecha, estado, disponibilidad y permiso.', icon: 'fa-share-alt', color: '#6366f1', selector: null, position: 'center' }, { title: 'Filtros y fechas', content: 'Busca por nombre o ruta y combina los filtros de vencimiento, disponibilidad y rangos de fecha.', icon: 'fa-filter', color: '#2563eb', selector: '.border-b.border-gray-100', position: 'bottom' },             { title: 'Ordenamiento', content: 'Pulsa los encabezados Recurso, Permiso, Estado, Expira, Accesos o Creado para ordenar ascendente o descendentemente.', icon: 'fa-sort', color: '#4654a8', selector: 'table thead', position: 'bottom' }, { title: 'Depuración masiva', content: 'Selecciona enlaces, verifica disponibilidad y previsualiza la eliminación antes de revocar definitivamente.', icon: 'fa-trash-alt', color: '#dc2626', selector: '[x-show="selectedCount() > 0"]', position: 'bottom' }] }); }
    };
}
</script>
<script src="/js/interactive-tour.js?v=21"></script>
@endsection
