# Spec — Integridad del texto de los segmentos transcritos

> El texto transcrito no debe perderse en silencio: es el único contenido buscable del módulo de
> transcripción.

## ADDED Requirements

### Requirement: El límite de longitud por segmento es configurable

`SrtParser` SHALL leer la longitud máxima de segmento desde `transcriptor.srt_max_segment_chars`
(default **3000**), resuelto a través de `TranscriptorSettings` para que sea ajustable en caliente sin
desplegar. El valor **0** SHALL significar «sin límite».

- El parser NO SHALL fijar el límite en una constante de clase.
- El recorte SHALL hacerse por **caracteres** (`mb_substr`), nunca por bytes, para no partir un UTF-8.

> **Historia.** El límite estuvo fijado en 500 caracteres con el comentario *"trunca segmentos > 500
> chars para no inflar la BD con basura"*. La premisa era falsa en los dos extremos:
>
> - `transcription_segments.text` y `.text_raw` son de tipo `text`, **ilimitado en Postgres**: no
>   había ninguna razón técnica para recortar.
> - Lo recortado no era basura. Los segmentos afectados duraban **29,5 s de media** frente a 5,8 s de
>   los normales, y se cortaban a mitad de palabra. Eran habla continua real sin pausas naturales,
>   típica de emisoras de radio — que es donde se concentraban.
>
> Coste del límite: **9.125 segmentos** perdieron texto, a razón de ~700-1.100 nuevos al día. Ahorro:
> **4 MB sobre una tabla de 3,2 GB** (0,13%).

#### Scenario: Segmento por debajo del límite
- **WHEN** un segmento del SRT mide menos que `srt_max_segment_chars`
- **THEN** se guarda íntegro y no se emite ningún aviso

#### Scenario: Segmento por encima del límite
- **WHEN** un segmento supera el límite configurado
- **THEN** se recorta a esa longitud en caracteres
- **AND** se contabiliza para el aviso agregado

#### Scenario: Límite desactivado
- **WHEN** `srt_max_segment_chars` es 0
- **THEN** no se recorta ningún segmento, sea cual sea su longitud

### Requirement: Un aviso agregado por SRT, no uno por segmento

Cuando se recorte al menos un segmento, `SrtParser` SHALL emitir **un solo** `Log::warning` por SRT
procesado, con `{segmentos, de_total, chars_perdidos, mas_largo, limite}`.

> El aviso anterior se emitía **por segmento** y suponía el **15% de todas las líneas del log** (3.042
> de 20.144). Ese ruido ahogaba cualquier otra señal — incluidas las guardas de sincronizado
> (`prune_refused`, `scan_untrusted`, `mount_detached`), que son alertas tempranas de problemas de
> montaje. Tras el cambio: 2 avisos.

### Requirement: El texto recortado es recuperable dentro de la ventana de retención

El sistema SHALL ofrecer `transcription:repair-truncated` para recuperar el texto de segmentos
recortados, re-descargando el SRT que el transcriptor ya generó vía `job_id` + `node_url`.

- **NO SHALL reprocesar audio ni consumir GPU**: solo vuelve a leer un resultado existente.
- SHALL reaplicar las correcciones aprobadas, de modo que `text` quede coherente con `text_raw`, igual
  que hace `TranscriptionProcessor` al procesar un SRT nuevo.
- SHALL ser **idempotente**: solo escribe cuando el SRT trae más texto del guardado, y nunca acorta.
- SHALL disponer de `--dry-run`, y de `--since` para no malgastar peticiones sobre lo ya purgado.
- SHALL abortar si `srt_max_segment_chars` sigue en un valor que volvería a recortar.

#### Scenario: SRT todavía disponible
- **WHEN** el transcriptor conserva el SRT del `job_id`
- **THEN** se recupera el texto completo del segmento y se reaplican las correcciones

#### Scenario: SRT ya purgado
- **WHEN** el transcriptor ya no conserva el SRT
- **THEN** se contabiliza como irrecuperable y NO SHALL tratarse como error

> **El transcriptor retiene los SRT unos 7 días.** Verificado el 2026-07-27 sondeando un día a la vez:
> recuperable desde el 21-jul, purgado el 19-jul y anteriores. De los 9.125 segmentos afectados se
> recuperaron **6.680** (249.131 caracteres de habla); los **2.290** anteriores al 20-jul son pérdida
> definitiva.

### Requirement: Un segmento de longitud exactamente igual al límite no implica recorte

El diagnóstico SHALL distinguir entre un segmento **recortado** y uno que mide esa longitud en el SRT
original. La comprobación fiable es re-descargar el SRT y comparar, no la longitud por sí sola.

> Tras la reparación quedaron ~159 segmentos de exactamente 500 caracteres que **no** estaban
> recortados: los medía así el propio SRT. Un barrido de `--dry-run` sobre la ventana recuperable
> devolvió 0 recuperables, que es lo que lo confirma.

---

## Acceptance Criteria

1. `php artisan transcription:config` muestra `srt_max_segment_chars` con su valor efectivo, origen y
   rango.
2. Un SRT con un segmento de 800 caracteres se guarda íntegro con el límite en 3000.
3. Con `srt_max_segment_chars = 0` no se recorta un segmento de 9.000 caracteres.
4. El recorte de 25 caracteres `ñ` con límite 10 produce exactamente 10 caracteres, no bytes partidos.
5. Un SRT con varios segmentos recortados produce **un** aviso en log, no uno por segmento.
6. `transcription:repair-truncated --dry-run` no modifica nada y reporta segmentos y caracteres
   recuperables.
7. Re-ejecutar el comando sobre datos ya reparados reporta 0 recuperados (idempotencia).
8. `select count(*) from transcription_segments where length(text_raw) > 500` crece con el tiempo:
   confirma que el límite nuevo está activo en producción.
