<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tcloud')</title>
    @yield('og_meta')
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
        .hover\:bg-white\/8:hover { background-color: rgba(255,255,255,0.08); }
        .bg-white\/8 { background-color: rgba(255,255,255,0.08); }
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
    @stack('styles')
</head>
<body class="bg-slate-100">
    @if(session('user_id'))
    <div x-data="{
        sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false',
        isMobile: window.innerWidth < 1024,
        userMenuOpen: false,
        showProfileModal: false,
        showSettingsModal: false,
        init() {
            window.addEventListener('resize', () => {
                this.isMobile = window.innerWidth < 1024;
                if (!this.isMobile && !this.sidebarOpen) {
                    this.sidebarOpen = true;
                    localStorage.setItem('sidebarOpen', 'true');
                }
            });
        },
        toggleSidebar() {
            if (this.isMobile) {
                this.sidebarOpen = !this.sidebarOpen;
            } else {
                this.sidebarOpen = !this.sidebarOpen;
                localStorage.setItem('sidebarOpen', this.sidebarOpen);
            }
        }
    }" class="h-screen flex flex-col overflow-hidden">

        <!-- Top Navigation Bar -->
        <header class="bg-[#03153C] border-b border-[#0A1F4D] shadow-lg z-40 flex-shrink-0">
            <div class="flex items-center h-14 px-2 sm:px-4 gap-1 sm:gap-3">

                <!-- Hamburger -->
                <button @click="toggleSidebar()"
                        class="flex items-center justify-center w-10 h-10 rounded-xl hover:bg-white/10 active:bg-white/20 transition-colors flex-shrink-0">
                    <i :class="sidebarOpen && isMobile ? 'fas fa-times' : 'fas fa-bars'" class="text-white text-base"></i>
                </button>

                <!-- Brand -->
                <a href="/dashboard" class="flex items-center gap-2 flex-shrink-0">
                    <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg overflow-hidden">
                        <img src="/logo.png" alt="Logo" class="w-full h-full object-contain">
                    </div>
                    <span class="font-bold text-base sm:text-lg text-white tracking-tight">Tcloud</span>
                </a>

                <!-- Spacer -->
                <div class="flex-1"></div>

                <!-- User menu -->
                <div class="relative">
                    <button @click="userMenuOpen = !userMenuOpen"
                            class="flex items-center gap-2 pl-2 pr-2.5 py-1.5 rounded-xl hover:bg-white/10 active:bg-white/20 transition-colors">
                        <!-- Avatar -->
                        <div class="w-8 h-8 bg-gradient-to-br from-brand-400 to-brand-600 rounded-full flex items-center justify-center flex-shrink-0 shadow-inner">
                            <span class="text-white text-xs font-bold leading-none">
                                {{ strtoupper(substr(session('user_username', session('user_email', 'U')), 0, 2)) }}
                            </span>
                        </div>
                        <!-- Name (hidden on small mobile) -->
                        <span class="hidden sm:block text-sm font-medium text-white max-w-[120px] truncate">{{ session('user_username', session('user_email', 'Usuario')) }}</span>
                        <i class="fas fa-chevron-down text-white/50 text-[10px] transition-transform duration-200" :class="userMenuOpen ? 'rotate-180' : ''"></i>
                    </button>

                    <!-- Dropdown -->
                    <div x-cloak x-show="userMenuOpen"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="transform opacity-0 scale-95 -translate-y-1"
                         x-transition:enter-end="transform opacity-100 scale-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="transform opacity-100 scale-100"
                         x-transition:leave-end="transform opacity-0 scale-95"
                         @click.outside="userMenuOpen = false"
                         class="absolute right-0 mt-2 w-56 bg-[#0d2259] rounded-2xl shadow-2xl border border-white/10 py-1.5 z-50"
                         style="box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.06);">

                        <!-- User info header -->
                        <div class="px-4 py-3 border-b border-white/10">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-gradient-to-br from-brand-400 to-brand-600 rounded-full flex items-center justify-center flex-shrink-0">
                                    <span class="text-white text-sm font-bold">{{ strtoupper(substr(session('user_username', session('user_email', 'U')), 0, 2)) }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-semibold text-white text-sm truncate">{{ session('user_username', session('user_email', 'Usuario')) }}</p>
                                    <p class="text-xs font-medium mt-0.5 {{ session('user_role') === 'admin' ? 'text-amber-400' : 'text-brand-300' }}">
                                        {{ session('user_role') === 'admin' ? '⭐ Administrador' : 'Usuario' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Menu items -->
                        <div class="py-1">
                            <a href="#" @click.prevent="showSettingsModal = true; userMenuOpen = false"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-white/8 text-white/80 hover:text-white transition-colors">
                                <div class="w-7 h-7 rounded-lg bg-white/8 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-lock text-brand-300 text-xs"></i>
                                </div>
                                <span class="text-sm">Cambiar contraseña</span>
                            </a>
                            <a href="#" @click.prevent="showProfileModal = true; userMenuOpen = false"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-white/8 text-white/80 hover:text-white transition-colors">
                                <div class="w-7 h-7 rounded-lg bg-white/8 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-user-circle text-brand-300 text-xs"></i>
                                </div>
                                <span class="text-sm">Mi Perfil</span>
                            </a>
                        </div>

                        <!-- Logout -->
                        <div class="border-t border-white/10 pt-1 pb-0.5">
                            <form action="/logout" method="POST">
                                @csrf
                                <button type="submit"
                                        class="flex items-center gap-3 px-4 py-2.5 hover:bg-red-500/15 text-red-400 hover:text-red-300 w-full text-left transition-colors rounded-b-2xl">
                                    <div class="w-7 h-7 rounded-lg bg-red-500/10 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-sign-out-alt text-red-400 text-xs"></i>
                                    </div>
                                    <span class="text-sm font-medium">Cerrar Sesión</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </header>

        <div class="flex flex-1 overflow-hidden">
            <!-- Mobile sidebar backdrop -->
            <div x-cloak x-show="sidebarOpen && isMobile"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/60 z-20"></div>

            <!-- Sidebar -->
            <aside :class="isMobile
                    ? (sidebarOpen ? 'fixed top-14 left-0 bottom-0 z-30 w-72 translate-x-0' : 'fixed top-14 left-0 bottom-0 z-30 w-72 -translate-x-full')
                    : (sidebarOpen ? 'w-64' : 'w-20')"
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

                    @if(session('user_role') === 'admin')
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

                    <a href="/admin/sessions" data-nav-path="/admin/sessions"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-shield-alt w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Sesiones</span>
                    </a>

                    <a href="/admin/redis" data-nav-path="/admin/redis"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-memory w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Redis</span>
                    </a>

                    <a href="/admin/external-sites" data-nav-path="/admin/external-sites"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-globe w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Sites Externos</span>
                    </a>

                    @endif

                    <!-- Medios Puntuales - Visible para todos los usuarios autenticados -->
                    <div class="px-3 mt-6 mb-2" x-show="sidebarOpen">
                        <span class="text-xs font-semibold text-brand-400 uppercase tracking-wider">Multimedia</span>
                    </div>
                    <div x-show="!sidebarOpen" class="mx-2 my-3 border-t border-brand-800"></div>

                    <a href="/grabaciones-puntuales/canales" data-nav-path="/grabaciones-puntuales"                       class="nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors text-brand-200 hover:bg-brand-800 hover:text-white">
                        <i class="nav-icon fas fa-broadcast-tower w-5 text-center text-brand-300"></i>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm">Medios Puntuales</span>
                    </a>

                    @if(isset($userExternalSites) && $userExternalSites->count() > 0)
                    <!-- Sites Externos -->
                    <div class="px-3 mt-6 mb-2" x-show="sidebarOpen">
                        <span class="text-xs font-semibold text-brand-400 uppercase tracking-wider">Sites Externos</span>
                    </div>
                    <div x-show="!sidebarOpen" class="mx-2 my-3 border-t border-brand-800"></div>

                    @foreach($userExternalSites as $extSite)
                    @php
                        $siteColors = [
                            'blue'   => ['bg' => '#dbeafe', 'text' => '#2563eb'],
                            'green'  => ['bg' => '#dcfce7', 'text' => '#16a34a'],
                            'red'    => ['bg' => '#fee2e2', 'text' => '#dc2626'],
                            'purple' => ['bg' => '#f3e8ff', 'text' => '#9333ea'],
                            'amber'  => ['bg' => '#fef3c7', 'text' => '#d97706'],
                            'slate'  => ['bg' => '#f1f5f9', 'text' => '#64748b'],
                            'cyan'   => ['bg' => '#cffafe', 'text' => '#0891b2'],
                            'rose'   => ['bg' => '#ffe4e6', 'text' => '#e11d48'],
                        ];
                        $clr = $siteColors[$extSite->color] ?? $siteColors['blue'];
                        $isActive = request()->is('sites/' . $extSite->id) || request()->is('sites/' . $extSite->id . '/*');
                    @endphp
                    <a href="/sites/{{ $extSite->id }}"
                       title="{{ $extSite->name }}"
                       class="flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors {{ $isActive ? 'bg-brand-700 text-white' : 'text-brand-200 hover:bg-brand-800 hover:text-white' }}">
                        <div class="w-5 h-5 rounded flex items-center justify-center flex-shrink-0"
                             style="background-color: {{ $clr['bg'] }}">
                            <i class="fas {{ $extSite->icon }} text-[11px]" style="color: {{ $clr['text'] }}"></i>
                        </div>
                        <span x-show="sidebarOpen" x-transition class="font-medium text-sm truncate">{{ $extSite->name }}</span>
                    </a>
                    @endforeach
                    @endif

                </nav>

                <!-- Sidebar Footer -->
                <div class="p-3 border-t border-white/10">
                    {{-- Sidebar abierto --}}
                    <div x-show="sidebarOpen" x-transition>
                        <div class="rounded-xl overflow-hidden" style="background: linear-gradient(135deg, rgba(255,255,255,0.07) 0%, rgba(255,255,255,0.03) 100%); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="px-3 pt-3 pb-2">
                                <div class="flex items-center gap-2 mb-2.5">
                                    <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                                         style="background: rgba(255,255,255,0.08);">
                                        <i class="fas fa-hdd text-brand-300 text-xs"></i>
                                    </div>
                                    <span class="text-xs font-semibold text-white/60 uppercase tracking-wider">Mi Espacio</span>
                                </div>

                                <div class="flex items-end justify-between mb-2">
                                    <div>
                                        <p class="text-base font-bold text-white leading-none">{{ $sidebarQuota['used_label'] }}</p>
                                        <p class="text-xs text-white/40 mt-0.5">utilizados</p>
                                    </div>
                                    @if($sidebarQuota['is_unlimited'])
                                        <span class="text-xs text-brand-300 font-medium flex items-center gap-1">
                                            <i class="fas fa-infinity text-[10px]"></i> Sin límite
                                        </span>
                                    @else
                                        <span class="text-xs text-white/50">de {{ $sidebarQuota['limit_label'] }}</span>
                                    @endif
                                </div>

                                @if(!$sidebarQuota['is_unlimited'])
                                <div class="w-full rounded-full h-1.5 mb-1" style="background: rgba(255,255,255,0.08);">
                                    <div class="h-1.5 rounded-full transition-all"
                                         style="width: {{ max($sidebarQuota['percentage'], 1) }}%;
                                                background: linear-gradient(90deg,
                                                    {{ $sidebarQuota['percentage'] >= 90 ? '#f87171, #ef4444' : ($sidebarQuota['percentage'] >= 70 ? '#fbbf24, #f59e0b' : '#60a5fa, #818cf8') }});">
                                    </div>
                                </div>
                                <p class="text-right text-xs text-white/30">{{ $sidebarQuota['percentage'] }}%</p>
                                @else
                                <div class="w-full rounded-full h-1" style="background: rgba(255,255,255,0.06);">
                                    <div class="h-1 rounded-full w-full" style="background: linear-gradient(90deg, rgba(96,165,250,0.3), rgba(129,140,248,0.3));"></div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Sidebar cerrado --}}
                    <div x-show="!sidebarOpen" x-transition class="flex justify-center">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center"
                             style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08);"
                             title="{{ $sidebarQuota['used_label'] }} / {{ $sidebarQuota['limit_label'] }}">
                            <i class="fas fa-hdd text-brand-300 text-sm"></i>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 overflow-auto bg-slate-50">
                @yield('content')
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
                    <h3 class="text-white font-semibold">{{ session('user_username', session('user_email', 'Usuario')) }}</h3>
                    <span class="inline-flex items-center gap-1.5 mt-2 px-3 py-1 rounded-full text-xs font-medium {{ session('user_role') === 'admin' ? 'bg-brand-500/30 border border-brand-500/50 text-brand-300' : 'bg-brand-600/30 border border-brand-600/50 text-brand-200' }}">
                        <i class="fas {{ session('user_role') === 'admin' ? 'fa-shield-alt' : 'fa-user' }}"></i>
                        {{ session('user_role') === 'admin' ? 'Administrador' : 'Usuario' }}
                    </span>
                </div>

                <div class="space-y-4">
                    <div class="bg-brand-900/40 rounded-xl p-4 border border-brand-700/30">
                        <p class="text-brand-300 text-xs uppercase tracking-wider mb-1">Correo Electrónico</p>
                        <p class="text-white font-medium">{{ session('user_email', 'Usuario') }}</p>
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
                        @csrf
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
        <!-- Mobile FAB: abrir menú lateral -->
        <button class="lg:hidden fixed bottom-16 right-5 z-30 w-13 h-13 flex items-center justify-center rounded-full shadow-2xl transition-all duration-200 active:scale-95"
                style="width:52px; height:52px; background: linear-gradient(135deg, #3d4899, #2b3077); box-shadow: 0 8px 24px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.1);"
                @click="sidebarOpen = !sidebarOpen">
            <i :class="sidebarOpen ? 'fas fa-times text-base' : 'fas fa-bars text-base'" class="text-white"></i>
        </button>
    @else
    @yield('content')
    @endif

    @stack('scripts')
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

    {{-- Modal global para instructivos PDF --}}
    <div id="instructivo-modal"
         style="display:none; position:fixed; inset:0; z-index:9999; flex-direction:column; background:rgba(0,0,0,0.85);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; background:#0f172a; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:32px; height:32px; background:#dc2626; border-radius:8px; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-file-pdf" style="color:white; font-size:12px;"></i>
                </div>
                <span style="color:white; font-weight:600; font-size:14px;">Instructivo</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <a id="instructivo-open-tab" href="#" target="_blank"
                   style="color:#94a3b8; font-size:13px; text-decoration:none; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-external-link-alt" style="font-size:11px;"></i> Abrir en pestaña
                </a>
                <button onclick="closeInstructivo()"
                        style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:20px; padding:4px; line-height:1;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div style="flex:1; overflow:hidden;">
            <iframe id="instructivo-iframe"
                    src=""
                    style="width:100%; height:100%; border:none;"
                    title="Instructivo PDF"></iframe>
        </div>
    </div>

    <script>
    function openInstructivo(url) {
        document.getElementById('instructivo-iframe').src = url + '#toolbar=1&navpanes=1';
        document.getElementById('instructivo-open-tab').href = url;
        var modal = document.getElementById('instructivo-modal');
        modal.style.display = 'flex';
        document.addEventListener('keydown', _instrEscHandler);
    }
    function closeInstructivo() {
        document.getElementById('instructivo-modal').style.display = 'none';
        document.getElementById('instructivo-iframe').src = '';
        document.removeEventListener('keydown', _instrEscHandler);
    }
    function _instrEscHandler(e) { if (e.key === 'Escape') closeInstructivo(); }
    </script>
</body>
</html>
