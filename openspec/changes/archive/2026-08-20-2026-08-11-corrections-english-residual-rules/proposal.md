# Change: 4 reglas de bajo riesgo para inglés residual en transcripciones ES

## Why

Auditoría manual de las últimas 5 transcripciones `done` (todas del 2026-08-11 16:19) reveló que el corrector se ejecutó (`corrected=1`) pero **no modificó prácticamente ningún segmento** y dejó frases con palabras inglesas incrustadas dentro de texto en español en 3 de las 5 transcripciones:

- `#165434 alertabogotá` (322 segmentos, 2 modificados)
- `#165436 unradio` (98 segmentos, 0 modificados)
- `#165445 telepacifico` (129 segmentos, 0 modificados)

Ejemplos reales del `text` corregido que quedaron intactos:

```text
#165434 / seg 19   "The gente que nos escucha a través de la TDT..."
#165434 / seg 46   "Abrazo for the gente que está in the canal of YouTube."
#165434 / seg 48   "...the experience and the veteran ayude."
#165434 / seg 194  "...lo que han mostrado in this initiative de campagna..."
#165436 / seg 102  "...el celular..." (precedido de frases EN)
#165445 / seg 124  "Sergio Bartelsmann is a photographer colombian with..."
```

El corrector actual solo aplica reglas del diccionario. El diccionario no tiene reglas para frases mixtas cortas `the gente` / `for the gente` / `in the celular` / `in this initiative`, así que pasan sin tocar. Es un **punto ciego operativo**: el UI actual de revisión solo expone los segmentos que ya fueron modificados (`text_raw != text`), por lo que estos casos no aparecen en la cola "Últimas 10".

Hay reglas candidatas recurrentes y seguras (forzarían poco contexto español, baja probabilidad de falso positivo) que sí vale la pena meter al diccionario. Las frases largas en inglés dentro de entrevistas bilingües no son candidatos a regla automática: requieren juicio humano.

## What Changes

### 1. Cuatro correcciones approved con `risk_level=low`

Insertar directamente en la tabla `corrections` con `status='approved'`, `risk_level='low'` y `proposed_by`/`approved_by` apuntando al usuario administrador actual:

| wrong_normalized   | wrong_text          | correct_text         | risk_level | Razón |
|--------------------|---------------------|----------------------|------------|-------|
| `the gente`        | `The gente`         | `La gente`           | low        | 4+ ocurrencias en 3 transcripciones; "gente" fuerza el contexto ES, no hay forma legítima de "the gente" en español correcto |
| `for the gente`    | `for the gente`     | `para la gente`      | low        | Variante contigua a la anterior; aparece en #165434 seg 46 |
| `in the celular`   | `in the celular`    | `en el celular`      | low        | "celular" fuerza contexto ES |
| `in this initiative` | `in this initiative` | `en esta iniciativa` | low      | Frase completa, no fragmentable; aparece en #165434 seg 194 |

Estas reglas se aplicarán automáticamente a transcripciones futuras vía `CorrectionService::applyToSegments()` (que respeta `risk_level=low` por defecto), contribuyendo a cerrar la brecha progresivamente como el usuario espera.

### 2. NO se agregan reglas para casos ambiguos o one-off

Patrones **descartados explícitamente** y motivo:

- **`in the canal` → `en el canal`**: choca con "The Canal" como nombre propio.
- **`regale like` → `dale like`**: CTA colombiano legítimo, pero caso aislado.
- **`and actually` → `y actualmente`**: `actually` se usa tal cual en español moderno; cambiar rompe tono.
- **`super diferente`, `thing`, `moment`**: anglicismos aceptados o sustantivos válidos en ES.
- **Frases largas en inglés puro** (segmentos enteros de entrevistas bilingües): no son candidatos a regla de diccionario; requieren un detector de mezcla ES/EN por segmento (alcance distinto, no en este change).

### 3. Marcar las 3 transcripciones problemáticas como `needs_review`

Persistir en `transcription_reviews` (tabla ya creada por el change `2026-08-11-corrections-transcription-review`):

- `transcription_id=165434` → status=`needs_review`, notes="Inglés residual: 'The gente', 'for the gente', 'in this initiative'. 320 seg sin tocar."
- `transcription_id=165436` → status=`needs_review`, notes="Entrevista bilingüe ES/EN; múltiples segmentos con frases enteras en inglés. Revisión humana."
- `transcription_id=165445` → status=`needs_review`, notes="Secciones enteras transcritas en inglés dentro de texto ES. Requiere traducción humana o rechazo del audio."

Estas marcas alimentan la UI existente de "Revisión de transcripciones" (`/ia/correcciones` → tab Revisión) para que el admin las ubique luego sin re-buscar a mano.

### 4. Sin código de aplicación nuevo

Las correcciones entrarán como approved y `CorrectionService` las aplicará automáticamente. La marca `needs_review` se hace por la UI existente o por `POST /ia/correcciones/transcription-review/{id}`. No se agrega servicio nuevo, no se modifica `CorrectionService` ni `TranscriptionReviewService`.

## Non-goals

- No se agrega un detector de mezcla ES/EN por segmento (sería un change separado y de alcance mayor).
- No se cambian reglas existentes.
- No se tocan las 2 transcripciones sin problemas (#165433 minutodedios, #165435 sol) — sus falsos positivos son palabras españolas legítimas.
- No se modifica `CorrectionService` ni `TranscriptionReviewService`.
- No se agrega UI nueva; se usa la UI existente de Correcciones y de Revisión.
- No se reaplican retroactivamente las reglas a transcripciones pasadas (la reaplicación retroactiva es responsabilidad del admin cuando lo decida).

## Success Criteria

- Las 4 reglas existen en `corrections` con `status='approved'` y `risk_level='low'`, con `proposed_by`/`approved_by` válidos.
- Las 3 transcripciones (#165434, #165436, #165445) tienen fila en `transcription_reviews` con `status='needs_review'`, `reviewed_by` válido y `reviewed_at` reciente.
- Una próxima transcripción que diga "the gente..." recibe esa sustitución automáticamente (verificable en `text` vs `text_raw`).
- Las transcripciones #165433 y #165435 no quedan marcadas como `needs_review` (no tienen inglés residual real).
- La UI `/ia/correcciones` → Revisión muestra las 3 con estado `Necesita revisión` al recargar.
