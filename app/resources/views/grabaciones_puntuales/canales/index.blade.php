@extends('layouts.app')

@section('content')
<div class="p-6">

{{-- Toast --}}
<div id="toast" class="fixed top-6 right-6 z-50 hidden max-w-sm px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium">
    <span id="toast-msg"></span>
</div>

{{-- Encabezado --}}
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-broadcast-tower text-indigo-600"></i>
            </div>
            Grabaciones Puntuales
        </h1>
        <p class="text-sm text-slate-500 mt-1">Canales de grabación configurados</p>
    </div>
    @if(!$user || !$user->isAdmin())
        <a href="{{ route('canales.create') }}"
           class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2.5 rounded-xl font-medium text-sm transition-colors shadow-sm">
            <i class="fas fa-plus text-xs"></i> Crear Canal
        </a>
    @endif
</div>

{{-- Barra de búsqueda --}}
<div class="mb-4 relative">
    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
    <input id="busqueda" type="text" placeholder="Buscar por slot, detalle, API ID..."
           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl bg-white text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 shadow-sm">
</div>

{{-- Tabla --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full" id="tabla-canales">
        <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
                <th class="px-4 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider w-10">#</th>
                @if($user && $user->isAdmin())
                    <th data-col="usuario" class="sortable px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-indigo-600 transition-colors">
                        Usuario <span class="sort-icon text-indigo-400"></span>
                    </th>
                    <th data-col="grabador" class="sortable px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-indigo-600 transition-colors">
                        Grabador <span class="sort-icon text-indigo-400"></span>
                    </th>
                @endif
                <th data-col="slot" class="sortable px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-indigo-600 transition-colors">
                    Slot <span class="sort-icon text-indigo-400"></span>
                </th>
                <th data-col="api_id" class="sortable px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-indigo-600 transition-colors">
                    API ID <span class="sort-icon text-indigo-400"></span>
                </th>
                <th data-col="detalle" class="sortable px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-indigo-600 transition-colors">
                    Detalle <span class="sort-icon text-indigo-400"></span>
                </th>
                <th data-col="estado" class="sortable px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-indigo-600 transition-colors">
                    Estado <span class="sort-icon text-indigo-400"></span>
                </th>
                <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse($canales as $canal)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-4 py-3.5 text-center text-xs text-slate-400 font-mono row-num"></td>
                @if($user && $user->isAdmin())
                    <td class="px-5 py-3.5 text-sm text-slate-600">{{ $canal->usuario->username ?? 'N/A' }}</td>
                    <td class="px-5 py-3.5 text-sm text-slate-600">{{ $canal->grabador->nombre }}</td>
                @endif
                <td class="px-5 py-3.5 text-sm font-semibold text-slate-800">{{ $canal->slot_nombre }}</td>
                <td class="px-5 py-3.5 text-sm font-mono text-slate-500">{{ $canal->api_canal_id ?? '—' }}</td>
                <td class="px-5 py-3.5 text-sm text-slate-600 max-w-[200px] truncate" title="{{ $canal->detalle ?? '' }}">{{ $canal->detalle ?? '—' }}</td>
                <td class="px-5 py-3.5">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $canal->activo ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $canal->activo ? 'bg-green-500' : 'bg-red-500' }}"></span>
                        {{ $canal->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                </td>
                <td class="px-5 py-3.5">
                    <div class="flex gap-1.5 items-center">
                        @if($canal->activo && $canal->api_canal_id)
                            <button type="button"
                                    class="btn-ejecutar inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-green-50 text-green-700 hover:bg-green-100 text-xs font-medium transition-colors border border-green-100"
                                    data-url="{{ route('canales.ejecutar', $canal) }}">
                                <i class="fas fa-play text-xs"></i> Ejecutar
                            </button>
                        @endif
                        <a href="{{ route('canales.edit', $canal) }}"
                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-xs font-medium transition-colors border border-indigo-100">
                            <i class="fas fa-edit text-xs"></i> Editar
                        </a>
                        <button type="button"
                                class="btn-limpiar inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 text-xs font-medium transition-colors border border-amber-100"
                                data-url="{{ route('canales.destroy', $canal) }}"
                                data-nombre="{{ $canal->slot_nombre }}">
                            <i class="fas fa-eraser text-xs"></i> Limpiar
                        </button>
                    </div>
                </td>
            </tr>
            @empty
            <tr id="fila-vacia">
                <td colspan="{{ $user && $user->isAdmin() ? '8' : '6' }}" class="px-6 py-16 text-center">
                    <div class="flex flex-col items-center gap-2">
                        <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-broadcast-tower text-slate-300 text-lg"></i>
                        </div>
                        <p class="text-slate-500 font-medium text-sm">No hay canales configurados</p>
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    <div id="sin-resultados" class="hidden px-6 py-12 text-center">
        <div class="flex flex-col items-center gap-2">
            <i class="fas fa-search text-slate-300 text-2xl"></i>
            <p class="text-slate-500 text-sm">Sin resultados para "<span id="busqueda-texto" class="font-medium text-slate-700"></span>"</p>
        </div>
    </div>
</div>

</div>{{-- /p-6 --}}

{{-- Modal de confirmación Limpiar --}}
<div id="modal-limpiar" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full mx-4 p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-eraser text-amber-600"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-800 text-base">Limpiar canal</h3>
                <p class="text-xs text-gray-500">Esta acción no elimina el slot</p>
            </div>
        </div>
        <p class="text-sm text-gray-600 mb-1">¿Deseas limpiar los campos de <span id="modal-canal-nombre" class="font-semibold text-gray-800"></span>?</p>
        <p class="text-xs text-gray-400 mb-6">Se borrarán el link de origen, detalle y el registro en la API del grabador. El slot quedará listo para reconfigurar.</p>
        <div class="flex gap-3 justify-end">
            <button id="modal-cancelar"
                    class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-medium transition-colors">
                Cancelar
            </button>
            <button id="modal-confirmar"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium transition-colors">
                <i class="fas fa-eraser text-xs"></i>
                <span id="modal-confirmar-texto">Sí, limpiar</span>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const tabla = document.getElementById('tabla-canales');
    const tbody = tabla ? tabla.querySelector('tbody') : null;
    const inputBusqueda = document.getElementById('busqueda');
    const sinResultados = document.getElementById('sin-resultados');
    const toastEl = document.getElementById('toast');
    const toastMsg = document.getElementById('toast-msg');
    let toastTimer = null;

    // --- Toast ---
    function mostrarToast(mensaje, exito) {
        toastEl.classList.remove('hidden', 'bg-green-600', 'bg-red-600');
        toastEl.classList.add(exito ? 'bg-green-600' : 'bg-red-600');
        toastMsg.textContent = mensaje;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.add('hidden'), 4000);
    }

    // --- Numeración de filas visibles ---
    function actualizarNumeracion() {
        if (!tbody) return;
        let n = 1;
        tbody.querySelectorAll('tr:not(#fila-vacia)').forEach(function (fila) {
            const cel = fila.querySelector('.row-num');
            if (cel) cel.textContent = fila.style.display === 'none' ? '' : n++;
        });
    }

    // --- Búsqueda en tiempo real ---
    if (inputBusqueda && tbody) {
        inputBusqueda.addEventListener('input', function () {
            const texto = this.value.trim().toLowerCase();
            const filas = tbody.querySelectorAll('tr:not(#fila-vacia)');
            let visibles = 0;

            filas.forEach(function (fila) {
                const contenido = fila.textContent.toLowerCase();
                const coincide = texto === '' || contenido.includes(texto);
                fila.style.display = coincide ? '' : 'none';
                if (coincide) visibles++;
            });

            if (sinResultados) {
                document.getElementById('busqueda-texto').textContent = this.value;
                sinResultados.classList.toggle('hidden', visibles > 0 || texto === '');
            }
            actualizarNumeracion();
        });
    }

    // --- Ordenamiento por columna ---
    let sortCol = null;
    let sortDir = 'asc';

    function indiceCelda(th) {
        const ths = Array.from(th.closest('tr').querySelectorAll('th'));
        return ths.indexOf(th);
    }

    function ordenarTabla(thActivo, col, dir) {
        if (!tbody) return;
        const filas = Array.from(tbody.querySelectorAll('tr:not(#fila-vacia)'));
        const idx = indiceCelda(thActivo);

        filas.sort(function (a, b) {
            const ta = a.cells[idx] ? a.cells[idx].textContent.trim().toLowerCase() : '';
            const tb = b.cells[idx] ? b.cells[idx].textContent.trim().toLowerCase() : '';
            const num_a = parseFloat(ta), num_b = parseFloat(tb);
            const usarNum = !isNaN(num_a) && !isNaN(num_b);
            const cmp = usarNum ? (num_a - num_b) : ta.localeCompare(tb, 'es');
            return dir === 'asc' ? cmp : -cmp;
        });

        filas.forEach(function (fila) { tbody.appendChild(fila); });

        tabla.querySelectorAll('th.sortable .sort-icon').forEach(function (ic) { ic.textContent = ''; });
        thActivo.querySelector('.sort-icon').textContent = dir === 'asc' ? ' ▲' : ' ▼';
        actualizarNumeracion();
    }

    if (tabla) {
        tabla.querySelectorAll('th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                const col = this.dataset.col;
                if (sortCol === col) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortCol = col;
                    sortDir = 'asc';
                }
                ordenarTabla(this, sortCol, sortDir);
            });
        });

        // Orden por defecto: Slot ascendente
        const thSlot = tabla.querySelector('th[data-col="slot"]');
        if (thSlot) {
            sortCol = 'slot';
            sortDir = 'asc';
            ordenarTabla(thSlot, 'slot', 'asc');
        }
    }

    // --- Modal Limpiar ---
    const modalLimpiar = document.getElementById('modal-limpiar');
    const modalNombre = document.getElementById('modal-canal-nombre');
    const modalCancelar = document.getElementById('modal-cancelar');
    const modalConfirmar = document.getElementById('modal-confirmar');
    const modalConfirmarTexto = document.getElementById('modal-confirmar-texto');
    let urlLimpiar = null;

    document.querySelectorAll('.btn-limpiar').forEach(function (btn) {
        btn.addEventListener('click', function () {
            urlLimpiar = this.dataset.url;
            modalNombre.textContent = this.dataset.nombre;
            modalLimpiar.classList.remove('hidden');
        });
    });

    modalCancelar.addEventListener('click', function () {
        modalLimpiar.classList.add('hidden');
        urlLimpiar = null;
    });

    modalLimpiar.addEventListener('click', function (e) {
        if (e.target === modalLimpiar) {
            modalLimpiar.classList.add('hidden');
            urlLimpiar = null;
        }
    });

    modalConfirmar.addEventListener('click', function () {
        if (!urlLimpiar) return;
        modalConfirmar.disabled = true;
        modalConfirmarTexto.textContent = 'Limpiando...';

        const fd = new FormData();
        fd.append('_method', 'DELETE');
        fd.append('_token', csrfToken);

        fetch(urlLimpiar, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: fd,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            modalLimpiar.classList.add('hidden');
            if (data.success) {
                mostrarToast('Canal limpiado — listo para reconfigurar', true);
                setTimeout(function () { window.location.reload(); }, 1800);
            } else {
                mostrarToast('Error al limpiar el canal', false);
            }
        })
        .catch(function () {
            modalLimpiar.classList.add('hidden');
            mostrarToast('No se pudo conectar con el servidor', false);
        })
        .finally(function () {
            modalConfirmar.disabled = false;
            modalConfirmarTexto.textContent = 'Sí, limpiar';
            urlLimpiar = null;
        });
    });

    // --- Botón Ejecutar AJAX ---
    const csrfToken = document.querySelector('meta[name="csrf-token"]')
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        : '';

    document.querySelectorAll('.btn-ejecutar').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const url = this.dataset.url;
            const boton = this;
            const textoOriginal = boton.textContent.trim();

            boton.disabled = true;
            boton.textContent = 'Ejecutando...';

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                mostrarToast(data.message || (data.success ? 'Grabación iniciada' : 'Error desconocido'), data.success);
            })
            .catch(function () {
                mostrarToast('No se pudo conectar con el servidor', false);
            })
            .finally(function () {
                boton.disabled = false;
                boton.textContent = textoOriginal;
            });
        });
    });
})();
</script>
@endsection
