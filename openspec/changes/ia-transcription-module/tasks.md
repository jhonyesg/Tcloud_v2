## 1. Configuración base

- [ ] 1.1 Añadir `TRANSCRIPTOR_BASE_URL`, `TRANSCRIPTOR_API_KEY` (vacío por defecto), `TRANSCRIPTOR_WEBHOOK_TOKEN`, `TRANSCRIPTOR_CALLBACK_HOST` a `.env.example` con valores de ejemplo
- [ ] 1.2 Publicar valores en `config/services.php` o crear `config/transcriptor.php` con bloque `transcriptor.base_url|api_key|webhook_token|callback_host`
- [ ] 1.3 Generar `TRANSCRIPTOR_WEBHOOK_TOKEN` con `openssl rand -hex 32` y escribirlo en `.env` (no commitear `.env`, solo `.env.example` con placeholder)
- [ ] 1.4 Verificar `which ffmpeg` en el servidor de despliegue; documentar versión mínima (>= 4.0 por soporte libopus)

## 2. Base de datos — migraciones requeridas

- [ ] 2.1 **[migración]** Crear `2026_07_xx_create_transcriptions_table` (id, file_id unique FK, job_id, node_url, state, language, srt_content text, duration_seconds, word_count, started_at, finished_at, error_message, retries, timestamps) con índice en `(state, created_at)`
- [ ] 2.2 **[migración]** Crear `2026_07_xx_create_transcription_segments_table` (id, transcription_id FK cascade, segment_index, start_seconds, end_seconds, **text_raw** (SRT original, inmutable), **text** (vivo, corregido por el diccionario), created_at, updated_at) con índice GIN trigram en `text` y FK index en `transcription_id`
- [ ] 2.3 **[migración]** Crear `2026_07_xx_create_keywords_table` (id, text, normalized unique, timestamps) — `normalized` lowercase + sin acentos vía `Str::ascii()`
- [ ] 2.4 **[migración]** Crear `2026_07_xx_create_user_keyword_table` (pivot) con UNIQUE(user_id, keyword_id) y FK cascade
- [ ] 2.5 **[migración]** Crear `2026_07_xx_create_keyword_matches_table` (id, transcription_id FK, keyword_id FK, segment_id FK, user_id FK, snippet, matched_at) con índice en `(user_id, matched_at DESC)`
- [ ] 2.6 **[migración]** Crear `2026_07_xx_create_alert_logs_table` (id, user_id FK, email_to, transcription_id FK, match_count, subject, status, error_message, sent_at)
- [ ] 2.7 **[migración]** Crear `2026_07_xx_create_user_alerts_inteligentes_table` (id, user_id FK unique, emails jsonb, enabled bool default true, keywords_quota int, emails_quota int, timestamps)
- [ ] 2.8 **[migración]** Crear `2026_07_0X_create_corrections_table` (id, wrong_text, correct_text, wrong_normalized varchar(500), status enum `pending|approved|rejected|merged` default pending, proposed_by FK users, approved_by FK users nullable, approved_at timestamp nullable, rejected_reason text nullable, source_segment_id FK transcription_segments nullable, applies_count int default 0, timestamps) con índice parcial único `(wrong_normalized) WHERE status='approved'` y FK index en `proposed_by`/`approved_by`
- [ ] 2.9 **[migración]** Crear `2026_07_xx_add_transcription_fields_to_storage_providers_table` — añadir `transcription_enabled boolean default false` y `transcription_priority integer default 0`
- [ ] 2.10 Correr migraciones en dev: `php artisan migrate` y verificar esquema en PG con `\d+ transcriptions`, `\d+ corrections`, `\d+ transcription_segments`

## 3. Modelos Eloquent

