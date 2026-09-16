# Design: Semántica clara de recencia en revisión de transcripciones

## Overview

La corrección se limita a la selección, ordenamiento y presentación de la cola de
revisión. El detalle de comparación `text_raw` versus `text`, las decisiones humanas
y la detección de reglas sensibles se conservan.

```text
                         /ia/correcciones
                                │
                ┌───────────────┼────────────────┐
                ▼               ▼                ▼
       solicitadas          finalizadas       sensibles
       created_at DESC      finished_at DESC   finished_at DESC
       state = done         state = done       state = done
                │               │                │
                └───────────────┼────────────────┘
                                ▼
                    created_at + finished_at
                    visibles en cada registro
```

## Backend

`TranscriptionReviewService::list()` deberá aceptar modos canónicos:

| Modo | Filtro | Orden principal | Propósito |
|---|---|---|---|
| `requested` | `state = done` | `created_at DESC` | Revisar trabajos solicitados recientemente |
| `completed` | `state = done` | `finished_at DESC NULLS LAST` | Revisar lo que acaba de terminar |
| `sensitive` | `state = done` + match medium/high | `finished_at DESC NULLS LAST` | Revisar cambios sensibles disponibles |

El alias de entrada `latest` se normalizará a `requested`, y la respuesta deberá
devolver el modo canónico normalizado. Todos los ordenamientos tendrán un desempate
por `created_at DESC` e `id DESC` para que dos trabajos con el mismo timestamp no
produzcan un orden inestable.

La consulta seguirá limitada a diez filas antes de cargar conteos de segmentos. El
modo `sensitive` mantendrá `whereExists` para no duplicar transcripciones cuando
varios segmentos o reglas coincidan.

El payload de cada elemento conservará los campos actuales y añadirá o garantizará:

```json
{
  "id": 149644,
  "file_name": "teleisla_08082026_024502.mp4",
  "created_at": "2026-08-08T03:02:07-05:00",
  "finished_at": "2026-08-11T18:17:03-05:00",
  "recency_mode": "completed",
  "review": {"status": "pending"}
}
```

No se debe usar la fecha embebida en `file_name` para ordenar ni para determinar la
fecha que se muestra.

## Frontend

El selector de la pestaña mostrará etiquetas explícitas:

- `Últimas 10 solicitadas`;
- `Últimas 10 finalizadas`;
- `Últimas 10 sensibles`.

La fila o tarjeta mostrará dos líneas independientes:

```text
Solicitada: 8 ago. 03:02
Finalizada: 11 ago. 18:17
```

Si las fechas difieren más de un umbral visual razonable, se mostrará un texto
neutral como `Terminó después de esperar en cola`. No se afirmará que la solicitud
falló ni se alterará su estado.

El enlace al detalle seguirá funcionando para todos los modos, y el estado de
revisión se mantendrá al cambiar entre modos.

## API Transcriptor Relationship

No se reutilizará la respuesta completa de `/ia/api-transcriptor`, porque esa pantalla
incluye estados no terminados y tiene paginación propia. La relación se explicará con
los mismos IDs y timestamps:

```text
/ia/api-transcriptor
  -> todos los estados y cola operativa

/ia/correcciones/transcription-review
  -> solo done, con segmentos revisables
```

Si se agrega texto de ayuda, deberá aclarar que una solicitud visible hoy en API
Transcriptor puede no estar todavía disponible en Correcciones.

## Testing And Verification

- Probar que `requested` devuelve solo `done` y ordena por `created_at`.
- Probar que `completed` devuelve solo `done` y ordena por `finished_at`.
- Probar el fallback y desempate cuando `finished_at` sea `NULL` o igual.
- Probar que `latest` se normaliza a `requested`.
- Probar que `sensitive` conserva deduplicación y orden por finalización.
- Probar que ambas fechas aparecen en el JSON y en la interfaz.
- Verificar manualmente un caso de backlog: creado el 8 de agosto, terminado el 11.
- Verificar en móvil y escritorio que las dos fechas no se confundan.
