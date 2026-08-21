# Design: 4 reglas de bajo riesgo para inglés residual en transcripciones ES

## Overview

Este change es de **datos**, no de código. Las 4 correcciones se insertan directamente en la tabla `corrections` con los campos ya existentes; las 3 marcas de revisión se insertan en `transcription_reviews` con los campos ya existentes. Ambos endpoints/UI ya están implementados por changes previos.

```text
┌──────────────────────────────────────────────────────────────┐
│ Estado actual (auditoría 2026-08-11 16:19)                    │
│                                                              │
│ 5 transcripciones done, todas corrected=1, pero             │
│ solo 1 tuvo segmentos modificados por el corrector.         │
│ El corrector no tenía reglas aplicables para frases mixtas. │
└──────────────────────────────────────────────────────────────┘
                            │
        ┌───────────────────┴───────────────────┐
        ▼                                       ▼
┌──────────────────────────┐      ┌──────────────────────────┐
│ 4 correcciones nuevas    │      │ 3 marcas needs_review    │
│ (risk_level=low, approved)│      │ (transcription_reviews)  │
│                          │      │                          │
│ the gente       → la    │      │ #165434 alertabogotá    │
│ for the gente   → para  │      │ #165436 unradio         │
│ in the celular  → en el │      │ #165445 telepacifico    │
│ in this init.   → en    │      │                          │
│   esta iniciativa       │      │                          │
└──────────────────────────┘      └──────────────────────────┘
        │                                       │
        ▼                                       ▼
  Próximas transcripciones         UI Revisión los marca
  reciben la sustitución            como "Necesita revisión"
  automáticamente
```

## Decision Log

### D1. ¿Por qué solo 4 reglas y no más?

Se evaluaron 10 patrones candidatos extraídos de las 3 transcripciones problemáticas. La matriz de decisión usó tres ejes:

- **Recurrencia**: ¿aparece 2+ veces en el set auditado o tiene alta probabilidad de repetirse?
- **Seguridad semántica**: ¿la palabra "ancla" del lado ES (`gente`, `celular`, `initiative`) reduce el riesgo de falso positivo?
- **Costo de error**: ¿qué tan grave es aplicarla mal en una transcripción que no la necesita?

| Patrón | Recurrencia | Seguridad | Costo error | Veredicto |
|--------|-------------|-----------|-------------|-----------|
| `the gente` → `la gente` | alta (4+) | muy alta | bajo | **aprobado** |
| `for the gente` → `para la gente` | media (2) | alta | bajo | **aprobado** |
| `in the celular` → `en el celular` | media | alta | bajo | **aprobado** |
| `in this initiative` → `en esta iniciativa` | baja (1) | alta | medio | aprobado |
| `in the canal` → `en el canal` | baja | media | medio (puede chocar con "The Canal" propio) | rechazado |
| `regale like` → `dale like` | baja (1) | media | alto (cambia intención CTA) | rechazado |
| `and actually` → `y actualmente` | baja | baja | alto (`actually` válido en ES) | rechazado |
| `super diferente`, `thing`, `moment` | varias | muy baja | muy alto (anglicismos legítimos) | rechazado |
| Frases largas EN completas | alta (varias) | baja | medio (cambia idioma del segmento) | **rechazado — detección de mezcla queda fuera de alcance** |
| `but I think`, `so I started` | alta | muy baja | alto (code-switching legítimo) | rechazado |

### D2. ¿Por qué `risk_level=low` (no medium)?

El corrector (`CorrectionService::applyToSegments`) aplica por defecto todas las reglas excepto `high`. Las 4 reglas propuestas usan palabras ancla inequívocamente españolas en el mismo segmento (`gente`, `celular`, `initiative`), lo que minimiza falsos positivos. Marcarlas `medium` requeriría que aparezcan en la pestaña "Contexto sensible" para auditoría constante, lo cual diluye la señal: esas reglas NO son sensibles, son rutinarias.

Si en una auditoría futura (1-2 semanas) se observa que alguna regla generó falsos positivos, el plan es degradar su `risk_level` a `medium` o `high` con una sola UPDATE, sin re-aplicar retroactivamente.

### D3. ¿Por qué NO re-aplicar retroactivamente?

Re-aplicar las 4 reglas sobre las transcripciones #165434/165436/165445 reescribiría el `text` de segmentos que el admin está por revisar manualmente. Eso le quitaría justamente el dato que necesita para decidir (`text_raw` vs `text` post-corrección). La reaplicación retroactiva debe ser decisión consciente del admin, fuera de este change.

### D4. ¿Por qué propuesta + aprobación en un solo paso (no flujo normal)?

El flujo normal es "minero/AI sugiere → admin aprueba desde la UI". Aquí el admin está tomando el rol de "quien detectó el patrón manualmente", así que se salta el paso de pending y se inserta directamente como `approved`. Esto es seguro porque:

