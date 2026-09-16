## Context

La familia storage admin son 3 vistas Blade con Alpine.js: `storages.blade.php` (4 modales: crear, editar, eliminar, usuarios), `storage-users.blade.php` (gestión desde perfil de usuario) y `user-storages.blade.php` (asignaciones de un usuario). Las 3 tienen cambios sin commitear (loading states del change `admin-destructive-actions-loading-state` / `fix-storages-delete-loading-state`); este change se construye encima de ese working tree.

El house style de modales ya existe en el codebase y está validado en producción en `ia/correcciones/index.blade.php` (~10 modales), `admin/external-sites.blade.php`, `files/index.blade.php` y `ia/api-transcriptor/index.blade.php`. No hay build step: Tailwind corre como Play CDN (`/js/tailwind.js`) con la paleta `brand` definida inline en `layouts/app.blade.php`, así que cualquier clase usada por correcciones ya está disponible en runtime para las demás vistas.

## Goals / Non-Goals

**Goals:**
- Que los 4+3 modales de la familia compartan exactamente las mismas clases de contenedor, overlay, labels, inputs y footer de botones que el house style.
- Eliminar los dos patrones viejos dentro del modal crear: `alert()` en errores y `onchange` con manipulación DOM directa.
- Preservar todo comportamiento existente (loading states, chips, typeahead, tour).

**Non-Goals:**
- No crear componente Blade `<x-modal>` (decisión: restyle inline; el codebase no usa View Components y esto no introduce el seam).
- No migrar otros módulos; no rediseñar tabla/filtros/paginación.
- No tocar controladores, rutas ni endpoints.

## Decisions

**D1. Restyle inline copiando clases, no abstracción.**
Alternativa descartada: `<x-modal>` (componente Blade). Razón: sería el primer componente de vista del proyecto (`app/app/View/Components` está vacío), introduce un seam nuevo para 7 modales, y el resto de la app ya consolidó el patrón por copia. Si más adelante otra familia requiere restyle, un change posterior puede extraer el componente cuando haya suficientes call sites para justificarlo.

**D2. Fuente de verdad del estilo: `ia/correcciones/index.blade.php`.**
El token kit a replicar (verificado en producción):

| Elemento | Clases |
|---|---|
| Overlay | `fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4` + `x-transition` |
| Panel | `bg-white rounded-2xl w-full max-w-{size} shadow-2xl` |
| Cuerpo | `p-6` con `space-y-4` entre campos |
| Label | `block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide` |
| Input | `w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none` |
| Primaria | `flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium` |
| Neutra | `px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium` |
| Destructiva | `flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium` |

**D3. `alert()` → toast existente.** El modal crear ya está en la vista que tiene `this.toast` + `showToast()`. Los handlers de error de `createStorage` y `updateStorage` pasan a construir el toast (verde/rojo) igual que `deleteStorage` ya lo hace. Sin dependencias nuevas.

**D4. `onchange` DOM-directo → estado Alpine.** El modal crear usa `onchange="document.getElementById('s3_config').style.display=..."`. Se reemplaza por `x-model="newStorageType"` + `x-show="newStorageType === 's3'"` (y su inverso para Base Path), dentro del mismo `x-data` existente. Esto elimina el único uso de manipulación DOM directa del módulo.

**D5. Botones de acción de tabla: unificar forma, preservar texto.** El tour localiza botones por texto (`getActionButton('Usuarios'|'Probar'|'Editar'|'Eliminar')`) y por posición de celda. Se conservan los textos exactos y los botones pasan de texto plano a píldoras compactas (`px-3 py-1.5 rounded-lg text-xs font-medium border` con la paleta de la vista móvil existente: verde/neutro/índigo/rojo), que ya es el patrón que usa la tarjeta móvil — se sube a escritorio en vez de inventar uno nuevo.

**D6. Vista por vista, commiteable por vista.** El orden de trabajo es `storages` → `storage-users` → `user-storages`, verificando el tour tras la primera. Cada vista es un paquete de tareas independiente para reducir el blast radius de regresión.

## Risks / Trade-offs

- **[Riesgo] Tour guiado roto por clases nuevas en selectores.** Los pasos usan selectores por clase (`.bg-white.rounded-lg.shadow.p-4` para la barra de controles, `table thead`, paginación) → Mitigación: D5 solo toca botones de acción; la barra de controles, thead y paginación NO se reestilizan en este change. Verificación manual del tour completa tras task de `storages`.
- **[Riesgo] Doble spinner/estados de carga regredidos al tocar botones.** El botón Eliminar ya tiene `disabled` + spinner del change anterior → Mitigación: al restylear el botón se conservan `:disabled`, las clases `disabled:*` y los spans `x-show` de estado.
- **[Riesgo] Tailwind Play CDN no conoce una clase aún no renderizada.** El Play CDN escanea el DOM en vivo y genera clases al vuelo; `rounded-2xl`, `shadow-2xl`, `brand-*`, `backdrop-blur-sm` ya aparecen en otras vistas del mismo layout → Mitigación: reutilizar solo clases ya presentes en vistas que cargan bajo el mismo layout; probar visualmente tras cada vista.
- **[Trade-off] 5ª copia a mano del shell.** Aceptado conscientemente (D1); el drift se controla porque el kit vive en D2 de este design como referencia única.

## Migration Plan

Deploy directo: son vistas Blade; `php artisan view:clear` tras desplegar. Rollback: revertir el commit de la vista afectada + `view:clear`. Sin migraciones ni estado persistente.

## Open Questions

Ninguna: alcance (familia completa), enfoque (inline) y profundidad (comportamiento incluido) quedaron decididos con el usuario al abrir el change.