- [ ] 3.1 Crear `App\Models\Transcription` con `$fillable`, `$casts` (state enum, booleans), relación `file()`, `segments()`, `nodeUrl`, scopes `pending()`, `recent($days)`
- [ ] 3.2 Crear `App\Models\TranscriptionSegment` con `$fillable` (incluye `text_raw` y `text`), relación `transcription()`, método `getStartLabel()` que devuelve `HH:MM:SS`
- [ ] 3.3 Crear `App\Models\Keyword` con `$fillable`, `$casts`, scope `matchingText($normalized)`, accessor `getNormalizedAttribute()` que aplica `Str::lower(Str::ascii($this->text))`
- [ ] 3.4 Crear `App\Models\KeywordMatch` con `$fillable` y relaciones `transcription()`, `keyword()`, `segment()`, `user()`
- [ ] 3.5 Crear `App\Models\AlertLog` con `$fillable` y relaciones `user()`, `transcription()`
- [ ] 3.6 Crear `App\Models\UserAlertsInteligente` con `$fillable`, `$casts`, método `hasCupo()`, relación `user()`
- [ ] 3.7 Crear `App\Models\Correction` con `$fillable`, `$casts` (status enum, applies_count int), relaciones `proposedBy()`, `approvedBy()`, `sourceSegment()`, scopes `pending()`, `approved()`, método estático `applyToText(string $text): string` (aplica todas las approved en orden de longitud DESC)
- [ ] 3.8 Añadir relaciones en `App\Models\User`: `transcriptions()` (hasMany through matches), `userKeywords()` (belongsToMany Keyword vía user_keyword), `keywordMatches()`, `alertsInteligente()` (hasOne), `alertLogs()`, `correctionsProposed()`, `correctionsApproved()`
- [ ] 3.9 Añadir en `App\Models\StorageProvider`: scope `transcriptionEnabled()` y accessor de las 2 columnas nuevas

## 4. Servicios backend

- [ ] 4.1 Crear `App\Services\Ia\TranscriptorApiClient` con `submit(File, string $opusPath, string $callbackUrl): array`, `getSrt(string $jobId, string $nodeUrl): string`, `getJob(string $jobId, string $nodeUrl): array`, `getStats(): array` — usa `Http` facade de Laravel con timeout 60s en submit y 30s en GET
- [ ] 4.2 Crear `App\Services\Ia\AudioConverter` con `toOpus64k(string $src, string $dst): void` que invoca ffmpeg vía `Process::run` con args del design §D1, lanza `RuntimeException` si exit != 0 o si `Process::which('ffmpeg')` es null
- [ ] 4.3 Crear `App\Services\Ia\SrtParser` con `parse(string $content): array<{index,start_seconds,end_seconds,text}>` — regex SRT estándar, trunca segmentos >500 chars con warning
- [ ] 4.4 Crear `App\Services\Ia\KeywordMatcher` con `run(Transcription $t): int` (devuelve nº alertas enviadas) — busca contra `text` (corregido) NO contra `text_raw`. Implementa coalescing y dedupe por (transcription_id, user_id) según spec §keyword-alerts
- [ ] 4.5 Crear `App\Services\Ia\AlertDispatcher` con `send(User $user, Transcription $t, array $matches): void` — usa `App\Modules\Correo` para enviar y persiste `AlertLog`
- [ ] 4.6 Crear `App\Services\Ia\CorrectionService` con `applyToSegments(array $segments): void` (setea `text` desde `text_raw` aplicando correcciones approved), `propose(User $by, string $wrong, string $correct, ?int $segmentId): Correction` (crea pending), `approve(Correction $c, User $by): Correction` (transición + upsert approved), `reject(Correction $c, User $by, ?string $reason): Correction`, `applyRetroactively(callable $progressCb): int` (corre todas las transcripciones en chunks)
- [ ] 4.7 Registrar bindings en `AppServiceProvider::register()` si se prefiere DI con interfaces (opcional, primeras versiones pueden resolver por `app()` directamente)

## 5. Jobs y Console Commands

