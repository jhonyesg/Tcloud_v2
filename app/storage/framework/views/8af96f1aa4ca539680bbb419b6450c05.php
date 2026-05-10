<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', 'Tcloud'); ?></title>
    <link rel="icon" type="image/png" href="/logo.png">
    <script src="/js/tailwind.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#e6e9f4',
                            100: '#c4cbe4',
                            200: '#9faad1',
                            300: '#7a89be',
                            400: '#5b6db3',
                            500: '#4654a8',
                            600: '#3d4899',
                            700: '#343c88',
                            800: '#2b3077',
                            900: '#1a1f4d',
                        }
                    }
                }
            }
        }
    </script>
    <script defer src="/js/alpine.min.js"></script>
    <link rel="stylesheet" href="/css/fontawesome.min.css">
    <style>
        [x-cloak] { display: none !important; }
        .glass-card {
            background: rgba(8, 29, 74, 0.9);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1.5rem;
            padding: 1.75rem;
            box-shadow: 
                0 0 60px rgba(45, 90, 160, 0.25),
                0 0 100px rgba(45, 90, 160, 0.15),
                inset 0 0 80px rgba(255, 255, 255, 0.02);
        }
    </style>
    <?php echo $__env->yieldPushContent('styles'); ?>
</head>
<body class="bg-slate-100">
    <?php if(session('user_id')): ?>
    <div x-data="{ sidebarOpen: true, userMenuOpen: false, showProfileModal: false, showSettingsModal: false }" class="min-h-screen flex flex-col">

        <!-- Top Navigation Bar -->
        <header class="bg-[#03153C] border-b border-[#0A1F4D] shadow-sm sticky top-0 z-40">
            <div class="flex items-center justify-between px-4 h-14">
                <!-- Left side -->
                <div class="flex items-center gap-4">
                    <button @click="sidebarOpen = !sidebarOpen" class="p-2 hover:bg-[#0A1F4D] rounded-lg transition-colors">
                        <i class="fas fa-bars text-white"></i>
                    </button>
                    <a href="/dashboard" class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg overflow-hidden">
                            <img src="/logo.png" alt="Logo" class="w-full h-full object-contain">
                        </div>
                        <span class="font-bold text-lg text-white hidden sm:block drop-shadow-md">Tcloud</span>
                    </a>
                </div>

                <!-- Right side -->
                <div class="flex items-center gap-2">
                    <button class="p-2 hover:bg-[#0A1F4D] rounded-lg transition-colors relative">
                        <i class="fas fa-bell text-white"></i>
                        <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                    </button>

                    <div class="relative">
                        <button @click="userMenuOpen = !userMenuOpen"
                                class="flex items-center gap-2 p-2 hover:bg-[#0A1F4D] rounded-lg transition-colors">
                            <div class="w-8 h-8 bg-brand-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-white text-sm"></i>
                            </div>
                            <span class="hidden sm:block text-sm font-medium text-white"><?php echo e(session('user_username', session('user_email', 'Usuario'))); ?></span>
                            <i class="fas fa-chevron-down text-brand-300 text-xs"></i>
                        </button>

                        <div x-cloak x-show="userMenuOpen"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             @click.outside="userMenuOpen = false"
                             class="absolute right-0 mt-2 w-56 bg-[#0A1F4D] rounded-xl shadow-lg border border-brand-700 py-2 z-50">
                            <div class="px-4 py-2 border-b border-brand-700">
                                <p class="font-medium text-white text-sm"><?php echo e(session('user_username', session('user_email', 'Usuario'))); ?></p>
                                <p class="text-xs text-brand-300 font-medium mt-0.5"><?php echo e(session('user_role') === 'admin' ? 'Administrador' : 'Usuario'); ?></p>
                            </div>
                            <a href="#" @click.prevent="showSettingsModal = true; userMenuOpen = false" class="flex items-center gap-3 px-4 py-2 hover:bg-brand-700 text-brand-100 transition-colors">
                                <i class="fas fa-cog text-brand-300 w-5"></i>
                                <span class="text-sm">Configuración</span>
                            </a>
                            <a href="#" @click.prevent="showProfileModal = true; userMenuOpen = false" class="flex items-center gap-3 px-4 py-2 hover:bg-brand-700 text-brand-100 transition-colors">
                                <i class="fas fa-user-circle text-brand-300 w-5"></i>
                                <span class="text-sm">Mi Perfil</span>
                            </a>
                            <div class="border-t border-brand-700 mt-2 pt-2">
                                <form action="/logout" method="POST">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit"
                                            class="flex items-center gap-3 px-4 py-2 hover:bg-red-900 text-red-300 w-full text-left transition-colors">
                                        <i class="fas fa-sign-out-alt text-red-400 w-5"></i>
                                        <span class="text-sm">Cerrar Sesión</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex flex-1 overflow-hidden">
            <!-- Sidebar -->
            <aside :class="sidebarOpen ? 'w-64' : 'w-20'"
                   class="bg-brand-900 transition-all duration-300 flex flex-col overflow-hidden flex-shrink-0">

                <!-- Sidebar Header -->
                <div class="p-4 border-b border-brand-800">
                    <div x-show="sidebarOpen" x-transition>
                        <div class="flex items-center gap-2">
                            <div class="w-10 h-10 bg-brand-800 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-folder-open text-brand-300"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-white text-sm">Archivos</p>
                                <p class="text-xs text-brand-300">Gestión de archivos</p>
                            </div>
                        </div>
                    </div>
                    <div x-show="!sidebarOpen" x-transition class="flex justify-center">
                        <div class="w-10 h-10 bg-brand-800 rounded-xl flex items-center justify-center">
                            <i class="fas fa-folder-open text-brand-300"></i>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 py-4 overflow-y-auto">
                    <div class="px-3 mb-2" x-show="sidebarOpen">
                        <span class="text-xs font-semibold text-brand-400 uppercase tracking-wider">Navegación</span>
                    </div>

                    <a href="/dashboard" data-nav-path="/dashboard"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-home w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Dashboard</span>
                    </a>

                    <a href="/files" data-nav-path="/files"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-folder w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Mis Archivos</span>
                    </a>

                    <a href="/shares" data-nav-path="/shares"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-link w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Compartidos</span>
                    </a>

                    <?php if(session('user_role') === 'admin'): ?>
                    <div class="px-3 mt-6 mb-2" x-show="sidebarOpen">
                        <span class="text-xs font-semibold text-brand-400 uppercase tracking-wider">Administración</span>
                    </div>
                    <div x-show="!sidebarOpen" class="mx-2 my-3 border-t border-brand-800"></div>

                    <a href="/admin/users" data-nav-path="/admin/users"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-users w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Usuarios</span>
                    </a>

                    <a href="/admin/storages" data-nav-path="/admin/storages"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-database w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Storages</span>
                    </a>

                    <a href="/admin/postgres" data-nav-path="/admin/postgres"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-server w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">PostgreSQL</span>
                    </a>

                    <a href="/admin/media-editor" data-nav-path="/admin/media-editor"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-cut w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Editor de Medios</span>
                    </a>

                    <a href="/grabaciones-puntuales/grabadores" data-nav-path="/grabaciones-puntuales/grabadores"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-satellite-dish w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Grabadores</span>
                    </a>

                    <a href="/correo" data-nav-path="/correo"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-envelope w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Correo</span>
                    </a>

                    <?php endif; ?>

                    <!-- Medios Puntuales - Visible para todos los usuarios autenticados -->
                    <div class="px-3 mt-6 mb-2" x-show="sidebarOpen">
                        <span class="text-xs font-semibold text-brand-400 uppercase tracking-wider">Multimedia</span>
                    </div>
                    <div x-show="!sidebarOpen" class="mx-2 my-3 border-t border-brand-800"></div>

                    <a href="/grabaciones-puntuales/canales" data-nav-path="/grabaciones-puntuales"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-broadcast-tower w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Medios Puntuales</span>
                    </a>

                </nav>

                <!-- Sidebar Footer -->
                <div class="p-4 border-t border-brand-800">
                    <div x-show="sidebarOpen" x-transition class="bg-brand-800 rounded-lg p-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs text-white/70 font-medium">Almacenamiento</span>
                            <span class="text-xs font-medium text-brand-100"><?php echo e($sidebarQuota['used_label']); ?> / <?php echo e($sidebarQuota['limit_label']); ?></span>
                        </div>
                        <?php if(!$sidebarQuota['is_unlimited']): ?>
                        <div class="w-full bg-brand-700 rounded-full h-1.5">
                            <div class="<?php echo e($sidebarQuota['color_class']); ?> h-1.5 rounded-full" style="width: <?php echo e($sidebarQuota['percentage']); ?>%"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div x-show="!sidebarOpen" x-transition class="flex justify-center">
                        <div class="w-10 h-10 bg-brand-800 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-pie text-brand-300"></i>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 overflow-auto bg-slate-50">
                <?php echo $__env->yieldContent('content'); ?>
            </main>
        </div>

        <!-- Profile Modal -->
        <div x-cloak x-show="showProfileModal"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="transform opacity-0"
             x-transition:enter-end="transform opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="transform opacity-100"
             x-transition:leave-end="transform opacity-0"
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background: rgba(3, 21, 60, 0.85); backdrop-filter: blur(8px);"
             @click.outside="showProfileModal = false"
             @keydown.escape.window="showProfileModal = false">
            <div x-show="showProfileModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="transform scale-95 opacity-0"
                 x-transition:enter-end="transform scale-100 opacity-100"
                 class="glass-card w-full max-w-md"
                 @click.stop>
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-white">Mi Perfil</h2>
                    <button @click="showProfileModal = false" class="p-2 hover:bg-brand-700 rounded-lg transition-colors">
                        <i class="fas fa-times text-brand-300"></i>
                    </button>
                </div>
                
                <div class="text-center mb-6">
                    <div class="w-20 h-20 bg-gradient-to-br from-brand-500 to-brand-400 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                        <i class="fas fa-user text-white text-2xl"></i>
                    </div>
                    <h3 class="text-white font-semibold"><?php echo e(session('user_username', session('user_email', 'Usuario'))); ?></h3>
                    <span class="inline-flex items-center gap-1.5 mt-2 px-3 py-1 rounded-full text-xs font-medium <?php echo e(session('user_role') === 'admin' ? 'bg-brand-500/30 border border-brand-500/50 text-brand-300' : 'bg-brand-600/30 border border-brand-600/50 text-brand-200'); ?>">
                        <i class="fas <?php echo e(session('user_role') === 'admin' ? 'fa-shield-alt' : 'fa-user'); ?>"></i>
                        <?php echo e(session('user_role') === 'admin' ? 'Administrador' : 'Usuario'); ?>

                    </span>
                </div>

                <div class="space-y-4">
                    <div class="bg-brand-900/40 rounded-xl p-4 border border-brand-700/30">
                        <p class="text-brand-300 text-xs uppercase tracking-wider mb-1">Correo Electrónico</p>
                        <p class="text-white font-medium"><?php echo e(session('user_email', 'Usuario')); ?></p>
                    </div>
                    
                    <div class="bg-brand-900/40 rounded-xl p-4 border border-brand-700/30">
                        <p class="text-brand-300 text-xs uppercase tracking-wider mb-2">Almacenamiento</p>
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-brand-200">Usado</span>
                            <span class="text-white font-medium">2.5 GB / 10 GB</span>
                        </div>
                        <div class="w-full bg-brand-800 rounded-full h-2 border border-brand-700/30">
                            <div class="bg-gradient-to-r from-brand-500 to-brand-400 h-2 rounded-full" style="width: 25%"></div>
                        </div>
                    </div>
                </div>

                <button @click="showProfileModal = false; showSettingsModal = true" class="w-full mt-6 py-3 px-4 bg-brand-700 hover:bg-brand-600 text-white rounded-xl font-medium transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-key"></i>
                    Cambiar Contraseña
                </button>
            </div>
        </div>

        <!-- Settings Modal -->
        <div x-cloak x-show="showSettingsModal"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="transform opacity-0"
             x-transition:enter-end="transform opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="transform opacity-100"
             x-transition:leave-end="transform opacity-0"
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background: rgba(3, 21, 60, 0.85); backdrop-filter: blur(8px);"
             @click.outside="showSettingsModal = false"
             @keydown.escape.window="showSettingsModal = false">
            <div x-show="showSettingsModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="transform scale-95 opacity-0"
                 x-transition:enter-end="transform scale-100 opacity-100"
                 class="glass-card w-full max-w-md"
                 @click.stop>
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-white">Configuración</h2>
                    <button @click="showSettingsModal = false" class="p-2 hover:bg-brand-700 rounded-lg transition-colors">
                        <i class="fas fa-times text-brand-300"></i>
                    </button>
                </div>

                <div x-data="{ loading: false, error: '', success: '', current_password: '', new_password: '', new_password_confirmation: '' }">
                    <div x-show="error" class="bg-red-900/30 border border-red-700/50 text-red-300 p-3 rounded-xl text-sm mb-4" x-text="error"></div>
                    <div x-show="success" class="bg-green-900/30 border border-green-700/50 text-green-300 p-3 rounded-xl text-sm mb-4" x-text="success"></div>

                    <form @submit.prevent="loading = true; error = ''; success = ''">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="_method" value="PUT">
                        
                        <div class="mb-4">
                            <label class="block text-brand-300 text-xs font-medium mb-2 uppercase tracking-wider">Contraseña Actual</label>
                            <input type="password" name="current_password" x-model="current_password" required
                                   class="w-full bg-brand-900/50 border border-brand-700/50 text-white rounded-xl px-4 py-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition-all placeholder:text-brand-400/50"
                                   placeholder="Ingrese su contraseña actual">
                        </div>

                        <div class="mb-4">
                            <label class="block text-brand-300 text-xs font-medium mb-2 uppercase tracking-wider">Nueva Contraseña</label>
                            <input type="password" name="new_password" x-model="new_password" required minlength="8"
                                   class="w-full bg-brand-900/50 border border-brand-700/50 text-white rounded-xl px-4 py-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition-all placeholder:text-brand-400/50"
                                   placeholder="Mínimo 8 caracteres">
                        </div>

                        <div class="mb-6">
                            <label class="block text-brand-300 text-xs font-medium mb-2 uppercase tracking-wider">Confirmar Nueva Contraseña</label>
                            <input type="password" name="new_password_confirmation" x-model="new_password_confirmation" required
                                   class="w-full bg-brand-900/50 border border-brand-700/50 text-white rounded-xl px-4 py-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition-all placeholder:text-brand-400/50"
                                   placeholder="Repita la nueva contraseña">
                        </div>

                        <div class="flex gap-3">
                            <button type="button" @click="showSettingsModal = false" class="flex-1 py-3 px-4 bg-brand-800 hover:bg-brand-700 text-brand-200 rounded-xl font-medium transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="flex-1 py-3 px-4 bg-gradient-to-r from-brand-500 to-brand-400 hover:from-brand-400 hover:to-brand-500 text-white rounded-xl font-semibold transition-all shadow-lg hover:shadow-xl disabled:opacity-50" :disabled="loading">
                                <span x-show="!loading">Actualizar</span>
                                <span x-show="loading"><i class="fas fa-spinner fa-spin mr-2"></i>Guardando...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>
    <?php echo $__env->yieldContent('content'); ?>
    <?php endif; ?>

    <?php echo $__env->yieldPushContent('scripts'); ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var path = window.location.pathname;
        document.querySelectorAll('[data-nav-path]').forEach(function (link) {
            var navPath = link.getAttribute('data-nav-path');
            var isActive = path === navPath || (navPath !== '/dashboard' && path.startsWith(navPath));
            if (isActive) {
                link.classList.add('bg-brand-700', 'text-white');
                link.classList.remove('text-brand-200');
                var icon = link.querySelector('.nav-icon');
                if (icon) { icon.classList.add('text-white'); icon.classList.remove('text-brand-300'); }
            } else {
                link.classList.remove('bg-brand-700', 'text-white');
                link.classList.add('text-brand-200');
                var icon = link.querySelector('.nav-icon');
                if (icon) { icon.classList.remove('text-white'); icon.classList.add('text-brand-300'); }
            }
        });
    });
    </script>
</body>
</html>
<?php /**PATH /var/www/html/resources/views/layouts/app.blade.php ENDPATH**/ ?>