# Design: Presets de ventana temporal para "Escanear ahora"

## Context

El disparo manual vive en `AvisosInteligentesController@runScan` → `AvisosScanService::run/selectCandidates/estimate`. Hoy `from`/`to` se validan como `date` y se truncan a día completo (`Carbon::parse($from)->startOfDay()`). El frontend (Blade + Alpine) tiene una fila de filtros inline y un modal de confirmación con fase `confirm` cuyo estimado se pide **sin** filtros (`runScan()` llama a `/scan` sin body). El modo "Histórico completo" (`noWindow`) ya existe y el backend lo bloquea para cron.

## Goals / Non-Goals

**Goals:**
- Resolución de presets server-side (fuente única de verdad), no en el cliente.
- Respeto de componente horario en `from`/`to` con compatibilidad hacia atrás (solo fecha = día completo).
- Estimado del modal con los filtros reales de la corrida.
- Exclusividad UI noWindow ↔ presets sin poder enviar ambos.

**Non-Goals:**
- No tocar el cron automático, el cursor de drenaje ni `windowHours` del automático.
- No cambiar el matcher ni el pipeline de entregas.

## Decisions

**D1 — Presets resueltos en el backend.** El cliente envía `preset` (`8h|24h|3d|7d|today`) o `from`/`to` explícitos; el servicio traduce preset → `from`/`to` concretos (`now()->subHours(...)` / `today()->startOfDay()`). Alternativa considerada: calcular el rango en JS y enviar `from`/`to` — rechazada porque divide la semántica y depende del reloj del cliente; con `preset` el log audita la intención, no solo el rango.

**D2 — Granularidad horaria con detección de formato.** `from`/`to` pasan a validarse como `date` + regex de hora opcional (`Y-m-d( H:i(:s)?)?`). Si traen hora → `Carbon::parse` directo; si no → semántica previa (`startOfDay`/`endOfDay`). Esto mantiene compatibilidad con `ScanMentionsCommand` y corridas existentes.

**D3 — Estimado con filtros.** La fase `confirm` del modal llama a `GET /scan?preset=...&storageId=...&from=...&to=...&no_window=...` y el controller `scanStatus` reutiliza esos parámetros en `estimate()`. Se elimina el uso del estimado global salvo que no haya filtros.

**D4 — Exclusividad en UI.** `noWindow` y `preset` son estados excluyentes en el componente Alpine: activar uno desmarca/deshabilita el otro; `runScanSequence()` nunca envía `noWindow` junto a rango (guard también en el backend: si llegan ambos, `noWindow` pierde ante rango explícito y se loguea).

**D5 — Ventana efectiva auditable.** El servicio añade al log `avisos.scan.run` las claves `preset` y `range` (`from`→`to` resueltos, ISO-8601). No se agregan columnas a `avisos_scan_runs` (migración evitada; `error` es para errores, no metadatos).

## Risks / Trade-offs

- [Rango 8h/24h + lote 50 → el usuario encadena muchas tandas] → ya existe el drenaje sucesivo en `runScanSequence()` con botón detener; sin cambio.
- [`estimate()` con rango sin límite sobre histórico enorme] → la consulta ya está acotada por `finished_at` con índice; los presets acotan más que el histórico completo actual.
- [Zona horaria: medianoche "Hoy" depende de `app.timezone`] → comportamiento estándar de la app; documentado, sin config nueva.
- [Cliente viejo envía solo `from` fecha] → D2 garantiza semántica idéntica a la actual.

## Migration Plan

Deploy directo, sin migraciones ni cambios de rutas. Rollback = revert del commit; los parámetros nuevos (`preset`, hora en from/to) son aditivos y el backend viejo los ignoraría de forma segura (validation nullable).

## Open Questions

Ninguna. Las decisiones de UI (presets en el modal, preset "Hoy", datetime-local) fueron aprobadas por el usuario en la conversación.