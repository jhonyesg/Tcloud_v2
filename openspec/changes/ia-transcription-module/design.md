## Context

TCloud v2 almacena ~1M archivos de grabación (TV/Radio) en `StorageProvider`s locales y S3. La organización de medios (Caracol, NTN24, etc.) opera un servidor externo de transcripción ASR (`192.168.0.138:9000`) que recibe archivos por HTTP, devuelve SRT y notifica por webhook. Hasta hoy TCloud no integra esa API: las grabaciones viven como cajas negras que nadie puede buscar por contenido hablado.

El módulo "IA" construye el puente: orquesta el envío de archivos a la API externa, persiste los resultados vinculados al `File` original, y vigila el contenido transcrito para alertar a clientes por email cuando aparecen sus keywords registradas.

El layout actual (`layouts/app.blade.php`) tiene 4 grupos en el sidebar: Navegación (todos), Administración (admin), Multimedia (todos, contiene Medios Puntuales), Sitios Externos (todos, dinámico por asignación). El patrón de "asignación admin → visibilidad condicional en sidebar" ya existe en `Sitios Externos` (línea 339 `@if(isset($userExternalSites) && $userExternalSites->count() > 0)`) y se reusa para el cliente de este módulo.

## Goals / Non-Goals

**Goals:**
- Admin habilita/deshabilita la transcripción por `StorageProvider` (`transcription_enabled`)
- Scanner automático cada 2-3 min detecta archivos válidos nuevos → encola `ConvertAndTranscribeJob`
- Conversión a Opus 64k mono 16kHz vía ffmpeg local (~28× menos ancho de banda, validado por doc de la API)
- `ConvertAndTranscribeJob` envía `POST /v1/transcribe` con `callback_url` apuntando a TCloud
- Webhook `POST /webhooks/transcription` (validado por `TRANSCRIPTOR_WEBHOOK_TOKEN`) recibe `state` y `srt_url`
- Handler descarga SRT, parsea en `TranscriptionSegment`s con timestamps, guarda versión canónica
- Admin asigna módulo "Avisos Inteligentes" a usuarios con cupo de keywords (100/200) y tope de correos
- Por cada nueva `Transcription`: matching keywords por usuario → email vía `Modules/Correo` con File link + minuto del match
- Log de cada alerta enviada en `AlertLog` (auditoría, anti-duplicados)
- Cliente (M3): ve y gestiona SUS propias keywords dentro del cupo; ve historial de SUS alertas
- Sidebar: nuevo grupo "IA" (admin) entre Administración y Multimedia; nueva entrada condicional "Mis Avisos" dentro de Multimedia

**Non-Goals:**
- UI cliente para ver SRT completo (solo alerta con link al File original)
- Re-transcripción automática de archivos fallidos (queda retry manual desde M1)
- Multi-idioma (solo `es` por ahora)
- Búsqueda fuzzy / similarity (solo match exacto o de frase)
- UI para que el cliente modifique SUS correos (queda en M2 admin en esta versión)
- Modificación del bloque "Medios Puntuales" del sidebar (queda intacto)
- SSO ni comunicación entre la plataforma y el transcriptor más allá de `Bearer TRANSCRIPTOR_API_KEY` y webhook con `TRANSCRIPTOR_WEBHOOK_TOKEN`

## Decisions

### D1: Where ffmpeg runs
**Decisión:** En el servidor TCloud vía `Symfony\Component\Process\Process` invocando el binario `ffmpeg` instalado.
**Alternativas:** (a) servicio separado deffmpeg-as-a-service; (b) server-side transcoding en la API externa.
**Razón:** TCloud ya tiene ffmpeg disponible (el `MediaClipController` lo usa para cortes). Lo más simple y menos infra nueva. La API externa no soporta pre-procesado porque su `lang_fix` opera sobre el audio ya decodificado.

### D2: Cómo dispara el envío: cron vs. event listener
**Decisión:** Console command `transcription:scan-new` ejecutado cada 2-3 min por cron (Laravel scheduler).
**Alternativas:** (a) listener en `FileController::store` / `Grabador` callback que dispare al guardar; (b) webhook entrante desde el grabador externo.
**Razón:** Simple, robusto ante fallos (reintenta cada 2-3 min), desacoplado del flujo de subida. `Grabador` no expone hook al guardar grabaciones — solo crea entradas vía `Canal`. El command escanea `storage_providers WHERE transcription_enabled = true` y toma los 5 archivos más recientes sin transcripción.

