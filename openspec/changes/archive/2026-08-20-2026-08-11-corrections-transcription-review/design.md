# Design: Revisión manual de transcripciones y correcciones sensibles

## Overview

La funcionalidad será una extensión del módulo existente de Correcciones. El backend devolverá una cola pequeña de transcripciones y un detalle bajo demanda. Alpine.js se encargará de alternar los modos y presentar la comparación, pero la selección de datos y el cálculo de coincidencias se harán en servidor.

```text
┌──────────────────────────────────────────────────────────┐
│ /ia/correcciones                                         │
│                                                          │
│ Pendientes | Aprobadas | Contexto sensible |             │
│ Revisión de transcripciones                              │
└──────────────────────────────┬───────────────────────────┘
                               │
                ┌──────────────▼──────────────┐
                │ Lista de transcripciones    │
                │ done, limit 10              │
                └──────────────┬──────────────┘
                               │ seleccionar
                ┌──────────────▼──────────────┐
                │ Detalle de revisión         │
                │ raw vs corrected            │
                │ reglas y riesgo             │
                │ contexto vecino             │
                └──────────────┬──────────────┘
                               │
          ┌────────────────────┼────────────────────┐
          ▼                    ▼                    ▼
       Correcta          Necesita revisión        Ignorada
```

## Data Model

Se propone una tabla pequeña para conservar el estado de revisión por transcripción:

```text
transcription_reviews
---------------------
id
transcription_id unique
status: pending | correct | needs_review | ignored
reviewed_by nullable
reviewed_at nullable
notes nullable
created_at
updated_at
```

La relación debe usar `transcription_id` con eliminación en cascada o una política equivalente. El registro se crea de forma perezosa cuando el administrador abre o marca una transcripción; no se generará una fila para cada transcripción automáticamente.

No se añadirá inicialmente una tabla de reemplazos. La comparación se calcula desde los campos existentes:

```text
text_raw != text
        │
        ├── mostrar diferencia
        └── buscar reglas aprobadas que coincidan en text_raw
```

## Backend Endpoints

Rutas sugeridas dentro del grupo existente de admin `/ia`:

- `GET /correcciones/transcription-review?mode=latest`
  - Devuelve hasta diez transcripciones `done` más recientes.
- `GET /correcciones/transcription-review?mode=sensitive`
  - Devuelve hasta diez transcripciones `done` con segmentos que coincidan con reglas medium/high.
- `GET /correcciones/transcription-review/{id}`
  - Devuelve metadatos, segmentos modificados, reglas explicativas, vecinos y estado de revisión.
- `PATCH /correcciones/transcription-review/{id}`
  - Actualiza `status` y `notes` de la revisión.

La respuesta de lista debe ser liviana:

```json
{
  "items": [
    {
      "id": 8421,
      "file_name": "archivo.mp3",
      "finished_at": "2026-08-11T15:42:00Z",
      "segments_count": 180,
      "changed_segments_count": 7,
      "sensitive_matches_count": 2,
      "review_status": "pending"
    }
  ],
  "mode": "sensitive"
}
```

El detalle deberá evitar devolver el SRT completo por defecto si no es necesario. Los segmentos modificados se devolverán como objetos separados:

```json
{
  "segment_index": 41,
  "start_seconds": 728.4,
  "end_seconds": 734.1,
  "text_raw": "She was actually very sympathetic to the victims.",
  "text": "Ella era actualmente muy simpática con las víctimas.",
  "matches": [
    {
      "correction_id": 12,
      "wrong_text": "actually",
      "correct_text": "actualmente",
      "risk_level": "high",
      "confidence": "exact"
    }
  ],
  "previous_segment": null,
  "next_segment": null
}
```

## Sensitive Query

La consulta `mode=sensitive` deberá identificar transcripciones mediante segmentos, no mediante el SRT completo, para usar los datos normalizados:

```text
transcriptions
  └── transcription_segments
        └── match against approved corrections
              └── correction.risk_level IN (medium, high)
```

Debe evitar duplicar una transcripción cuando varios segmentos o varias reglas hagan match. La ordenación será por `finished_at DESC`, con fallback a `created_at DESC`.

## Match Explanation

La explicación se calculará con las mismas reglas de límites de palabra utilizadas por el corrector. Se deberán considerar primero las frases más largas para evitar atribuir un cambio a una regla corta cuando existe una regla más específica.

La respuesta distinguirá:

- `exact`: una regla explica directamente una sustitución en el segmento;
- `candidate`: la regla coincide, pero hay solapamiento o más de una explicación posible;
- `unknown`: el texto cambió, pero no se puede atribuir con seguridad a una regla actual.

Esto evita presentar una explicación especulativa como si fuera un hecho histórico.

## UI

La nueva pestaña reutilizará el estado y los patrones visuales de `index.blade.php`:

- selector `Últimas 10` / `Últimas 10 sensibles`;
- botón de recarga;
- contador de resultados;
- tabla o tarjetas responsive para la lista;
- badges para cambios, coincidencias sensibles y estado de revisión;
- panel de detalle o modal ancho para comparar segmentos;
- resaltado visual separado para texto original y texto corregido;
- acciones de revisión independientes de las acciones sobre el diccionario.

En móvil, cada transcripción deberá mostrarse como tarjeta y cada segmento como bloque apilado. En escritorio podrá utilizarse una tabla de resumen y un panel lateral o modal para el detalle.

## Rule Actions

La revisión podrá enlazar con acciones existentes:

- abrir edición de la regla;
- cambiar `risk_level`;
- eliminar o excluir el término;
- crear una propuesta de corrección desde el contexto observado.

Las acciones destructivas o globales deberán requerir confirmación y no se ejecutarán al cambiar el estado de revisión.

## High-Risk Consistency

Antes de cerrar la implementación se debe verificar la aplicación en estos caminos:

```text
TranscriptionProcessor::processDoneWithSrt()
        └── CorrectionService::applyToSegments()

Correcciones retroactivas
        └── CorrectionService::applyRetroactively()

Helpers y previews
        └── Correction::applyToText()
```

Los tres deben compartir la misma semántica de exclusión de `risk_level=high`, salvo cuando exista una opción explícita equivalente a `include_high_risk`.

## Performance and Privacy

- La lista limitará la consulta a diez filas.
- El detalle cargará segmentos bajo demanda.
- Se evitará traer todos los segmentos de todas las transcripciones al inicializar la página.
- Se reutilizarán índices existentes por fecha, transcripción y texto; se revisará si la consulta sensible requiere un índice adicional.
- El contenido de las transcripciones seguirá protegido por el middleware `auth` + `admin` existente.
- Las notas de revisión no deben incluir automáticamente el SRT completo.

## Open Questions To Resolve During Implementation

- Si el estado `needs_review` requiere una bandeja persistente separada o basta con filtrarlo en la misma pestaña.
- Si los segmentos vecinos se cargan siempre o solo bajo demanda.
- Si las acciones de edición de reglas existentes pueden reutilizarse sin crear un modal nuevo.
- Si se requiere guardar una copia de la regla usada en el momento de aplicación para futuras auditorías exactas.
