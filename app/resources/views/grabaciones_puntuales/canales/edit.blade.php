@extends('layouts.app')

@section('title', 'Editar Canal - Tcloud')

@section('content')
<div class="p-6">
    <!-- Page Header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('canales.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center hover:bg-slate-200 transition-colors">
            <i class="fas fa-arrow-left text-slate-600"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                <div class="w-10 h-10 bg-sky-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-edit text-sky-600"></i>
                </div>
                Editar Canal
            </h1>
            <p class="text-sm text-gray-500 mt-1">Modifica la configuración del canal <span class="font-medium text-slate-700">{{ $canal->slot_nombre }}</span></p>
        </div>
    </div>

    <!-- Toast de feedback -->
    <div id="toast" class="fixed top-6 right-6 z-50 hidden max-w-sm px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium transition-all">
        <span id="toast-msg"></span>
    </div>

    <!-- API Status Banner (actualizable vía JS) -->
    <div id="api-status-banner">
        @if($canal->api_canal_id)
            <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-5 flex items-center gap-3">
                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-check text-green-600 text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-green-800">Registrado en la API del grabador</p>
                    <p class="text-xs text-green-600">API Canal ID: <span class="font-mono font-medium">{{ $canal->api_canal_id }}</span></p>
                </div>
            </div>
        @else
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5 flex items-center gap-3">
                <div class="w-8 h-8 bg-amber-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-amber-600 text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-amber-800">No registrado en la API</p>
                    <p class="text-xs text-amber-600">Agrega el Link de Origen para crear el canal en el grabador remoto</p>
                </div>
            </div>
        @endif
    </div>

    <!-- Form -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-2xl">
        <form id="form-canal" action="{{ route('canales.update', $canal) }}" method="POST" class="p-6">
            @csrf @method('PUT')

            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Grabador</label>
                    <div class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm bg-slate-50 text-slate-500 flex items-center gap-2">
                        <i class="fas fa-server text-slate-400 text-xs"></i>
                        {{ $canal->grabador->nombre }} ({{ $canal->grabador->ip }})
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre del Slot</label>
                    <input type="text" name="slot_nombre" value="{{ $canal->slot_nombre }}" required
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-sky-400 focus:border-sky-400 outline-none transition-all">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">
                        Link de Origen <span class="text-slate-400 font-normal">(URL del stream)</span>
                    </label>
                    <input type="url" name="link_origen" value="{{ $canal->link_origen }}"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm font-mono focus:ring-2 focus:ring-sky-400 focus:border-sky-400 outline-none transition-all"
                           placeholder="http://ejemplo.com/stream.mp3">
                    <p class="text-xs text-slate-400 mt-1">
                        @if($canal->api_canal_id)
                            Actualiza el link para cambiar la fuente del stream
                        @else
                            Al guardar con un link válido, el canal se creará automáticamente en el grabador
                        @endif
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">
                        Detalle <span class="text-slate-400 font-normal">(descripción del canal)</span>
                    </label>
                    <textarea name="detalle" rows="2"
                              class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-sky-400 focus:border-sky-400 outline-none transition-all resize-none"
                              placeholder="Ej: Canal principal de noticias, frecuencia 99.1 FM...">{{ $canal->detalle }}</textarea>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer group">
                        <div class="relative">
                            <input type="checkbox" name="activo" value="1"
                                   {{ $canal->activo ? 'checked' : '' }}
                                   class="sr-only peer">
                            <div class="w-10 h-6 bg-slate-200 peer-checked:bg-sky-600 rounded-full transition-colors"></div>
                            <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow-sm transition-transform peer-checked:translate-x-4"></div>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors">Activo</span>
                            <p class="text-xs text-slate-400">Permitir ejecutar grabaciones en este canal</p>
                        </div>
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-8 pt-6 border-t border-slate-200">
                <button id="btn-guardar" type="submit"
                        class="flex items-center gap-2 bg-sky-600 hover:bg-sky-700 text-white px-6 py-2.5 rounded-xl font-medium transition-colors shadow-sm">
                    <i class="fas fa-save text-sm"></i>
                    <span id="btn-guardar-texto">{{ $canal->api_canal_id ? 'Guardar y Sincronizar' : 'Guardar y Registrar en API' }}</span>
                </button>
                <a href="{{ route('canales.index') }}"
                   class="px-6 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('form-canal');
    const btnGuardar = document.getElementById('btn-guardar');
    const btnTexto = document.getElementById('btn-guardar-texto');
    const toastEl = document.getElementById('toast');
    const toastMsg = document.getElementById('toast-msg');
    let toastTimer = null;

    function mostrarToast(mensaje, exito) {
        toastEl.classList.remove('hidden', 'bg-green-600', 'bg-red-600');
        toastEl.classList.add(exito ? 'bg-green-600' : 'bg-red-600');
        toastMsg.textContent = mensaje;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.add('hidden'), 5000);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const textoOriginal = btnTexto.textContent.trim();
        btnGuardar.disabled = true;
        btnTexto.textContent = 'Guardando...';

        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: formData,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                mostrarToast(data.message || 'Canal guardado', true);
                setTimeout(function () {
                    window.location.href = '{{ route('canales.index') }}';
                }, 1800);
            } else {
                mostrarToast(data.message || 'Error al guardar', false);
                btnTexto.textContent = textoOriginal;
                btnGuardar.disabled = false;
            }
        })
        .catch(function () {
            mostrarToast('No se pudo conectar con el servidor', false);
            btnTexto.textContent = textoOriginal;
        })
        .finally(function () {
            btnGuardar.disabled = false;
        });
    });
})();
</script>
@endsection