### D3: Transfer mode — async webhook + polling fallback
**Decisión:** Webhook PRIMARIO (`callback_url` en cada POST) + polling de respaldo cada 5 min para jobs `processing` con `created + 30 min` que aún no recibieron callback.
**Alternativas:** (a) solo polling; (b) solo webhook.
**Razón:** La doc de la API externa es explícita: "el webhook es fire-and-forget, no se reintenta; se recomienda polling de respaldo". Si TCloud cae 10 min durante un callback, el polling recupera el job. Sin polling, el SRT quedaría perdido tras 7 días de retención efímera.

### D4: Persistencia del SRT — en BD vs. en disco
**Decisión:** SRT guardado EN BD como `text` en `transcriptions.srt_content`, junto con segmentos normalizados en `transcription_segments`.
**Alternativas:** SRT en disco + ruta en BD; SRT solo en disco.
**Razón:** El SRT es data canónica (la doc lo dice textual: "la información canónica debe vivir en el orquestador"). Tenerlo en BD permite queries sin I/O de filesystem y simplifica la búsqueda por minuto. Tamaño: 15 min SRT ≈ 3 KB, incluso 1M archivos serían ~3 GB — manejable en Postgres.

### D5: Modelo de keywords — globales con pivot vs. por usuario aislado
**Decisión:** Globales con pivot `keywords` (texto único global) — tabla `keywords(id, text, normalized)` reusable + tabla `user_keyword` pivot (user_id, keyword_id, created_at).
**Alternativas:** `user_keywords(user_id, text)` aislado por usuario.
**Razón:** Globales con pivot normaliza el texto (lowercase, sin acentos) y deduplica "presidente" entre 5 clientes. El matching corre una vez por keyword por transcripción (no N veces por cliente). Privacidad: las keywords son libres, el contenido matcheado (texto del segmento) NO se comparte entre clientes — cada cliente solo ve sus matches.

### D6: Anti-spam de alertas — coalescing de matches
**Decisión:** Una alerta por (transcripción, usuario) con todos los matches encontrados. No se envía 1 email por keyword.
**Alternativa:** 1 email por (transcripción, usuario, keyword).
**Razón:** Si una transcripción matchea 5 keywords del usuario, recibe 1 email con 5 líneas, no 5 emails separados. Más útil y menos ruidoso. `AlertLog` registra cada match individualmente para auditoría pero el envío es coalesced.

### D7: Multi-nodo del transcriptor — single vs. balancer
**Decisión:** Single node en v1, configurable vía `TRANSCRIPTOR_BASE_URL`. Preparar el cliente (`TranscriptorApiClient`) con interfaz que permita listar nodos y elegir por `queued` count (como sugiere la doc API §5) para v2.
**Razón:** YAGNI. Hay 1 nodo operativo hoy. Diseñar el cliente con la abstracción desde día 1 evita reescritura cuando llegue el segundo nodo.

### D8: Sidebar group placement — IA entre Administración y Multimedia
**Decisión:** Grupo "IA" insertado después de Administración, antes de Multimedia. Solo admin lo ve.
**Razón:** Decisión explícita del usuario. Visualmente queda agrupado con módulos admin pero separado de Multimedia (que es "para todos").

### D9: M3 sidebar item pattern
**Decisión:** Entrada "Mis Avisos" dentro de Multimedia, condicionada por `User::alertsInteligentes()->exists()` (paralelo a la línea 339 de Sites Externos).
**Razón:** Reusa patrón existente, sin nuevos grupos en sidebar. El cliente ve "Medios Puntuales" Y "Mis Avisos" si tiene ambos habilitados.

## Data Model

