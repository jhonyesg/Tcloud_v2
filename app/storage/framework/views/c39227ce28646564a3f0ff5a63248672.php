<?php $__env->startSection('title', 'Configuración de Correo - Tcloud'); ?>

<?php $__env->startSection('content'); ?>
<div class="p-6" x-data="correoData()" x-init="init()">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Configuración de Correo</h1>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
                <button @click="setTab('config')"
                        :class="activeTab === 'config' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Configuración SMTP
                </button>
                <button @click="setTab('plantillas')"
                        :class="activeTab === 'plantillas' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Plantillas
                </button>
                <button @click="setTab('logs')"
                        :class="activeTab === 'logs' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-6 py-3 border-b-2 font-medium text-sm transition-colors">
                    Log de Correos
                </button>
            </nav>
        </div>

        <div class="p-6">
            <template x-if="activeTab === 'config'">
                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Host SMTP <span class="text-red-500">*</span></label>
                            <input type="text" x-model="config.host" class="w-full border rounded px-3 py-2" :class="{'border-red-500': errors.host}" placeholder="smtp.gmail.com">
                            <p x-show="errors.host" class="text-red-500 text-xs mt-1" x-text="errors.host"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Puerto <span class="text-red-500">*</span></label>
                            <input type="number" x-model="config.port" class="w-full border rounded px-3 py-2" :class="{'border-red-500': errors.port}" placeholder="587">
                            <p x-show="errors.port" class="text-red-500 text-xs mt-1" x-text="errors.port"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Usuario <span class="text-red-500">*</span></label>
                            <input type="text" x-model="config.user" class="w-full border rounded px-3 py-2" :class="{'border-red-500': errors.user}" placeholder="correo@example.com">
                            <p x-show="errors.user" class="text-red-500 text-xs mt-1" x-text="errors.user"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                            <input type="password" x-model="config.password" class="w-full border rounded px-3 py-2" placeholder="Dejar vacío para no cambiar">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From Nombre <span class="text-red-500">*</span></label>
                            <input type="text" x-model="config.from_name" class="w-full border rounded px-3 py-2" :class="{'border-red-500': errors.from_name}" placeholder="TCloud">
                            <p x-show="errors.from_name" class="text-red-500 text-xs mt-1" x-text="errors.from_name"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From Email <span class="text-red-500">*</span></label>
                            <input type="email" x-model="config.from_email" class="w-full border rounded px-3 py-2" :class="{'border-red-500': errors.from_email}" placeholder="noreply@example.com">
                            <p x-show="errors.from_email" class="text-red-500 text-xs mt-1" x-text="errors.from_email"></p>
                        </div>
                        <div class="col-span-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="config.secure" class="rounded">
                                <span class="text-sm font-medium text-gray-700">Usar SSL/TLS</span>
                            </label>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="saveConfig()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            Guardar Configuración
                        </button>
                        <button @click="testConnection()" :disabled="testing" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:opacity-50">
                            <span x-show="!testing">Probar Conexión</span>
                            <span x-show="testing">Probando...</span>
                        </button>
                    </div>
                    <div x-show="testResult" x-transition class="mt-4 p-4 rounded" :class="testResult && testResult.success ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
                        <p x-text="testResult ? testResult.message : ''"></p>
                    </div>
                    <div x-show="message.text" x-transition class="mt-4 p-4 rounded" :class="message.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
                        <p x-text="message.text"></p>
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'plantillas'">
                <div>
                    <div class="flex justify-end mb-4">
                        <button @click="openPlantillaModal()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                            + Nueva Plantilla
                        </button>
                    </div>
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Nombre</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Asunto</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Variables</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="plantilla in plantillas" :key="plantilla.id">
                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                    <td class="py-3 px-4 text-sm text-gray-800" x-text="plantilla.display_name"></td>
                                    <td class="py-3 px-4 text-sm text-gray-800" x-text="plantilla.subject"></td>
                                    <td class="py-3 px-4 text-sm text-gray-500" x-text="plantilla.variables || '-'"></td>
                                    <td class="py-3 px-4">
                                        <button @click="openPlantillaModal(plantilla)" class="text-blue-600 hover:text-blue-800 text-sm mr-3">Editar</button>
                                        <button @click="openTestModal(plantilla)" class="text-green-600 hover:text-green-800 text-sm mr-3">Probar</button>
                                        <button @click="deletePlantilla(plantilla)" class="text-red-600 hover:text-red-800 text-sm">Eliminar</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div x-show="plantillas.length === 0" class="text-center py-8 text-gray-500">
                        <p>No hay plantillas configuradas</p>
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'logs'">
                <div>
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Fecha</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Destinatario</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Plantilla</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Estado</th>
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="log in logs" :key="log.id">
                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                    <td class="py-3 px-4 text-sm text-gray-800" x-text="new Date(log.sent_at).toLocaleString()"></td>
                                    <td class="py-3 px-4 text-sm text-gray-800" x-text="log.destinatario"></td>
                                    <td class="py-3 px-4 text-sm text-gray-800" x-text="log.plantilla"></td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-1 rounded text-xs font-medium" :class="log.estado === 'exito' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="log.estado"></span>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-red-600" x-text="log.error_message || '-'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div x-show="logs.length === 0" class="text-center py-8 text-gray-500">
                        <p>No hay logs de correos</p>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Modal Plantilla -->
    <div x-show="plantillaModal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl mx-4" @click.away="closePlantillaModal()">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold" x-text="plantillaModal.isEdit ? 'Editar Plantilla' : 'Nueva Plantilla'"></h3>
                <button @click="closePlantillaModal()" class="text-gray-500 hover:text-gray-700 text-xl">&times;</button>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre (identificador) <span class="text-red-500">*</span></label>
                    <input type="text" x-model="plantillaModal.data.name" class="w-full border rounded px-3 py-2" :class="{'border-red-500': plantillaModal.errors.name}" :disabled="plantillaModal.isEdit" placeholder="bienvenida-usuario">
                    <p x-show="plantillaModal.errors.name" class="text-red-500 text-xs mt-1" x-text="plantillaModal.errors.name"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre visible <span class="text-red-500">*</span></label>
                    <input type="text" x-model="plantillaModal.data.display_name" class="w-full border rounded px-3 py-2" :class="{'border-red-500': plantillaModal.errors.display_name}" placeholder="Bienvenida de Usuario">
                    <p x-show="plantillaModal.errors.display_name" class="text-red-500 text-xs mt-1" x-text="plantillaModal.errors.display_name"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Asunto <span class="text-red-500">*</span></label>
                    <input type="text" x-model="plantillaModal.data.subject" class="w-full border rounded px-3 py-2" :class="{'border-red-500': plantillaModal.errors.subject}" placeholder="Bienvenido a TCloud">
                    <p x-show="plantillaModal.errors.subject" class="text-red-500 text-xs mt-1" x-text="plantillaModal.errors.subject"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contenido HTML <span class="text-red-500">*</span></label>
                    <textarea x-model="plantillaModal.data.body_html" rows="8" class="w-full border rounded px-3 py-2 font-mono text-sm" :class="{'border-red-500': plantillaModal.errors.body_html}" placeholder="<h1>Hola <?php echo e('{' . '{nombre}' . '}'); ?></h1>"></textarea>
                    <p x-show="plantillaModal.errors.body_html" class="text-red-500 text-xs mt-1" x-text="plantillaModal.errors.body_html"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Variables (separadas por coma)</label>
                    <input type="text" x-model="plantillaModal.data.variables" class="w-full border rounded px-3 py-2" placeholder="nombre, email, fecha">
                </div>
            </div>
            <div class="flex justify-end gap-3 p-4 border-t">
                <button @click="closePlantillaModal()" class="px-4 py-2 border rounded hover:bg-gray-50 text-sm">Cancelar</button>
                <button @click="savePlantilla()" :disabled="plantillaModal.saving" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm disabled:opacity-50">
                    <span x-show="!plantillaModal.saving" x-text="plantillaModal.isEdit ? 'Actualizar' : 'Crear'"></span>
                    <span x-show="plantillaModal.saving">Guardando...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Probar Plantilla -->
    <div x-show="testModal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto" @click.away="closeTestModal()">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold" x-text="'Probar: ' + (testModal.plantilla?.display_name || '')"></h3>
                <button @click="closeTestModal()" class="text-gray-500 hover:text-gray-700 text-xl">&times;</button>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Destinatario de prueba <span class="text-red-500">*</span></label>
                    <input type="email" x-model="testModal.to" class="w-full border rounded px-3 py-2" placeholder="correo@ejemplo.com">
                </div>
                <template x-if="testModal.plantilla?.variables">
                    <div class="bg-gray-50 p-3 rounded">
                        <p class="text-sm font-medium text-gray-700 mb-2">Variables de la plantilla:</p>
                        <div class="grid grid-cols-2 gap-3">
                            <template x-for="v in testModal.plantilla.variables.split(',').map(s => s.trim()).filter(Boolean)" :key="v">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1" x-text="v"></label>
                                    <input type="text" x-model="testModal.variables[v]" class="w-full border rounded px-2 py-1 text-sm" :placeholder="'Valor para ' + v">
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                <div class="flex gap-3">
                    <button @click="previewPlantilla()" :disabled="testModal.previewLoading" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 text-sm disabled:opacity-50">
                        <span x-show="!testModal.previewLoading">Vista previa</span>
                        <span x-show="testModal.previewLoading">Cargando...</span>
                    </button>
                    <button @click="sendTestPlantilla()" :disabled="testModal.sending" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm disabled:opacity-50">
                        <span x-show="!testModal.sending">Enviar correo de prueba</span>
                        <span x-show="testModal.sending">Enviando...</span>
                    </button>
                </div>
                <div x-show="testModal.previewResult" class="border rounded p-3 bg-gray-50">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Asunto</p>
                    <p class="text-sm text-gray-800 mb-3" x-text="testModal.previewResult?.subject"></p>
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Contenido HTML renderizado</p>
                    <div class="border rounded bg-white p-3 text-sm" x-html="testModal.previewResult?.body"></div>
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1 mt-3">Código HTML</p>
                    <pre class="bg-gray-800 text-green-400 text-xs p-3 rounded overflow-x-auto" x-text="testModal.previewResult?.body"></pre>
                </div>
                <div x-show="testModal.result" x-transition class="p-3 rounded" :class="testModal.result?.success ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
                    <p x-text="testModal.result?.message"></p>
                </div>
            </div>
            <div class="flex justify-end gap-3 p-4 border-t">
                <button @click="closeTestModal()" class="px-4 py-2 border rounded hover:bg-gray-50 text-sm">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Confirmación Eliminar -->
    <div x-show="confirmDelete.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
            <div class="p-4 border-b">
                <h3 class="text-lg font-semibold text-red-600">Confirmar Eliminación</h3>
            </div>
            <div class="p-4">
                <p class="text-gray-700">¿Estás seguro de que deseas eliminar la plantilla <strong x-text="confirmDelete.plantilla?.display_name"></strong>?</p>
                <p class="text-sm text-gray-500 mt-2">Esta acción no se puede deshacer.</p>
            </div>
            <div class="flex justify-end gap-3 p-4 border-t">
                <button @click="confirmDelete.open = false" class="px-4 py-2 border rounded hover:bg-gray-50 text-sm">Cancelar</button>
                <button @click="confirmDeleteAction()" :disabled="confirmDelete.loading" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm disabled:opacity-50">
                    <span x-show="!confirmDelete.loading">Eliminar</span>
                    <span x-show="confirmDelete.loading">Eliminando...</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script>
