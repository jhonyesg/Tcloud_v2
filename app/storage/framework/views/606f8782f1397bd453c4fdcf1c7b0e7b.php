<?php $__env->startSection('title', 'Gestionar Usuarios - Tcloud'); ?>

<?php $__env->startSection('content'); ?>
<div class="p-6" x-data="{
    users: [],
    showCreateModal: false,
    showEditModal: false,
    showDeleteModal: false,
    editingUser: null,
    deletingUser: null,
    
    async loadUsers() {
        const res = await fetch('/admin/users', {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (res.ok) {
            const data = await res.json();
            const userList = data.data || data;
            this.users = Array.isArray(userList) ? userList.filter(u => u && u.id) : [];
        }
    },
    
    async createUser(formData) {
        const res = await fetch('/admin/users', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(Object.fromEntries(formData))
        });
        if (res.ok) {
            this.showCreateModal = false;
            this.loadUsers();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },
    
    async updateUser(formData, id) {
        const res = await fetch('/admin/users/' + id, {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(Object.fromEntries(formData))
        });
        if (res.ok) {
            this.showEditModal = false;
            this.editingUser = null;
            this.loadUsers();
        } else {
            const err = await res.json();
            alert('Error: ' + JSON.stringify(err));
        }
    },
    
    async deleteUser(id) {
        const res = await fetch('/admin/users/' + id, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            }
        });
        if (res.ok) {
            this.showDeleteModal = false;
            this.deletingUser = null;
            this.loadUsers();
        }
    },
    
    formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
}" x-init="loadUsers()">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Gestionar Usuarios</h1>
        <button @click="showCreateModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            Crear Usuario
        </button>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quota</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <template x-for="user in (users || [])" :key="user.id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="user.id"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="user.email"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="user.username || '—'"></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="user.role === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800'"
                                  x-text="user.role"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" 
                            x-text="user.personal_quota_bytes === 0 ? 'Ilimitado' : formatBytes(user.personal_quota_bytes)"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" 
                            x-text="formatBytes(user.personal_used_bytes)"></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a :href="'/admin/users/' + user.id + '/storages'" class="text-green-600 hover:text-green-900 mr-3">Storages</a>
                            <button @click="editingUser = user; showEditModal = true" class="text-indigo-600 hover:text-indigo-900 mr-3">Editar</button>
                            <button @click="deletingUser = user; showDeleteModal = true" class="text-red-600 hover:text-red-900">Eliminar</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="users.length === 0" class="text-center py-8 text-gray-500">
            No hay usuarios registrados.
        </div>
    </div>

    <div x-cloak x-show="showCreateModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showCreateModal = false">
            <h2 class="text-xl font-bold mb-4">Crear Usuario</h2>
            <form @submit.prevent="createUser(new FormData($event.target))">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" required class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Username <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input type="text" name="username" class="w-full border p-2 rounded" placeholder="ej. jsuarez">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Contraseña</label>
                    <input type="password" name="password" required class="w-full border p-2 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Rol</label>
                    <select name="role" required class="w-full border p-2 rounded">
                        <option value="user">Usuario</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Quota (bytes, 0 = ilimitado)</label>
                    <input type="number" name="personal_quota_bytes" value="0" min="0" class="w-full border p-2 rounded">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Crear</button>
                    <button type="button" @click="showCreateModal = false" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div x-cloak x-show="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showEditModal = false">
            <h2 class="text-xl font-bold mb-4">Editar Usuario</h2>
            <template x-if="editingUser">
                <form @submit.prevent="updateUser(new FormData($event.target), editingUser.id)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Email</label>
                        <input type="email" name="email" :value="editingUser.email" required class="w-full border p-2 rounded">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Username <span class="text-gray-400 font-normal">(opcional)</span></label>
                        <input type="text" name="username" :value="editingUser.username || ''" class="w-full border p-2 rounded" placeholder="ej. jsuarez">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Nueva Contraseña <span class="text-gray-400 font-normal">(dejar vacío para no cambiar)</span></label>
                        <input type="password" name="password" class="w-full border p-2 rounded">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Rol</label>
                        <select name="role" class="w-full border p-2 rounded"
                                x-init="$el.value = editingUser.role">
                            <option value="user">Usuario</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Quota (bytes, 0 = ilimitado)</label>
                        <input type="number" name="personal_quota_bytes" :value="editingUser.personal_quota_bytes" min="0" class="w-full border p-2 rounded">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>
                        <button type="button" @click="showEditModal = false; editingUser = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    <div x-cloak x-show="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150">
        <div class="bg-white rounded-lg p-6 w-full max-w-md" @click.away="showDeleteModal = false">
            <h2 class="text-xl font-bold mb-4">Eliminar Usuario</h2>
            <template x-if="deletingUser">
                <div>
                    <p class="mb-4">¿Estás seguro de eliminar al usuario <strong x-text="deletingUser.email"></strong>?</p>
                    <p class="text-red-600 text-sm mb-4">Esta acción eliminará todos sus archivos, storages asignados y shares.</p>
                    <div class="flex gap-2">
                        <button @click="deleteUser(deletingUser.id)" class="bg-red-600 text-white px-4 py-2 rounded">Eliminar</button>
                        <button @click="showDeleteModal = false; deletingUser = null" class="bg-gray-300 px-4 py-2 rounded">Cancelar</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/users.blade.php ENDPATH**/ ?>