```
storage_providers (existing, +2 columns)
  ...
  transcription_enabled    boolean default false
  transcription_priority   integer default 0

transcriptions
  id                bigint PK
  file_id           bigint FK → files (unique, 1:1)
  job_id            varchar(64)            -- job_id devuelto por la API
  node_url          varchar(200)           -- nodo del transcriptor que procesó
  state             varchar(20)            -- queued|processing|done|error|dead
  language          varchar(5) default 'es'
  srt_content       text                   -- SRT completo canónico
  duration_seconds  integer nullable
  word_count        integer nullable
  started_at        timestamp nullable
  finished_at       timestamp nullable
  error_message     text nullable
  retries           integer default 0
  created_at / updated_at

transcription_segments
  id                bigint PK
  transcription_id  FK → transcriptions (cascade delete)
  segment_index     integer                -- 1, 2, 3... (orden del SRT)
  start_seconds     numeric(10,3)
  end_seconds       numeric(10,3)
  text              text                   -- índice GIN trigram para búsqueda rápida

keywords
  id                bigint PK
  text              varchar(200)           -- "paro nacional"
  normalized        varchar(200) unique    -- lowercase + sin acentos para matching
  created_at

user_keyword      (pivot: cliente ↔ keywords)
  id                bigint PK
  user_id           FK → users (cascade delete)
  keyword_id        FK → keywords (cascade delete)
  created_at
  UNIQUE(user_id, keyword_id)

keyword_matches
  id                bigint PK
  transcription_id  FK → transcriptions
  keyword_id        FK → keywords
  segment_id        FK → transcription_segments
  user_id           FK → users
  snippet           varchar(500)           -- 200 chars alrededor del match
  matched_at        timestamp

alert_logs
  id                bigint PK
  user_id           FK → users
  email_to          varchar(200)
  transcription_id  FK → transcriptions
  match_count       integer
  subject           varchar(300)
  status            varchar(20)            -- sent|failed|skipped
  error_message     text nullable
  sent_at           timestamp

user_alerts_inteligentes  (pivot: user ↔ módulo)
  id                bigint PK
  user_id           FK → users (unique, 1 fila por usuario)
  enabled           boolean default true
  keywords_quota    integer                -- ej. 100, 200, 0=sin módulo
  emails_quota      integer                -- cuántos correos puede registrar
  created_at / updated_at
```

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ CONSOLE (cron cada 2-3 min)                                                  │
│   php artisan transcription:scan-new                                       │
│     ├─ StorageProvider::where('transcription_enabled', true)->get()         │
│     ├─ por cada uno: SELECT files WHERE storage_provider_id = ?             │
│     │                       AND NOT EXISTS transcription WHERE file_id=f.id │
│     │                       AND modified_at < NOW() - 60s  (archivo completo)│
│     │                       ORDER BY modified_at DESC LIMIT 5               │
│     └─ dispatch(new ConvertAndTranscribeJob(file))                          │
└─────────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ QUEUE JOB: ConvertAndTranscribeJob                                          │
│   1. File $file = ...                                                       │
│   2. Process::run(['ffmpeg','-y','-i', $path,                               │
│                      '-vn','-ac','1','-ar','16000',                          │
│                      '-c:a','libopus','-b:a','64k', $opus_tmp])             │
│   3. Transcription::create([file_id, state=queued, ...])                    │
│   4. TranscriptorApiClient::submit($file, $opus_tmp, $callbackUrl)          │
│        └─ POST multipart a TRANSCRIPTOR_BASE_URL/v1/transcribe              │
│        └─ guarda job_id y node_url en la Transcription                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                  │
                                  │ (asíncrono, minutos/horas después)
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ WEBHOOK: POST /webhooks/transcription                                       │
│   headers: X-Webhook-Token = TRANSCRIPTOR_WEBHOOK_TOKEN                     │
│   1. verificar token (abort 401 si mismatch)                                │
│   2. Transcription::where('job_id', $data['job_id'])->first()             │
│   3. si state=done:                                                         │
│      a. descargar SRT vía GET {node_url}/v1/jobs/{job_id}/srt              │
│      b. SrtParser::parse($srt) → [segments...]                              │
│      c. Transcription::update(['state'=>'done', 'srt_content'=>...,        │
│         'duration_seconds'=>..., 'finished_at'=>now()])                     │
│      d. TranscriptionSegment::insertMany(...)                               │
│      e. KeywordMatcher::run($transcription) → alerts                       │
│   4. si state in (error,dead): guardar error_message + log                  │
└─────────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ SERVICE: KeywordMatcher                                                     │
│   por cada user con UserAlertsInteligentes (enabled=true):                  │
│     keywords = user.userKeywords()->pluck('keywords.normalized')            │
│     foreach segment en transcription.segments:                              │
│       text_normalized = strtolower(Str::ascii(segment.text))                │
│       foreach keyword in keywords:                                          │
│         if str_contains(text_normalized, keyword.normalized):               │
│           KeywordMatch::create([...])                                       │
│           acumular en matches_para_email[]                                  │
│     if matches_para_email:                                                  │
│       AlertDispatcher::send($user, $transcription, $matches)                │
│         └─ usa App\Modules\Correo para enviar                               │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ WEB UI                                                                       │
│ Admin:                                                                       │
│   /ia/api-transcriptor             → vista de storages habilitados + jobs    │
│   /ia/api-transcriptor/jobs/{id}   → detalle (ver SRT, reintentar, borrar)  │
│   /ia/avisos-inteligentes          → gestión de usuarios + cupo + correos   │
│   /ia/avisos-inteligentes/{user}   → keywords del usuario + matches         │
│ Cliente:                                                                     │
│   /mis-avisos                      → SUS keywords + historial de alertas     │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Blade Views