- [ ] 5.1 Crear `App\Jobs\ConvertAndTranscribeJob` con `public function __construct(public int $fileId)`, `handle()` invoca AudioConverter, crea `Transcription`, llama TranscriptorApiClient::submit; `tries=1`, `timeout=600`, `failOnException=true`
- [ ] 5.2 Crear `App\Console\Commands\ScanNewRecordingsCommand` (firma `transcription:scan-new`) que ejecuta el scanner según design §D2 y despacha `ConvertAndTranscribeJob` por cada File elegible
- [ ] 5.3 Crear `App\Console\Commands\ScanStaleJobsCommand` (firma `transcription:scan-stale`) que corre cada 5 min y aplica el polling de respaldo del spec §transcription-api-orchestrator
- [ ] 5.4 Crear `App\Console\Commands\ApplyCorrectionsCommand` (firma `transcription:apply-corrections`) con opciones `--dry-run` y `--chunk=500`. Llama `CorrectionService::applyRetroactively()` y reporta progreso
- [ ] 5.5 Registrar los 3 commands en `app/Console/Kernel.php` con `everyTwoMinutes()` (`scan-new`), `everyFiveMinutes()` (`scan-stale`); `apply-corrections` queda solo manual
- [ ] 5.6 Asegurar que `app/Console/Kernel.php` ya está protegido por `* * * * * php artisan schedule:run` en crontab del servidor

## 6. Webhook y rutas backend

- [ ] 6.1 Crear `App\Http\Controllers\Ia\TranscriptionCallbackController` con `handle(Request $r)` que valida `X-Webhook-Token`, busca Transcription por job_id, descarga SRT, parsea, crea segments (`text_raw=raw`, `text=corrected` vía `CorrectionService::applyToSegments`), actualiza state, dispara KeywordMatcher
- [ ] 6.2 Registrar ruta `POST /webhooks/transcription` SIN middleware de auth (validado internamente por token) en `routes/web.php`
- [ ] 6.3 Añadir excepción CSRF para la ruta del webhook: excluir en `app/Http/Middleware/VerifyCsrfToken.php` si aplica
- [ ] 6.4 Crear `App\Http\Controllers\Ia\ApiTranscriptorController` (admin) con `index()` (listado paginado jobs), `show($id)` (detalle), `retry($id)` (re-encolar)
- [ ] 6.5 Crear `App\Http\Controllers\Ia\AvisosInteligentesController` (admin) con `index()` (lista usuarios), `updateUser($userId)`, `storeEmail($userId)`, `destroyEmail($userId, $email)`, `storeKeyword($userId)`, `destroyKeyword($userId, $keywordId)`, `testEmail($userId, $email)`, `matches($userId)`
- [ ] 6.6 Crear `App\Http\Controllers\Ia\CorreccionesController` (admin) con `index()` (lista approved + count pending), `pending()` (cola de pendientes), `approve($id)` (POST → approved), `reject($id)` (POST con `rejected_reason`), `store()` (admin agrega directo, status=approved auto), `destroy($id)`
- [ ] 6.7 Crear `App\Http\Controllers\MisAvisosController` (cliente) con middleware adicional `EnsureMisAvisosEnabled` que retorna 403 si no tiene fila activa en `user_alerts_inteligentes`
- [ ] 6.8 Implementar `EnsureMisavisosEnabled` middleware que chequea `session('user')->alertsInteligente?->enabled === true`
- [ ] 6.9 Añadir `CorreccionPropuestaController` (cliente) con `store()` (POST: crea pending) y `mine()` (lista del cliente con estado)
- [ ] 6.10 Registrar todas las rutas agrupadas: bajo `Route::prefix('ia')->middleware(['auth','admin'])` las de M1+M2+M4; bajo `Route::middleware(['auth','misavisos'])` (o usar `EnsureMisavisosEnabled` inline) las de M3 + propuestas de corrección

## 7. Frontend — Sidebar y view composer

