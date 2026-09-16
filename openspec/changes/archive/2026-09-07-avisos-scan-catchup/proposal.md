# Proposal — Modo "Poner al día" para el escaneo de menciones

## Why

El escaneo configurado (avisos-scan-configuration) cubre la ventana de re-escaneo (default 72h) y el pipeline cubre lo nuevo. Quedan **333,692 transcripciones históricas** terminadas sin hits (el backfill de años con `generate_alerts` invertido, ya reparado). No hay una vía cómoda para ponerlas al día: la sub-ventana solo alcanza la ventana en horas y el "Escanear ahora" manual requiere encadenar ~6,700 tandas a clic.

## What Changes

- **Modo "Poner al día" (catch-up)** en la sub-ventana "Escaneo":
  - Checkbox "Incluir histórico completo (sin límite de ventana)" en el panel "Escanear ahora".
  - El modal de progreso ya acumula tandas — el modo catch-up simplemente drena SIN ventana (from = null) hasta agotar los candidatos, con botón Detener en todo momento.
- **Aviso de magnitud**: antes de iniciar, el modal muestra los pendientes totales estimados y avisa que es un proceso largo que puede detenerse y retomarse (idempotente: lo ya escaneado se salta).
- **CLI igual**: `avisos:scan` ya soporta `--from`/`--to` sin ventana — el modo catch-up en CLI es `avisos:scan --from=2000-01-01 --ignore-schedule` (documentar; el servicio ignora la ventana cuando `from` es explícito).
- Ajuste menor del servicio: cuando `opts['noWindow']` (o `from` explícito anterior a la ventana), `selectCandidates` omite el filtro de ventana — ya funciona así hoy con `from` explícito (la ventana solo aplica si no hay `from`); solo se documenta y se protege el caso "sin from y sin ventana" para el cron (nunca catch-up automático: el cron SIEMPRE respeta su ventana).

## Capabilities

### New Capabilities
- `avisos-scan-catchup`: drenado controlado del histórico de transcripciones sin avisos — disparo manual con progreso acumulado, sin límite de ventana, detener/retomar seguro.

## Impact

- `AvisosScanService`: parámetro `noWindow` + guardia (el cron automático jamás ejecuta catch-up).
- Sub-ventana: checkbox + copys de magnitud; el modal de progreso ya soporta el bucle acumulado (sin cambios de backend de corrida).
- Riesgo: carga sostenida del servidor durante horas → mitigado por el ritmo natural (~50 transcripciones/4s por tanda ≈ 750/hora; 333k ≈ 18 días continuos — el admin decide hasta dónde; puede detener y retomar). El orden ASC drena lo más antiguo primero.
- No toca: motor, entrega, costura del visor.

## Non-goals

- No cambia la ventana del cron automático (sigue su setting).
- No paraleliza corridas ni usa colas.
- No modifica keywords ni motor de matching.