@extends('layouts.app')

@section('title', 'PostgreSQL Admin - Tcloud')

@section('content')
<div class="p-6" x-data="jsonData()" x-init="init()">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Administracion PostgreSQL</h1>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
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
                <button @click="setTab('query')"
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
                    <div class="grid grid-cols-2 gap-4">
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
                        <div class="col-span-2">
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
                    <div x-show="testResult !== null" x-transition class="mt-4 p-4 rounded" :class="testResult.success ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
                        <p x-text="testResult.message"></p>
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'diagram'">
                <div>
                    <div class="mb-4 flex justify-between items-center">
                        <p class="text-sm text-gray-500">Arrastra las tablas para organizar</p>
                        <button @click="saveDiagramPositions()" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                            <i class="fas fa-save mr-1"></i> Guardar Organizacion
                        </button>
                    </div>
                    <div x-show="schemaLoading" class="text-center py-8 text-gray-500">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                        <p class="mt-2">Cargando esquema...</p>
                    </div>
                    <div x-show="!schemaLoading" id="diagram-container" class="overflow-auto border rounded bg-gray-50" style="height: 600px;">
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'query'">
                <div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Consulta SQL (solo SELECT)</label>
                        <textarea x-model="querySql" rows="6" class="w-full border rounded px-3 py-2 font-mono text-sm"
                                  placeholder="SELECT * FROM users LIMIT 10;"></textarea>
                    </div>
                    <button @click="executeQuery()" :disabled="queryLoading" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50">
                        <span x-show="!queryLoading">Ejecutar Query</span>
                        <span x-show="queryLoading">Ejecutando...</span>
                    </button>
                    
                    <div x-show="queryError" x-transition class="mt-4 p-4 bg-red-50 text-red-800 rounded">
                        <p x-text="queryError"></p>
                    </div>
                    
                    <div x-show="queryResults !== null" x-transition class="mt-4">
                        <p class="text-sm text-gray-500 mb-2">Resultados: <span x-text="queryRowCount"></span> filas</p>
                        <div class="overflow-auto border rounded">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <template x-for="col in queryColumns" :key="col">
                                            <th class="px-4 py-2 text-left font-medium text-gray-600" x-text="col"></th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <template x-for="(row, idx) in queryRows" :key="idx">
                                        <tr class="hover:bg-gray-50">
                                            <template x-for="col in queryColumns" :key="col">
                                                <td class="px-4 py-2 text-gray-800" x-text="row[col] ?? ''"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'backup'">
                <div>
                    <h3 class="font-medium text-gray-800 mb-4">Backup Local</h3>
                    <button @click="startBackup()" :disabled="backupLoading" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:opacity-50">
                        <i class="fas fa-download mr-2"></i>
                        <span x-show="!backupLoading">Descargar Backup SQL</span>
                        <span x-show="backupLoading">Generando...</span>
                    </button>

                    <hr class="my-6">

                    <h3 class="font-medium text-gray-800 mb-4">Backup via FTP</h3>
                    <div class="grid grid-cols-2 gap-4">
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
                        <div class="col-span-2">
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

    <div x-show="backupModal.show" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
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
            host: '{{ env("PG_HOST", "postgres") }}',
            port: '{{ env("PG_PORT", "5432") }}',
            database: '{{ env("PG_DATABASE", "tcloudstorage") }}',
            username: '{{ env("PG_USERNAME", "cloud") }}',
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
        backupLoading: false,
        backupModal: { show: false, status: 'loading', message: '' },
        toastShow: false,
        toastMessage: '',
        toastSuccess: true,
        savedPositions: {},
        
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
        
        renderDiagram: function(tables) {
            var container = document.getElementById('diagram-container');
            if (!container) return;
            container.innerHTML = '';
            
            if (!tables || tables.length === 0) {
                container.innerHTML = '<p class="text-center text-gray-500 py-8">No se encontraron tablas</p>';
                return;
            }
            
            var tableWidth = 220;
            var gap = 100;
            var cols = Math.ceil(Math.sqrt(tables.length));
            var positions = {};
            var self = this;
            
            for (var i = 0; i < tables.length; i++) {
                var name = tables[i].name;
                if (this.savedPositions[name]) {
                    positions[name] = this.savedPositions[name];
                } else {
                    var col = i % cols;
                    var row = Math.floor(i / cols);
                    positions[name] = { x: 50 + col * (tableWidth + gap), y: 50 + row * 280 };
                }
            }
            
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('width', '3000');
            svg.setAttribute('height', '2000');
            svg.style.background = '#f8fafc';
            svg.style.display = 'block';
            svg.style.userSelect = 'none';
            
            var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            defs.innerHTML = '<marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto"><polygon points="0 0, 10 3.5, 0 7" fill="#94a3b8"/></marker>';
            svg.appendChild(defs);
            
            for (var i = 0; i < tables.length; i++) {
                var table = tables[i];
                var pos = positions[table.name];
                var totalHeight = Math.max(table.columns.length * 22 + 50, 80);
                
                var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                g.setAttribute('data-table', table.name);
                g.setAttribute('transform', 'translate(' + pos.x + ',' + pos.y + ')');
                g.style.cursor = 'move';
                
                var rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                rect.setAttribute('width', tableWidth);
                rect.setAttribute('height', totalHeight);
                rect.setAttribute('fill', '#ffffff');
                rect.setAttribute('stroke', '#3b82f6');
                rect.setAttribute('stroke-width', '2');
                rect.setAttribute('rx', '8');
                g.appendChild(rect);
                
                var headerBg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                headerBg.setAttribute('width', tableWidth);
                headerBg.setAttribute('height', '36');
                headerBg.setAttribute('fill', '#3b82f6');
                headerBg.setAttribute('rx', '8');
                g.appendChild(headerBg);
                
                var headerClip = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                headerClip.setAttribute('y', '20');
                headerClip.setAttribute('width', tableWidth);
                headerClip.setAttribute('height', '16');
                headerClip.setAttribute('fill', '#3b82f6');
                g.appendChild(headerClip);
                
                var title = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                title.setAttribute('x', '12');
                title.setAttribute('y', '24');
                title.setAttribute('fill', '#ffffff');
                title.setAttribute('font-weight', 'bold');
                title.setAttribute('font-size', '14');
                title.textContent = table.name;
                g.appendChild(title);
                
                for (var ci = 0; ci < table.columns.length; ci++) {
                    var col = table.columns[ci];
                    var y = 50 + ci * 22;
                    var isFK = false;
                    for (var fki = 0; fki < table.foreignKeys.length; fki++) {
                        if (table.foreignKeys[fki].column === col.name) { isFK = true; break; }
                    }
                    
                    var colName = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    colName.setAttribute('x', '12');
                    colName.setAttribute('y', y);
                    colName.setAttribute('fill', '#374151');
                    colName.setAttribute('font-size', '12');
                    colName.textContent = col.name + (isFK ? ' FK' : '');
                    g.appendChild(colName);
                    
                    var colType = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    colType.setAttribute('x', tableWidth - 12);
                    colType.setAttribute('y', y);
                    colType.setAttribute('fill', '#9ca3af');
                    colType.setAttribute('font-size', '11');
                    colType.setAttribute('text-anchor', 'end');
                    colType.textContent = col.type;
                    g.appendChild(colType);
                }
                
                svg.appendChild(g);
            }
            
            var zoomGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            zoomGroup.setAttribute('id', 'zoom-group');
            while (svg.firstChild && svg.firstChild.tagName !== 'defs') {
                zoomGroup.appendChild(svg.firstChild);
            }
            svg.appendChild(zoomGroup);
            
            var fkGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            fkGroup.setAttribute('id', 'fk-lines');
            svg.appendChild(fkGroup);
            
            this.diagramState = {
                positions: positions,
                tables: tables,
                svg: svg,
                zoomGroup: zoomGroup,
                fkGroup: fkGroup,
                tableWidth: tableWidth,
                zoom: 1
            };
            
            this.renderFKLines();
            this.setupZoomControls();
            
            container.appendChild(svg);
            
            var draggable = svg.querySelectorAll('g[data-table]');
            var dragState = null;
            var appData = this;
            
            draggable.forEach(function(g) {
                g.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var tableName = g.getAttribute('data-table');
                    var transform = g.getAttribute('transform');
                    var match = transform.match(/translate\(([^,]+),([^)]+)\)/);
                    var startX = parseFloat(match[1]);
                    var startY = parseFloat(match[2]);
                    
                    dragState = {
                        g: g,
                        tableName: tableName,
                        startX: startX,
                        startY: startY,
                        mouseX: e.clientX,
                        mouseY: e.clientY
                    };
                    
                    g.style.cursor = 'grabbing';
                });
            });
            
            document.addEventListener('mousemove', function(e) {
                if (!dragState) return;
                
                var dx = e.clientX - dragState.mouseX;
                var dy = e.clientY - dragState.mouseY;
                
                var newX = dragState.startX + dx;
                var newY = dragState.startY + dy;
                
                dragState.g.setAttribute('transform', 'translate(' + newX + ',' + newY + ')');
                
                appData.diagramState.positions[dragState.tableName] = { x: newX, y: newY };
                
                var fkGroup = document.getElementById('fk-lines');
                if (fkGroup) {
                    fkGroup.innerHTML = '';
                    var positions = appData.diagramState.positions;
                    var tables = appData.diagramState.tables;
                    var tableWidth = appData.diagramState.tableWidth;
                    
                    for (var i = 0; i < tables.length; i++) {
                        var table = tables[i];
                        var pos = positions[table.name];
                        if (!pos) continue;
                        
                        for (var fki = 0; fki < table.foreignKeys.length; fki++) {
                            var fk = table.foreignKeys[fki];
                            var refParts = fk.references.split('.');
                            if (refParts.length === 2 && positions[refParts[0]]) {
                                var refPos = positions[refParts[0]];
                                var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                                line.setAttribute('x1', pos.x + tableWidth);
                                line.setAttribute('y1', pos.y + 50);
                                line.setAttribute('x2', refPos.x);
                                line.setAttribute('y2', refPos.y + 50);
                                line.setAttribute('stroke', '#e74c3c');
                                line.setAttribute('stroke-width', '3');
                                line.setAttribute('marker-end', 'url(#arrowhead)');
                                line.pointerEvents = 'none';
                                fkGroup.appendChild(line);
                            }
                        }
                    }
                }
            });
            
            document.addEventListener('mouseup', function(e) {
                if (!dragState) return;
                
                var transform = dragState.g.getAttribute('transform');
                var match = transform.match(/translate\(([^,]+),([^)]+)\)/);
                var finalX = parseFloat(match[1]);
                var finalY = parseFloat(match[2]);
                
                appData.savedPositions[dragState.tableName] = { x: finalX, y: finalY };
                
                dragState.g.style.cursor = 'move';
                dragState = null;
            });
        },
        
        renderFKLines: function() {
            var state = this.diagramState;
            if (!state || !state.fkGroup) return;
            
            var fkGroup = state.fkGroup;
            while (fkGroup.firstChild) {
                fkGroup.removeChild(fkGroup.firstChild);
            }
            
            var positions = state.positions;
            var tables = state.tables;
            var tableWidth = state.tableWidth;
            
            for (var i = 0; i < tables.length; i++) {
                var table = tables[i];
                var pos = positions[table.name];
                if (!pos) continue;
                
                for (var fki = 0; fki < table.foreignKeys.length; fki++) {
                    var fk = table.foreignKeys[fki];
                    var refParts = fk.references.split('.');
                    if (refParts.length === 2 && positions[refParts[0]]) {
                        var refPos = positions[refParts[0]];
                        var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line.setAttribute('x1', pos.x + tableWidth);
                        line.setAttribute('y1', pos.y + 50);
                        line.setAttribute('x2', refPos.x);
                        line.setAttribute('y2', refPos.y + 50);
                        line.setAttribute('stroke', '#e74c3c');
                        line.setAttribute('stroke-width', '3');
                        line.setAttribute('marker-end', 'url(#arrowhead)');
                        line.pointerEvents = 'none';
                        fkGroup.appendChild(line);
                    }
                }
            }
        },
        
        setupZoomControls: function() {
            var container = document.getElementById('diagram-container');
            if (!container || this.diagramState.zoomControlsAdded) return;
            
            var controls = document.createElement('div');
            controls.className = 'diagram-zoom-controls';
            controls.innerHTML = '<style>.diagram-zoom-controls{position:absolute;top:10px;right:10px;display:flex;gap:4px;z-index:10}.diagram-zoom-controls button{width:32px;height:32px;border:1px solid #d1d5db;background:white;border-radius:4px;cursor:pointer;font-size:16px;font-weight:bold}.diagram-zoom-controls button:hover{background:#f3f4f6}</style><button onclick="this.closest('[x-data]').__x.$data.zoomIn()">+</button><button onclick="this.closest('[x-data]').__x.$data.zoomOut()">-</button><button onclick="this.closest('[x-data]').__x.$data.resetZoom()">R</button>';
            
            container.style.position = 'relative';
            container.appendChild(controls);
            this.diagramState.zoomControlsAdded = true;
            
            container.addEventListener('wheel', function(e) {
                e.preventDefault();
                var delta = e.deltaY > 0 ? -0.1 : 0.1;
                var data = e.target.closest('[x-data]').__x.$data;
                data.diagramState.zoom = Math.max(0.25, Math.min(2, data.diagramState.zoom + delta));
                data.applyZoom();
            }, { passive: false });
        },
        
        zoomIn: function() {
            if (!this.diagramState) return;
            this.diagramState.zoom = Math.min(2, this.diagramState.zoom + 0.25);
            this.applyZoom();
        },
        
        zoomOut: function() {
            if (!this.diagramState) return;
            this.diagramState.zoom = Math.max(0.25, this.diagramState.zoom - 0.25);
            this.applyZoom();
        },
        
        resetZoom: function() {
            if (!this.diagramState) return;
            this.diagramState.zoom = 1;
            this.applyZoom();
        },
        
        applyZoom: function() {
            var state = this.diagramState;
            if (!state || !state.zoomGroup) return;
            state.zoomGroup.setAttribute('transform', 'scale(' + state.zoom + ')');
        },
        
        executeQuery: function() {
            var self = this;
            if (!this.querySql.trim()) {
                this.queryError = 'Ingresa una consulta SQL';
                return;
            }
            this.queryLoading = true;
            this.queryError = null;
            this.queryResults = null;
            
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
                if (data.success) self.queryResults = data;
                else self.queryError = data.message;
                self.queryLoading = false;
            });
        },
        
        get queryColumns() { return this.queryResults ? this.queryResults.columns : []; },
        get queryRows() { return this.queryResults ? this.queryResults.rows : []; },
        get queryRowCount() { return this.queryResults ? this.queryResults.rowCount : 0; },
        
        startBackup: function() {
            this.backupModal = { show: true, status: 'loading', message: '' };
            this.backupLoading = true;
            
            fetch('/admin/postgres/backup', {
                method: 'GET',
                credentials: 'include'
            })
            .then(function(res) {
                if (!res.ok) throw new Error('Error en la peticion');
                return res.blob();
            })
            .then(function(blob) {
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'backup_{{ env("PG_DATABASE", "tcloud") }}_' + new Date().toISOString().slice(0,10) + '.sql';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                self.backupLoading = false;
                self.backupModal = { show: true, status: 'success', message: '' };
            })
            .catch(function(err) {
                self.backupLoading = false;
                self.backupModal = { show: true, status: 'error', message: err.message };
            });
            
            var self = this;
            setTimeout(function() {
                if (self.backupModal.show && self.backupModal.status === 'loading') {
                    self.backupModal.show = false;
                    self.backupLoading = false;
                }
            }, 15000);
        },
        
        startBackupToFtp: function() {
            var self = this;
            this.backupModal = { show: true, status: 'loading', message: '' };
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
                else self.backupModal = { show: true, status: 'error', message: data.message };
            })
            .catch(function(err) {
                self.backupLoading = false;
                self.backupModal = { show: true, status: 'error', message: err.message };
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
