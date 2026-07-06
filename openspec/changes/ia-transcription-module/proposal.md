## Why

TCloud almacena ~1M grabaciones de TV/Radio pero no puede buscar contenido hablado dentro de ellas. La plataforma externa de transcripción ASR (`192.168.0.138:9000`) ya está operativa; lo que falta es el orquestador en TCloud que envíe archivos, reciba resultados, los guarde vinculados a la grabación, y alerte por email a clientes cuando aparezcan sus keywords registradas.

## What Changes

- **Nuevo grupo en sidebar** "IA" (admin only), posicionado después de Administración y antes de Multimedia
- **Nueva entrada en Multimedia** "Mis Avisos" (cliente), visible solo si el admin activó el módulo para el usuario
- **5 modelos nuevos**: `Transcription`, `TranscriptionSegment`, `Keyword`, `KeywordMatch`, `AlertLog`
- **1 pivot nuevo**: `user_alerts_inteligentes` (user_id, enabled, keywords_quota, emails_quota, timestamps)
- **2 columnas nuevas** en `storage_providers`: `transcription_enabled`, `transcription_priority`
- **Nuevas rutas admin** bajo `/ia/api-transcriptor` y `/ia/avisos-inteligentes`
- **Nueva ruta cliente** `/mis-avisos`
- **Webhook** `POST /webhooks/transcription` validado por `TRANSCRIPTOR_WEBHOOK_TOKEN`
- **Console command** `transcription:scan-new` (corre cada 2-3 min)
- **Queue job** `ConvertAndTranscribeJob` (ffmpeg → Opus 64k → POST API)
- **Migraciones requeridas**: 6 archivos nuevos

## Capabilities

### New Capabilities

- `transcription-api-orchestrator`: Escaneo automático de archivos válidos en storages habilitados; conversión a Opus 64k mono 16kHz vía ffmpeg; envío a `POST /v1/transcribe` con callback URL; recepción del webhook; parseo del SRT en segmentos con timestamps; almacenamiento canónico del SRT y metadatos del job vinculados al `File` original
- `keyword-alerts`: Admin asigna el módulo a usuarios con cupo de keywords (100, 200, etc.) y tope de correos; matching automático contra segmentos de cada nueva transcripción; envío de emails vía `Modules/Correo` indicando grabación, minuto exacto y keyword matcheada; log de alertas enviadas
- `client-alerts-view`: Usuario gestiona sus propias keywords dentro del cupo asignado por admin; ve el historial de sus alertas recibidas con link al File original y minuto del match

### Modified Capabilities

- `spa-navigation`: Añadir entrada "Mis Avisos" en el bloque Multimedia del sidebar con visibilidad condicional por pivot `user_alerts_inteligentes` (paralelo al patrón de Sites Externos, línea 339 de `app.blade.php`)

## Impact

- **Modelos nuevos**: `Transcription`, `TranscriptionSegment`, `Keyword`, `KeywordMatch`, `AlertLog`
- **Controladores nuevos**: `Ia\ApiTranscriptorController`, `Ia\AvisosInteligentesController` (admin), `MisAvisosController`, `TranscriptionCallbackController` (webhook)
- **Servicios nuevos**: `TranscriptorApiClient`, `AudioConverter` (wrapper ffmpeg), `SrtParser`, `KeywordMatcher`, `AlertDispatcher`
- **Rutas nuevas**: `/ia/api-transcriptor/*`, `/ia/avisos-inteligentes/*`, `/mis-avisos`, `/webhooks/transcription`
- **Job**: `ConvertAndTranscribeJob` (en cola Redis, default Laravel)
- **Migraciones**: `2026_07_0X_create_transcriptions_table`, `..._create_transcription_segments_table`, `..._create_keywords_table`, `..._create_keyword_matches_table`, `..._create_alert_logs_table`, `..._create_user_alerts_inteligentes_table`, `..._add_transcription_fields_to_storage_providers_table`
- **Sidebar**: `layouts/app.blade.php` (nuevo bloque "IA" + entrada condicional "Mis Avisos")
- **`.env`**: `TRANSCRIPTOR_BASE_URL`, `TRANSCRIPTOR_WEBHOOK_TOKEN`
- **No afecta**: `File` (FK desde Transcription), `User` (solo relaciones nuevas), `StorageProvider` (solo 2 columnas)

## Non-goals

- No se incluye UI para ver el SRT completo en el cliente (solo alerta + minuto del match + link al File)
- No se re-transcriben archivos fallidos automáticamente (queda expuesto para retry manual desde M1)
- No se soportan múltiples idiomas (solo `es` por ahora, hardcoded)
- No se implementa búsqueda fuzzy ni por similitud semántica (solo match exacto o de frase registrado)
- No se incluye UI para que el cliente modifique sus correos de aviso en esta versión (queda para el admin en M2)
- No se modifica el comportamiento del bloque "Medios Puntuales" en Multimedia (queda intacto)
