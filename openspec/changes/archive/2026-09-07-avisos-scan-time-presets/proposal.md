# Proposal: Presets de ventana temporal para "Escanear ahora" en Avisos Inteligentes

## Why

El disparo manual "Escanear ahora" del módulo `/ia/avisos-inteligentes` solo ofrece rango de fechas (días completos) e "Histórico completo". El admin no puede lanzar un escaneo rápido de "las últimas 8 horas" o "el último día" sin construir fechas a mano, y el estimado mostrado en el modal de confirmación ignora los filtros elegidos (siempre estima la ventana global), lo que desorienta al confirmar corridas acotadas.

## What Changes

- Presets de ventana temporal en el modal de confirmación del escaneo: Últimas 8 horas, Último día, Últimos 3 días, Últimos 7 días, Hoy y Rango personalizado.
- El rango personalizado pasa a aceptar fecha **y hora** (`datetime-local`); el backend (`AvisosScanService::selectCandidates`/`estimate`) respeta el componente horario cuando `from`/`to` vienen con hora en vez de truncar a día completo.
- El estimado de pendientes del modal se calcula **con los filtros elegidos** (storage + ventana), no con la ventana global.
- Exclusividad mutua en UI: elegir un preset o rango desactiva "Histórico completo" y viceversa.
- El disparo manual registra la ventana efectiva en la corrida (auditable en el historial).
- **Runner en background**: el escaneo manual corre como proceso setsid (`avisos:scan-run`) con progreso en cache y polling por runId — sobrevive recargas, cierre de navegador y cortes de red. La UI se re-adjunta automáticamente al recargar.
- **Drenaje terminable**: cada tanda excluye los IDs ya intentados (`excludeIds`/`attemptedIds`), eliminando el bucle de re-escaneo en rangos/presets.

## Capabilities

### New Capabilities

- `avisos-scan-time-presets`: presets de ventana temporal (8h, 24h, 3d, 7d, hoy, personalizado con hora) para el disparo manual "Escanear ahora", estimado honesto por filtros y exclusividad con el modo histórico completo.

### Modified Capabilities

- `avisos-scan-configuration`: el requisito de "Disparo manual acotado" pasa de rango de fechas (días) a rango con hora opcional + presets; el estimado mostrado antes de confirmar debe reflejar los filtros aplicados.

## Impact

- **Backend**: `AvisosInteligentesController@runScan` / `scanStatus` (validación de `from`/`to` como datetime, `preset` opcional); `AvisosScanService` (resolución de presets a rangos, respeto de hora en `from`/`to`).
- **Frontend**: `app/resources/views/ia/avisos-inteligentes/index.blade.php` (radio de presets en el modal, datetime-local, estimado con filtros, exclusividad noWindow).
- **Sin migración**: no se agregan columnas; `avisos_scan_runs.error` ya acepta JSON y no se usa para esto — la ventana queda en el log `avisos.scan.run` y en `options` de corrida si existe.
- **Rutas**: sin cambios (mismos endpoints `/ia/avisos-inteligentes/scan*`).

## Non-goals

- No cambia el comportamiento del cron automático (su ventana sigue siendo `windowHours` y JAMÁS usa noWindow).
- No añade presets ni filtros al flujo de correcciones retroactivas (cambio `corrections-apply-retroactive-scope-controls`).
- No modifica la cadencia de envío de correos ni el dispatcher.
- No añade zona horaria configurable: se usa la zona de la app.