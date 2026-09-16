## 1. Modal Crear Storage — house style + comportamiento

- [x] 1.1 En `admin/storages.blade.php`, restylear el contenedor del modal crear: overlay `bg-black/50 backdrop-blur-sm p-4` + `x-transition`, panel `bg-white rounded-2xl w-full max-w-md shadow-2xl`, cuerpo `p-6 space-y-4`.
- [x] 1.2 Restylear labels e inputs del modal crear con el kit del design (labels `text-xs font-semibold text-slate-600 uppercase tracking-wide`, inputs `border-slate-300 rounded-lg focus:ring-brand-500`).
- [x] 1.3 Reemplazar el `onchange` DOM-directo del select Tipo por `x-model="newStorageType"` + `x-show="newStorageType === 's3'"` (añadir `newStorageType: 'local'` al `x-data` y resetearlo al cerrar/abrir el modal).
- [x] 1.4 Restylear footer del modal crear: primaria `flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 rounded-xl`, cancelar `bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl`.
- [x] 1.5 Reemplazar `alert()` de `createStorage` por toast existente (rojo con `err.error`, modal permanece abierto) y añadir toast verde de éxito tras recargar la lista.
- [x] 1.6 Reemplazar `alert()` de `updateStorage` por toast igual (misma alineación aunque el modal editar no cambie de estructura).

## 2. Modales Editar y Eliminar — house style

- [x] 2.1 Restylear contenedor/labels/inputs del modal Editar con el kit (misma estructura que 1.1–1.2; footer con primaria de marca).
- [x] 2.2 Restylear contenedor del modal Eliminar (overlay blur + panel rounded-2xl shadow-2xl) preservando íntegro el botón Eliminar con su estado de carga (`:disabled`, spinner, spans `x-show`, clases `disabled:*`).
- [x] 2.3 Restylear footer del modal Eliminar: "Eliminar" en `bg-red-500 hover:bg-red-600` ancho flexible, "Cancelar" neutro.

## 3. Modal Usuarios + chips — house style

- [x] 3.1 Restylear contenedor y header del modal Usuarios (panel `rounded-2xl shadow-2xl`, overlay con blur/p-4, botón × con hover neutro).
- [x] 3.2 Restylear panel de edición inline de permisos: fondo pastel de marca (`bg-brand-50` con borde `border-brand-200`), labels uppercase, botón Guardar de marca y Cancelar neutro.
- [x] 3.3 Restylear sección "Asignar usuario": labels uppercase, input typeahead con focus ring de marca, selects con estilo del kit, botón Asignar de marca con estado disabled consistente.
- [x] 3.4 Verificar que chips (colores por permiso), checkbox "Todas las personas" y spinner del × se conservan funcionales.

## 4. Botones de acción de tabla — unificación

- [x] 4.1 En la tabla escritorio, pasar las 4 acciones de texto plano a píldoras compactas (`px-3 py-1.5 rounded-lg text-xs font-medium border`) con la paleta de la vista móvil: verde (Usuarios), neutro (Probar), índigo/marca (Editar), rojo (Eliminar); preservar textos exactos.
- [x] 4.2 Verificar `startStoragesTour()`: ejecutar la guía completa en escritorio y móvil, confirmando que los 15 pasos localizan sus elementos (especialmente los pasos que usan `getActionButton`).

## 5. Vista storage-users.blade.php — familia

- [x] 5.1 Restylear contenedor/overlay/labels/inputs/footer del modal de gestión de usuarios con el kit del design D2.
- [x] 5.2 Unificar botones de acción (Remover y demás) con el mismo estilo de píldora preservando estados de carga (`removingStorageUserKey` spinner).

## 6. Vista user-storages.blade.php — familia

- [x] 6.1 Restylear contenedor/overlay/labels/inputs/footer de sus modales con el kit del design D2.
- [x] 6.2 Unificar botones de acción y preservar estados de carga existentes.

## 7. Verificación final

- [x] 7.1 Revisión visual de los 4 modales en desktop y móvil (375px): padding del overlay, scroll del modal largo (usuarios), contraste de labels.
- [x] 7.2 Ejecutar `php artisan view:clear` y verificar en producción/staging que los modales renderizan con el nuevo estilo (Tailwind Play CDN genera las clases).
- [x] 7.3 Grep de regresión: cero `alert(` y cero `onchange="document.getElementById` en las 3 vistas de la familia.
- [x] 7.4 Ejecutar la suite del módulo storage (75 tests del change anterior) para confirmar que no hay regresión de backend.