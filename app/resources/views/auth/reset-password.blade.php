<!DOCTYPE html>
<html>
<head>
    <title>Recuperar Contraseña - TCloud</title>
    <script src="/js/tailwind.js"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded shadow p-8 w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-center">Recuperar Contraseña</h1>
        
        @if(session('success'))
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4">{{ session('success') }}</div>
        @endif
        
        @if(session('error'))
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4">{{ session('error') }}</div>
        @endif

        <form action="{{ route('reset-password') }}" method="POST">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            
            <div class="mb-4">
                <label class="block mb-1">Nueva Contraseña</label>
                <input type="password" name="password" required class="w-full border rounded px-3 py-2">
            </div>
            
            <div class="mb-4">
                <label class="block mb-1">Confirmar Contraseña</label>
                <input type="password" name="password_confirmation" required class="w-full border rounded px-3 py-2">
            </div>
            
            <button type="submit" class="w-full bg-blue-500 text-white py-2 rounded">Actualizar Contraseña</button>
        </form>
    </div>
</body>
</html>