function correoData() {
    return {
        activeTab: 'config',
        testing: false,
        testResult: null,
        message: { text: '', type: '' },
        errors: {},
        config: {
            host: '',
            port: 587,
            secure: false,
            user: '',
            password: '',
            from_name: '',
            from_email: ''
        },
        plantillas: [],
        logs: [],
        plantillaModal: {
            open: false,
            isEdit: false,
            saving: false,
            errors: {},
            data: { name: '', display_name: '', subject: '', body_html: '', variables: '' }
        },
        confirmDelete: {
            open: false,
            loading: false,
            plantilla: null
        },
        testModal: {
            open: false,
            plantilla: null,
            to: '',
            variables: {},
            previewLoading: false,
            sending: false,
            previewResult: null,
            result: null
        },

        init() {
            this.loadConfig();
            this.loadPlantillas();
            this.loadLogs();
        },
        
        setTab(tab) {
            this.activeTab = tab;
        },
        
        async loadConfig() {
            try {
                const res = await fetch('/admin/correo/config');
                const data = await res.json();
                if (data.data) {
                    this.config = {
                        host: data.data.host || '',
                        port: data.data.port || 587,
                        secure: data.data.secure || false,
                        user: data.data.user || '',
                        password: '',
                        from_name: data.data.from_name || '',
                        from_email: data.data.from_email || ''
                    };
                }
            } catch (e) {
                console.error('Error loading config:', e);
            }
        },
        
        validateConfig() {
            this.errors = {};
            if (!this.config.host) this.errors.host = 'El host es obligatorio';
            if (!this.config.port) this.errors.port = 'El puerto es obligatorio';
            if (!this.config.user) this.errors.user = 'El usuario es obligatorio';
            if (!this.config.from_name) this.errors.from_name = 'El nombre del remitente es obligatorio';
            if (!this.config.from_email) this.errors.from_email = 'El email del remitente es obligatorio';
            return Object.keys(this.errors).length === 0;
        },
        
        async saveConfig() {
            if (!this.validateConfig()) return;
            try {
                const payload = { ...this.config };
                if (!payload.password) delete payload.password;
                
                const res = await fetch('/admin/correo/config', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok && data.data) {
                    this.message = { text: 'Configuración guardada correctamente', type: 'success' };
                } else {
                    this.message = { text: data.message || 'Error al guardar la configuración', type: 'error' };
                }
                setTimeout(() => this.message = { text: '', type: '' }, 3000);
            } catch (e) {
                this.message = { text: 'Error al guardar: ' + e.message, type: 'error' };
            }
        },
        
        async testConnection() {
            this.testing = true;
            this.testResult = null;
            try {
                const res = await fetch('/admin/correo/config/test', { method: 'POST' });
                this.testResult = await res.json();
            } catch (e) {
                this.testResult = { success: false, message: 'Error: ' + e.message };
            }
            this.testing = false;
        },
        
        async loadPlantillas() {
            try {
                const res = await fetch('/admin/correo/plantillas');
                const data = await res.json();
                this.plantillas = data.data || [];
            } catch (e) {
                console.error('Error loading plantillas:', e);
            }
        },

        async loadLogs() {
            try {
                const res = await fetch('/admin/correo/logs');
                const data = await res.json();
                this.logs = data.data || [];
            } catch (e) {
                console.error('Error loading logs:', e);
            }
        },
        
        openPlantillaModal(plantilla = null) {
            this.plantillaModal.isEdit = !!plantilla;
            this.plantillaModal.errors = {};
            if (plantilla) {
                this.plantillaModal.data = { 
                    id: plantilla.id,
                    name: plantilla.name,
                    display_name: plantilla.display_name,
                    subject: plantilla.subject,
                    body_html: plantilla.body_html,
                    variables: plantilla.variables || ''
                };
            } else {
                this.plantillaModal.data = { name: '', display_name: '', subject: '', body_html: '', variables: '' };
            }
            this.plantillaModal.open = true;
        },
        
        closePlantillaModal() {
            this.plantillaModal.open = false;
            this.plantillaModal.data = { name: '', display_name: '', subject: '', body_html: '', variables: '' };
            this.plantillaModal.errors = {};
        },
        
        validatePlantilla() {
            this.plantillaModal.errors = {};
            if (!this.plantillaModal.data.name) this.plantillaModal.errors.name = 'El nombre identificador es obligatorio';
            if (!this.plantillaModal.data.display_name) this.plantillaModal.errors.display_name = 'El nombre visible es obligatorio';
            if (!this.plantillaModal.data.subject) this.plantillaModal.errors.subject = 'El asunto es obligatorio';
            if (!this.plantillaModal.data.body_html) this.plantillaModal.errors.body_html = 'El contenido es obligatorio';
            return Object.keys(this.plantillaModal.errors).length === 0;
        },
        
        async savePlantilla() {
            if (!this.validatePlantilla()) return;
            this.plantillaModal.saving = true;
            try {
                const url = this.plantillaModal.isEdit
                    ? `/admin/correo/plantillas/${this.plantillaModal.data.id}`
                    : '/admin/correo/plantillas';
                const method = this.plantillaModal.isEdit ? 'PUT' : 'POST';
                const payload = { ...this.plantillaModal.data };
                if (this.plantillaModal.isEdit) delete payload.name;
                
                const res = await fetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok) {
                    this.message = { 
                        text: this.plantillaModal.isEdit ? 'Plantilla actualizada correctamente' : 'Plantilla creada correctamente', 
                        type: 'success' 
                    };
                    this.closePlantillaModal();
                    await this.loadPlantillas();
                } else {
                    this.plantillaModal.errors = data.errors || {};
                    this.message = { text: data.message || 'Error al guardar la plantilla', type: 'error' };
                }
                setTimeout(() => this.message = { text: '', type: '' }, 3000);
            } catch (e) {
                this.message = { text: 'Error al guardar: ' + e.message, type: 'error' };
            }
            this.plantillaModal.saving = false;
        },
        
        deletePlantilla(plantilla) {
            this.confirmDelete.plantilla = plantilla;
            this.confirmDelete.open = true;
        },
        
        async confirmDeleteAction() {
            if (!this.confirmDelete.plantilla) return;
            this.confirmDelete.loading = true;
            try {
                const res = await fetch(`/admin/correo/plantillas/${this.confirmDelete.plantilla.id}`, { method: 'DELETE' });
                if (res.ok) {
                    this.message = { text: 'Plantilla eliminada correctamente', type: 'success' };
                    await this.loadPlantillas();
                } else {
                    const data = await res.json();
                    this.message = { text: data.message || 'Error al eliminar la plantilla', type: 'error' };
                }
                setTimeout(() => this.message = { text: '', type: '' }, 3000);
            } catch (e) {
                this.message = { text: 'Error al eliminar: ' + e.message, type: 'error' };
            }
            this.confirmDelete.loading = false;
            this.confirmDelete.open = false;
            this.confirmDelete.plantilla = null;
        },

        openTestModal(plantilla) {
            this.testModal.plantilla = plantilla;
            this.testModal.to = '';
            this.testModal.variables = {};
            this.testModal.previewResult = null;
            this.testModal.result = null;
            this.testModal.open = true;
        },

        closeTestModal() {
            this.testModal.open = false;
            this.testModal.plantilla = null;
            this.testModal.previewResult = null;
            this.testModal.result = null;
        },

        async previewPlantilla() {
            if (!this.testModal.plantilla) return;
            this.testModal.previewLoading = true;
            this.testModal.previewResult = null;
            try {
                const res = await fetch(`/admin/correo/plantillas/${this.testModal.plantilla.id}/preview`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ variables: this.testModal.variables })
                });
                const data = await res.json();
                if (res.ok) {
                    this.testModal.previewResult = data.data;
                } else {
                    this.testModal.result = { success: false, message: data.message || 'Error al generar vista previa' };
                }
            } catch (e) {
                this.testModal.result = { success: false, message: 'Error: ' + e.message };
            }
            this.testModal.previewLoading = false;
        },

        async sendTestPlantilla() {
            if (!this.testModal.plantilla || !this.testModal.to) {
                this.testModal.result = { success: false, message: 'Ingresa un destinatario' };
                return;
            }
            this.testModal.sending = true;
            this.testModal.result = null;
            try {
                const res = await fetch(`/admin/correo/plantillas/${this.testModal.plantilla.id}/send-test`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ to: this.testModal.to, variables: this.testModal.variables })
                });
                const data = await res.json();
                this.testModal.result = data;
            } catch (e) {
                this.testModal.result = { success: false, message: 'Error: ' + e.message };
            }
            this.testModal.sending = false;
        }
    }
}
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/correo.blade.php ENDPATH**/ ?>