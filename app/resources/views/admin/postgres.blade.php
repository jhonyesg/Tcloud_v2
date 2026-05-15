@extends('layouts.app')

@section('title', 'PostgreSQL Admin - Tcloud')

@section('content')
<style>
.query-sidebar { max-height: 180px; }
@media (min-width: 640px) { .query-sidebar { max-height: 520px; } }
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
                    <div class="mb-4 flex justify-between items-center flex-wrap gap-2">
                        <p class="text-sm text-gray-500">Arrastra las tablas para organizar &bull; Rueda del mouse para hacer zoom</p>
                        <div class="flex items-center gap-2">
                            <button @click="diagramZoomOut()" title="Alejar" class="w-8 h-8 flex items-center justify-center border border-gray-300 bg-white rounded hover:bg-gray-50 text-gray-700 font-bold text-lg leading-none">−</button>
                            <span class="text-sm text-gray-600 w-14 text-center tabular-nums" x-text="diagramState ? Math.round(diagramState.zoom * 100) + '%' : '100%'"></span>
                            <button @click="diagramZoomIn()" title="Acercar" class="w-8 h-8 flex items-center justify-center border border-gray-300 bg-white rounded hover:bg-gray-50 text-gray-700 font-bold text-lg leading-none">+</button>
                            <button @click="diagramResetZoom()" title="Restablecer zoom" class="px-2 h-8 border border-gray-300 bg-white rounded hover:bg-gray-50 text-gray-600 text-xs">Reset</button>
                            <button @click="saveDiagramPositions()" class="bg-indigo-600 text-white px-3 py-1 rounded text-sm hover:bg-indigo-700 flex items-center gap-1">
                                <i class="fas fa-save"></i> Guardar Organizacion
                            </button>
                        </div>
                    </div>
                    <div x-show="schemaLoading" class="text-center py-8 text-gray-500">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                        <p class="mt-2">Cargando esquema...</p>
                    </div>
                    <div x-show="!schemaLoading" id="diagram-container" class="overflow-auto border rounded bg-gray-100 relative" style="height: 640px;">
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
            fetch('/admin/postgres/config', {
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
            fetch('/admin/postgres/test', {
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
            fetch('/admin/postgres/schema', {
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
            var cols         = Math.ceil(Math.sqrt(tables.length));

            for (var i = 0; i < tables.length; i++) {
                var t = tables[i];
                tableLookup[t.name]  = t;
                tableHeights[t.name] = Math.max(t.columns.length * ROW_H + HEADER_H + PAD_TOP + 8, 80);

                if (this.savedPositions[t.name]) {
                    positions[t.name] = this.savedPositions[t.name];
                } else {
                    var c = i % cols;
                    var r = Math.floor(i / cols);
                    positions[t.name] = { x: 60 + c * (TABLE_W + COL_GAP), y: 60 + r * 320 };
                }
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
                badge.textContent = table.columns.length + ' col' + (table.columns.length !== 1 ? 's' : '');
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
                    var isPK = !isFK && (col.name === 'id' || col.name === table.name + '_id');

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

            // ── Drag ──────────────────────────────────────────────────────
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
                        mouseY:  e.clientY
                    };
                    g.style.cursor = 'grabbing';
                    // Hide tooltip while dragging
                    appData.diagramState.tooltip.style.display = 'none';
                });
            });

            var mmFn = function(e) {
                if (!dragState) return;
                var z  = appData.diagramState.zoom;
                var dx = (e.clientX - dragState.mouseX) / z;
                var dy = (e.clientY - dragState.mouseY) / z;
                var nx = dragState.startX + dx;
                var ny = dragState.startY + dy;
                dragState.g.setAttribute('transform', 'translate(' + nx + ',' + ny + ')');
                appData.diagramState.positions[dragState.name] = { x: nx, y: ny };
                appData.renderFKLines();
            };

            var muFn = function() {
                if (!dragState) return;
                var m = dragState.g.getAttribute('transform').match(/translate\(([^,]+),([^)]+)\)/);
                appData.savedPositions[dragState.name] = { x: parseFloat(m[1]), y: parseFloat(m[2]) };
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

                    // ── Routing: choose best port (L/R/T/B) ──────────────
                    var x1, y1, x2, y2, cp1x, cp1y, cp2x, cp2y;
                    var hOverlap = Math.min(srcPos.x + W, tgtPos.x + W) > Math.max(srcPos.x, tgtPos.x);

                    if (hOverlap) {
                        // Tables overlap horizontally → route vertically
                        if (srcPos.y + srcH / 2 < tgtPos.y + tgtH / 2) {
                            x1 = srcPos.x + W / 2; y1 = srcPos.y + srcH;
                            x2 = tgtPos.x + W / 2; y2 = tgtPos.y;
                        } else {
                            x1 = srcPos.x + W / 2; y1 = srcPos.y;
                            x2 = tgtPos.x + W / 2; y2 = tgtPos.y + tgtH;
                        }
                        var vgap = Math.max(40, Math.abs(y2 - y1) * 0.4);
                        cp1x = x1; cp1y = y1 + (y2 > y1 ? vgap : -vgap);
                        cp2x = x2; cp2y = y2 - (y2 > y1 ? vgap : -vgap);
                    } else if (srcMidX <= tgtMidX) {
                        // Source is to the LEFT → right→left
                        x1 = srcPos.x + W; y1 = srcRowY;
                        x2 = tgtPos.x;     y2 = tgtRowY;
                        var hgap = Math.max(50, (x2 - x1) * 0.45);
                        cp1x = x1 + hgap; cp1y = y1;
                        cp2x = x2 - hgap; cp2y = y2;
                    } else {
                        // Source is to the RIGHT → left→right
                        x1 = srcPos.x;         y1 = srcRowY;
                        x2 = tgtPos.x + W;     y2 = tgtRowY;
                        var hgap = Math.max(50, (x1 - x2) * 0.45);
                        cp1x = x1 - hgap; cp1y = y1;
                        cp2x = x2 + hgap; cp2y = y2;
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
                    (function(srcTableName, srcColName, refTableName, refColName, vp) {
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
                                '<div style="color:#6b7280;font-size:11px;margin-bottom:10px;">Cardinalidad: Muchos &#8594; Uno &nbsp;(N : 1)</div>' +
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
                    }(table.name, fk.column, refName, refCol, visPath));

                    st.fkGroup.appendChild(hitPath);
                    st.fkGroup.appendChild(visPath);
                }
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
            fetch('/admin/postgres/schema', { credentials: 'include', headers: { 'Accept': 'application/json' } })
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

            fetch('/admin/postgres/query', {
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

            fetch('/admin/postgres/backup', { method: 'GET', credentials: 'include' })
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

            fetch('/admin/postgres/ftp/backup', {
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
            fetch('/admin/postgres/ftp/config', {
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
        }
    };
}
</script>
@endsection
