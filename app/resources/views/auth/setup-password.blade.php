<!DOCTYPE html>
<html>
<head>
    <title>Establecer Contraseña - TCloud</title>
    <script src="/js/tailwind.js"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded shadow p-8 w-full max-w-md">
        <h1 class="text-2xl font-bold mb-2 text-center">Establecer Contraseña</h1>

        <div class="bg-blue-50 border border-blue-200 text-blue-800 p-3 rounded mb-5 text-sm">
            <div class="font-semibold mb-1">Vas a asignar una contraseña nueva a:</div>
            <div class="text-base font-bold break-all">{{ $email }}</div>
            @if(!empty($username))
                <div class="text-xs text-blue-600 mt-1">Usuario: {{ $username }}</div>
            @endif
        </div>

        <p class="text-sm text-gray-600 text-center mb-6">
            Bienvenido a TCloud. Define la contraseña que usarás para acceder a esta cuenta.
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

        <form action="{{ url('/auth/setup-password') }}" method="POST">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="mb-4">
                <label class="block mb-1">Nueva Contraseña</label>
                <input type="password" name="password" required minlength="8" class="w-full border rounded px-3 py-2">
            </div>

            <div class="mb-4">
                <label class="block mb-1">Confirmar Contraseña</label>
                <input type="password" name="password_confirmation" required minlength="8" class="w-full border rounded px-3 py-2">
            </div>

            <button type="submit" class="w-full bg-blue-500 text-white py-2 rounded">Establecer Contraseña</button>
        </form>
    </div>
</body>
</html>
