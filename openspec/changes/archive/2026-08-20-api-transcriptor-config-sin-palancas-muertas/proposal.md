# Change: La configuración de API Transcriptor sin palancas muertas

## Why

Auditamos las 43 claves de la pestaña Configuración siguiendo quién las lee de verdad. Tres no cumplían lo que la pantalla prometía:

- **`ai_coherence_threshold`**: no lo lee nadie. El pase de coherencia selecciona segmentos con su propia heurística de mezcla EN+ES y un corte fijo de `0.5` en `TranscriptionCoherencePass::apply()`. El deslizador mostraba 0,4 y moverlo no cambiaba nada.
- **`ai_coherence_model`**: no lo lee nadie. El modelo sale siempre de `llm-correction.model`.
- **`ui_max_parallel_sends`, `ui_batch_max`, `scan_batch`**: la vista los pintaba desde `config()`, así que un override guardado en la pantalla solo surtía efecto tras abrir esa misma pestaña. Como el servidor **sí** clampea con el override (`processBatch` usa `ui_batch_max`), bajar el tope y no entrar ahí dejaba al navegador pidiendo lotes que el servidor truncaba en silencio — justo el fallo que el esquema decía haber resuelto.

Además, seis claves del esquema no existían en `config/transcriptor.php`. Funcionaban (el accessor cae al default del esquema) pero la columna "Origen" informaba "archivo" sobre un archivo que no las tenía, y el test que vigila esa correspondencia llevaba tiempo en rojo.

Un panel con palancas que no mueven nada es peor que no tenerlas: invita a diagnosticar con ellas.

## What Changes

- **Se retiran** `ai_coherence_threshold` y `ai_coherence_model` del esquema, y con ello de la pantalla. El umbral pasa a ser la constante `MOSTLY_EN_SCORE` junto a la heurística que lo acompaña, documentada como decisión de código, no de UI.
- **Se retira** el método muerto `transcribeFile(f)` del componente Alpine (alias de `openProgress` sin ningún binding desde hace tiempo).
- **Los topes de interfaz** viajan en `indexData()` como `ui_limits` desde la capa de settings; la vista deja de leer `config()`.
- **Se declaran en `config/transcriptor.php`** las seis claves que solo vivían en el esquema, con su mismo default.

No requiere migración. No hay overrides guardados de las claves retiradas, así que ninguna fila de `system_settings` queda huérfana.

## Non-goals

- **No** se cablea el umbral del pase de coherencia a la UI. Hoy corre con 0.5 efectivo y el panel decía 0,4: activarlo cambiaría qué segmentos van al LLM, y eso es un cambio de comportamiento que merece su propio análisis, no un efecto colateral de una limpieza.
- **No** se toca el resto del panel: las otras 40 claves tienen consumidor real y quedan como están.
- **No** se barren otros módulos: la auditoría se limita a API Transcriptor.

## Capabilities

### Modified Capabilities
- `transcription-api-orchestrator`: la pantalla de configuración del módulo solo expone ajustes con consumidor real, y sus topes de interfaz se sirven desde la capa de settings.

  (El delta va aquí y no en `transcription-orchestrator-runtime` porque ese spec está escrito en un formato antiguo —requisitos numerados, sin bloques `Scenario`— que el validador de OpenSpec no puede reconstruir. Se le repararon las cabeceras de sección, pero convertir sus 12 requisitos excede esta limpieza.)

## Impact

- `app/Services/Ia/TranscriptorSettings.php` — dos claves fuera del `SCHEMA`.
- `app/Services/Ia/TranscriptionCoherencePass.php` — constante `MOSTLY_EN_SCORE` y docblock al día.
- `app/Http/Controllers/Ia/ApiTranscriptorController.php` — `indexData()` publica `ui_limits`.
- `resources/views/ia/api-transcriptor/index.blade.php` — consume `ui_limits`; fuera el alias muerto.
- `config/transcriptor.php` — seis claves declaradas.
- Efecto medible: `TranscriptorSettingsTest` pasa de 1 fallo a 56/56 en verde.
