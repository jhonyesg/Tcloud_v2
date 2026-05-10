<?php $__env->startSection('content'); ?>
<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold">Canales</h2>
    <?php if(!auth()->user()->isAdmin()): ?>
        <a href="<?php echo e(route('canales.create')); ?>"
           class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
            <i class="fas fa-plus"></i> Crear Canal
        </a>
    <?php endif; ?>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <?php if(auth()->user()->isAdmin()): ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Grabador</th>
                <?php endif; ?>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Slot</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">API ID</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php $__empty_1 = true; $__currentLoopData = $canales; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $canal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <?php if(auth()->user()->isAdmin()): ?>
                    <td class="px-6 py-4"><?php echo e($canal->usuario->name ?? 'N/A'); ?></td>
                    <td class="px-6 py-4"><?php echo e($canal->grabador->nombre); ?></td>
                <?php endif; ?>
                <td class="px-6 py-4 font-medium"><?php echo e($canal->slot_nombre); ?></td>
                <td class="px-6 py-4"><?php echo e($canal->api_canal_id ?? '—'); ?></td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 rounded text-xs <?php echo e($canal->activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'); ?>">
                        <?php echo e($canal->activo ? 'Activo' : 'Inactivo'); ?>

                    </span>
                </td>
                <td class="px-6 py-4 flex gap-2">
                    <a href="<?php echo e(route('canales.edit', $canal)); ?>"
                       class="text-indigo-600 hover:underline">Editar</a>
                    <?php if($canal->activo && $canal->api_canal_id): ?>
                        <form action="<?php echo e(route('canales.ejecutar', $canal)); ?>" method="POST" class="inline">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="text-green-600 hover:underline">
                                Ejecutar
                            </button>
                        </form>
                    <?php endif; ?>
                    <form action="<?php echo e(route('canales.destroy', $canal)); ?>" method="POST"
                          onsubmit="return confirm('¿Eliminar?')" class="inline">
                        <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                        <button type="submit" class="text-red-600 hover:underline">Eliminar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="<?php echo e(auth()->user()->isAdmin() ? '6' : '4'); ?>" class="px-6 py-4 text-center text-gray-500">
                    No hay canales
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('grabaciones_puntuales.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/grabaciones_puntuales/canales/index.blade.php ENDPATH**/ ?>