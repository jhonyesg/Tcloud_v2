## Context

El listado server-side de shares (`GET /shares`) ya implementa ordenamiento dinámico con whitelist, validación `in:` y tiebreaker `shares.id DESC` para garantizar paginación determinista. La UI Alpine (`sharesApp()` en `shares/index.blade.php`) ya tiene un `toggleSort(field)` genérico y los headers clickeables existentes (`name`, `expires_at`, `accesses`, `created_at`) se renderizan como `<button @click="toggleSort('...')">`. El único gap es extender esa cadena a `permission` y `status`.

Hoy:
- `ShareController::SORT_FIELDS` (`ShareController.php:21-27`) lista `name, created_at, expires_at, accesses, size`.
- `validateListRequest` (`:339-361`) restringe `sort` al mismo conjunto.
- `shareQuery` (`:432-440`) traduce cada caso con `orderBy` o subconsulta escalar sobre `files`.
- El frontend envía `sort` y `direction` en `URLSearchParams` (`shares/index.blade.php:297`).

## Goals / Non-Goals

**Goals:**
- Habilitar orden por `permission` y `status` en backend y frontend sin cambiar la API pública.
- Mantener orden lógico por nivel/estado, no alfabético.
- Preservar el tiebreaker existente para paginación.

**Non-Goals:**
- No agregar índices nuevos (no se justifica por la cardinalidad esperada por usuario).
- No crear columna persistida `expiry_status` (no es viable con columna generada porque depende de `now()`).
- No cambiar el contrato JSON del endpoint (`data` / `meta` / `counters` intactos).
- No tocar el orden por defecto (`created_at desc`).

## Decisions

### 1. Orden por `permission` usa `CASE` por nivel, no por cadena

`permissions` se almacena como string `read | write | upload | full`. El orden alfabético pondría `full` antes que `read` en ascendente, lo cual es contraintuitivo. Reutilizamos el mapeo conceptual que ya existe en `ShareController::getPermissionLevel()` (`:582-589`) — `read=1`, `write/upload=2`, `full=3` — pero lo emitimos como `ORDER BY CASE` en `shareQuery` para que el plan sea estable y no dependa de funciones no inmutables.

```sql
ORDER BY
  CASE shares.permissions
    WHEN 'read' THEN 1
    WHEN 'write' THEN 2
    WHEN 'upload' THEN 2
    WHEN 'full' THEN 3
    ELSE 4
  END {asc|desc},
  shares.id DESC
```

Alternativas consideradas:
- Subconsulta escalar `getPermissionLevel()`: rechazada porque esa función es PHP, no SQL.
- Columna persistida con nivel: rechazada por costo de migración y duplicación.

### 2. Orden por `status` usa `CASE` derivado de `expires_at`

`expiry_status` no es columna de BD (ver `sharePayload()` `ShareController.php:508`). Ordenamos directamente desde `expires_at`:

```sql
ORDER BY
  CASE
    WHEN shares.expires_at IS NULL THEN 1  -- Sin vencimiento
    WHEN shares.expires_at <  now() THEN 3 -- Expirado
    ELSE 2                                  -- Activo
  END {asc|desc},
  shares.id DESC
```

Mapeo ascendente: `Sin vencimiento (1) → Activo (2) → Expirado (3)` (default más natural). Descendente invierte el orden. El planner puede seguir aprovechando el índice `idx_shares_owner_expires_at` para el `WHERE`, aunque no para el `ORDER BY` derivado.

Alternativas consideradas:
- Generar columna `expiry_status`: descartada (`now()` no es inmutable).
- Ordenar por `expires_at` crudo: descartada (mezcla nulls con futuros, no agrupa por estado).

### 3. Whitelist de `sort` se amplía, no se relaja

`validateListRequest` añade `'permission'` y `'status'` al `in:` de `sort`. Mantener la whitelist es crítico para la garantía `422` ante campos no permitidos (escenario "Ordenamiento inválido" en `share-management/spec.md:20-22`). No se permite wildcard.

### 4. UI: extender el patrón existente sin nuevos componentes

`shares/index.blade.php:149-150` (headers `Permiso` y `Estado`) se convierten en `<button @click="toggleSort('permission'|'status')">` con el mismo `<i :class="sortIcon(...)">` que usan los demás headers clickeables. `toggleSort` y `sortIcon` no requieren cambios: ya soportan cualquier `field` arbitrario. El objeto Alpine `sort` por defecto (`'created_at'`) y `direction` (`'desc'`) no cambian — solo se amplían los valores aceptados.

## Risks / Trade-offs

- [Riesgo: orden por `permission`/`status` no usa índice] → Mitigación: el `WHERE` reduce a filas del usuario antes del sort; el set esperado es pequeño. Si se vuelve cuello de botella, índice compuesto `(created_by, permissions)` en migración `CONCURRENTLY` futura.
- [Riesgo: contadores del header se ven afectados al cambiar orden] → Mitigación: `shareCounters()` (`ShareController.php:476-491`) usa clones con `reorder()`, así que ignora el orden actual.
- [Riesgo: paginación inestable si el ORDER BY derivado no es determinista] → Mitigación: el tiebreaker `orderBy('shares.id','desc')` se conserva tras el `orderByRaw` para que dos filas con el mismo CASE compartan orden estable.
- [Riesgo: validación 422 si un cliente envía `permission` antes de desplegar el backend] → Mitigación: el frontend y backend se despliegan juntos; durante el rollout transitorio la UI simplemente verá un 422, comportamiento ya cubierto por la spec.
- [Trade-off: `orderByRaw` es ligeramente menos legible] → Aceptado; la alternativa SQL bind-eada con `?` por rama es más verbosa y no aporta seguridad (los valores son enum validados).

## Migration Plan

Sin migración. Deploy atómico:
1. Backend: editar `ShareController.php` (whitelist, validación, `match`).
2. Frontend: editar `shares/index.blade.php` (headers Permiso/Estado clickeables).
3. Spec: actualizar `openspec/specs/share-management/spec.md` para reflejar los dos nuevos campos.

Rollback: revertir el commit. La whitelist previa sigue aceptando los campos antiguos sin colisión.

## Open Questions

Ninguna. Las decisiones están alineadas con los patrones existentes del módulo y la spec se actualizará como delta.