- [ ] 7.1 En `app/Providers/AppServiceProvider.php::boot()`, ampliar el view composer existente para inyectar `$misAvisosEnabled` (boolean por usuario) y `$correctionsPendingCount` (int, badge del admin)
- [ ] 7.2 En `resources/views/layouts/app.blade.php`, justo después del `</div>` que cierra el grupo "Administración" y antes de la sección "Multimedia", insertar el nuevo grupo "IA" con icono `fas fa-brain` (admin only, envuelto en `@if(session('user_role') === 'admin')`)
- [ ] 7.3 Añadir 3 nav-links dentro del bloque IA: "API Transcriptor" (`/ia/api-transcriptor`, icono `fas fa-microphone-alt`), "Avisos Inteligentes" (`/ia/avisos-inteligentes`, icono `fas fa-bell`), "Correcciones" (`/ia/correcciones`, icono `fas fa-spell-check`, badge con `$correctionsPendingCount` si >0)
- [ ] 7.4 Dentro del bloque Multimedia existente (después del link "Medios Puntuales" línea 334-337), agregar `@if($misAvisosEnabled)` que renderiza el nav-link "Mis Avisos" apuntando a `/mis-avisos` con icono `fas fa-search` y clase `data-nav-path="/mis-avisos"`
- [ ] 7.5 Reusar mismo estilo de nav-link existente (clases `nav-link flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg transition-colors ...`)
- [ ] 7.6 Verificar active state: aplicar lógica de highlight en "Mis Avisos" cuando `request()->is('mis-avisos')` y en "Correcciones" cuando `request()->is('ia/correcciones*')`

## 8. Frontend — Vistas admin M1 (API Transcriptor)

- [ ] 8.1 Crear `resources/views/ia/api-transcriptor/index.blade.php` extendiendo `layouts.app` con tabla paginada de jobs (filename, file_id, state con badge de color, duration, started_at, finished_at)
- [ ] 8.2 Implementar modal Alpine.js para listar storages con `transcription_enabled` toggleable (acción POST `/ia/api-transcriptor/storages/{id}/toggle`)
- [ ] 8.3 Implementar banner superior con health de la API (`/health` del transcriptor) y contadores por state (`/api/stats`)
- [ ] 8.4 Crear `resources/views/ia/api-transcriptor/job-detail.blade.php` con: state badge, file info, botón "Reintentar" (visible solo si state ∈ error|dead), botón "Eliminar Transcription" (DELETE), y `<pre>` con el SRT
- [ ] 8.5 Añadir formulario para asignar `transcription_priority` por storage (modal Alpine)
- [ ] 8.6 Implementar estado vacío: si no hay storages con `transcription_enabled`, mostrar mensaje con CTA "Activa storages para empezar"

## 9. Frontend — Vistas admin M2 (Avisos Inteligentes)

- [ ] 9.1 Crear `resources/views/ia/avisos-inteligentes/index.blade.php` con tabla de usuarios: username, email, módulo activo (toggle on/off), keywords_count/emails_count vs cupo, badge "alertas últimas 24h"
- [ ] 9.2 Implementar modal Alpine para asignar el módulo (form con `keywords_quota`, `emails_quota`, switch `enabled`)
- [ ] 9.3 Crear `resources/views/ia/avisos-inteligentes/user-detail.blade.php` con: header con cupo, sección "Correos" (lista + form agregar + botón eliminar), sección "Keywords" (lista + form agregar validando cupo), sección "Matches" (tabla paginada con link al File original)
- [ ] 9.4 Implementar endpoint JS (fetch) para agregar/eliminar keywords sin recargar la página (UX consistente con otros módulos admin)
- [ ] 9.5 Botón "Email de prueba" por cada correo registrado del usuario
- [ ] 9.6 Filtros y búsqueda en la tabla de usuarios (por username, email, estado del módulo)

## 10. Frontend — Vista cliente M3 (Mis Avisos)

