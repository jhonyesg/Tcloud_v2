<?php $__env->startSection('title', 'Gestionar Storages - Tcloud'); ?>

<?php $__env->startSection('content'); ?>
<div class="p-6" x-data="{
    storages: [],
    showCreateModal: false,
    showEditModal: false,
    showDeleteModal: false,
    editingStorage: null,
    deletingStorage: null,
    testingStorage: null,
    
    toast: { show: false, message: '', success: true },
    showToast() {
        setTimeout(() => { this.toast.show = false; }, 3500);
    },
    
    async loadStorages() {
        const res = await fetch('/admin/storages', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            this.storages = await res.json();
        } else {
            console.error('Error loading storages');
        }
    },
    
    async createStorage(formData) {
        const data = Object.fromEntries(formData);
        data.config = {};
        if (data.type === 's3') {
            data.config = {
                region: data.region || '',
                version: 'latest',
                credentials: {
                    key: data.s3_key || '',
                    secret: data.s3_secret || ''
                },
                bucket: data.bucket || ''
            };
        }
        delete data.region;
        delete data.s3_key;
        delete data.s3_secret;
        delete data.bucket;
        
        const res = await fetch('/admin/storages', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        if (res.ok) {
            this.showCreateModal = false;
            this.loadStorages();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },
    
    async updateStorage(formData, id) {
        const data = Object.fromEntries(formData);
        if (data.type === 'local') {
            data.config = {};
        }
        
        const res = await fetch('/admin/storages/' + id, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        if (res.ok) {
            this.showEditModal = false;
            this.editingStorage = null;
            this.loadStorages();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },
    
    async deleteStorage(id) {
        const res = await fetch('/admin/storages/' + id, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            }
        });
        if (res.ok) {
            this.showDeleteModal = false;
            this.deletingStorage = null;
            this.loadStorages();
        }
    },
    
    async testStorage(storage) {
        this.testingStorage = storage.id;
        
        const res = await fetch('/admin/storages/' + storage.id + '/test', {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        });
        const result = await res.json();
        this.toast = { show: true, message: storage.name + ': ' + result.message, success: result.success };
        this.showToast();
        this.testingStorage = null;
    }
}" x-init="loadStorages()">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Gestionar Storages</h1>
        <button @click="showCreateModal = true" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
            Crear Storage
        </button>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Archivos</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <template x-for="storage in storages" :key="storage.id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="storage.id"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900" x-text="storage.name"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="storage.type === 'local' ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800'"
                                  x-text="storage.type"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="storage.files_count"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="storage.enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  x-text="storage.enabled ? 'Activo' : 'Inactivo'"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a :href="'/admin/storages/' + storage.id + '/users'" class="text-green-600 hover:text-green-900 mr-3">Usuarios</a>
                            <button @click="testStorage(storage)" class="text-green-600 hover:text-green-900 mr-3">Probar</button>
                            <button @click="editingStorage = storage; showEditModal = true" class="text-indigo-600 hover:text-indigo-900 mr-3">Editar</button>
                            <button @click="deletingStorage = storage; showDeleteModal = true" class="text-red-600 hover:text-red-900">Eliminar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="storages.length === 0" class="text-center py-8 text-gray-500">
            No hay storages configurados.
        </div>
    </div>

    <div x-cloak x-show="showCreateModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showCreateModal = false">
            <h2 class="text-xl font-bold mb-4">Crear Storage</h2>
            <form @submit.prevent="createStorage(new FormData($event.target))">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Nombre</label>
                    <input type="text" name="name" required class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Tipo</label>
                    <select name="type" required class="w-full border p-2 rounded" onchange="document.getElementById('s3_config').style.display = this.value === 's3' ? 'block' : 'none'">
                        <option value="local">Local</option>
                        <option value="s3">S3</option>
                    </select>
                </div>
                <div class="mb-4" id="s3_config" style="display: none;">
                    <label class="block text-sm font-medium mb-1">S3 Region</label>
                    <input type="text" name="region" placeholder="us-east-1" class="w-full border p-2 rounded mb-2">
                    <label class="block text-sm font-medium mb-1">S3 Key</label>
                    <input type="text" name="s3_key" placeholder="AKIA..." class="w-full border p-2 rounded mb-2">
                    <label class="block text-sm font-medium mb-1">S3 Secret</label>
                    <input type="password" name="s3_secret" class="w-full border p-2 rounded mb-2">
                    <label class="block text-sm font-medium mb-1">Bucket</label>
                    <input type="text" name="bucket" class="w-full border p-2 rounded">
                </div>
                <div class="mb-4" id="local_config">
                    <label class="block text-sm font-medium mb-1">Base Path</label>
                    <input type="text" name="base_path" placeholder="/data/storage" class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="enabled" value="1" checked class="mr-2">
                        Habilitado
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Crear</button>
                    <button type="button" @click="showCreateModal = false" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showEditModal = false">
            <h2 class="text-xl font-bold mb-4">Editar Storage</h2>
            <template x-if="editingStorage">
                <form @submit.prevent="updateStorage(new FormData($event.target), editingStorage.id)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Nombre</label>
                        <input type="text" name="name" :value="editingStorage.name" required class="w-full border p-2 rounded">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Tipo</label>
                        <span x-text="editingStorage.type" class="text-gray-600"></span>
                    </div>
                    <template x-if="editingStorage.type === 'local'">
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Base Path</label>
                            <input type="text" name="base_path" :value="editingStorage.base_path" class="w-full border p-2 rounded">
                        </div>
                    </template>
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="enabled" value="1" :checked="editingStorage.enabled" class="mr-2">
                            Habilitado
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>
                        <button type="button" @click="showEditModal = false; editingStorage = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    <div x-cloak x-show="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showDeleteModal = false">
            <h2 class="text-xl font-bold mb-4">Eliminar Storage</h2>
            <template x-if="deletingStorage">
                <div>
                    <p class="mb-4">¿Estás seguro de eliminar el storage <strong x-text="deletingStorage.name"></strong>?</p>
                    <p class="text-red-600 text-sm mb-4">Los archivos no serán eliminados, pero quedarán huérfanos.</p>
                    <div class="flex gap-2">
                        <button @click="deleteStorage(deletingStorage.id)" class="bg-red-600 text-white px-4 py-2 rounded">Eliminar</button>
                        <button @click="showDeleteModal = false; deletingStorage = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
         x-transition:leave="transition ease-in duration-200"
         class="fixed bottom-4 right-4 z-50 max-w-sm"
         :class="toast.success ? 'bg-green-500' : 'bg-red-500'">
        <div class="flex items-center px-4 py-3 text-white">
            <svg x-show="toast.success" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <svg x-show="!toast.success" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            <span x-text="toast.message"></span>
            <button @click="toast.show = false" class="ml-4 text-white hover:text-gray-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/storages.blade.php ENDPATH**/ ?>