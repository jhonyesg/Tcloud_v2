# Design: Cierre explícito de fds en TranscriptorApiClient

## El problema, en una imagen

```
                       TranscriptorApiClient::submitNoCallback()
                       ─────────────────────────────────────────
  $audioPath = "/dev/shm/tcloud-transcription/foo_ac046c.wav"
       │
       ▼
  submitRequest() {
       │
       │  $stream = fopen($audioPath, 'r');    ← fd #N abierto
       │  Http::timeout()->retry(3, ...)->attach('file', $stream, ...)
       │       │
       │       ▼
       │   Intento 1: conexión OK ──▶ Guzzle acepta el stream,
       │                              POST /v1/transcribe ──▶ API responde
       │                              ¿fclose($stream)?  Depende de Guzzle.
       │
       │   Intento 2 (5xx): Guzzle reabre internamente? No — el resource
       │                    se mantiene. retry() crea NUEVO request, pero
       │                    $stream es el mismo. Tras 3 intentos: 3 requests,
       │                    1 stream sin cerrar.
       │
       │   Intento 3 (ConnectionException): throw=false atrapa, retorna
       │                                     Response sin éxito. $stream
       │                                     queda abierto hasta el próximo
       │                                     GC del proceso PHP.
  }
       │
       ▼
  WAV intermedio: ya unlink() por el finally{} de TranscriptionSubmitService.
  PERO el fd sigue abierto en el worker → tmpfs NO libera RAM.
       │
       ▼
  Repetido por cada job en cola durante horas → 100 fds por worker,
  40 GB de Shmem, /dev/shm al 100%.
       │
       ▼
  ffmpeg del próximo job: write() retorna ENOSPC → falla en 250 ms.
  El job nunca llega al API.
```

## Decisión 1: el ciclo de vida del resource es del caller, no del HTTP client

Guzzle + Laravel `Http::attach()` aceptan un resource y se comprometen a cerrarlo
cuando el body del multipart se serializa. Pero:

- En retries, Laravel **crea un nuevo `Request`** pero **reutiliza los attachments**
  del builder original. El resource se pasa por referencia y se asume que sigue
  abierto para los siguientes intentos. Guzzle no siempre lo cierra tras el
  primer intento, sobre todo si el response builder se descarta en mitad de un
  retry.
- En `throw: false` (configurado aquí: línea 74), Laravel **no lanza** la
  excepción: la devuelve como `Response`. Eso significa que ningún `catch`
  aguas abajo ejecuta `fclose()`. El resource queda colgado hasta que el GC de
  PHP lo reclame — y los workers de cola tienen GC conservador.

La solución es **no delegar el cierre al HTTP client**. El caller abre, llama,
cierra. Sin excepciones.

```php
// Patrón actual (con fuga):
->attach('file', fopen($audioPath, 'r'), basename($audioPath), [...])
```

```php
// Patrón nuevo (sin fuga):
$stream = fopen($audioPath, 'r');
if ($stream === false) {
    throw new \RuntimeException("No se pudo abrir el WAV: {$audioPath}");
}
try {
    $response = $this->httpBuilder($audioPath, $stream)
        ->retry(...)
        ->post($this->baseUrl() . '/v1/transcribe');
    return $this->parseResponse($response);
} finally {
    if (is_resource($stream)) {
        fclose($stream);
    }
}
```

### Por qué `try/finally` y no `unset()`

`unset($stream)` solo destruye la variable PHP; **no llama `fclose()`** sobre el
resource subyacente. El fd queda abierto en el kernel hasta que el proceso muera
o el GC de PHP decida cerrarlo (lo cual en `queue:work` puede ser nunca).

`fclose()` es la **única** llamada que libera el fd del kernel. Por eso el
`try/finally` es obligatorio, no opcional.

### Por qué no usar `register_shutdown_function`

Funciona, pero introduce orden de ejecución no determinista con el destructor de
la request y complica el testing. `try/finally` es la primitiva correcta.

## Decisión 2: pre-flight de espacio en `/dev/shm`

Antes de `AudioConverter::convert()`, consultar `disk_free_space()` sobre el path
del directorio destino. Si el espacio libre cae bajo `min_shm_free_bytes`, abortar
**sin** abrir ffmpeg.

```php
// En TranscriptionSubmitService::submit(), justo antes de llamar a convert():
$tmpDir = $this->resolveTmpDir();   // /dev/shm/tcloud-transcription o fallback
$needed = $this->settings->int('min_shm_free_bytes');   // default 200 MB
$free   = @disk_free_space($tmpDir);

if ($free !== false && $free < $needed) {
    $msg = "tmpfs sin espacio: {$free} bytes libres, mínimo {$needed}. "
         . "Job reencolado para próximo intento (revisar /dev/shm).";
    $this->markError($transcription, $msg);
    Log::warning("TranscriptionSubmitService: {$msg}", [
        'tmp_dir' => $tmpDir,
        'free_bytes' => $free,
        'file_id' => $file->id,
    ]);
    return ['ok' => false, 'error' => $msg, 'requeueable' => true];
}
```

