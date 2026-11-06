## Context

El modal "Mover a..." / "Copiar a..." de Mis Archivos está roto: dice "No hay subcarpetas aquí" cuando navegas desde una carpeta que SÍ tiene subcarpetas visibles en el listado. El bug bloquea la acción principal del módulo (mover archivos entre carpetas) y obliga a usar la API directamente. Ver `proposal.md` para motivación y `specs/file-copy-move/spec.md` para el contrato de comportamiento.

Este documento explica las decisiones técnicas de implementación en Alpine.js (todo el cambio es frontend; el backend no se toca).

## Goals / Non-Goals

**Goals:**
- Restaurar el flujo "mover archivo entre carpetas" sin tocar el backend.
- Mejorar la UX: el modal abre en el folder actual (no en la raíz del storage) para que mover a una carpeta hermana sea 1 click, no 4.
- Mantener consistencia con los otros 2 call-sites del módulo que ya consumen el shape `{files, breadcrumbs, pagination}`.
- Mantener el guardrail backend existente: `FileController::move` ya valida con `isDescendantOf()` y retorna 422; el frontend solo agrega hint visual.

**Non-Goals:**
- No se rediseña el modal (mantener mismo `<div x-show="showCopyMoveModal">` y mismas clases Tailwind).
- No se introduce un árbol de descendientes en frontend (caro para carpetas grandes); el guardrail del backend cubre.
- No se cambia la lógica de `confirmCopyMove()` (sigue usando `copyMoveDestFolderId` igual que antes).
- No se agrega soporte cross-storage (mover entre storages distintos).

## Decisions

### D1. Fix del shape en `loadCopyMoveFolders`

**Decisión**: cambiar la línea 1156 de:
```js
this.copyMoveFolders = (Array.isArray(data) ? data : []).filter(f => f.is_folder);
```
a:
```js
const list = Array.isArray(data) ? data : (data?.files ?? []);
this.copyMoveFolders = list.filter(f => f.is_folder);
```

**Por qué**: paridad exacta con los call-sites de las líneas 440 y 1274 que ya consumen el shape nuevo correctamente. Una sola línea, cero regresión posible.

**Alternativas**:
- Cambiar solo el ternario para aceptar `data.files` sin encadenar `?? []`: queda inconsistente con el patrón del módulo.
- Crear un helper compartido `parseListingResponse(data)`: sobre-ingeniería para un patrón de 2 líneas repetido 3 veces. Si el cuarto call-site aparece, refactorizamos; hoy YAGNI.

### D2. Modal abre en `currentFolder`, no en root

**Decisión**: en `openCopyMoveModal` (línea 1137-1147):
```js
this.copyMoveDestFolderId = this.currentFolder;        // antes: null
this.copyMoveDestLabel = this.currentFolderName || 'Raíz';
this.copyMoveBreadcrumb = [];                          // sin trail de largo recorrido
await this.loadCopyMoveFolders(this.currentFolder);      // antes: null
```

**Por qué**: mover un archivo a una carpeta hermana (mismo padre) es el caso más común. Mostrar "Aquí" como destino default elimina 3 navegaciones innecesarias por caso. El label "Raíz" se preserva cuando el usuario está realmente en la raíz del storage (porque `currentFolderName` es null ahí).

**Alternativas**:
- Arrastrar el `breadcrumbs` completo al modal: ruidoso a partir de 2 niveles, no escala.
- Mantener el modal en root y agregar un breadcrumb de "estás" teaja: funcional pero requiere lógica adicional para excluir routes de la raíz.

### D3. Botón "↑ Subir" usando `/files/{id}`

**Decisión**: nuevo método `navigateCopyMoveUp()` que:
1. Hace `fetch('/files/' + this.copyMoveDestFolderId)` con `Accept: application/json` y `X-Requested-With: XMLHttpRequest`.
2. Lee `data.parent_id` (null si llegamos a la raíz del storage).
3. Actualiza `copyMoveDestFolderId`, `copyMoveDestLabel`, y recarga las carpetas hijas con `loadCopyMoveFolders(newParentId)`.
4. Si `newParentId === null`, deshabilita el botón "↑ Subir" (chip visual).

**Por qué**: `/files/{id}` ya existe y devuelve `{id, name, parent_id, ...}` en un solo SELECT. Construir el breadcrumb padre en frontend obligaría a una query recursiva; usar el endpoint existente es 1 round-trip y mantiene el contrato simple.

**Alternativas**:
- Endpoint nuevo `GET /files/{id}/parent`: sobre-ingeniería. La info está disponible con `/files/{id}`.
- Que el frontend calcule el padre a partir del breadcrumb cacheado: requiere que el breadcrumb se mantenga en sync exacto con la jerarquía real; propenso a drift.
- Eliminar el botón "↑ Subir" y obligar al usuario a hacer click en una carpeta del nivel actual + cerrar y reabrir el modal: peor que la decisión.

### D5. Backend no se toca

El guardrail `isDescendantOf()` en `FileController::move:1126` ya cubre el caso "mover carpeta dentro de sí misma o de un descendiente". El disable visual en UI (línea 3553, hoy solo bloquea el id directo del source) es hint cosmético; el backend es hard guard. No agregamos filtro recursivo en frontend porque:
- Para carpetas con miles de descendientes, filtrar recursivamente en JS es lento.
- El backend ya rechaza el caso con HTTP 422 + mensaje claro, que el modal muestra vía `copyMoveError`.

## Risks / Trade-offs

**[R1] Cache de folder_listing puede tener shape stale** → el cache key es `folder_listing:{storageId}:{pid}:{gen}:{page}` y contiene `{files, breadcrumbs, pagination}` desde hace semanas (cambios `mis-archivos-fs-first`). El fix es backward-compatible: `Array.isArray(data)` cae al primer branch si alguna vez el cache tiene un array directo. Mitigación innecesaria.

**[R2] `currentFolder` puede ser null cuando el usuario navega en una URL compartida o por deep-link** → el modal abre en root con el mismo comportamiento que antes del fix. No hay regresión.

**[R3] El botón "↑ Subir" hace 1 fetch extra por cada nivel** → aceptable porque /usercontrole/{id} es una query O(1) por PK. Para mover a 5 niveles arriba son 5 fetches de <10ms cada uno. No vale la pena un endpoint batch.

**[R4] El disable de descendientes en frontend es solo cosmético (no bloquea recursivamente)** → aceptable. El guardrail backend es la fuente de verdad. Si el operador lo intenta, recibe HTTP 422 con mensaje claro. Documentado en `specs/file-copy-move/spec.md`.

## Migration Plan

No hay migración. No hay schema change. No hay backend change. Solo se modifica un Blade template:

1. Editar `resources/views/files/index.blade.php` (3 hunks pequeños).
2. Recargar PHP-FPM: `systemctl reload php-fpm` (equivalente: `/etc/init.d/php-fpm-84 reload`). La vista es Blade compilada en cache por PHP-FPM; un reload la libera.
3. Hard-refresh del navegador del operador (Ctrl+Shift+R) para invalidar el JS de Alpine cacheado.

**Rollback**: `git revert <commit>`. No hay estado persistente que limpiar. No hay cache que invalidar manualmente.

## Open Questions

Ninguna. Las decisiones D1-D5 son completas y las preguntas D1-D4 del diseño original fueron resueltas durante la conversación.