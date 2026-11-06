{{--
Toast para mensajes flash de sesión (success / warning / error).

Por qué existe: el módulo `grabaciones_puntuales` rechazaba operaciones con
`back()->with('error', ...)` pero ninguna de sus vistas renderizaba
`session('error')`. El usuario veía el formulario vacío, sin explicación.
Este partial centraliza el feedback para los flujos POST clásicos (crear
canal, asignar usuario), sin depender de Alpine ni de `mostrarToast` (que
solo existe en el JS de AJAX de `canales/index`).

Uso:
    @include('partials.flash-toast')

Ids distintos de los que usa `mostrarToast` (`#toast`) para no colisionar
cuando ambas piezas conviven en la misma vista.
--}}
@php
    $flashToast = null;
    foreach (['success' => 'emerald', 'warning' => 'amber', 'error' => 'rose'] as $clave => $paleta) {
        if (session()->has($clave)) {
            $flashToast = ['tipo' => $clave, 'paleta' => $paleta, 'texto' => session($clave)];
            break;
        }
    }
@endphp

@if($flashToast)
<div id="flash-toast"
     data-tipo="{{ $flashToast['tipo'] }}"
     class="fixed z-[70] bottom-6 right-6 max-w-md w-[calc(100%-3rem)] sm:w-auto
            rounded-xl shadow-lg border px-4 py-3 flex items-start gap-3
            text-sm font-medium
            @if($flashToast['paleta'] === 'emerald') bg-emerald-50 border-emerald-200 text-emerald-800
            @elseif($flashToast['paleta'] === 'amber') bg-amber-50 border-amber-200 text-amber-900
            @else bg-rose-50 border-rose-200 text-rose-800
            @endif">
    <i class="fas mt-0.5
        @if($flashToast['paleta'] === 'emerald') fa-check-circle text-emerald-500
        @elseif($flashToast['paleta'] === 'amber') fa-exclamation-triangle text-amber-500
        @else fa-times-circle text-rose-500
        @endif"></i>
    <span class="flex-1">{{ $flashToast['texto'] }}</span>
    <button type="button" onclick="document.getElementById('flash-toast')?.remove()"
            class="text-slate-400 hover:text-slate-600 shrink-0" aria-label="Cerrar">
        <i class="fas fa-times"></i>
    </button>
</div>

<script>
    (function () {
        var el = document.getElementById('flash-toast');
        if (!el) return;
        setTimeout(function () {
            el.style.transition = 'opacity 300ms ease, transform 300ms ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(8px)';
            setTimeout(function () { el.remove(); }, 350);
        }, 4000);
    })();
</script>
@endif