- [ ] 10.1 Crear `resources/views/mis-avisos/index.blade.php` extendiendo `layouts.app`, protegida por `EnsureMisAvisosEnabled` middleware
- [ ] 10.2 Sección "Mis palabras clave" con tabla (palabra, fecha, botón eliminar) + form inline para agregar (input text + botón "Agregar"), deshabilitado si cupo lleno con tooltip
- [ ] 10.3 Contador visible "X / Y" palabras usadas; refresh incremental tras agregar/eliminar sin recargar página
- [ ] 10.4 Sección "Alertas recibidas" con tabla paginada: fecha, filename (link al preview del File), minuto (`HH:MM:SS`), keyword, snippet, **botón "✏ Reportar corrección" debajo del snippet** (abre modal con `wrong_text` prellenado)
- [ ] 10.5 Si el usuario no tiene alertas previas, mostrar empty state "Aún no se han detectado coincidencias. Las alertas llegan por email en tiempo real."
- [ ] 10.6 Si el cupo es 0, mostrar mensaje "El administrador aún no te ha activado este módulo."
- [ ] 10.7 Crear `resources/views/mis-avisos/_correction-modal.blade.php` (partial Alpine.js) con campos `wrong_text` (readonly, viene del segmento) y `correct_text` (input), botón "Enviar para revisión"
- [ ] 10.8 Crear `resources/views/mis-avisos/corrections-mine.blade.php` mostrando el historial de propuestas del cliente con badges de estado (pending/approved/rejected) y motivo de rechazo si aplica

## 11. Frontend — Vista admin M4 (Correcciones)

- [ ] 11.1 Crear `resources/views/ia/correcciones/index.blade.php` con 2 pestañas (Alpine `x-show`): "Pendientes (N)" y "Aprobadas"
- [ ] 11.2 Tab "Pendientes": tabla con `wrong → correct`, proponente (username), fecha, segment_id_origen (link al match), botones "Aprobar" y "Rechazar"
- [ ] 11.3 Modal de rechazo con campo opcional `rejected_reason`
- [ ] 11.4 Tab "Aprobadas": tabla con `wrong → correct`, proponente original, aprobador, applies_count, fecha; columnas ordenables
- [ ] 11.5 Botón "Nueva corrección" en la parte superior que abre modal con campos `wrong` + `correct` (admin agrega directo, status=approved auto)
- [ ] 11.6 Botón "Re-aplicar a todas las transcripciones" que llama al comando `transcription:apply-corrections` (vía Process::run o directamente invocando `CorrectionService::applyRetroactively`) con confirmación previa mostrando cuántos segments se verían afectados
- [ ] 11.7 Mostrar modal con resultado: "X segmentos actualizados en Y segundos"

## 12. Verificación

- [ ] 12.1 Smoke test admin: login admin, entrar a `/ia/api-transcriptor`, habilitar 1 storage con `transcription_priority=10`
- [ ] 12.2 Esperar 2-3 min y verificar que aparezca un job en `queued` → pasar a `processing` → en webhook recibir SRT y poblarse `transcription_segments` (verificar que `text_raw` y `text` arrancan iguales)
- [ ] 12.3 Asignar "Avisos Inteligentes" a un usuario de prueba con 5 keywords y 1 correo
- [ ] 12.4 Verificar envío de email al correo registrado tras una nueva transcripción que matchee
- [ ] 12.5 Login con el usuario cliente: confirmar entrada "Mis Avisos" en sidebar, agregar/eliminar keywords, ver historial
- [ ] 12.6 Cliente reporta una corrección (modal sobre snippet de un match) → verificar fila en `corrections` con `status=pending`
- [ ] 12.7 Admin ve badge "Pendientes (1)" en sidebar de IA, abre `/ia/correcciones`, aprueba → verificar `status=approved` y `applies_count=0` inicialmente
- [ ] 12.8 Admin corre `php artisan transcription:apply-corrections --dry-run` → revisar conteo de cambios sin tocar BD
- [ ] 12.9 Admin corre `php artisan transcription:apply-corrections` (real) → verificar que `applies_count` se incrementa y los segmentos ahora tienen `text != text_raw` donde aplique
- [ ] 12.10 Admin agrega corrección directa en `/ia/correcciones` → verificar que entra como `status=approved` con `proposed_by=admin.id`
- [ ] 12.11 Verificar rollback limpio: `php artisan migrate:rollback --step=7` (7 migraciones: 2.1 a 2.9) y comprobar que el state del sidebar vuelve al estado previo