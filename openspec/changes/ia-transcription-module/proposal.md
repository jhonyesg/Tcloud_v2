## Why

TCloud almacena ~1M grabaciones de TV/Radio pero no puede buscar contenido hablado dentro de ellas, ni corregir errores sistemáticos del transcriptor ASR (nombres propios, jerga, nombres de canal). La plataforma externa de transcripción (`192.168.0.138:9000`) ya está operativa; lo que falta es el orquestador en TCloud que envíe archivos, reciba resultados, los guarde vinculados a la grabación, alerte por email a clientes cuando aparezcan sus keywords registradas, y mantenga un diccionario de correcciones moderado cliente→admin para limpiar el texto de futuras transcripciones.

## What Changes

- **Nuevo grupo en sidebar** "IA" (admin only), posicionado después de Administración y antes de Multimedia
- **Nueva entrada en Multimedia** "Mis Avisos" (cliente), visible solo si el admin activó el módulo para el usuario
- **6 modelos nuevos**: `Transcription`, `TranscriptionSegment`, `Keyword`, `KeywordMatch`, `AlertLog`, `Correction`
- **1 pivot nuevo**: `user_alerts_inteligentes` (user_id, enabled, keywords_quota, emails_quota, timestamps)
- **1 columna adicional** en `transcription_segments`: `text_raw` (preserva el original del ASR; `text` queda como texto "vivo" usado en búsqueda y matching)
- **2 columnas nuevas** en `storage_providers`: `transcription_enabled`, `transcription_priority`
- **Nuevas rutas admin** bajo `/ia/api-transcriptor`, `/ia/avisos-inteligentes`, `/ia/correcciones`
- **Nueva ruta cliente** `/mis-avisos`
- **Webhook** `POST /webhooks/transcription` validado por `TRANSCRIPTOR_WEBHOOK_TOKEN` (llamado desde LAN por el transcriptor)
- **Console commands**: `transcription:scan-new` (cada 2-3 min), `transcription:scan-stale` (cada 5 min), `transcription:apply-corrections` (manual retroactivo)
- **Queue job** `ConvertAndTranscribeJob` (ffmpeg → Opus 64k → POST API)
- **Migraciones requeridas**: 7 archivos nuevos

## Capabilities

### New Capabilities

- `transcription-api-orchestrator`: Escaneo automático de archivos válidos en storages habilitados; conversión a Opus 64k mono 16kHz vía ffmpeg; envío a `POST /v1/transcribe` con callback URL apuntando a la IP LAN de TCloud; recepción del webhook; parseo del SRT en segmentos con timestamps; almacenamiento canónico del SRT y metadatos del job vinculados al `File` original
- `keyword-alerts`: Admin asigna el módulo a usuarios con cupo de keywords (100, 200, etc.) y tope de correos; matching automático contra segmentos de cada nueva transcripción; envío de emails vía `Modules/Correo` indicando grabación, minuto exacto y keyword matcheada; log de alertas enviadas
- `client-alerts-view`: Usuario gestiona sus propias keywords dentro del cupo asignado por admin; ve el historial de sus alertas recibidas con link al File original y minuto del match; puede proponer correcciones sobre los textos que el transcriptor escribió mal
- `transcription-corrections`: Diccionario de pares (wrong→correct) moderado por admin. Cliente propone (status=pending) sobre texto que vio mal; admin aprueba/rechaza o agrega directo (status=approved). Las correcciones activas se aplican al texto vivo (`text`) de los `TranscriptionSegment` al parsear SRT nuevo. Comando retroactivo `transcription:apply-corrections` reaplica a todas las transcripciones existentes. Mejora la calidad y fiabilidad de la información evitando entradas basura (todo pasa por filtro humano del admin)

### Modified Capabilities

- `spa-navigation`: Añadir entrada "Mis Avisos" en el bloque Multimedia del sidebar con visibilidad condicional por pivot `user_alerts_inteligentes` (paralelo al patrón de Sites Externos, línea 339 de `app.blade.php`)

## Impact

- **Modelos nuevos**: `Transcription`, `TranscriptionSegment`, `Keyword`, `KeywordMatch`, `AlertLog`, `Correction`
- **Controladores nuevos**: `Ia\ApiTranscriptorController`, `Ia\AvisosInteligentesController`, `Ia\CorreccionesController` (admin), `MisAvisosController` (cliente), `TranscriptionCallbackController` (webhook)
- **Servicios nuevos**: `TranscriptorApiClient`, `AudioConverter` (wrapper ffmpeg), `SrtParser`, `KeywordMatcher`, `AlertDispatcher`, `CorrectionService`
- **Rutas nuevas**: `/ia/api-transcriptor/*`, `/ia/avisos-inteligentes/*`, `/ia/correcciones/*`, `/mis-avisos`, `/webhooks/transcription`
- **Job**: `ConvertAndTranscribeJob` (en cola Redis, default Laravel)
- **Migraciones**: `2026_07_0X_create_transcriptions_table`, `..._create_transcription_segments_table` (con text_raw + text), `..._create_keywords_table`, `..._create_user_keyword_table`, `..._create_keyword_matches_table`, `..._create_alert_logs_table`, `..._create_user_alerts_inteligentes_table`, `..._create_corrections_table`, `..._add_transcription_fields_to_storage_providers_table`
- **Sidebar**: `layouts/app.blade.php` (nuevo bloque "IA" + entrada condicional "Mis Avisos")
- **`.env`**: `TRANSCRIPTOR_BASE_URL`, `TRANSCRIPTOR_API_KEY` (vacío si la API no usa auth), `TRANSCRIPTOR_WEBHOOK_TOKEN`, `TRANSCRIPTOR_CALLBACK_HOST` (IP LAN de TCloud)
- **No afecta**: `File` (FK desde Transcription), `User` (solo relaciones nuevas), `StorageProvider` (solo 2 columnas)

## Non-goals

- No se incluye UI para ver el SRT completo en el cliente (solo alerta + minuto del match + link al File)
- No se re-transcriben archivos fallidos automáticamente (queda expuesto para retry manual desde M1)
- No se soportan múltiples idiomas (solo `es` por ahora, hardcoded)
- No se implementa búsqueda fuzzy ni por similitud semántica (solo match exacto o de frase registrado)
- No se incluye UI para que el cliente modifique sus correos de aviso en esta versión (queda para el admin en M2)
- No se modifica el comportamiento del bloque "Medios Puntuales" en Multimedia (queda intacto)
- No se notifica al cliente cuando admin rechaza una corrección propuesta (la propuesta simplemente no aparece activa)
- No se re-envían emails cuando se aplica una corrección retroactiva (los AlertLog históricos preservan el texto con el que se enviaron)
- No se diferencian correcciones por usuario (es un diccionario global compartido entre todos los clientes; el flujo de aprobación por admin evita ruido entre usuarios)