- Las 4 reglas fueron validadas una por una contra los segmentos reales.
- El admin es quien ejecuta este change y aprueba implícitamente al ejecutarlo.
- Si se prefiere el flujo de dos pasos, el admin puede revertir a `status='pending'` y aprobar desde la UI después.

### D5. ¿Por qué marcar `needs_review` y no `correct` o `ignored`?

- `correct` sería falso: las transcripciones tienen errores claros.
- `ignored` ocultaría el problema y las haría desaparecer de la cola de seguimiento.
- `needs_review` deja el caso visible para que el admin (o un operador) ataquen los segmentos restantes (los largos en inglés) con conocimiento explícito.

## Data Model

No se agrega ni modifica tabla. Se usan las ya existentes:

```text
corrections
-----------
id
wrong_text (string 500)
correct_text (string 500)
wrong_normalized (string 500)   ← clave de match case-insensitive
status: 'approved'
risk_level: 'low'
proposed_by: <admin_id>
approved_by: <admin_id>
approved_at: now()
applies_count: 0
source: NULL  (no proviene de miner/AI)
```

```text
transcription_reviews
---------------------
transcription_id: 165434 | 165436 | 165445
status: 'needs_review'
reviewed_by: <admin_id>
reviewed_at: now()
notes: '<texto corto por transcripción>'
```

## INSERTs a ejecutar

```sql
-- 4 correcciones
INSERT INTO corrections
  (wrong_text, correct_text, wrong_normalized, status, risk_level,
   proposed_by, approved_by, approved_at, applies_count, created_at, updated_at)
VALUES
  ('The gente',   'la gente',          'the gente',          'approved','low', :a,:a,NOW(),0,NOW(),NOW()),
  ('for the gente','para la gente',    'for the gente',      'approved','low', :a,:a,NOW(),0,NOW(),NOW()),
  ('in the celular','en el celular',   'in the celular',     'approved','low', :a,:a,NOW(),0,NOW(),NOW()),
  ('in this initiative','en esta iniciativa','in this initiative','approved','low', :a,:a,NOW(),0,NOW(),NOW());

-- 3 marcas de revisión
INSERT INTO transcription_reviews
  (transcription_id, status, reviewed_by, reviewed_at, notes, created_at, updated_at)
VALUES
  (165434,'needs_review',:a,NOW(),'Inglés residual en segs 19,46,48,194 ("the gente","for the gente","in this initiative")',NOW(),NOW()),
  (165436,'needs_review',:a,NOW(),'Entrevista bilingüe ES/EN; ~15 segs con frases enteras en inglés. Requiere revisión humana.',NOW(),NOW()),
  (165445,'needs_review',:a,NOW(),'Secciones enteras transcritas en inglés dentro de texto ES. Requiere traducción humana o rechazo.',NOW(),NOW())
ON CONFLICT (transcription_id) DO UPDATE
  SET status='needs_review',
      reviewed_by=EXCLUDED.reviewed_by,
      reviewed_at=NOW(),
      notes=EXCLUDED.notes,
      updated_at=NOW();
```

`:a` = ID del usuario administrador que ejecute el change (sesión actual).

El `ON CONFLICT (transcription_id) DO UPDATE` permite re-ejecución idempotente.

## Verificación post-INSERT

```sql
-- Confirmar 4 reglas nuevas approved/low
SELECT id, wrong_normalized, correct_text, risk_level
FROM corrections
WHERE status='approved' AND risk_level='low' AND created_at > NOW() - INTERVAL '1 hour'
ORDER BY id DESC;

-- Confirmar 3 marcas needs_review nuevas
SELECT transcription_id, status, reviewed_at
FROM transcription_reviews
WHERE transcription_id IN (165434,165436,165445)
ORDER BY transcription_id;
```

Y, manualmente, esperar la siguiente transcripción que contenga "the gente…" y verificar en `text_raw` vs `text` que la regla se aplicó.

## Performance and Privacy

- 4 INSERTs y 3 INSERTs/UPDATEs. Carga trivial.
- No se reescriben las 3 transcripciones marcadas; el `text` queda intacto para que el admin decida.
- El constraint UNIQUE parcial `corrections_wrong_active_unique` (solo `status='approved'`) impide duplicar regla activa con mismo `wrong_normalized`.
- Todos los endpoints de UI/admin siguen detrás de los middlewares `auth` + `admin` ya existentes.

## Open Questions To Resolve During Implementation

- ¿Se prefiere crear las 4 reglas con el flujo normal (pending → aprobar desde UI) en lugar de approved directo? D4 lo descarta por defecto, pero el admin lo puede revertir.
- ¿Tiene sentido agregar nota pública en `/ia/correcciones` indicando "reglas agregadas el 2026-08-11 por auditoría de transcripciones"? Hoy el campo `source` queda NULL; se podría poblar con `"audit-2026-08-11"` para trazabilidad.
