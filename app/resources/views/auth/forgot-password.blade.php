<!DOCTYPE html>
<html>
<head>
    <title>Recuperar Contraseña - TCloud</title>
    <script src="/js/tailwind.js"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded shadow p-8 w-full max-w-md">
        <h1 class="text-2xl font-bold mb-2 text-center">Recuperar Contraseña</h1>
        <p class="text-sm text-gray-600 text-center mb-6">
            Te enviaremos un enlace al correo registrado para que puedas restablecer tu contraseña.
        </p>

        @if(session('success'))
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ url('/auth/forgot-password') }}" method="POST">
            @csrf

            <div class="mb-4">
                <label class="block mb-1">Correo electrónico</label>
                <input type="email" name="email" required class="w-full border rounded px-3 py-2" value="{{ old('email') }}">
            </div>

            <button type="submit" class="w-full bg-blue-500 text-white py-2 rounded">Enviar enlace de recuperación</button>
        </form>

        <p class="text-sm text-gray-600 text-center mt-4">
            <a href="{{ url('/login') }}" class="text-blue-500 hover:underline">← Volver al inicio de sesión</a>
        </p>
    </div>
</body>
</html>
