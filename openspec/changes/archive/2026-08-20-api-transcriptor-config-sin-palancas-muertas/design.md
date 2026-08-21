# Design

## Cómo se auditó

No por lectura: por rastreo de consumidores. Para cada una de las 43 claves del `SCHEMA` se buscó quién la lee, descartando el propio `TranscriptorSettings` y el `config/transcriptor.php` (que son la definición, no el uso).

Dos trampas que el método tuvo que sortear:

1. **Menciones en comentarios cuentan como cero.** `ai_coherence_threshold` y `ai_coherence_model` aparecían en el docblock de `TranscriptionCoherencePass` como si fueran ajustes vivos. Solo el grep sobre `$this->settings->…` distingue documentación de uso.
2. **Leer `config()` no es leer el ajuste.** Un consumidor que hace `config('transcriptor.x')` se salta la capa de settings y, con ella, el override de la pantalla. Por eso la comprobación decisiva fue buscar `config('transcriptor.` en `app/` y en las vistas: ahí salieron los topes de interfaz.

Casos que parecían fallo y no lo eran, comprobados uno a uno:

- `min_batch` / `max_batch`: sin consumidor aparente porque los usa `computeDispatchBatch()` dentro del propio accessor.
- `srt_max_segment_chars` y `corrections_chunk`: sus servicios (`SrtParser`, `CorrectionService`) prefieren la capa de settings y solo caen a `config()` si se instancian a mano — y ambos son singletons resueltos por el contenedor.
- `inflight_max`: se aplica en `LimitTranscriptionConcurrency`, y se verificó que ese middleware está efectivamente enganchado en `ConvertAndTranscribeJob::middleware()`.
- `submit_timeout` / `get_timeout`: se leen **por llamada**, no en el constructor, así que un cambio no exige reiniciar workers.

## La cadena de override, comprobada en caliente

```
UI → POST /settings → system_settings → Cache::forget(transcriptor:settings)
                                              │
                          cada worker refresca su memo cada 30 s
                                              ▼
                                     consumidor real
```

Se verificó de punta a punta: con `corrections_chunk = 777` guardado, el consumidor real (`CorrectionService`, resuelto por el contenedor) leyó 777; al retirar el override volvió a 500. Los 30 s de memo por proceso son deliberados — memoizar de por vida congelaría los valores en los `queue:work`, que viven horas.

## Por qué retirar el umbral en vez de cablearlo

Cablear `ai_coherence_threshold` habría sido una línea, y es la opción tentadora. Se descartó porque **no es una limpieza, es un cambio de comportamiento**: el pase corre hoy con 0.5 y el panel mostraba 0,4, así que "conectarlo" bajaría el corte y mandaría más segmentos al LLM. Eso merece medirse aparte.

Además, el umbral no vive solo: acompaña a la heurística `isMix` (al menos un token EN en contexto ES). Separarlos en dos sitios —uno en la UI y otro en el código— es precisamente cómo un criterio de selección se vuelve incoherente. La constante queda pegada a la condición que gobierna.

## Topes de interfaz

El servidor ya clampeaba con `ui_batch_max` de la capa de settings; la vista pintaba el suyo desde el archivo. Mientras coincidieran, nadie lo notaba. La corrección es servir ambos desde la misma fuente:

```
indexData() ──> payload['ui_limits'] ──> Blade (uiBatchMax, uiMaxParallelSends, batchSize)
     │
     └── mismos settings que usa processBatch() para clampear
```

Comprobado: con un override de `ui_batch_max = 75`, la página pasa a pintar 75 desde la primera carga (antes pintaba 200 siempre).

## Las seis claves ausentes del archivo

Funcionaban por el default del esquema, así que el arreglo es documental y de honestidad del panel: la columna "Origen" solo puede decir "archivo" si el archivo las tiene. Se declaran con el mismo default para que declarar no cambie ningún valor efectivo.
