# Proposal — Sub-ventana de configuración del escaneo de menciones (avisos inteligentes)

## Why

Hoy las menciones solo se generan en el pipeline: `TranscriptionProcessor` llama a `KeywordMatcher::run()` cuando una transcripción termina, si su columna `generate_alerts` está activa. No existe ningún escaneo automático independiente: las transcripciones terminadas antes de la Fase 1 (backfill), las re-procesadas sin trigger, las que fallaron al escanear o las terminadas por vías alternas quedan **sin avisos para siempre**, y el admin no tiene ninguna herramienta para verlo ni gobernarlo: ni frecuencia, ni alcance, ni estado de las corridas, ni un "escanear ahora".

El módulo admin `/ia/avisos-inteligentes` ya gestiona habilitación de clientes, límites y gestores/storages habilitados — eso queda como está. Lo que falta es gobernar **el motor** que produce los avisos.

## What Changes

- **Comando `avisos:scan`**: escaneo idempotente y acotado de transcripciones `done` con `generate_alerts=true` que aún no tengan hits (LEFT JOIN anti-hits), reutilizando `KeywordMatcher::run()` (UNIQUE triple + insertOrIgnore ya garantizan idempotencia). Opciones: `--storage=`, `--transcription=`, `--from/--to`, `--limit`, `--force` (re-escanear aunque existan hits, borrando los previos de esa transcripción), `--dry-run`.
- **Cron automático**: `Schedule` con tick fijo (cada 5 min) que consulta el intervalo configurado (`SystemSetting avisos_scan_interval_minutes`) y decide si toca correr — mismo patrón que `sessions_cleanup_interval_minutes` (Laravel cachea la expresión cron al boot, la frecuencia real vive en settings). `withoutOverlapping` + guardarraíles.
- **Sub-ventana "Escaneo"** en `/ia/avisos-inteligentes` (nueva pestaña junto a la lista de clientes):
  - Escaneo automático ON/OFF + intervalo en minutos (default 30, mínimo 5).
  - Ventana de re-escaneo: solo transcripciones terminadas en las últimas N horas sin hits (default 72h) — evita backfills masivos de históricos sin querer.
  - Botón **"Escanear ahora"** (sincrónico con resumen, o encolado si el lote es grande) con opciones puntuales (storage / rango de fechas / forzar).
  - **Tabla de corridas** (`avisos_scan_runs`): fecha, origen (cron/manual), transcripciones escaneadas, hits nuevos, duración, estado y error si hubo.
- **Guardarraíles**: consultas acotadas por lote (nada de agregaciones masivas sobre el histórico), log de eventos `avisos.scan.*`, el escaneo no envía correos (la entrega sigue siendo trabajo exclusivo de `avisos:deliver-alerts`).

## Capabilities

### New Capabilities
- `avisos-scan-configuration`: gobernanza del escaneo de menciones — comando idempotente, cron automático con intervalo configurable y sub-ventana admin de configuración con estado de corridas y disparo manual.

## Impact

- Backend: nuevo `ScanMentionsCommand` (`avisos:scan`), nueva migración `avisos_scan_runs`, métodos en `MentionsSearchService` NO (la costura de visor queda intacta) — el escaneo vive junto al matcher: nueva clase `AvisosScanService` en `app/app/Services/Ia/`, rutas admin en `AvisosInteligentesController` (show/settings/scan), `routes/console.php` (tick del cron).
- Frontend: `ia/avisos-inteligentes/index.blade.php` (nueva pestaña "Escaneo" con Alpine, mismo stack).
- Settings: `SystemSetting` keys `avisos_scan_enabled`, `avisos_scan_interval_minutes`, `avisos_scan_window_hours`.
- **No toca**: la entrega (`avisos:deliver-alerts`), el matching (`KeywordMatcher`), la costura del visor (`MentionsSearchService`), el pipeline existente (su disparo se mantiene; el scan evita duplicar por idempotencia).
- Riesgo controlado: lote por corrida acotado (`--limit`, default 50 transcripciones) y ventana de re-escaneo por defecto de 72h — sin barridos completos del histórico salvo orden explícita del admin (`--from` muy atrás o `--force`).

## Non-goals

- No se cambia el motor de matching ni la dedup de keywords (universal-matching-engine).
- No se toca la entrega de avisos (cadencia, techo, correos — ya opera por minuto).
- No se corrige el mime invertido de archivos (otro dominio).
- No se implementan alertas por medios distintos de los existentes (email).
- No se migra el escaneo a colas/works distribuidos: un comando CLI acotado por corrida es suficiente a la escala actual.