El job termina con `error` pero `markError()` incrementa `retries` y, si alcanza
`max_retries`, va a `dead`. Para evitar que un `/dev/shm` lleno mande todo a `dead`,
**el flag `requeueable` debe ser respetado por el tick**: si llega un job con
`requeueable=true` al tick, NO se dispatcha (es responsabilidad del cleanup-cron
revertirlo cuando haya espacio). Se propone una columna `requeueable` o usar el
`error_message` con prefijo `[REQUEUEABLE]` como heurística simple.

**Decisión final:** añadir **una columna nueva** `requeue_after_at TIMESTAMP NULL`
a `transcriptions`. Cuando se setea con un timestamp futuro, el tick filtra esos
jobs. Es explícito, testeable, y no contamina `error_message`.

### Threshold conservador

`min_shm_free_bytes = 200 MB` parece mucho, pero:

- Un WAV mono 16kHz PCM de 20 min pesa ~38 MB. Con 12 workers = 460 MB pico.
- Si cada worker tiene además 2 reintentos pendientes en memoria del HTTP client
  (raro pero posible), añade otros ~76 MB.
- 200 MB es ~30% sobre el peor caso. Suficiente para amortiguar sin ser tan
  grande que la detección llegue tarde.

Si en producción se observa que 200 MB es muy agresivo (jobs rebotan sin razón),
se sube. Pero por defecto es conservador para **no permitir** que se vuelva a
reproducir el estado actual.

## Decisión 3: cleanup de huérfanos

`transcription:cleanup-orphan-wav` corre cada 15 min y elimina archivos en
`/dev/shm/tcloud-transcription/` que:

1. Tienen `mtime > 30 min` (probablemente olvidados por un crash).
2. **Ningún proceso los tiene abierto** (verificación con
   `lsof +D /dev/shm/tcloud-transcription/` y parseo: si el archivo aparece en
   la lista, NO se borra).
3. Opcionalmente: cualquier `.wav` con mtime > 30 min que NO esté en la lista de
   jobs activos del último `transcription:tick`.

La verificación de fd abierto es **la que importa** porque un archivo listado
en el directorio puede tener fd abiertos (no debería tras el fix #1, pero este
comando es defensivo contra el estado actual).

## Decisión 4: monitoreo

`transcription:check-shm-health` cada 10 min:

```php
$stat = @statvfs('/dev/shm');   // o disk_free_space + disk_total_space
$total = $stat['blocks'] * $stat['bsize'];
$free  = $stat['bavail'] * $stat['bsize'];
$used  = $total - $free;
$pct   = $total > 0 ? round($used * 100 / $total, 1) : 0;

Cache::put('transcriptor:shm:status', [
    'total' => $total, 'used' => $used, 'free' => $free, 'percent' => $pct,
    'checked_at' => now()->toIso8601String(),
], 600);

if ($pct >= 80) {
    Log::warning("transcriptor: /dev/shm al {$pct}% ({$used}/{$total})", [
        'free_bytes' => $free,
        'dir_writable' => is_writable('/dev/shm/tcloud-transcription'),
    ]);
}
```

El endpoint `GET /ia/api-transcriptor/shm-status` (solo admin) lee la cache y la
devuelve. Es solo lectura, no muta estado, se cachea 10 min en servidor.

## Mitigación operativa (Task 0)

`umount /dev/shm && mount -t tmpfs ...` libera **todos** los inodos, incluyendo
los huérfanos. Los workers que estén en mitad de un `submit` abortan con error
de path (esperable, el job se reencola). Tiempo total de indisponibilidad:
~30 segundos si se automatiza.

Si el sistema tiene `mount -o remount,size=80G` sin perder contenido, es
preferible, pero tmpfs en kernel 5.x sí lo soporta y es atómico.

## Consideraciones de prueba

- **Test unitario de `TranscriptorApiClient::submitNoCallback`**: mockear el HTTP
  client, contar fds antes/después de 1000 invocaciones con respuesta simulada
  de éxito. Debe ser estable.
- **Test con ffmpeg fake**: stub `AudioConverter::convert` para que cree un wav
  pequeño, contar fds en `/dev/shm` antes/después, verificar que el fd se cierra
  en el path de excepción.
- **Test del pre-flight**: con `disk_free_space` mockeado a 50 MB y umbral 200 MB,
  verificar que `submit()` retorna sin invocar `convert()`.