- `resources/views/ia/api-transcriptor/index.blade.php` — Storages habilitados + jobs recientes + estado del transcriptor
- `resources/views/ia/api-transcriptor/job-detail.blade.php` — Detalle del job (state, SRT viewer, retry)
- `resources/views/ia/avisos-inteligentes/index.blade.php` — Tabla de usuarios con módulo, activar/desactivar, cupo
- `resources/views/ia/avisos-inteligentes/user-detail.blade.php` — Keywords del usuario, correos, matches, enviar test
- `resources/views/mis-avisos/index.blade.php` — Vista del cliente con sus keywords (form inline) + tabla de alertas recibidas
- `resources/views/layouts/app.blade.php` — Nuevo grupo "IA" en sidebar (admin only) + entrada condicional "Mis Avisos" en Multimedia

## AppServiceProvider

Extender el view composer existente para inyectar `$userMisAvisosEnabled` (boolean) y `$userExternalSites` ya está:
```php
$user = session('user');
$misAvisosEnabled = false;
if ($user) {
    $misAvisosEnabled = \App\Models\UserAlertsInteligente::where('user_id', $user->id)
        ->where('enabled', true)
        ->exists();
}
$view->with('misAvisosEnabled', $misAvisosEnabled);
```

## Services detail

### TranscriptorApiClient
```php
class TranscriptorApiClient {
    public function __construct(private string $baseUrl, private ?string $apiKey) {}
    public function submit(File $file, string $opusPath, string $callbackUrl): array
        // POST multipart, devuelve ['job_id', 'priority']
    public function getSrt(string $jobId, string $nodeUrl): string
        // GET text/plain, devuelve contenido SRT
    public function getStats(): array
        // GET /api/stats, devuelve ['jobs'=>['queued'=>N,...], ...]
}
```

### AudioConverter
```php
class AudioConverter {
    public function toOpus64k(string $srcPath, string $dstPath): void
        // Process::run(['ffmpeg','-y','-i', $src,
        //                '-vn','-ac','1','-ar','16000',
        //                '-c:a','libopus','-b:a','64k', $dst])
        // Lanza RuntimeException si exit != 0
}
```

### SrtParser
```php
class SrtParser {
    public function parse(string $srtContent): array
        // Regex /(?:^|\n)(\d+)\n(\d{2}:\d{2}:\d{2},\d{3}) --> (\d{2}:\d{2}:\d{2},\d{3})\n([\s\S]*?)(?=\n\n|\Z)/
        // Devuelve [['index'=>1, 'start_seconds'=>0.64, 'end_seconds'=>6.56, 'text'=>'...'], ...]
}
```

### KeywordMatcher
```php
class KeywordMatcher {
    public function run(Transcription $t): void
        // itera User::with('userKeywords.keyword')->whereHas('alertsInteligentes', fn=>$q->where('enabled',true))
        // por cada match: KeywordMatch::create + acumula por user
        // al final por cada user: AlertDispatcher::send
}
```

