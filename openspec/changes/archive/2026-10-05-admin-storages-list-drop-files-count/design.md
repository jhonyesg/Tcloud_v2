## Context

`/admin/storages` es una pantalla de administración con 196 filas de `storage_providers` (registro pequeño, ~40 KB). Su listado AJAX se construye con `StorageProvider::withCount('files')`, que añade una columna derivada "Archivos" pagando 196 subconsultas `count(*)` contra `files` (1.367.803 filas / 2.5 GB). El operador confirmó que esa columna no aporta en el listado y es la causa de la lentitud y de los cuelgues percibidos (cache miss + stampede). Este change elimina el conteo del listado y lo resuelve bajo demanda solo en el modal de borrado, que sí lo necesita.

## Goals / Non-Goals

**Goals**
- `GET /admin/storages` (AJAX) deja de tocar la tabla `files`.
- El listado carga en `< 100 ms` caliente / `< 250 ms` frío (hoy 625 ms–1.8 s).
- El modal de borrado conserva su aviso con número exacto, obtenido bajo demanda para 1 storage.
- El listado nunca aparece vacío en silencio: loading explícito + toast de error si el fetch falla.

**Non-Goals**
- No retirar la caché Redis del listado ni sus invalidaciones.
- No implementar contador mantenido en `storage_providers`.
- No paginar server-side.
- No recortar campos de `config` en el payload.

## Decisions

### D1. El conteo `files_count` sale del contrato del listado

**Decisión**: `computeAdminStorages()` pasa de `StorageProvider::withCount('files')->get()->toArray()` a `StorageProvider::get()->toArray()`.

**Razón**: el conteo es la única razón por la que el listado toca `files`. Medición (2026-10-05): leer los 196 storages solos cuesta 44 ms vía Eloquent; agregar el conteo lo lleva a 625 ms en caliente y 1.78 s en frío. El coste no está en el tamaño del registro sino en 196 barridos sobre 1,36M filas.

**Alternativa descartada**: computar el conteo en un solo `GROUP BY` (`SELECT storage_provider_id, count(*) ... GROUP BY 1`) y mapear en PHP. Aún paga ~250-330 ms sobre `files` (el planner hace 625k heap fetches por bloat; ver `EXPLAIN ANALYZE`). Sigue tocando la tabla grande en cada carga. Descartada por innecesaria: el dato no aporta al listado.

### D2. El modal de borrado resuelve el conteo bajo demanda

**Decisión**: el conteo exacto se pide a `GET /admin/storages/{id}` (endpoint `show()`, que **conserva** `withCount('files')`) en el momento de abrir el modal de borrado, no al listar. Estado Alpine nuevo `deletingStorageCount` (`null` = calculando, número = disponible).

**Razón**: el modal sí necesita el número — es un aviso destructivo y el borrado elimina archivos de verdad (FK `files_storage_provider_id_fkey ON DELETE CASCADE`). Contar 1 storage cuesta ~40 ms (el más grande, 247k archivos) y 1 ms si está vacío; contar 196 cuesta 625 ms. Se paga solo cuando el operador va a borrar.

**Flujo**:
```
clic "Eliminar" en fila
   │
   ▼
openDeleteModal(storage)
   ├─ deletingStorage = storage
   ├─ deletingStorageCount = null            → "Calculando archivos asociados…"
   ├─ showDeleteModal = true
   └─ apiFetch('/admin/storages/' + id)      (GET, Accept: json)
            │
            ├─ res.ok → deletingStorageCount = payload.files_count ?? 0
            └─ !res.ok → deletingStorageCount = -1   → fallback textual
```

**Alternativa descartada**: quitar el número y dejar texto genérico siempre (opción ii de la exploración). Se conserva el número porque el aviso es de una acción destructiva; el coste de resolverlo por storage es despreciable.

### D3. La UI de la columna "Archivos" se elimina por completo

**Decisión**: en `app/resources/views/admin/storages.blade.php`:
- Tabla escritorio: quitar el `<th>` ordenable "Archivos" (líneas ~569-574) y el `<td x-text="storage.files_count">` (línea ~599).
- Tarjeta móvil: la línea `"ID: X · N archivos"` pasa a `"ID: X"` (línea ~502).
- `sortBy` por defecto sigue siendo `{ column: 'id', direction: 'asc' }` (ya lo es); no queda referencia a `'files_count'`.

**Razón**: si el dato no se muestra, no debe computarse ni listarse como columna ordenable. Consistencia UI ↔ contrato.

### D4. Estado de carga del listado (cierre del síntoma "queda en blanco")

**Decisión**: añadir a `loadStorages()` un flag `loadingStorages` (inicial `true`, `false` en `finally`) y un toast rojo si `!res.ok`, con el detalle `err.error || err.message || ('HTTP ' + res.status)`. Mientras `loadingStorages === true`, las vistas muestran un estado "Cargando storages…" en vez del mensaje "No hay storages configurados".

**Razón**: hoy `loadStorages()` sólo hace `console.error` en fallo (línea 92) y el estado inicial `storages: []` (línea 7) hace que el mensaje "No hay storages configurados" se pinte mientras el request está en vuelo. Esa es exactamente la captura del operador. El flag + toast elimina el vacío silencioso.

**Nota**: esta vista ya tiene un helper `toast = { show, message, success }` + `showToast()` (líneas 76-79), así que no se introduce infraestructura nueva.

### D5. La caché se conserva, pero el comentario se corrige

**Decisión**: `admin:storages:index` (TTL 60 s) se mantiene tal cual. El comentario de `StorageProviderController.php:19-21` se actualiza para no afirmar que el caché es necesario por el `withCount`.

**Razón**: con el listado en ~44 ms la caché ya no es un requisito de rendimiento, pero retirarla (y sus 3 puntos de invalidación) amplía el blast radius sin beneficio. El comentario actual miente tras el fix y debe reflejar la realidad.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| (R1) El modal muestra "Calculando…" un instante antes del número | El botón "Eliminar" nunca se bloquea por el conteo; si el fetch falla o tarda, cae a texto genérico. El conteo del storage más grande tarda ~40 ms. |
| (R2) Consumidor externo del payload esperando `files_count` | Verificado por grep: el único consumidor es `admin/storages.blade.php`. `show()` conserva el atributo para el modal. |
| (R3) `loadingStorages` mal gestionado deja spinner pegado | `try/finally` en `loadStorages()` garantiza el reset; mismo patrón que `deletingStorageId` ya existente. |
| (R4) `show()` conserva `withCount` y alguien lo vuelve a usar en bucle | El propio comentario del controlador documentará que `show()` es de un-solo-storage por diseño. |

## Migration Plan

Sin migración de BD. Deploy = cambio de código + recarga de vistas. Tras el deploy:

```bash
# Invalidar la caché vieja (payload con files_count) para que nadie reciba el shape anterior:
redis-cli -a 'Clouding2026!Redis' -n 1 DEL 'tcloud_tcloud_cache_admin:storages:index'
systemctl reload php84-php-fpm   # liberar opcode cache de la vista Blade y del controlador
```

**Rollback**: `git revert <commit>` + `systemctl reload php84-php-fpm`. La caché se repuebla sola.

## Open Questions

- Ninguna bloqueante. El operador ya decidió eliminar el estado/columna "Archivos" y conservar el número en el modal bajo demanda.
