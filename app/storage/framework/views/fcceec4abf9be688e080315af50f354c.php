<?php $__env->startSection('title', 'Dashboard - Tcloud'); ?>

<?php $__env->startSection('content'); ?>
<?php
$quotaUsedMB  = $quota['used'] / 1024 / 1024;
$quotaLimitMB = $quota['limit'] / 1024 / 1024;
$percentage   = $quota['limit'] > 0 ? min(100, ($quota['used'] / $quota['limit']) * 100) : 0;
?>
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">Bienvenido, <?php echo e($user->email); ?></h1>
        <p class="text-slate-500 mt-0.5">Gestiona tus archivos y carpetas</p>
    </div>

    <!-- Quick-access cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <a href="/files" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-brand-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-folder text-brand-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Mis Archivos</h3>
                    <p class="text-xs text-slate-500"><?php echo e($recentFiles->count()); ?> archivos recientes</p>
                </div>
            </div>
        </a>

        <a href="/shares" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-brand-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-link text-brand-500 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Compartidos</h3>
                    <p class="text-xs text-slate-500"><?php echo e($user->shares->count()); ?> enlaces activos</p>
                </div>
            </div>
        </a>

        <a href="/profile" class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-green-200 transition-all">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-user-cog text-green-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Mi Perfil</h3>
                    <p class="text-xs text-slate-500">Configuración</p>
                </div>
            </div>
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Storage quota card -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Mi Cuota de Almacenamiento</h3>
            <div class="mb-4">
                <div class="flex justify-between text-sm mb-2">
                    <span class="text-slate-600">Espacio Utilizado</span>
                    <span class="font-medium text-slate-800">
                        <?php echo e(number_format($quotaUsedMB, 2)); ?> MB
                        <?php if($quota['limit'] > 0): ?> / <?php echo e(number_format($quotaLimitMB, 2)); ?> MB
                        <?php else: ?> / Ilimitado <?php endif; ?>
                    </span>
                </div>
                <?php if($quota['limit'] > 0): ?>
                <div class="w-full bg-slate-100 rounded-full h-3">
                    <div class="bg-brand-600 h-3 rounded-full transition-all" style="width: <?php echo e($percentage); ?>%"></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-2 gap-4 mt-6">
                <a href="/files" class="flex items-center gap-3 p-4 bg-brand-50 hover:bg-brand-100 rounded-lg transition-colors">
                    <div class="w-10 h-10 bg-brand-700 rounded-lg flex items-center justify-center">
                        <i class="fas fa-cloud-upload-alt text-white"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800">Subir Archivos</p>
                        <p class="text-xs text-slate-500">Arrastra o selecciona</p>
                    </div>
                </a>
                <a href="/shares" class="flex items-center gap-3 p-4 bg-brand-50 hover:bg-brand-100 rounded-lg transition-colors">
                    <div class="w-10 h-10 bg-brand-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-share-alt text-white"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800">Crear Enlace</p>
                        <p class="text-xs text-slate-500">Compartir archivo</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- Assigned storages -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Storages Asignados</h3>
            <?php if($storages->isNotEmpty()): ?>
                <div class="space-y-3">
                    <?php $__currentLoopData = $storages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $storage): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <a href="/files?storage_id=<?php echo e($storage->storage_provider_id); ?>"
                           class="block p-3 bg-slate-50 hover:bg-brand-50 rounded-lg transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-brand-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hdd text-brand-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-slate-800 text-sm truncate"><?php echo e($storage->storageProvider->name); ?></p>
                                    <span class="inline-block px-2 py-0.5 rounded text-xs mt-1
                                        <?php if($storage->permissions === 'full'): ?> bg-green-100 text-green-700
                                        <?php elseif($storage->permissions === 'write'): ?> bg-brand-100 text-brand-700
                                        <?php elseif($storage->permissions === 'upload'): ?> bg-amber-100 text-amber-700
                                        <?php else: ?> bg-slate-100 text-slate-600 <?php endif; ?>">
                                        <?php echo e(ucfirst($storage->permissions)); ?>

                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="w-12 h-12 bg-brand-50 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-folder-open text-brand-300"></i>
                    </div>
                    <p class="text-slate-500 text-sm">No hay storages asignados</p>
                    <p class="text-slate-400 text-xs mt-1">Contacta al administrador</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent files -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Archivos Recientes</h3>
        <?php if($recentFiles->isNotEmpty()): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-slate-200">
                            <th class="pb-3 font-medium text-slate-500">Nombre</th>
                            <th class="pb-3 font-medium text-slate-500">Tamaño</th>
                            <th class="pb-3 font-medium text-slate-500">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $recentFiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $file): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="py-3">
                                <div class="flex items-center gap-2">
                                    <?php if($file->is_folder): ?>
                                        <i class="fas fa-folder text-amber-500"></i>
                                    <?php else: ?>
                                        <i class="fas fa-file text-brand-400"></i>
                                    <?php endif; ?>
                                    <span class="text-slate-800"><?php echo e($file->name); ?></span>
                                </div>
                            </td>
                            <td class="py-3 text-slate-500"><?php echo e(number_format($file->size / 1024, 2)); ?> KB</td>
                            <td class="py-3 text-slate-500"><?php echo e($file->created_at->format('d/m/Y H:i')); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-8">
                <div class="w-12 h-12 bg-brand-50 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-folder-open text-brand-300"></i>
                </div>
                <p class="text-slate-500 text-sm">No tienes archivos recientes</p>
                <a href="/files" class="inline-block mt-3 text-brand-600 hover:text-brand-700 text-sm font-medium">
                    Ir a Mis Archivos →
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/dashboard/user.blade.php ENDPATH**/ ?>