### AlertDispatcher
```php
class AlertDispatcher {
    public function send(User $user, Transcription $t, array $matches): void
        // $correo = \App\Modules\Correo\Facades\Correo::send([
        //     'to' => $user->alertsInteligentes->emails,
        //     'subject' => "Coincidencia en grabación ...",
        //     'template' => 'ia-alert-match',
        //     'data' => [...]
        // ]);
        // AlertLog::create([...])
}
```

## Risks / Trade-offs

- **[Risk] Webhook perdido si TCloud está caído** → Mitigación: polling de respaldo cada 5 min sobre jobs `processing` con `created + 30 min` que aún no tienen `finished_at`. Si tras 3 polls siguen sin terminar, se marcan como `error` y se reintentan manualmente.
- **[Risk] ffmpeg no instalado o versión incompatible** → Mitigación: `AudioConverter::toOpus64k` chequea `Process::which('ffmpeg')` y lanza excepción clara con instrucciones de instalación. `ConvertAndTranscribeJob` tiene `tries=3` antes de marcar `dead`.
- **[Risk] SRT muy grande en BD (>50 KB por archivo)** → Mitigación: 15 min SRT ≈ 3 KB según doc; cap a 500 KB en el parser (trunca con warning si excede, aún graba los primeros segmentos).
- **[Risk] Cliente acumula muchas keywords y matching se vuelve lento** → Mitigación: índice GIN trigram en `transcription_segments.text` (ya hay `pg_trgm` instalado en `2026_05_21_000001_install_pgtrgm_and_gin_index.php`). Matching esperado ~50 ms por keyword con índice.
- **[Risk] Privacidad: cliente A ve keyword "presidente" que también tiene cliente B** → Aceptado: las keywords son libres (no confidenciales). El contenido matcheado solo se muestra al cliente dueño de la keyword (cada `KeywordMatch` tiene `user_id`).
- **[Risk] Doble envío si el webhook llega ANTES que el polling tome el job** → Mitigación: `convertAndTranscribe` actualiza `state=done` ANTES de disparar matching; el polling chequea `state` y si ya es `done`/`error`, lo saltea.
- **[Risk] ffmpeg tarda mucho en archivos de 2+ horas** → Mitigación: timeout de 600 s en `Process::run`, `tries=1` para este job (reintentar no ayuda si el input es muy grande). Se documenta que archivos >4 h requieren `storage_priority` mayor.

## Migration Plan

1. **Pre-deploy**: Configurar `TRANSCRIPTOR_BASE_URL` y `TRANSCRIPTOR_WEBHOOK_TOKEN` en `.env` del servidor. Verificar conectividad con `curl -H "Authorization: Bearer $TRANSCRIPTOR_API_KEY" $TRANSCRIPTOR_BASE_URL/health`.
2. **Deploy**:
   - `php artisan migrate` (crea 6 tablas nuevas, agrega 2 columnas a `storage_providers`)
   - `php artisan config:cache` y `route:cache`
   - Añadir entrada al crontab del servidor: `* * * * * php artisan schedule:run` (Laravel scheduler; este change añade `transcription:scan-new` cada 2 min)
3. **Smoke test**: Admin entra a `/ia/api-transcriptor`, marca un Storage como habilitado con 1 archivo, en `2-3 min` debe aparecer un job en estado `queued`.
4. **Rollback**: `php artisan migrate:rollback --step=6` (elimina 6 tablas y remueve las 2 columnas via `down()` de cada migración). Sin impacto en datos existentes (FK solo se referencian desde tablas nuevas).

## Open Questions

- ¿La búsqueda de keywords del cliente debe usar SRT o el segmento exacto? → Asumido: segmento exacto (más preciso para "minuto 10:42"). Se puede refinar.
- ¿Se notifica al cliente cuando AGREGA una keyword nueva que matchea transcripciones antiguas? → En esta v1 NO se hace retro-búsqueda automática. Documentado como Non-goal; el admin puede correr un comando de re-scan manual si es necesario (`transcription:rescan keyword_id`).
- ¿Se permite asignar keywords entre grupos de usuarios? → No en esta v1, se queda en asignación 1-a-1.
