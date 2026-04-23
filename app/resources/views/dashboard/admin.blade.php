@extends('layouts.app')

@section('title', 'Dashboard - Tcloud')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">Panel de Administración</h1>
        <p class="text-slate-500 mt-0.5">Resumen del sistema y estadísticas</p>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Usuarios</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_users'] }}</p>
                </div>
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-users text-brand-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm">
                <span class="text-green-600 flex items-center gap-1">
                    <i class="fas fa-arrow-up text-xs"></i> 12%
                </span>
                <span class="text-slate-400 ml-2">vs mes anterior</span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Storages</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_storages'] }}</p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-database text-green-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm text-slate-400">
                <i class="fas fa-check-circle mr-1 text-green-500"></i> Todos activos
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Archivos</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_files'] }}</p>
                </div>
                <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-file text-amber-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm text-slate-400">
                {{ number_format($stats['storage_used'] / 1024 / 1024, 2) }} MB usados
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-500">Total Shares</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1">{{ $stats['total_shares'] }}</p>
                </div>
                <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-link text-brand-500 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-sm text-slate-400">
                <i class="fas fa-external-link-alt mr-1 text-brand-300 text-xs"></i> Enlaces activos
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Usage stats -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-5">Estadísticas de Uso</h3>
            <div class="space-y-5">
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-slate-600">Usuarios Activos</span>
                        <span class="font-medium text-slate-800">{{ $stats['total_users'] }}</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="bg-brand-600 h-2 rounded-full" style="width: 75%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-slate-600">Almacenamiento Utilizado</span>
                        <span class="font-medium text-slate-800">{{ number_format($stats['storage_used'] / 1024 / 1024, 2) }} MB</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width: 25%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-slate-600">Enlaces Compartidos</span>
                        <span class="font-medium text-slate-800">{{ $stats['total_shares'] }}</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="bg-brand-400 h-2 rounded-full" style="width: 40%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Acciones Rápidas</h3>
            <div class="space-y-3">
                <a href="/admin/users"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-brand-50 rounded-lg transition-colors group">
                    <div class="w-10 h-10 bg-brand-700 rounded-lg flex items-center justify-center">
                        <i class="fas fa-user-plus text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800 text-sm group-hover:text-brand-700">Nuevo Usuario</p>
                        <p class="text-xs text-slate-500">Agregar al sistema</p>
                    </div>
                </a>
                <a href="/admin/storages"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-green-50 rounded-lg transition-colors group">
                    <div class="w-10 h-10 bg-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-hdd text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800 text-sm group-hover:text-green-700">Nuevo Storage</p>
                        <p class="text-xs text-slate-500">Proveedor de almacenamiento</p>
                    </div>
                </a>
                <a href="/files"
                   class="flex items-center gap-3 p-3 bg-slate-50 hover:bg-brand-50 rounded-lg transition-colors group">
                    <div class="w-10 h-10 bg-brand-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-cloud-upload-alt text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-slate-800 text-sm group-hover:text-brand-600">Subir Archivos</p>
                        <p class="text-xs text-slate-500">Gestión de archivos</p>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- System info -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Información del Sistema</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">Versión Laravel</p>
                <p class="font-semibold text-slate-700">13.5.0</p>
            </div>
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">PHP Version</p>
                <p class="font-semibold text-slate-700">8.4.20</p>
            </div>
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">Base de Datos</p>
                <p class="font-semibold text-slate-700">PostgreSQL</p>
            </div>
            <div class="p-4 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-500 mb-1">Estado</p>
                <p class="font-semibold text-green-600 flex items-center gap-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full inline-block"></span>
                    Operativo
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
