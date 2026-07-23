@extends('layouts.app')

@section('title', 'PostgreSQL Admin - Tcloud')

@section('content')
<style>
.query-sidebar { max-height: 180px; }
@media (min-width: 640px) { .query-sidebar { max-height: 520px; } }
.pg-rel-badge { background:#ede9fe; color:#4f46e5; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
.pg-rel-badge-in { background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
.pg-tour-overlay { background:rgba(0,0,0,.55); }
[x-cloak] { display:none !important; }
</style>
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="jsonData()" x-init="init()">
    <div class="flex justify-between items-center mb-4 sm:mb-6">
        <h1 class="text-lg sm:text-2xl font-bold text-gray-800">Administracion PostgreSQL</h1>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
                <button @click="setTab('config')"
                        :class="activeTab === 'config' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Configuracion
                </button>
                <button @click="setTab('diagram'); loadSchema()"
                        :class="activeTab === 'diagram' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Diagrama
                </button>
                <button @click="setTab('query'); loadQueryTables()"
                        :class="activeTab === 'query' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Query SQL
                </button>
                <button @click="setTab('backup')"
                        :class="activeTab === 'backup' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Backup
                </button>
            </nav>
        </div>

        <div class="p-6">
            <template x-if="activeTab === 'config'">
                <div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Host</label>
                            <input type="text" x-model="config.host" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Puerto</label>
                            <input type="text" x-model="config.port" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Base de Datos</label>
                            <input type="text" x-model="config.database" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Usuario</label>
                            <input type="text" x-model="config.username" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contrasena</label>
                            <input type="password" x-model="config.password" class="w-full border rounded px-3 py-2">
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="saveConfig()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            Guardar Configuracion
                        </button>
                        <button @click="testConnection()" :disabled="testing" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:opacity-50">
                            <span x-show="!testing">Probar Conexion</span>
                            <span x-show="testing">Probando...</span>
                        </button>
                    </div>
                    <template x-if="testResult !== null">
                        <div x-transition class="mt-4 p-4 rounded" :class="testResult.success ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
                            <p x-text="testResult.message"></p>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="activeTab === 'diagram'">
                <div>
                    <div class="mb-3 flex justify-between items-center flex-wrap gap-2">
                        <p class="text-sm text-gray-500">Arrastra las tablas para organizar &bull; Rueda del mouse para zoom &bull; Click en una tabla para ver detalles</p>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button @click="diagramZoomOut()" title="Alejar" class="w-8 h-8 flex items-center justify-center border border-gray-300 bg-white rounded hover:bg-gray-50 text-gray-700 font-bold text-lg leading-none">−</button>
                            <span class="text-sm text-gray-600 w-14 text-center tabular-nums" x-text="diagramState ? Math.round(diagramState.zoom * 100) + '%' : '100%'"></span>
                            <button @click="diagramZoomIn()" title="Acercar" class="w-8 h-8 flex items-center justify-center border border-gray-300 bg-white rounded hover:bg-gray-50 text-gray-700 font-bold text-lg leading-none">+</button>
                            <button @click="diagramResetZoom()" title="Restablecer zoom" class="px-2 h-8 border border-gray-300 bg-white rounded hover:bg-gray-50 text-gray-600 text-xs">Reset</button>
                            <button @click="autoArrange()" title="Reorganizar tablas por jerarquia de dependencias"
                                    class="bg-teal-600 text-white px-3 h-8 rounded text-xs hover:bg-teal-700 flex items-center gap-1">
                                <i class="fas fa-sitemap"></i> Auto-organizar
                            </button>
                            <button @click="toggleFlowAnimation()" title="Animar flujo de datos por las relaciones FK"
                                    :class="flowAnimating ? 'bg-amber-500 text-white' : 'bg-white text-gray-600'"
                                    class="px-3 h-8 border border-gray-300 rounded hover:bg-gray-50 text-xs flex items-center gap-1">
                                <i class="fas fa-route"></i> Flujo
                            </button>
                            <button @click="startGuidedTour()" title="Tour guiado interactivo"
                                    class="bg-purple-600 text-white px-3 h-8 rounded text-xs hover:bg-purple-700 flex items-center gap-1">
                                <i class="fas fa-map-marked-alt"></i> Tour
                            </button>
                            <button @click="saveDiagramPositions()" class="bg-indigo-600 text-white px-3 py-1 rounded text-sm hover:bg-indigo-700 flex items-center gap-1">
                                <i class="fas fa-save"></i> Guardar
                            </button>
                        </div>
                    </div>

                    <!-- Leyenda de colores -->
                    <div class="mb-3 flex items-center gap-4 text-xs text-gray-500 flex-wrap">
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-amber-400"></span> PK</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-indigo-500"></span> FK</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-10 h-0.5 bg-indigo-400"></span> Relación (N:1)</span>
                        <span x-show="flowAnimating" class="flex items-center gap-1 text-amber-600 font-medium"><i class="fas fa-circle-notch fa-spin"></i> Animando flujo...</span>
                    </div>

                    <div x-show="schemaLoading" class="text-center py-8 text-gray-500">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                        <p class="mt-2">Cargando esquema...</p>
                    </div>
                    <div x-show="!schemaLoading" class="flex gap-3" style="height: 640px;">
                        <!-- Panel lateral de detalle -->
                        <div x-show="selectedTable !== null" x-transition
                             class="w-80 flex-shrink-0 overflow-y-auto bg-white border rounded shadow-sm p-4">
                            <template x-if="selectedTable !== null">
                                <div>
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="font-bold text-indigo-700 text-base break-all" x-text="selectedTable.name"></h3>
                                        <button @click="selectedTable = null" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                                    </div>
                                    <div class="space-y-1 mb-4 text-xs">
                                        <p><span class="text-gray-400">Filas (estimado):</span> <span class="font-semibold text-gray-700" x-text="selectedTable.rowCount.toLocaleString()"></span></p>
                                        <p><span class="text-gray-400">Columnas:</span> <span class="font-semibold text-gray-700" x-text="selectedTable.columns.length"></span></p>
                                        <p><span class="text-gray-400">PK:</span>
                                            <template x-if="selectedTable.primaryKey && selectedTable.primaryKey.length > 0">
                                                <span class="font-mono font-semibold text-amber-600" x-text="selectedTable.primaryKey.join(', ')"></span>
                                            </template>
                                            <template x-if="!selectedTable.primaryKey || selectedTable.primaryKey.length === 0">
                                                <span class="text-gray-300">sin PK</span>
                                            </template>
                                        </p>
                                    </div>

                                    <!-- Columnas -->
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Columnas</p>
                                    <div class="space-y-0.5 mb-4 max-h-48 overflow-y-auto pr-1">
                                        <template x-for="col in selectedTable.columns" :key="col.name">
                                            <div class="flex items-center justify-between text-xs py-1 px-2 rounded hover:bg-indigo-50">
                                                <span class="flex items-center gap-1.5 min-w-0">
                                                    <span x-show="col.isPK" class="text-amber-500 font-bold" title="Primary Key">⚿</span>
                                                    <span class="truncate font-medium text-gray-700" x-text="col.name"></span>
                                                </span>
                                                <span class="text-gray-400 text-[10px] font-mono whitespace-nowrap ml-2" x-text="(col.type||'').replace('character varying','varchar').replace('timestamp without time zone','timestamp').replace('timestamp with time zone','timestamptz').replace('double precision','float8')"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- FKs salientes -->
                                    <template x-if="selectedTable.foreignKeys.length > 0">
                                        <div class="mb-4">
                                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Referencia a (FK salientes)</p>
                                            <div class="space-y-1.5">
                                                <template x-for="(fk, idx) in selectedTable.foreignKeys" :key="idx">
                                                    <div class="text-xs p-2 bg-violet-50 rounded border border-violet-100">
                                                        <div class="flex items-center gap-1 mb-1">
                                                            <span class="pg-rel-badge" x-text="selectedTable.name + '.' + fk.column"></span>
                                                            <span class="text-gray-400">→</span>
                                                            <span class="pg-rel-badge-in" x-text="fk.references"></span>
                                                        </div>
                                                        <p class="text-[10px] text-gray-400">
                                                            ON DELETE: <span class="font-medium" x-text="fk.onDelete"></span> ·
                                                            ON UPDATE: <span class="font-medium" x-text="fk.onUpdate"></span>
                                                        </p>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- FKs entrantes -->
                                    <template x-if="selectedTable.incomingFKs && selectedTable.incomingFKs.length > 0">
                                        <div class="mb-4">
                                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Referenciada por (FK entrantes)</p>
                                            <div class="space-y-1.5">
                                                <template x-for="(fk, idx) in selectedTable.incomingFKs" :key="idx">
                                                    <div class="text-xs p-2 bg-emerald-50 rounded border border-emerald-100">
                                                        <div class="flex items-center gap-1 mb-1">
                                                            <span class="pg-rel-badge" x-text="fk.fromTable + '.' + fk.fromColumn"></span>
                                                            <span class="text-gray-400">→</span>
                                                            <span class="pg-rel-badge-in" x-text="fk.references"></span>
                                                        </div>
                                                        <p class="text-[10px] text-gray-400">
                                                            ON DELETE: <span class="font-medium" x-text="fk.onDelete"></span>
                                                        </p>
                                                        <button @click="selectTableByName(fk.fromTable)" class="text-[10px] text-indigo-500 hover:underline mt-1">Ver tabla <span x-text="fk.fromTable"></span></button>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- JOIN sugerido -->
                                    <template x-if="selectedTable.foreignKeys.length > 0">
                                        <div>
                                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">JOIN sugerido</p>
                                            <div class="bg-gray-900 text-gray-100 rounded p-3 text-[10px] font-mono leading-relaxed overflow-x-auto">
                                                <template x-for="(fk, idx) in selectedTable.foreignKeys" :key="'j'+idx">
                                                    <div class="mb-2">
                                                        <span class="text-emerald-400">SELECT</span> *<br>
                                                        <span class="text-emerald-400">FROM</span> <span class="text-indigo-300" x-text="selectedTable.name"></span> s<br>
                                                        <span class="text-emerald-400">JOIN</span> <span class="text-indigo-300" x-text="fk.references.split('.')[0]"></span> r<br>
                                                        &nbsp;&nbsp;<span class="text-emerald-400">ON</span> s.<span x-text="fk.column"></span> = r.<span x-text="fk.references.split('.')[1]"></span>;
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <div id="diagram-container" class="flex-1 overflow-auto border rounded bg-gray-100 relative">
                        </div>
                    </div>

                    <!-- Tour guiado overlay -->
                    <div x-cloak x-show="tour.active" class="pg-tour-overlay fixed inset-0 z-40 flex items-end sm:items-center justify-center p-4"
                         @click.self="dismissTour()">
                        <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6 relative" @click.stop>
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <p class="text-xs font-semibold text-purple-500 uppercase tracking-wider">
                                        Tour Guiado · Paso <span x-text="tour.step + 1"></span> de <span x-text="tour.total"></span>
                                    </p>
                                    <h3 class="text-lg font-bold text-gray-800 mt-1" x-text="tour.title"></h3>
                                </div>
                                <button @click="dismissTour()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                            </div>
                            <div class="progress-bar bg-gray-200 rounded-full h-1.5 mb-4 overflow-hidden">
                                <div class="bg-purple-600 h-full rounded-full transition-all duration-500" :style="'width:' + ((tour.step + 1) / tour.total * 100) + '%'"></div>
                            </div>
                            <div class="text-sm text-gray-600 leading-relaxed mb-4" x-html="tour.content"></div>
                            <div class="flex justify-between items-center">
                                <button @click="tourPrev()" x-show="tour.step > 0"
                                        class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
                                    <i class="fas fa-arrow-left"></i> Anterior
                                </button>
                                <span x-show="tour.step === 0" class="text-sm text-gray-300">Inicio</span>
                                <button @click="tourNext()" class="bg-purple-600 text-white px-4 py-2 rounded text-sm hover:bg-purple-700 flex items-center gap-1">
                                    <span x-text="tour.step < tour.total - 1 ? 'Siguiente' : 'Finalizar'"></span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'query'">
                <div class="flex flex-col sm:flex-row gap-0">

                    <!-- ── Sidebar ── -->
                    <div class="query-sidebar w-full sm:w-52 sm:flex-shrink-0 border-b sm:border-b-0 sm:border-r pb-3 sm:pb-0 pr-0 sm:pr-3 mr-0 sm:mr-4 overflow-y-auto">

                        <!-- Tables list -->
                        <div class="mb-5">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                                <i class="fas fa-table text-indigo-400"></i> Tablas
                            </p>
                            <div x-show="queryTablesLoading" class="text-xs text-gray-400 py-2">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Cargando...
                            </div>
                            <div class="space-y-0.5">
                                <template x-for="tbl in queryTables" :key="tbl.name">
                                    <button @click="insertQuickQuery('SELECT * FROM ' + tbl.name + '\nLIMIT 50;', true)"
                                            class="w-full text-left px-2 py-1.5 text-sm rounded hover:bg-indigo-50 text-gray-700 hover:text-indigo-700 flex items-center gap-1.5 group">
                                        <i class="fas fa-chevron-right text-gray-300 group-hover:text-indigo-400 text-xs"></i>
                                        <span x-text="tbl.name" class="truncate"></span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Quick queries grouped -->
                        <template x-for="group in quickQueryGroups" :key="group.label">
                            <div class="mb-4">
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5" x-text="group.label"></p>
                                <template x-for="q in group.queries" :key="q.label">
                                    <button @click="insertQuickQuery(q.sql, q.run)"
                                            :title="q.label"
                                            class="w-full text-left px-2 py-1.5 text-xs rounded hover:bg-blue-50 text-gray-600 hover:text-blue-700 flex items-center gap-1.5 group">
                                        <i class="fas fa-bolt text-gray-300 group-hover:text-blue-400 text-xs"></i>
                                        <span x-text="q.label" class="truncate"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>

                    <!-- ── Editor + Results ── -->
                    <div class="flex-1 min-w-0 flex flex-col gap-3">

                        <!-- SQL Editor -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-sm font-medium text-gray-700">Consulta SQL <span class="text-gray-400 font-normal">(solo SELECT)</span></label>
                                <div class="flex gap-2">
                                    <button @click="querySql = ''" class="text-xs text-gray-400 hover:text-gray-600">Limpiar</button>
                                    <button @click="executeQuery()" :disabled="queryLoading"
                                            class="bg-indigo-600 text-white px-4 py-1 rounded text-sm hover:bg-indigo-700 disabled:opacity-50 flex items-center gap-1">
                                        <i class="fas fa-play text-xs"></i>
                                        <span x-show="!queryLoading">Ejecutar</span>
                                        <span x-show="queryLoading">Ejecutando...</span>
                                    </button>
                                </div>
                            </div>
                            <textarea x-model="querySql" rows="6"
                                      @keydown.ctrl.enter.prevent="executeQuery()"
                                      class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 resize-y"
                                      placeholder="SELECT * FROM users LIMIT 10;&#10;&#10;Ctrl+Enter para ejecutar"></textarea>
                        </div>

                        <!-- Error -->
                        <div x-show="queryError" x-transition class="p-3 bg-red-50 border border-red-200 text-red-800 rounded text-sm flex items-start gap-2">
                            <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                            <span x-text="queryError"></span>
                        </div>

                        <!-- Results -->
                        <div x-show="queryResults !== null" x-transition>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-gray-500">
                                    <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                    <span x-text="queryRowCount"></span> fila<span x-show="queryRowCount !== 1">s</span>
                                    <span x-show="queryElapsedMs !== null" class="text-gray-400 ml-2" x-text="'· ' + queryElapsedMs + ' ms'"></span>
                                </span>
                                <button @click="copyResultsCSV()" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-copy"></i> Copiar CSV
                                </button>
                            </div>
                            <div class="overflow-auto border rounded" style="max-height:280px;">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-medium text-gray-400 text-xs w-8">#</th>
                                            <template x-for="col in queryColumns" :key="col">
                                                <th class="px-3 py-2 text-left font-semibold text-gray-600 text-xs whitespace-nowrap" x-text="col"></th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <template x-for="(row, idx) in queryRows" :key="idx">
                                            <tr class="hover:bg-indigo-50 transition-colors">
                                                <td class="px-3 py-1.5 text-gray-300 text-xs select-none" x-text="idx + 1"></td>
                                                <template x-for="col in queryColumns" :key="col">
                                                    <td class="px-3 py-1.5 text-gray-800 text-xs font-mono whitespace-nowrap max-w-xs truncate"
                                                        :title="row[col] ?? ''"
                                                        x-text="row[col] ?? 'NULL'">
                                                    </td>
                                                </template>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'backup'">
                <div>
                    <h3 class="font-medium text-gray-800 mb-1">Backup Local</h3>
                    <p class="text-sm text-gray-500 mb-4">Genera un archivo <code class="bg-gray-100 px-1 rounded">.sql</code> con el esquema completo y todos los datos de la base de datos.</p>

                    <div class="flex items-center gap-3 mb-4">
                        <button @click="startBackup()" :disabled="backupLoading"
                                class="bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700 disabled:opacity-50 flex items-center gap-2">
                            <i x-show="!backupLoading" class="fas fa-download"></i>
                            <i x-show="backupLoading"  class="fas fa-spinner fa-spin"></i>
                            <span x-show="!backupLoading">Descargar Backup SQL</span>
                            <span x-show="backupLoading">Generando backup...</span>
                        </button>
                    </div>

                    <!-- Backup status inline (no modal needed) -->
                    <div x-show="backupInlineStatus !== null" x-transition class="mb-6 p-4 rounded-lg flex items-center gap-3"
                         :class="backupInlineStatus === 'ok' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800'">
                        <i :class="backupInlineStatus === 'ok' ? 'fas fa-check-circle text-green-500 text-xl' : 'fas fa-times-circle text-red-500 text-xl'"></i>
                        <div>
                            <p class="font-medium" x-text="backupInlineStatus === 'ok' ? 'Backup generado exitosamente' : 'Error al generar backup'"></p>
                            <p x-show="backupInlineMsg" class="text-sm mt-0.5" x-text="backupInlineMsg"></p>
                        </div>
                        <button @click="backupInlineStatus = null" class="ml-auto text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>

                    <hr class="my-6">

                    <h3 class="font-medium text-gray-800 mb-4">Backup via FTP</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Host FTP</label>
                            <input type="text" x-model="ftp.host" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Puerto FTP</label>
                            <input type="text" x-model="ftp.port" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Usuario FTP</label>
                            <input type="text" x-model="ftp.username" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contrasena FTP</label>
                            <input type="password" x-model="ftp.password" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ruta FTP (opcional)</label>
                            <input type="text" x-model="ftp.path" class="w-full border rounded px-3 py-2">
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="saveFtpConfig()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            Guardar Config FTP
                        </button>
                        <button @click="startBackupToFtp()" :disabled="backupLoading" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700 disabled:opacity-50">
                            <i class="fas fa-upload mr-2"></i>
                            <span x-show="!backupLoading">Subir a FTP</span>
                            <span x-show="backupLoading">Subiendo...</span>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div x-cloak x-show="backupModal.show" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-8 w-full max-w-sm">
            <div class="text-center">
                <div x-show="backupModal.status === 'loading'">
                    <i class="fas fa-spinner fa-spin text-4xl text-blue-600 mb-4"></i>
                    <p class="text-lg font-medium text-gray-800">Generando Backup...</p>
                    <p class="text-sm text-gray-500 mt-2">Esto puede tomar unos segundos</p>
                </div>
                <div x-show="backupModal.status === 'success'">
                    <i class="fas fa-check-circle text-5xl text-green-500 mb-4"></i>
                    <p class="text-lg font-medium text-gray-800">Backup Generado Exitosamente</p>
                    <p class="text-sm text-gray-500 mt-2">Tu archivo se esta descargando</p>
                    <button @click="closeBackupModal()" class="mt-4 bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">
                        Aceptar
                    </button>
                </div>
                <div x-show="backupModal.status === 'error'">
                    <i class="fas fa-times-circle text-5xl text-red-500 mb-4"></i>
                    <p class="text-lg font-medium text-gray-800">Error en Backup</p>
                    <p class="text-sm text-gray-500 mt-2" x-text="backupModal.message"></p>
                    <button @click="closeBackupModal()" class="mt-4 bg-gray-600 text-white px-6 py-2 rounded hover:bg-gray-700">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div x-show="toastShow" x-transition:enter="transition ease-out duration-300"
         x-transition:leave="transition ease-in duration-200"
         class="fixed bottom-4 right-4 z-50 max-w-sm"
         :class="toastSuccess ? 'bg-green-500' : 'bg-red-500'">
        <div class="flex items-center px-4 py-3 text-white">
            <span x-text="toastMessage"></span>
            <button @click="toastShow = false" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</div>

<script>
function jsonData() {
    return {
        activeTab: 'config',
        config: {
            host: '{{ env("DB_HOST", "127.0.0.1") }}',
            port: '{{ env("DB_PORT", "5432") }}',
            database: '{{ env("DB_DATABASE", "tcloudstorage") }}',
            username: '{{ env("DB_USERNAME", "cloud") }}',
            password: ''
        },
        ftp: {
            host: '{{ env("FTP_HOST", "") }}',
            port: '{{ env("FTP_PORT", "21") }}',
            username: '{{ env("FTP_USERNAME", "") }}',
            password: '',
            path: '{{ env("FTP_PATH", "/") }}'
        },
        testResult: null,
        testing: false,
        schemaTables: [],
        schemaLoading: false,
        querySql: '',
        queryResults: null,
        queryLoading: false,
        queryError: null,
        queryElapsedMs: null,
        queryTables: [],
        queryTablesLoading: false,
        backupLoading: false,
        backupModal: { show: false, status: 'loading', message: '' },
        backupInlineStatus: null,
        backupInlineMsg: '',
        toastShow: false,
        toastMessage: '',
        toastSuccess: true,
        savedPositions: {},
        diagramState: null,
        selectedTable: null,
        flowAnimating: false,
        flowRAF: null,
        flowParticles: [],
        flowGroup: null,
        tour: {
            active: false,
            step: 0,
            total: 0,
            title: '',
            content: '',
            steps: [],
        },

        quickQueryGroups: [
            {
                label: 'Estructura',
                queries: [
                    { label: 'Listar tablas', run: true, sql: "SELECT table_name, table_type\nFROM information_schema.tables\nWHERE table_schema = 'public'\nORDER BY table_name;" },
                    { label: 'Ver todas las columnas', run: true, sql: "SELECT table_name, column_name, data_type, is_nullable, column_default\nFROM information_schema.columns\nWHERE table_schema = 'public'\nORDER BY table_name, ordinal_position;" },
                    { label: 'Ver foreign keys', run: true, sql: "SELECT tc.table_name, kcu.column_name,\n       ccu.table_name AS ref_table, ccu.column_name AS ref_column\nFROM information_schema.table_constraints tc\nJOIN information_schema.key_column_usage kcu\n  ON tc.constraint_name = kcu.constraint_name\nJOIN information_schema.constraint_column_usage ccu\n  ON ccu.constraint_name = tc.constraint_name\nWHERE tc.constraint_type = 'FOREIGN KEY'\nORDER BY tc.table_name;" },
                    { label: 'Ver indices', run: true, sql: "SELECT tablename, indexname, indexdef\nFROM pg_indexes\nWHERE schemaname = 'public'\nORDER BY tablename, indexname;" },
                    { label: 'Ver constraints', run: true, sql: "SELECT tc.table_name, tc.constraint_name, tc.constraint_type, kcu.column_name\nFROM information_schema.table_constraints tc\nJOIN information_schema.key_column_usage kcu\n  ON tc.constraint_name = kcu.constraint_name\nWHERE tc.table_schema = 'public'\nORDER BY tc.table_name, tc.constraint_type;" },
                ]
            },
            {
                label: 'Rendimiento',
                queries: [
                    { label: 'Tamano de tablas', run: true, sql: "SELECT relname AS tabla,\n  pg_size_pretty(pg_total_relation_size(relid)) AS tamano_total,\n  pg_size_pretty(pg_relation_size(relid)) AS tamano_datos,\n  pg_size_pretty(pg_total_relation_size(relid) - pg_relation_size(relid)) AS indices\nFROM pg_catalog.pg_statio_user_tables\nORDER BY pg_total_relation_size(relid) DESC;" },
                    { label: 'Conexiones activas', run: true, sql: "SELECT pid, usename, application_name, client_addr,\n  state, query_start,\n  LEFT(query, 80) AS query\nFROM pg_stat_activity\nWHERE state != 'idle'\nORDER BY query_start DESC;" },
                    { label: 'Locks activos', run: true, sql: "SELECT pid, relation::regclass, mode, granted, locktype\nFROM pg_locks\nWHERE relation IS NOT NULL\nORDER BY relation;" },
                    { label: 'Cache hit rate', run: true, sql: "SELECT relname AS tabla,\n  round(100.0 * heap_blks_hit / NULLIF(heap_blks_hit + heap_blks_read, 0), 2) AS cache_hit_pct\nFROM pg_statio_user_tables\nWHERE heap_blks_hit + heap_blks_read > 0\nORDER BY cache_hit_pct DESC;" },
                ]
            },
            {
                label: 'Utilidades',
                queries: [
                    { label: 'Contar filas por tabla', run: true, sql: "SELECT relname AS tabla, n_live_tup AS filas_estimadas\nFROM pg_stat_user_tables\nORDER BY n_live_tup DESC;" },
                    { label: 'Version PostgreSQL', run: true, sql: "SELECT version();" },
                    { label: 'Buscar texto (editar)', run: false, sql: "-- Edita tabla y columna antes de ejecutar\nSELECT *\nFROM nombre_tabla\nWHERE columna ILIKE '%texto%'\nLIMIT 50;" },
                    { label: 'Duplicados en columna', run: false, sql: "-- Edita tabla y columna antes de ejecutar\nSELECT columna, COUNT(*) AS repeticiones\nFROM nombre_tabla\nGROUP BY columna\nHAVING COUNT(*) > 1\nORDER BY repeticiones DESC;" },
                ]
            }
        ],

        init: function() {
            this.loadSavedPositions();
        },

        setTab: function(tab) {
            this.activeTab = tab;
        },

        showToast: function(success, message) {
            this.toastSuccess = success;
            this.toastMessage = message;
            this.toastShow = true;
            var self = this;
            setTimeout(function() { self.toastShow = false; }, 4000);
        },

        saveConfig: function() {
            var self = this;
            apiFetch('/admin/postgres/config', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(this.config)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) { self.showToast(data.success, data.message); });
        },

        testConnection: function() {
            var self = this;
            this.testing = true;
            this.testResult = null;
            apiFetch('/admin/postgres/test', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    host: this.config.host,
                    port: this.config.port,
                    database: this.config.database,
                    username: this.config.username,
                    password: this.config.password
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.testResult = data;
                self.testing = false;
            });
        },

        loadSchema: function() {
            var self = this;
            this.schemaLoading = true;
            apiFetch('/admin/postgres/schema', {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    self.schemaTables = data.tables;
                    self.$nextTick(function() { self.renderDiagram(data.tables); });
                } else {
                    self.showToast(false, data.message);
                }
                self.schemaLoading = false;
            });
        },

        loadSavedPositions: function() {
            try {
                var saved = localStorage.getItem('postgres_diagram_positions');
                if (saved) this.savedPositions = JSON.parse(saved);
            } catch (e) { this.savedPositions = {}; }
        },

        saveDiagramPositions: function() {
            try {
                localStorage.setItem('postgres_diagram_positions', JSON.stringify(this.savedPositions));
                this.showToast(true, 'Organizacion guardada');
            } catch (e) {
                this.showToast(false, 'Error al guardar');
            }
        },

        /* ─── DIAGRAM RENDER ─────────────────────────────────────────── */

        renderDiagram: function(tables) {
            var container = document.getElementById('diagram-container');
            if (!container) return;
            container.innerHTML = '';

            if (!tables || tables.length === 0) {
                container.innerHTML = '<p class="text-center text-gray-500 py-8">No se encontraron tablas</p>';
                return;
            }

            // Remove previous document-level listeners to avoid accumulation
            if (this.diagramState && this.diagramState.docListeners) {
                var prev = this.diagramState.docListeners;
                document.removeEventListener('mousemove', prev.mousemove);
                document.removeEventListener('mouseup', prev.mouseup);
            }

            var TABLE_W    = 230;
            var ROW_H      = 24;
            var HEADER_H   = 38;
            var PAD_TOP    = 10;
            var COL_GAP    = 130;
            var self       = this;
            var zoom       = (this.diagramState && this.diagramState.zoom) ? this.diagramState.zoom : 1;

            // Build lookup and compute heights
            var tableLookup  = {};
            var tableHeights = {};
            var positions    = {};

            for (var i = 0; i < tables.length; i++) {
                var t = tables[i];
                tableLookup[t.name]  = t;
                tableHeights[t.name] = Math.max(t.columns.length * ROW_H + HEADER_H + PAD_TOP + 8, 80);
            }

            // ── Hierarchical layout (topological sort by FK deps) ──────
            // Tables referenced by others (no outgoing FKs or referenced first) go on top levels.
            // Tables that reference them go on lower levels.
            var levelMap = {};
            var self = this;

            function computeLevel(name, visiting) {
                if (levelMap[name] !== undefined) return levelMap[name];
                var t = tableLookup[name];
                if (!t || t.foreignKeys.length === 0) { levelMap[name] = 0; return 0; }
                if (visiting[name]) return 0; // cycle guard
                visiting[name] = true;
                var maxDep = 0;
                for (var f = 0; f < t.foreignKeys.length; f++) {
                    var ref = t.foreignKeys[f].references.split('.')[0];
                    if (tableLookup[ref]) {
                        maxDep = Math.max(maxDep, computeLevel(ref, visiting) + 1);
                    }
                }
                visiting[name] = false;
                levelMap[name] = maxDep;
                return maxDep;
            }
            for (var i = 0; i < tables.length; i++) {
                computeLevel(tables[i].name, {});
            }

            // Group tables by level
            var maxLevel = 0;
            var levelGroups = {};
            for (var i = 0; i < tables.length; i++) {
                var lvl = levelMap[tables[i].name] || 0;
                if (!levelGroups[lvl]) levelGroups[lvl] = [];
                levelGroups[lvl].push(tables[i]);
                if (lvl > maxLevel) maxLevel = lvl;
            }

            // Position tables: level 0 at top, increasing Y per level
            var ROW_GAP = 80;
            var yCursor = 60;
            for (var lvl = 0; lvl <= maxLevel; lvl++) {
                var group = levelGroups[lvl] || [];
                var rowMaxH = 0;
                var tablesPerRow = Math.min(group.length, Math.max(3, Math.ceil(Math.sqrt(tables.length / (maxLevel + 1) * 1.5))));
                for (var gi = 0; gi < group.length; gi++) {
                    var tName = group[gi].name;
                    var c = gi % tablesPerRow;
                    var r = Math.floor(gi / tablesPerRow);
                    if (this.savedPositions[tName]) {
                        positions[tName] = this.savedPositions[tName];
                    } else {
                        positions[tName] = {
                            x: 60 + c * (TABLE_W + COL_GAP),
                            y: yCursor + r * 360
                        };
                    }
                    rowMaxH = Math.max(rowMaxH, tableHeights[tName] + 40);
                }
                // Add tallest row height + gap for next level
                var rowsInLevel = Math.ceil(group.length / tablesPerRow);
                yCursor += rowsInLevel * 360 + ROW_GAP;
            }

            // Tooltip element (created once, reused)
            var tooltip = document.getElementById('pg-fk-tooltip');
            if (!tooltip) {
                tooltip = document.createElement('div');
                tooltip.id = 'pg-fk-tooltip';
                document.body.appendChild(tooltip);
            }
            tooltip.style.cssText = [
                'display:none',
                'position:fixed',
                'z-index:9999',
                'background:#fff',
                'border:1px solid #e0e7ff',
                'border-radius:10px',
                'padding:12px 16px',
                'box-shadow:0 8px 30px rgba(79,70,229,.15)',
                'pointer-events:none',
                'max-width:370px',
                'font-size:12px',
                'line-height:1.6',
                'font-family:system-ui,-apple-system,sans-serif'
            ].join(';');

            // ── SVG root ──────────────────────────────────────────────────
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('width',  '5000');
            svg.setAttribute('height', '4000');
            svg.style.cssText = 'display:block;user-select:none;background:#f0f4f8;';

            // Defs: arrow markers + drop shadow filter
            var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            defs.innerHTML =
                '<marker id="fk-arrow" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto">' +
                  '<polygon points="0 0, 8 3, 0 6" fill="#6366f1"/>' +
                '</marker>' +
                '<marker id="fk-arrow-hl" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto">' +
                  '<polygon points="0 0, 8 3, 0 6" fill="#4338ca"/>' +
                '</marker>' +
                '<filter id="tbl-shadow" x="-5%" y="-5%" width="115%" height="120%">' +
                  '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="rgba(0,0,0,0.10)"/>' +
                '</filter>';
            svg.appendChild(defs);

            // Zoom group (everything lives here so zoom works uniformly)
            var zoomGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            zoomGroup.setAttribute('id', 'zoom-group');
            zoomGroup.setAttribute('transform', 'scale(' + zoom + ')');
            svg.appendChild(zoomGroup);

            // FK lines group — appended FIRST so it renders under the table nodes
            var fkGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            fkGroup.setAttribute('id', 'fk-lines');
            zoomGroup.appendChild(fkGroup);

            // ── Render table nodes ────────────────────────────────────────
            for (var i = 0; i < tables.length; i++) {
                var table  = tables[i];
                var pos    = positions[table.name];
                var tH     = tableHeights[table.name];

                var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                g.setAttribute('data-table', table.name);
                g.setAttribute('transform', 'translate(' + pos.x + ',' + pos.y + ')');
                g.style.cursor = 'move';
                g.style.filter = 'url(#tbl-shadow)';

                // Table border
                var border = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                border.setAttribute('width', TABLE_W);
                border.setAttribute('height', tH);
                border.setAttribute('fill', '#ffffff');
                border.setAttribute('stroke', '#c7d2fe');
                border.setAttribute('stroke-width', '1.5');
                border.setAttribute('rx', '8');
                g.appendChild(border);

                // Header background (rounded top)
                var hdr = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                hdr.setAttribute('width', TABLE_W);
                hdr.setAttribute('height', HEADER_H);
                hdr.setAttribute('fill', '#4f46e5');
                hdr.setAttribute('rx', '8');
                g.appendChild(hdr);

                // Square off the bottom corners of the header
                var hdrFix = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                hdrFix.setAttribute('y', HEADER_H - 8);
                hdrFix.setAttribute('width', TABLE_W);
                hdrFix.setAttribute('height', '8');
                hdrFix.setAttribute('fill', '#4f46e5');
                g.appendChild(hdrFix);

                // Table name
                var title = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                title.setAttribute('x', '12');
                title.setAttribute('y', '25');
                title.setAttribute('fill', '#fff');
                title.setAttribute('font-weight', '600');
                title.setAttribute('font-size', '13');
                title.setAttribute('font-family', 'system-ui,sans-serif');
                title.textContent = table.name;
                g.appendChild(title);

                // Column count badge (top-right)
                var badge = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                badge.setAttribute('x', TABLE_W - 8);
                badge.setAttribute('y', '25');
                badge.setAttribute('fill', '#c7d2fe');
                badge.setAttribute('font-size', '10');
                badge.setAttribute('text-anchor', 'end');
                badge.setAttribute('font-family', 'system-ui,sans-serif');
                badge.textContent = table.columns.length + ' col' + (table.columns.length !== 1 ? 's' : '') +
                    (table.rowCount ? ' · ~' + table.rowCount.toLocaleString() + ' filas' : '');
                g.appendChild(badge);

                // Build FK column set for quick lookup
                var fkSet = {};
                for (var fki = 0; fki < table.foreignKeys.length; fki++) {
                    fkSet[table.foreignKeys[fki].column] = table.foreignKeys[fki];
                }

                // Columns
                for (var ci = 0; ci < table.columns.length; ci++) {
                    var col  = table.columns[ci];
                    var rowY = HEADER_H + PAD_TOP + ci * ROW_H;
                    var isFK = fkSet.hasOwnProperty(col.name);
                    var isPK = col.isPK || (!isFK && (col.name === 'id' || col.name === table.name + '_id'));

                    // Row background stripe
                    var rowBg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    rowBg.setAttribute('x', '1');
                    rowBg.setAttribute('y', rowY - 3);
                    rowBg.setAttribute('width', TABLE_W - 2);
                    rowBg.setAttribute('height', ROW_H);
                    rowBg.setAttribute('fill', isFK ? '#ede9fe' : (ci % 2 === 0 ? '#f9fafb' : '#ffffff'));
                    if (ci === table.columns.length - 1) {
                        rowBg.setAttribute('rx', '0');
                    }
                    g.appendChild(rowBg);

                    // FK indicator dot on left edge
                    if (isFK) {
                        var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                        dot.setAttribute('cx', '9');
                        dot.setAttribute('cy', rowY + ROW_H / 2 - 3);
                        dot.setAttribute('r', '3.5');
                        dot.setAttribute('fill', '#6366f1');
                        g.appendChild(dot);
                    }

                    // PK key symbol
                    if (isPK) {
                        var pkText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                        pkText.setAttribute('x', '9');
                        pkText.setAttribute('y', rowY + 13);
                        pkText.setAttribute('font-size', '10');
                        pkText.setAttribute('fill', '#f59e0b');
                        pkText.setAttribute('font-family', 'system-ui,sans-serif');
                        pkText.textContent = '⚿';
                        g.appendChild(pkText);
                    }

                    // Column name text
                    var colNameX = isFK ? '20' : (isPK ? '21' : '12');
                    var colNameEl = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    colNameEl.setAttribute('x', colNameX);
                    colNameEl.setAttribute('y', rowY + 13);
                    colNameEl.setAttribute('fill', isFK ? '#4338ca' : (isPK ? '#92400e' : '#374151'));
                    colNameEl.setAttribute('font-size', '12');
                    colNameEl.setAttribute('font-weight', isFK || isPK ? '500' : '400');
                    colNameEl.setAttribute('font-family', 'system-ui,sans-serif');
                    colNameEl.textContent = col.name;
                    g.appendChild(colNameEl);

                    // Column type (right-aligned, abbreviated)
                    var typeStr = (col.type || '')
                        .replace('character varying', 'varchar')
                        .replace('timestamp without time zone', 'timestamp')
                        .replace('timestamp with time zone', 'timestamptz')
                        .replace('double precision', 'float8');
                    var colTypeEl = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    colTypeEl.setAttribute('x', TABLE_W - 8);
                    colTypeEl.setAttribute('y', rowY + 13);
                    colTypeEl.setAttribute('fill', '#9ca3af');
                    colTypeEl.setAttribute('font-size', '10');
                    colTypeEl.setAttribute('text-anchor', 'end');
                    colTypeEl.setAttribute('font-family', 'system-ui,sans-serif');
                    colTypeEl.textContent = typeStr;
                    g.appendChild(colTypeEl);
                }

                zoomGroup.appendChild(g);
            }

            container.appendChild(svg);

            // Auto-size SVG to fit all tables
            var maxX = 0, maxY = 0;
            for (var tn in positions) {
                maxX = Math.max(maxX, positions[tn].x + TABLE_W + 100);
                maxY = Math.max(maxY, positions[tn].y + (tableHeights[tn] || 200) + 100);
            }
            svg.setAttribute('width',  Math.max(maxX, 2000));
            svg.setAttribute('height', Math.max(maxY, 1600));

            // Save diagram state BEFORE rendering FK lines (renderFKLines reads from it)
            this.diagramState = {
                positions:    positions,
                tableHeights: tableHeights,
                tableLookup:  tableLookup,
                tables:       tables,
                svg:          svg,
                zoomGroup:    zoomGroup,
                fkGroup:      fkGroup,
                tooltip:      tooltip,
                tableW:       TABLE_W,
                headerH:      HEADER_H,
                padTop:       PAD_TOP,
                rowH:         ROW_H,
                zoom:         zoom,
                docListeners: {}
            };

            this.renderFKLines();

            // ── Drag + Click + Hover ──────────────────────────────────────
            var appData   = this;
            var dragState = null;

            svg.querySelectorAll('g[data-table]').forEach(function(g) {
                g.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var m = g.getAttribute('transform').match(/translate\(([^,]+),([^)]+)\)/);
                    dragState = {
                        g:       g,
                        name:    g.getAttribute('data-table'),
                        startX:  parseFloat(m[1]),
                        startY:  parseFloat(m[2]),
                        mouseX:  e.clientX,
                        mouseY:  e.clientY,
                        moved:   false
                    };
                    g.style.cursor = 'grabbing';
                    // Hide tooltip while dragging
                    appData.diagramState.tooltip.style.display = 'none';
                });

                // Hover: highlight related tables + FK lines
                g.addEventListener('mouseenter', function() {
                    if (dragState) return;
                    var name = g.getAttribute('data-table');
                    appData.highlightRelationships(name);
                });
                g.addEventListener('mouseleave', function() {
                    if (dragState) return;
                    appData.clearHighlight();
                });
            });

            var mmFn = function(e) {
                if (!dragState) return;
                var z  = appData.diagramState.zoom;
                var dx = (e.clientX - dragState.mouseX) / z;
                var dy = (e.clientY - dragState.mouseY) / z;
                if (Math.abs(e.clientX - dragState.mouseX) > 3 || Math.abs(e.clientY - dragState.mouseY) > 3) {
                    dragState.moved = true;
                }
                var nx = dragState.startX + dx;
                var ny = dragState.startY + dy;
                dragState.g.setAttribute('transform', 'translate(' + nx + ',' + ny + ')');
                appData.diagramState.positions[dragState.name] = { x: nx, y: ny };
                appData.renderFKLines();
            };

            var muFn = function() {
                if (!dragState) return;
                var m = dragState.g.getAttribute('transform').match(/translate\(([^,]+),([^)]+)\)/);
                // If not moved → treat as click → open detail panel
                if (!dragState.moved) {
                    appData.selectTableByName(dragState.name);
                } else {
                    appData.savedPositions[dragState.name] = { x: parseFloat(m[1]), y: parseFloat(m[2]) };
                }
                dragState.g.style.cursor = 'move';
                dragState = null;
            };

            document.addEventListener('mousemove', mmFn);
            document.addEventListener('mouseup',   muFn);
            this.diagramState.docListeners = { mousemove: mmFn, mouseup: muFn };

            // ── Mouse-wheel zoom ──────────────────────────────────────────
            container.addEventListener('wheel', function(e) {
                e.preventDefault();
                var state = appData.diagramState;
                state.zoom = Math.max(0.15, Math.min(3, state.zoom + (e.deltaY > 0 ? -0.08 : 0.08)));
                state.zoomGroup.setAttribute('transform', 'scale(' + state.zoom + ')');
            }, { passive: false });
        },

        /* ─── FK LINES (bezier routing + tooltip) ───────────────────── */

        renderFKLines: function() {
            var st = this.diagramState;
            if (!st || !st.fkGroup) return;

            // Clear old paths
            while (st.fkGroup.firstChild) st.fkGroup.removeChild(st.fkGroup.firstChild);

            var pos      = st.positions;
            var lookup   = st.tableLookup;
            var heights  = st.tableHeights;
            var W        = st.tableW;
            var HEADER_H = st.headerH;
            var PAD_TOP  = st.padTop;
            var ROW_H    = st.rowH;
            var tooltip  = st.tooltip;

            for (var i = 0; i < st.tables.length; i++) {
                var table  = st.tables[i];
                var srcPos = pos[table.name];
                if (!srcPos) continue;

                for (var fki = 0; fki < table.foreignKeys.length; fki++) {
                    var fk       = table.foreignKeys[fki];
                    var parts    = fk.references.split('.');
                    if (parts.length !== 2) continue;
                    var refName  = parts[0];
                    var refCol   = parts[1];
                    var tgtPos   = pos[refName];
                    if (!tgtPos) continue;

                    // Find row indices for precise Y anchors
                    var srcIdx = 0, tgtIdx = 0;
                    var srcT   = lookup[table.name];
                    var tgtT   = lookup[refName];
                    if (srcT) { for (var k = 0; k < srcT.columns.length; k++) { if (srcT.columns[k].name === fk.column) { srcIdx = k; break; } } }
                    if (tgtT) { for (var k = 0; k < tgtT.columns.length; k++) { if (tgtT.columns[k].name === refCol)    { tgtIdx = k; break; } } }

                    // Center-Y of the relevant column row
                    var srcRowY = srcPos.y + HEADER_H + PAD_TOP + srcIdx * ROW_H + ROW_H / 2 - 3;
                    var tgtRowY = tgtPos.y + HEADER_H + PAD_TOP + tgtIdx * ROW_H + ROW_H / 2 - 3;

                    var srcMidX = srcPos.x + W / 2;
                    var tgtMidX = tgtPos.x + W / 2;
                    var srcH    = heights[table.name] || 200;
                    var tgtH    = heights[refName]    || 200;

                    // ── Routing: orthogonal-style to avoid crossing tables ──
                    var x1, y1, x2, y2, cp1x, cp1y, cp2x, cp2y;
                    var hOverlap = Math.min(srcPos.x + W, tgtPos.x + W) > Math.max(srcPos.x, tgtPos.x);

                    if (hOverlap) {
                        // Tables overlap horizontally → route around the side
                        // Go from the right/left edge, curve out, then back in
                        var goRight = (srcPos.x + W / 2) < (tgtPos.x + W / 2);
                        if (goRight) {
                            x1 = srcPos.x + W; y1 = srcRowY;
                            x2 = tgtPos.x + W; y2 = tgtRowY;
                        } else {
                            x1 = srcPos.x;     y1 = srcRowY;
                            x2 = tgtPos.x;     y2 = tgtRowY;
                        }
                        var curveOut = 70;
                        if (goRight) {
                            cp1x = x1 + curveOut; cp1y = y1;
                            cp2x = x2 + curveOut; cp2y = y2;
                        } else {
                            cp1x = x1 - curveOut; cp1y = y1;
                            cp2x = x2 - curveOut; cp2y = y2;
                        }
                    } else if (srcMidX <= tgtMidX) {
                        // Source is to the LEFT → right edge to left edge
                        x1 = srcPos.x + W; y1 = srcRowY;
                        x2 = tgtPos.x;     y2 = tgtRowY;
                        // Route around: if tables at similar Y, bow outward
                        var yDiff = Math.abs(y1 - y2);
                        var hgap = Math.max(60, (x2 - x1) * 0.45);
                        if (yDiff < 30) {
                            // Similar Y → bow down to avoid passing through tables in between
                            var bow = 60 + yDiff;
                            cp1x = x1 + hgap; cp1y = y1 + bow;
                            cp2x = x2 - hgap; cp2y = y2 + bow;
                        } else {
                            cp1x = x1 + hgap; cp1y = y1;
                            cp2x = x2 - hgap; cp2y = y2;
                        }
                    } else {
                        // Source is to the RIGHT → left edge to right edge
                        x1 = srcPos.x;         y1 = srcRowY;
                        x2 = tgtPos.x + W;     y2 = tgtRowY;
                        var yDiff2 = Math.abs(y1 - y2);
                        var hgap = Math.max(60, (x1 - x2) * 0.45);
                        if (yDiff2 < 30) {
                            var bow2 = 60 + yDiff2;
                            cp1x = x1 - hgap; cp1y = y1 + bow2;
                            cp2x = x2 + hgap; cp2y = y2 + bow2;
                        } else {
                            cp1x = x1 - hgap; cp1y = y1;
                            cp2x = x2 + hgap; cp2y = y2;
                        }
                    }

                    var d = 'M ' + x1 + ' ' + y1 +
                            ' C ' + cp1x + ' ' + cp1y + ',' +
                                    cp2x + ' ' + cp2y + ',' +
                                    x2   + ' ' + y2;

                    // Visual path
                    var visPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    visPath.setAttribute('d', d);
                    visPath.setAttribute('stroke', '#6366f1');
                    visPath.setAttribute('stroke-width', '2');
                    visPath.setAttribute('fill', 'none');
                    visPath.setAttribute('marker-end', 'url(#fk-arrow)');
                    visPath.setAttribute('pointer-events', 'none');

                    // Wide invisible hit-area for easy hovering
                    var hitPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    hitPath.setAttribute('d', d);
                    hitPath.setAttribute('stroke', 'transparent');
                    hitPath.setAttribute('stroke-width', '18');
                    hitPath.setAttribute('fill', 'none');
                    hitPath.style.cursor = 'crosshair';

                    // Tooltip handlers via closure
                    (function(srcTableName, srcColName, refTableName, refColName, vp, delRule, updRule) {
                        hitPath.addEventListener('mouseenter', function(e) {
                            vp.setAttribute('stroke', '#4338ca');
                            vp.setAttribute('stroke-width', '3');
                            vp.setAttribute('stroke-dasharray', 'none');
                            vp.setAttribute('marker-end', 'url(#fk-arrow-hl)');

                            tooltip.innerHTML =
                                '<div style="font-weight:700;color:#1e1b4b;margin-bottom:8px;font-size:13px;border-bottom:1px solid #e0e7ff;padding-bottom:6px;">' +
                                    '&#128279; Clave Foranea (Foreign Key)' +
                                '</div>' +
                                '<div style="margin-bottom:8px;">' +
                                    '<span style="background:#ede9fe;color:#4f46e5;padding:3px 9px;border-radius:20px;font-weight:600;font-size:12px;">' +
                                        srcTableName + '.' + srcColName +
                                    '</span>' +
                                    '<span style="color:#9ca3af;margin:0 7px;font-size:14px;">&#8594;</span>' +
                                    '<span style="background:#d1fae5;color:#065f46;padding:3px 9px;border-radius:20px;font-weight:600;font-size:12px;">' +
                                        refTableName + '.' + refColName +
                                    '</span>' +
                                '</div>' +
                                '<div style="color:#6b7280;font-size:11px;margin-bottom:6px;">Cardinalidad: Muchos &#8594; Uno &nbsp;(N : 1)</div>' +
                                '<div style="font-size:10px;color:#9ca3af;margin-bottom:8px;">' +
                                    'ON DELETE: <span style="font-weight:600;color:' + (delRule === 'CASCADE' ? '#dc2626' : '#6b7280') + ';">' + delRule + '</span>' +
                                    ' &middot; ON UPDATE: <span style="font-weight:600;color:' + (updRule === 'CASCADE' ? '#dc2626' : '#6b7280') + ';">' + updRule + '</span>' +
                                '</div>' +
                                '<div style="font-size:11px;color:#4b5563;font-weight:600;margin-bottom:4px;">Ejemplo JOIN:</div>' +
                                '<div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:8px 10px;font-family:\'Courier New\',monospace;font-size:11px;color:#374151;line-height:1.8;">' +
                                    'SELECT *<br>' +
                                    'FROM <strong style="color:#4f46e5">' + srcTableName + '</strong> s<br>' +
                                    'JOIN <strong style="color:#065f46">' + refTableName + '</strong> r<br>' +
                                    '&nbsp;&nbsp;ON s.<strong>' + srcColName + '</strong> = r.<strong>' + refColName + '</strong>' +
                                '</div>';

                            tooltip.style.display = 'block';
                            tooltip.style.left = (e.clientX + 20) + 'px';
                            tooltip.style.top  = Math.max(10, e.clientY - 30) + 'px';
                        });

                        hitPath.addEventListener('mousemove', function(e) {
                            // Keep tooltip inside viewport horizontally
                            var tw  = tooltip.offsetWidth || 360;
                            var vpW = window.innerWidth;
                            var lx  = e.clientX + 20;
                            if (lx + tw > vpW - 10) lx = e.clientX - tw - 10;
                            tooltip.style.left = lx + 'px';
                            tooltip.style.top  = Math.max(10, e.clientY - 30) + 'px';
                        });

                        hitPath.addEventListener('mouseleave', function() {
                            vp.setAttribute('stroke', '#6366f1');
                            vp.setAttribute('stroke-width', '2');
                            vp.setAttribute('marker-end', 'url(#fk-arrow)');
                            tooltip.style.display = 'none';
                        });
                    }(table.name, fk.column, refName, refCol, visPath, fk.onDelete || 'NO ACTION', fk.onUpdate || 'NO ACTION'));

                    st.fkGroup.appendChild(hitPath);
                    st.fkGroup.appendChild(visPath);
                }
            }
        },

        /* ─── AUTO-ARRANGE (hierarchical layout reset) ──────────────── */

        autoArrange: function() {
            this.savedPositions = {};
            try { localStorage.removeItem('postgres_diagram_positions'); } catch (e) {}
            if (this.schemaTables.length > 0) {
                this.renderDiagram(this.schemaTables);
                this.showToast(true, 'Tablas reorganizadas por jerarquia');
            }
        },

        /* ─── ZOOM CONTROLS ─────────────────────────────────────────── */

        diagramZoomIn: function() {
            if (!this.diagramState) return;
            this.diagramState.zoom = Math.min(3, this.diagramState.zoom + 0.25);
            this.diagramState.zoomGroup.setAttribute('transform', 'scale(' + this.diagramState.zoom + ')');
        },

        diagramZoomOut: function() {
            if (!this.diagramState) return;
            this.diagramState.zoom = Math.max(0.15, this.diagramState.zoom - 0.25);
            this.diagramState.zoomGroup.setAttribute('transform', 'scale(' + this.diagramState.zoom + ')');
        },

        diagramResetZoom: function() {
            if (!this.diagramState) return;
            this.diagramState.zoom = 1;
            this.diagramState.zoomGroup.setAttribute('transform', 'scale(1)');
        },

        /* ─── QUERY ──────────────────────────────────────────────────── */

        loadQueryTables: function() {
            if (this.queryTables.length > 0) return;
            if (this.schemaTables.length > 0) { this.queryTables = this.schemaTables; return; }
            var self = this;
            this.queryTablesLoading = true;
            apiFetch('/admin/postgres/schema', { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) { self.queryTables = data.tables; self.schemaTables = data.tables; }
                self.queryTablesLoading = false;
            });
        },

        insertQuickQuery: function(sql, run) {
            this.querySql = sql;
            if (run) this.executeQuery();
        },

        copyResultsCSV: function() {
            if (!this.queryResults) return;
            var cols = this.queryResults.columns;
            var rows = this.queryResults.rows;
            var csv  = cols.join(',') + '\n';
            rows.forEach(function(row) {
                csv += cols.map(function(c) {
                    var v = row[c] != null ? String(row[c]) : '';
                    return v.includes(',') || v.includes('"') || v.includes('\n') ? '"' + v.replace(/"/g, '""') + '"' : v;
                }).join(',') + '\n';
            });
            navigator.clipboard.writeText(csv).then(function() {});
            this.showToast(true, 'Resultados copiados como CSV');
        },

        executeQuery: function() {
            var self = this;
            if (!this.querySql.trim()) { this.queryError = 'Ingresa una consulta SQL'; return; }
            this.queryLoading  = true;
            this.queryError    = null;
            this.queryResults  = null;
            this.queryElapsedMs = null;
            var t0 = Date.now();

            apiFetch('/admin/postgres/query', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ sql: this.querySql })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.queryElapsedMs = Date.now() - t0;
                if (data.success) self.queryResults = data;
                else self.queryError = data.message;
                self.queryLoading = false;
            })
            .catch(function(err) {
                self.queryError   = err.message;
                self.queryLoading = false;
            });
        },

        get queryColumns() { return this.queryResults ? this.queryResults.columns : []; },
        get queryRows()    { return this.queryResults ? this.queryResults.rows    : []; },
        get queryRowCount(){ return this.queryResults ? this.queryResults.rowCount : 0; },

        /* ─── BACKUP ─────────────────────────────────────────────────── */

        startBackup: function() {
            var self = this;
            this.backupLoading     = true;
            this.backupInlineStatus = null;
            this.backupInlineMsg    = '';

            apiFetch('/admin/postgres/backup', { method: 'GET', credentials: 'include' })
            .then(function(res) {
                if (!res.ok) {
                    return res.text().then(function(t) { throw new Error(t || 'Error del servidor'); });
                }
                // Extract filename from Content-Disposition header
                var cd   = res.headers.get('Content-Disposition') || '';
                var match = cd.match(/filename="?([^";\n]+)"?/);
                var fname = match ? match[1] : ('backup_' + new Date().toISOString().slice(0, 10) + '.sql');
                return res.blob().then(function(blob) { return { blob: blob, fname: fname }; });
            })
            .then(function(result) {
                var url = window.URL.createObjectURL(result.blob);
                var a   = document.createElement('a');
                a.href  = url;
                a.download = result.fname;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                self.backupLoading      = false;
                self.backupInlineStatus = 'ok';
                self.backupInlineMsg    = 'Archivo descargado: ' + result.fname;
            })
            .catch(function(err) {
                self.backupLoading      = false;
                self.backupInlineStatus = 'error';
                self.backupInlineMsg    = err.message;
            });
        },

        startBackupToFtp: function() {
            var self = this;
            this.backupModal   = { show: true, status: 'loading', message: '' };
            this.backupLoading = true;

            apiFetch('/admin/postgres/ftp/backup', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.backupLoading = false;
                if (data.success) self.backupModal = { show: true, status: 'success', message: '' };
                else              self.backupModal = { show: true, status: 'error', message: data.message };
            })
            .catch(function(err) {
                self.backupLoading = false;
                self.backupModal   = { show: true, status: 'error', message: err.message };
            });
        },

        closeBackupModal: function() { this.backupModal.show = false; },

        saveFtpConfig: function() {
            var self = this;
            apiFetch('/admin/postgres/ftp/config', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(this.ftp)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) { self.showToast(data.success, data.message); });
        },

        /* ─── INTERACTIVE: TABLE SELECT & DETAIL PANEL ──────────────── */

        highlightRelationships: function(name) {
            if (!this.diagramState || !this.diagramState.zoomGroup) return;
            var related = {};
            related[name] = true;
            var table = this.schemaTables.find(function(t) { return t.name === name; });
            if (table) {
                table.foreignKeys.forEach(function(fk) { related[fk.references.split('.')[0]] = true; });
                (table.incomingFKs || []).forEach(function(fk) { related[fk.fromTable] = true; });
            }
            var groups = this.diagramState.zoomGroup.querySelectorAll('g[data-table]');
            groups.forEach(function(g) {
                var tn = g.getAttribute('data-table');
                if (related[tn]) {
                    g.style.opacity = '1';
                    g.style.filter = 'url(#tbl-shadow)';
                } else {
                    g.style.opacity = '0.2';
                }
            });
            var paths = this.diagramState.fkGroup.querySelectorAll('path');
            paths.forEach(function(p) {
                if (p.getAttribute('stroke') === 'transparent') return;
                var d = p.getAttribute('d') || '';
                p.setAttribute('stroke-opacity', '0.15');
            });
        },

        clearHighlight: function() {
            if (!this.diagramState || !this.diagramState.zoomGroup) return;
            var groups = this.diagramState.zoomGroup.querySelectorAll('g[data-table]');
            groups.forEach(function(g) {
                g.style.opacity = '1';
                g.style.filter = 'url(#tbl-shadow)';
            });
            var paths = this.diagramState.fkGroup.querySelectorAll('path');
            paths.forEach(function(p) {
                if (p.getAttribute('stroke') === 'transparent') return;
                p.setAttribute('stroke-opacity', '1');
            });
        },

        selectTableByName: function(name) {
            var self = this;
            var found = this.schemaTables.find(function(t) { return t.name === name; });
            if (found) {
                this.selectedTable = found;
                this.focusTableInDiagram(name);
            }
        },

        focusTableInDiagram: function(name) {
            if (!this.diagramState) return;
            var pos = this.diagramState.positions[name];
            if (!pos) return;
            var container = document.getElementById('diagram-container');
            if (!container) return;
            var z = this.diagramState.zoom;
            container.scrollTo({
                left: pos.x * z - container.clientWidth / 2 + (this.diagramState.tableW * z) / 2,
                top:  pos.y * z - container.clientHeight / 2 + 100,
                behavior: 'smooth',
            });
            this.highlightTable(name);
        },

        highlightTable: function(name) {
            if (!this.diagramState || !this.diagramState.zoomGroup) return;
            var groups = this.diagramState.zoomGroup.querySelectorAll('g[data-table]');
            groups.forEach(function(g) {
                if (g.getAttribute('data-table') === name) {
                    g.style.filter = 'url(#tbl-shadow) drop-shadow(0 0 10px rgba(99,102,241,.7))';
                } else {
                    g.style.opacity = '0.35';
                }
            });
            var self = this;
            setTimeout(function() {
                groups.forEach(function(g) {
                    g.style.filter = 'url(#tbl-shadow)';
                    g.style.opacity = '1';
                });
            }, 2000);
        },

        getRelatedTables: function(name) {
            var related = {};
            var self = this;
            var table = this.schemaTables.find(function(t) { return t.name === name; });
            if (!table) return [];
            table.foreignKeys.forEach(function(fk) {
                related[fk.references.split('.')[0]] = true;
            });
            (table.incomingFKs || []).forEach(function(fk) {
                related[fk.fromTable] = true;
            });
            return Object.keys(related);
        },

        /* ─── FLOW ANIMATION (emulación de viaje de datos) ──────────── */

        toggleFlowAnimation: function() {
            if (this.flowAnimating) {
                this.stopFlowAnimation();
            } else {
                this.startFlowAnimation();
            }
        },

        startFlowAnimation: function() {
            if (!this.diagramState || !this.diagramState.fkGroup) return;
            this.flowAnimating = true;

            var st = this.diagramState;
            if (!this.flowGroup) {
                this.flowGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                this.flowGroup.setAttribute('id', 'flow-particles');
                st.zoomGroup.appendChild(this.flowGroup);
            }
            while (this.flowGroup.firstChild) this.flowGroup.removeChild(this.flowGroup.firstChild);

            var paths = st.fkGroup.querySelectorAll('path[stroke="#6366f1"]');
            this.flowParticles = [];
            for (var i = 0; i < paths.length; i++) {
                var p = paths[i];
                var len = p.getTotalLength();
                if (len < 1) continue;
                var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                circle.setAttribute('r', '4');
                circle.setAttribute('fill', '#f59e0b');
                circle.setAttribute('opacity', '0.9');
                circle.style.filter = 'drop-shadow(0 0 4px rgba(245,158,11,.8))';
                this.flowGroup.appendChild(circle);
                this.flowParticles.push({
                    path: p,
                    circle: circle,
                    length: len,
                    offset: Math.random() * len,
                    speed: 30 + Math.random() * 40,
                });
            }

            this.animateFlow(0);
        },

        animateFlow: function(timestamp) {
            if (!this.flowAnimating || this.flowParticles.length === 0) return;
            if (!this._flowLastT) this._flowLastT = timestamp;
            var dt = (timestamp - this._flowLastT) / 1000;
            this._flowLastT = timestamp;

            for (var i = 0; i < this.flowParticles.length; i++) {
                var pt = this.flowParticles[i];
                pt.offset += pt.speed * dt;
                if (pt.offset > pt.length) pt.offset = pt.offset % pt.length;
                var pos = pt.path.getPointAtLength(pt.offset);
                pt.circle.setAttribute('cx', pos.x);
                pt.circle.setAttribute('cy', pos.y);
            }
            var self = this;
            this.flowRAF = requestAnimationFrame(function(t) { self.animateFlow(t); });
        },

        stopFlowAnimation: function() {
            this.flowAnimating = false;
            this._flowLastT = null;
            if (this.flowRAF) cancelAnimationFrame(this.flowRAF);
            if (this.flowGroup) {
                while (this.flowGroup.firstChild) this.flowGroup.removeChild(this.flowGroup.firstChild);
            }
        },

        /* ─── GUIDED TOUR ──────────────────────────────────────────── */

        startGuidedTour: function() {
            if (this.schemaTables.length === 0) {
                this.showToast(false, 'Carga el esquema primero');
                return;
            }
            var self = this;
            var steps = [];

            steps.push({
                title: 'Bienvenido al Diagrama Interactivo',
                content: 'Este diagrama muestra todas las tablas de la base de datos PostgreSQL y sus relaciones. ' +
                         'Cada caja es una tabla, las líneas indican claves foráneas (FK) que conectan tablas. ' +
                         'Arrastra las tablas para reorganizar, usa la rueda del mouse para zoom. ' +
                         'Puedes hacer <strong>click en cualquier tabla</strong> para ver detalles completos.',
            });

            var sorted = this.schemaTables.slice().sort(function(a, b) {
                return (b.incomingFKs ? b.incomingFKs.length : 0) - (a.incomingFKs ? a.incomingFKs.length : 0);
            });

            var top = sorted.slice(0, Math.min(5, sorted.length));

            steps.push({
                title: 'Leyenda de colores',
                content: '<ul class="space-y-1">' +
                    '<li><span class="text-amber-500 font-bold">⚿</span> = Primary Key (clave primaria, identificador único)</li>' +
                    '<li><span class="text-indigo-500 font-bold">●</span> = Foreign Key (clave foránea, referencia a otra tabla)</li>' +
                    '<li>Líneas <span class="text-indigo-400 font-bold">índigo</span> = relación N:1 (muchos a uno)</li>' +
                    '<li>Pasa el mouse sobre una línea para ver el detalle de la relación y un JOIN de ejemplo.</li>' +
                    '</ul>',
            });

            top.forEach(function(t) {
                var fkCount = t.foreignKeys.length;
                var inCount = t.incomingFKs ? t.incomingFKs.length : 0;
                var content = '<p><strong>' + t.name + '</strong> tiene <strong>' + t.columns.length + '</strong> columnas y aproximadamente <strong>' + t.rowCount.toLocaleString() + '</strong> filas.</p>';
                if (t.primaryKey && t.primaryKey.length > 0) {
                    content += '<p class="mt-1">Clave primaria: <code class="bg-amber-100 text-amber-700 px-1 rounded">' + t.primaryKey.join(', ') + '</code></p>';
                }
                content += '<p class="mt-1">FK salientes: <strong>' + fkCount + '</strong> · FK entrantes: <strong>' + inCount + '</strong></p>';
                if (fkCount > 0) {
                    content += '<p class="mt-2 text-xs text-gray-400">Esta tabla referencia a:</p><ul class="text-xs mt-1 space-y-0.5">';
                    t.foreignKeys.forEach(function(fk) {
                        content += '<li><span class="text-indigo-600">' + fk.column + '</span> → <span class="text-emerald-600">' + fk.references + '</span></li>';
                    });
                    content += '</ul>';
                }
                content += '<p class="mt-2 text-xs text-purple-500">Click en "Siguiente" para enfocar esta tabla en el diagrama.</p>';
                steps.push({
                    title: 'Tabla destacada: ' + t.name,
                    content: content,
                    focusTable: t.name,
                });
            });

            steps.push({
                title: 'Exploración libre',
                content: 'Ya conoces las tablas más conectadas. Ahora puedes: ' +
                    '<ul class="mt-2 space-y-1">' +
                    '<li>Click en cualquier tabla del diagrama para ver su panel de detalle</li>' +
                    '<li>Pasar el mouse sobre las líneas FK para ver relaciones y JOINs</li>' +
                    '<li>Activar <strong>Flujo</strong> para ver una animación de cómo viajan los datos por las relaciones</li>' +
                    '<li>Arrastrar y reorganizar las tablas a tu gusto</li>' +
                    '</ul>',
            });

            this.tour.steps = steps;
            this.tour.total = steps.length;
            this.tour.step = 0;
            this.tour.active = true;
            this.applyTourStep();
        },

        applyTourStep: function() {
            var s = this.tour.steps[this.tour.step];
            if (!s) return;
            this.tour.title = s.title;
            this.tour.content = s.content;
            if (s.focusTable) {
                this.selectTableByName(s.focusTable);
            }
        },

        tourNext: function() {
            if (this.tour.step < this.tour.total - 1) {
                this.tour.step++;
                this.applyTourStep();
            } else {
                this.dismissTour();
            }
        },

        tourPrev: function() {
            if (this.tour.step > 0) {
                this.tour.step--;
                this.applyTourStep();
            }
        },

        dismissTour: function() {
            this.tour.active = false;
            this.tour.step = 0;
        },
    };
}
</script>
@endsection
