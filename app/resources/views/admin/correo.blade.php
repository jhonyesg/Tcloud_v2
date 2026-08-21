@extends('layouts.app')

@section('title', 'Configuración de Correo - Tcloud')

@section('content')
<div class="p-3 sm:p-6 pb-24 sm:pb-8" x-data="correoData()" x-init="init()">
    <div class="flex justify-between items-center mb-4 sm:mb-6">
        <h1 class="text-lg sm:text-2xl font-bold text-gray-800">Configuración de Correo</h1>
        <button onclick="startCorreoTour()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm transition-colors" title="Guía interactiva">
            <i class="fas fa-map-marked-alt"></i>
            <span class="hidden sm:inline">Guía</span>
        </button>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                        <div class="sm:col-span-2">
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

                    <!-- Mobile cards -->
                    <div class="sm:hidden space-y-3">
                        <template x-for="plantilla in plantillas" :key="plantilla.id">
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                <div class="flex items-center justify-between">
                                    <p class="font-semibold text-gray-800 text-sm" x-text="plantilla.display_name"></p>
                                    <span x-show="plantilla.body_html?.includes('<!DOCTYPE') || plantilla.body_html?.includes('<html')" class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-purple-100 text-purple-700">HTML</span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1" x-text="plantilla.subject"></p>
                                <p x-show="plantilla.variables" class="text-xs text-gray-400 mt-1">
                                    <span class="font-medium text-gray-500">Variables:</span>
                                    <span x-text="plantilla.variables"></span>
                                </p>
                                <div class="flex gap-2 mt-3">
                                    <button @click="openPlantillaModal(plantilla)" class="flex-1 py-1.5 text-xs font-medium rounded-lg bg-blue-50 text-blue-700 active:bg-blue-100">Editar</button>
                                    <button @click="openPreviewModal(plantilla)" class="flex-1 py-1.5 text-xs font-medium rounded-lg bg-purple-50 text-purple-700 active:bg-purple-100">Vista previa</button>
                                    <button @click="openTestModal(plantilla)" class="flex-1 py-1.5 text-xs font-medium rounded-lg bg-green-50 text-green-700 active:bg-green-100">Probar</button>
                                    <button @click="deletePlantilla(plantilla)" class="flex-1 py-1.5 text-xs font-medium rounded-lg bg-red-50 text-red-600 active:bg-red-100">Eliminar</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Desktop table -->
                    <div class="hidden sm:block overflow-x-auto">
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
                                    <td class="py-3 px-4 text-sm text-gray-800">
                                        <div class="flex items-center gap-2">
                                            <span x-text="plantilla.display_name"></span>
                                            <span x-show="plantilla.body_html?.includes('<!DOCTYPE') || plantilla.body_html?.includes('<html')" class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-purple-100 text-purple-700">HTML</span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-gray-800" x-text="plantilla.subject"></td>
                                    <td class="py-3 px-4 text-sm text-gray-500" x-text="plantilla.variables || '-'"></td>
                                    <td class="py-3 px-4">
                                        <div class="flex gap-3">
                                            <button @click="openPlantillaModal(plantilla)" class="text-blue-600 hover:text-blue-800 text-sm">Editar</button>
                                            <button @click="openPreviewModal(plantilla)" class="text-purple-600 hover:text-purple-800 text-sm">Vista previa</button>
                                            <button @click="openTestModal(plantilla)" class="text-green-600 hover:text-green-800 text-sm">Probar</button>
                                            <button @click="deletePlantilla(plantilla)" class="text-red-600 hover:text-red-800 text-sm">Eliminar</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    </div>

                    <div x-show="plantillas.length === 0" class="text-center py-8 text-gray-500">
                        <p>No hay plantillas configuradas</p>
                    </div>
                </div>
            </template>

            <template x-if="activeTab === 'logs'">
                <div>
                    <!-- Mobile cards -->
                    <div class="sm:hidden space-y-3">
                        <template x-for="log in logs" :key="log.id">
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <p class="text-xs text-gray-500 leading-snug" x-text="new Date(log.sent_at).toLocaleString()"></p>
                                    <span class="flex-shrink-0 px-2 py-0.5 rounded text-xs font-medium"
                                          :class="log.estado === 'exito' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                          x-text="log.estado"></span>
                                </div>
                                <p class="text-sm font-medium text-gray-800 truncate" x-text="log.destinatario"></p>
                                <p class="text-xs text-gray-500 mt-1" x-text="log.plantilla"></p>
                                <p x-show="log.error_message" class="text-xs text-red-500 mt-1 break-words" x-text="log.error_message"></p>
                            </div>
                        </template>
                    </div>

                    <!-- Desktop table -->
                    <div class="hidden sm:block overflow-x-auto">
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
                                    <td class="py-3 px-4 text-sm text-gray-800 whitespace-nowrap" x-text="new Date(log.sent_at).toLocaleString()"></td>
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
                    </div>

                    <div x-show="logs.length === 0" class="text-center py-8 text-gray-500">
                        <p>No hay logs de correos</p>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Modal Plantilla -->
    <div x-cloak x-show="plantillaModal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
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
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-gray-700">Contenido HTML <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <button type="button" @click="plantillaModal.showPreview = !plantillaModal.showPreview" class="text-xs px-2 py-1 rounded border bg-gray-50 hover:bg-gray-100 text-gray-700">
                                <span x-text="plantillaModal.showPreview ? 'Ocultar vista previa' : 'Ver vista previa'"></span>
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3" :class="plantillaModal.showPreview ? 'lg:grid-cols-2' : ''">
                        <textarea x-model="plantillaModal.data.body_html" rows="10" class="w-full border rounded px-3 py-2 font-mono text-sm" :class="{'border-red-500': plantillaModal.errors.body_html}" placeholder="<h1>Hola {{ '{' . '{nombre}' . '}' }}</h1>"></textarea>
                        <template x-if="plantillaModal.showPreview">
                            <div class="border rounded bg-white overflow-hidden flex flex-col">
                                <div class="bg-gray-50 px-3 py-2 border-b text-xs font-medium text-gray-500 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-green-400"></span> Vista previa en vivo
                                </div>
                                <div class="flex-1 p-0 overflow-auto" style="max-height: 280px;">
                                    <iframe class="w-full" style="min-height: 260px; border: none;" :srcdoc="plantillaModal.data.body_html || '<p style=\\'padding:20px;color:#9ca3af\\'>Escribe HTML para ver la vista previa...</p>'"></iframe>
                                </div>
                            </div>
                        </template>
                    </div>
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
    <div x-cloak x-show="testModal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
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
                    <div class="border rounded bg-white overflow-hidden">
                        <iframe class="w-full" style="min-height: 320px; border: none;" :srcdoc="testModal.previewResult?.body"></iframe>
                    </div>
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

    <!-- Modal Vista Previa Visual -->
    <div x-cloak x-show="previewModal.open" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto" @click.away="closePreviewModal()">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold" x-text="'Vista previa: ' + (previewModal.plantilla?.display_name || '')"></h3>
                <button @click="closePreviewModal()" class="text-gray-500 hover:text-gray-700 text-xl">&times;</button>
            </div>
            <div class="p-4 space-y-3">
                <div x-show="previewModal.loading" class="text-center py-8 text-gray-500">
                    <p>Generando vista previa...</p>
                </div>
                <template x-if="!previewModal.loading && previewModal.result">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Asunto</p>
                        <p class="text-sm text-gray-800 mb-3 border rounded px-3 py-2 bg-gray-50" x-text="previewModal.result?.subject"></p>
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Renderizado</p>
                        <div class="border rounded bg-white overflow-hidden">
                            <iframe class="w-full" style="min-height: 400px; border: none;" :srcdoc="previewModal.result?.body"></iframe>
                        </div>
                    </div>
                </template>
            </div>
            <div class="flex justify-end gap-3 p-4 border-t">
                <button @click="closePreviewModal()" class="px-4 py-2 border rounded hover:bg-gray-50 text-sm">Cerrar</button>
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
@endsection

