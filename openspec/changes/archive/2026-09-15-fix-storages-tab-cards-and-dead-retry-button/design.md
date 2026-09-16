# Design: fix-storages-tab-cards-and-dead-retry-button

## Context

Post-`simplify-api-transcriptor-to-storage-and-config` (commit `1c9d690`, 2026-09-15), el módulo quedó con dos pestañas (Storages + Configuración), pero la simplificación dejó:

1. Las 4 tarjetas del header del tab Storages (`index.blade.php:141-162`), dos de ellas alimentadas por `pendientesTotal()` / `listosTotal()` (JS, líneas 1383-1388), que suman el funnel diario de `StorageFunnelService::countsForScope()`.
2. Un formulario huérfano en `index.blade.php:169-179` que hace `POST /ia/api-transcriptor/retry-batch` — la ruta y el método `ApiTranscriptorController::retryBatch()` fueron eliminados en el mismo commit; la UI no se tocó → botón roto (404 en pestaña nueva).
3. La columna "Snapshot transcriptor" (líneas 384-421) que ya hace `fetch('/ia/api-transcriptor/storages/{id}/snapshot')` por fila y renderiza `pending_count` + delta + `remote_queue_queued`. El payload incluye `error_count` e `inflight_count` que hoy se descartan.

Restricciones: no hay migración posible en este change (no se necesita), el cron semanal de retry (`routes/console.php:111`) queda intacto, y la tabla de storages (columnas Pendientes/Listos por storage, orden, badge ⚠) es comportamiento especificado en `transcriptor-storage-funnel` y no se toca.

## Goals / Non-Goals

**Goals**
- Header honesto: 2 tarjetas de volumen + contador de errores contextual.
- Eliminar todo elemento muerto o engañoso del tab Storages.
- Superficie de "errores del día" visible sin clics, con costo cero (dato ya capturado).

**Non-Goals**
- Revivir el botón de reintento en la UI (la revisión del operador es visual; el reintento masivo ya es cron + CLI).
- Retirar `StorageFunnelService` (lo consume la tabla; ver Open Questions).
- Tocar el upstream o el pipeline de transcripción.

## Decisions

### D1: Columna "Errores" vive en la celda del snapshot, no como columna nueva

**Elegido**: renderizar los errores dentro de la columna "Snapshot transcriptor" existente (tercera línea de la celda: `3 errores` en rojo cuando `error_count > 0`) + contador global en el header.

**Alternativa descartada**: columna `<th>` nueva. Razón: la celda del snapshot YA hace el fetch por fila; una columna nueva necesitaría estado Alpine sincronizado por separado y duplicaría el lugar de lectura. El operador mira la celda como "mini-pantalla de estado del storage" (pendientes, cola remota, errores) — un solo ojo de pájaro por fila.

**Detalle de render** (misma celda):
```
┌─────────────────────────┐
│ 87 pendientes           │   ← ya existe
│ +12 vs 15min            │   ← ya existe (delta)
│ cola remota: 42         │   ← ya existe
│ ⛔ 3 errores            │   ← NUEVO (solo si > 0, rojo)
└─────────────────────────┘
```

### D2: Contador global "Errores hoy" suma los snapshots cargados en la página visible

**Elegido**: JS del lado cliente sumando `snapshot.current.error_count` de los componentes `x-data` por fila ya montados (los storages habilitados de la página actual). Se actualiza reactivamente cuando cada fetch por fila resuelve.

**Alternativas descartadas**:
- *Query agregada en servidor*: paginar todos los storages para sumar es caro e innecesario (el snapshot corre cada 15 min; el operador decide si revisa página por página).
- *Endpoint nuevo agregado*: viola el objetivo "cero endpoints nuevos".

Ubicación: junto al contador `X habilitado(s) de Y` del header de la tabla (`index.blade.php:194`), como texto discreto con color condicional.

### D3: El botón "Reintentar fallidos" se elimina sin reemplazo en UI

**Elegido**: `form` completo fuera (`index.blade.php:169-179`).

**Alternativa descartada**: revivir la ruta + `execBackground` como antes del simplify. Razón: duplicaría el cron semanal existente, el commit de simplificación ya decidió que esa superficie no pertenece al módulo, y el operador reportó no saber qué hacía — señal de que la UI no comunica; el cron semanal + CLI sí tienen dueño claro.

### D4: Las tarjetas "Pendientes hoy" / "Listos hoy" se borran junto con sus helpers JS

Se eliminan los dos `<div>` de tarjetas y las funciones `pendientesTotal()` / `listosTotal()` (líneas 1383-1388). `cantidadTotal()` / `cantidadPonderada()` se conservan. El grid pasa de `lg:grid-cols-4` a `lg:grid-cols-2` con las dos tarjetas de volumen.

## Risks / Trade-offs

- [Snapshot con hasta 15 min de retraso] → Aceptado: es la misma latencia que ya acepta la columna Snapshot; el dato es "errores del día según el último snapshot", y la celda lo deja claro con el timestamp del snapshot.
- [Contador de errores solo cubre la página visible] → Aceptado y especificado en la spec; el patrón de paginación ±2 del módulo hace visible ~25-100 storages por página y el fetch por fila es el mecanismo existente.
- [El badge ⚠ de la columna Pendientes (spec `transcriptor-storage-funnel`) depende de errores 24h] → No se toca: sigue funcionando con los datos del funnel de la tabla, sin relación con la nueva columna de errores (snapshot vs funnel son ventanas distintas, ambas correctas en su contexto).
- [Cliente (rol `cliente`) ve la celda del snapshot] → La privacidad ya está resuelta en `storageSnapshot()`: para cliente el payload solo expone `captured_at`, `pending_count`, `inflight_count` — sin `error_count`. El render nuevo usa `snapshot.current.error_count`, que para cliente es `undefined` → la línea de errores no se renderiza (guard `x-if` con `!= null && > 0`).

## Migration Plan

1. Deploy del Blade modificado (sin migración, sin workers, sin rutas).
2. Recarga PHP-FPM (`systemctl reload php84-php-fpm`) para liberar opcode cache de la vista compilada.
3. Rollback: `git revert <commit>` + reload FPM. No hay estado persistente ni cachés que invalidar (los snapshots siguen capturándose igual antes/después).

## Open Questions

Ninguna bloqueante. Nota futura (fuera de scope): `StorageFunnelService` queda con un solo consumidor (las columnas de la tabla); si el operador algún día elimina esas columnas, el servicio y su spec se retiran en el change correspondiente.