<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grabaciones Puntuales - Tcloud</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex flex-col">
        <nav class="bg-indigo-600 text-white px-6 py-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold">Grabaciones Puntuales</h1>
                <div class="flex items-center gap-4">
                    <a href="{{ route('grabadores.index') }}" class="hover:underline">Grabadores</a>
                    <a href="{{ route('canales.index') }}" class="hover:underline">Canales</a>
                    <a href="{{ route('dashboard') }}" class="hover:underline">
                        <i class="fas fa-home"></i> Inicio
                    </a>
                </div>
            </div>
        </nav>

        <main class="flex-1 p-6">
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</body>
</html>