@push('scripts')
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
            showPreview: true,
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
        previewModal: {
            open: false,
            plantilla: null,
            loading: false,
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
                const res = await apiFetch('/admin/correo/config');
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
                
                const res = await apiFetch('/admin/correo/config', {
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
                const res = await apiFetch('/admin/correo/config/test', { method: 'POST' });
                this.testResult = await res.json();
            } catch (e) {
                this.testResult = { success: false, message: 'Error: ' + e.message };
            }
            this.testing = false;
        },
        
        async loadPlantillas() {
            try {
                const res = await apiFetch('/admin/correo/plantillas');
                const data = await res.json();
                this.plantillas = data.data || [];
            } catch (e) {
                console.error('Error loading plantillas:', e);
            }
        },

        async loadLogs() {
            try {
                const res = await apiFetch('/admin/correo/logs');
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
            this.plantillaModal.showPreview = true;
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
                
                const res = await apiFetch(url, {
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
                const res = await apiFetch(`/admin/correo/plantillas/${this.confirmDelete.plantilla.id}`, { method: 'DELETE' });
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

        openPreviewModal(plantilla) {
            this.previewModal.plantilla = plantilla;
            this.previewModal.result = null;
            this.previewModal.loading = true;
            this.previewModal.open = true;
            apiFetch(`/admin/correo/plantillas/${plantilla.id}/preview`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ variables: {} })
            }).then(async res => {
                const data = await res.json();
                if (res.ok) {
                    this.previewModal.result = data.data;
                } else {
                    this.previewModal.result = { subject: 'Error', body: '<p class="p-4 text-red-600">' + (data.message || 'No se pudo generar la vista previa') + '</p>' };
                }
            }).catch(e => {
                this.previewModal.result = { subject: 'Error', body: '<p class="p-4 text-red-600">Error: ' + e.message + '</p>' };
            }).finally(() => {
                this.previewModal.loading = false;
            });
        },

        closePreviewModal() {
            this.previewModal.open = false;
            this.previewModal.plantilla = null;
            this.previewModal.result = null;
        },

        async previewPlantilla() {
            if (!this.testModal.plantilla) return;
            this.testModal.previewLoading = true;
            this.testModal.previewResult = null;
            try {
                const res = await apiFetch(`/admin/correo/plantillas/${this.testModal.plantilla.id}/preview`, {
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
                const res = await apiFetch(`/admin/correo/plantillas/${this.testModal.plantilla.id}/send-test`, {
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
<script src="/js/interactive-tour.js?v=20"></script>
<script>
function startCorreoTour() {
    const alpine = document.querySelector('[x-data]')?._x_dataStack?.[0];

    TcloudTour.start({
        steps: [
            {
                title: 'Configuración de Correo',
                content: 'Desde aquí configuras el servidor SMTP para enviar notificaciones, administras plantillas de correo y revisas el historial de envíos. Todo en tres pestañas.',
                icon: 'fa-envelope',
                color: '#6366f1',
                selector: null,
                position: 'center'
            },
            {
                title: 'Pestañas',
                content: '<strong>Configuración SMTP</strong>: datos del servidor de correo. <strong>Plantillas</strong>: mensajes personalizables. <strong>Log de Correos</strong>: historial de envíos.',
                icon: 'fa-folder-open',
                color: '#3b82f6',
                selector: 'nav.flex',
                position: 'bottom'
            },
            {
                title: 'SMTP',
                content: 'Configura el host, puerto, usuario, contraseña y remitente. Activa SSL/TLS si tu servidor lo requiere. Guarda y prueba la conexión antes de salir.',
                icon: 'fa-server',
                color: '#2563eb',
                selector: '.grid.grid-cols-1.sm\\:grid-cols-2',
                position: 'bottom',
                onShow: function () {
                    if (alpine) alpine.setTab('config');
                }
            },
            {
                title: 'Probar Conexión',
                content: 'Este botón envía un correo de prueba para verificar que la configuración SMTP sea correcta. Si falla, revisa credenciales y firewall.',
                icon: 'fa-vial',
                color: '#16a34a',
                selector: 'button[disabled]:not([disabled]) + button, button.bg-green-600',
                position: 'left',
                onShow: function () {
                    if (alpine) alpine.setTab('config');
                }
            },
            {
                title: 'Plantillas',
                content: 'Lista de plantillas de correo con variables dinámicas (ej: @{{name}}, @{{url}}). Puedes editar, eliminar, previsualizar y enviar una prueba.',
                icon: 'fa-file-alt',
                color: '#4654a8',
                selector: 'table',
                position: 'bottom',
                onShow: function () {
                    if (alpine) alpine.setTab('plantillas');
                }
            },
            {
                title: 'Log de Correos',
                content: 'Historial completo de correos enviados: destinatario, asunto, estado (enviado/fallido) y fecha. Útil para diagnosticar problemas de entrega.',
                icon: 'fa-history',
                color: '#7c3aed',
                selector: 'table',
                position: 'bottom',
                onShow: function () {
                    if (alpine) alpine.setTab('logs');
                }
            }
        ]
    });
}
</script>
@endpush
