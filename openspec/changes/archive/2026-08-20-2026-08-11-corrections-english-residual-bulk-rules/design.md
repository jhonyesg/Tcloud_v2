# Design: Ronda 2 — 12 reglas bulk para inglés residual

## Overview

Este change es un **complemento de datos** del change `2026-08-11-corrections-english-residual-rules`. Mismo mecanismo: INSERTs a la tabla `corrections` con reglas `approved`. Lo único que cambia es el volumen (12 reglas en lugar de 4) y la aparición de reglas `medium` que requieren seguimiento en la pestaña "Contexto sensible".

```text
┌──────────────────────────────────────────────────────────────┐
│  Estado al cierre del change (auditoría 17,057 transc.)     │
│                                                              │
│  2.28M segmentos hoy, solo 0.91% modificados por corrector  │
│  126 transcripciones con corrected=-1 (corrector falló)      │
│                                                              │
│  Brecha de inglés residual:                                  │
│   - the gente        × 318                                    │
│   - the authorities  × 640                                    │
│   - the information  × 391                                    │
│   - the principal    × 404                                    │
│   - in the world     × 629  (no va, demasiado generico)      │
│   - the opportunity  × 142                                    │
│   - ... + ~5 patrones mas                                   │
└──────────────────────────────────────────────────────────────┘
                            │
        ┌───────────────────┴───────────────────┐
        ▼                                       ▼
┌──────────────────────────┐      ┌──────────────────────────┐
│  10 low-risk (auto)      │      │  2 medium-risk (audit)   │
│  aplican en proximas     │      │  aparecen en pestaña     │
│  transcripciones         │      │  Contexto sensible       │
└──────────────────────────┘      └──────────────────────────┘
```

## Lista final consolidada

```text
LOW RISK (10 reglas nuevas)
============================
  #  wrong_normalized       →  correct_text         oc/día
  ─────────────────────────────────────────────────────────
  1  the gente              →  la gente              318
  2  the principal          →  el principal          404
  3  the opportunity        →  la oportunidad        142
  4  the cosas              →  las cosas              24
  5  the monitoreo          →  el monitoreo           12
  6  the personas           →  las personas           21
  7  ahora is               →  ahora es               34
  8  in the same            →  en el mismo            89
  9  for the gente          →  para la gente          14
 10  in the celular         →  en el celular           4

MEDIUM RISK (2 reglas nuevas)
=============================
  #  wrong_normalized       →  correct_text         oc/día
  ─────────────────────────────────────────────────────────
  M1 the authorities        →  las autoridades      640
  M2 the information        →  la información       391
```

Las 4 reglas de la ronda 1 (`the gente` ya en lista, `for the gente`, `in the celular`, `in this initiative`) son **subset** de las de ronda 2. Si la ronda 1 ya se aplicó, las 12 de ronda 2 cubren las 4 originales; si no se aplicó, este round cubre todo.

## Decision Log

### D1. ¿Por qué `the principal` y no `the principales`?

Conteo: `the principal` aparece 404 veces, `the principales` 0 veces (porque el plural sale como `the people` literalmente). La regla `the principal` → `el principal` es la palanca dominante; "the principales" no es un patrón que Whisper produzca consistentemente.

### D2. ¿Por qué no `in the world` (629)?

Demasiado contexto-dependiente. "In the world" en una frase puede ser:
- "...the best team **in the world**" (cognado icónico del sports)
- "...but **in the world** of finance..." (mismo)
- "...the largest **in the world**..." (cliché periodístico)

Sustituir por "en el mundo" sí gramaticalmente es válido, pero el segmento "in the world" en muchas de las 629 ocurrencias probablemente sea **discurso natural code-switched**, no error. Riesgo medio de romper el registro. Si en una auditoría futura se ve un sub-patrón más angosto (ej. `the best in the world` → `el mejor del mundo`), se agrega esa sola.

### D3. ¿Por qué `the authorities` medium y no low?

"The authorities" puede ser nombre propio (p.ej. una banda, una agencia, una obra). 640 ocurrencias en un día son excesivas para que TODAS sean errores de Whisper; probablemente hay contenido legítimo (noticias internacionales, citas de documentos en inglés, etc.). Marcarla `medium` permite aplicarla Y auditarla periódicamente.

### D4. ¿Por qué `the information` medium y no low?

Similar a D3. "The information" puede ser título de obra ("The Information", libro de James Gleick), nombre de medio ("The Information", periódico tech), o en jerga técnica mantener la frase en inglés. 391/día es alto; alguna fracción será contenido legítimo. `medium` la aplica pero la expone a la auditoría.

### D5. ¿Por qué NO `i think`, `you know`, `i mean` en low?

Auditoría visual de las 3 transcripciones con alta concentración de estos tokens mostró que **son code-switching legítimo**:

- `#164215 camarafm_antioquia` (seg 48, 144, etc.): "I think nobody was more or not" — persona narrando en inglés puro mezclado con español.
- `#164217 caracol_valle_cauca` (mismo programa replicado): "I think that...", "you know..." son muletillas naturales del entrevistado.

Forzar "i think → yo creo" destruiría el tono y el sentido. Marcarlos como reglas automática sería un desastre de naturalidad.

### D6. ¿Por qué NO `the` suelto?

`the` aparece 93,919 veces. Eso es **4% de todos los segmentos del día**. Si se hace `the` → `el/la`, el corrector tocaría 1 de cada 25 segmentos, muchos correctos:

- "The Beatles" → "El Beatles" ❌
- "The New York Times" → "El New York Times" ❌
- "the weekend" → "el weekend" ❌
- "the TikTok" → "el TikTok" (¿debería ser "el TikTok"? probablemente sí, pero sin LLM no es determinista)

Sin una capa LLM que decida cuál es nombre propio y cuál no, la regla general es impracticable. Las reglas focas con palabra ancla española son la alternativa segura.

## Data Model

Solo INSERTs. La constraint UNIQUE `corrections_wrong_active_unique` impide duplicar `wrong_normalized` para `status='approved'`, así que re-ejecución es segura.

```sql
-- 10 reglas low
INSERT INTO corrections
  (wrong_text, correct_text, wrong_normalized, status, risk_level,
   proposed_by, approved_by, approved_at, applies_count, created_at, updated_at)
VALUES
  ('the gente',         'la gente',         'the gente',         'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('the principal',     'el principal',     'the principal',     'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('the opportunity',   'la oportunidad',   'the opportunity',   'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('the cosas',         'las cosas',        'the cosas',         'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('the monitoreo',     'el monitoreo',     'the monitoreo',     'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('the personas',      'las personas',     'the personas',      'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('ahora is',          'ahora es',         'ahora is',          'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('in the same',       'en el mismo',      'in the same',       'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('for the gente',     'para la gente',    'for the gente',     'approved','low',    :a,:a,NOW(),0,NOW(),NOW()),
  ('in the celular',    'en el celular',    'in the celular',    'approved','low',    :a,:a,NOW(),0,NOW(),NOW());

-- 2 reglas medium
INSERT INTO corrections
  (wrong_text, correct_text, wrong_normalized, status, risk_level,
   proposed_by, approved_by, approved_at, applies_count, created_at, updated_at)
VALUES
  ('the authorities',   'las autoridades',  'the authorities',   'approved','medium', :a,:a,NOW(),0,NOW(),NOW()),
  ('the information',   'la información',   'the information',   'approved','medium', :a,:a,NOW(),0,NOW(),NOW());

-- Verificar duplicados por UNIQUE: si 'the gente' o 'for the gente' etc.
-- ya existen del round 1 con status='approved', el INSERT fallara
-- silenciosamente o devolvera error. Mitigation: usar ON CONFLICT o
-- verificar primero con SELECT y filtrar los existentes.
```

`:a` = ID del usuario admin que ejecute el change.

## Performance and Privacy

- 12 INSERTs. Carga trivial.
- No se reescriben transcripciones pasadas.
- No se altera UI existente.
- Constraint UNIQUE previene duplicación con ronda 1 (mismo `wrong_normalized` + `status='approved'` es rechazado).
- Endpoints de admin siguen detrás de `auth` + `admin`.

## Verificación

```sql
-- 12 reglas (o las que aplique tras dedupe)
SELECT wrong_normalized, correct_text, risk_level, applies_count, created_at
FROM corrections
WHERE created_at > NOW() - INTERVAL '1 hour'
  AND risk_level IN ('low','medium')
  AND wrong_normalized IN (
    'the gente','the principal','the opportunity','the cosas','the monitoreo',
    'the personas','ahora is','in the same','for the gente','in the celular',
    'the authorities','the information'
  )
ORDER BY id DESC;

-- Las medium deben figurar en "Contexto sensible"
SELECT id, wrong_normalized, correct_text, risk_level
FROM corrections
WHERE status='approved' AND risk_level='medium'
ORDER BY id DESC;

-- Probar aplicacion en una frase sintetica (no toca BD)
SELECT Correction::applyToText('the gente que nos escucha',
                              /* includeHighRisk */ false) AS prueba;
```

## Verificación manual en la UI

- [ ] `/ia/correcciones` → tab "Aprobadas" muestra las nuevas reglas al final (ordenadas por `applies_count DESC`, aparecerán con `applies_count=0` al inicio).
- [ ] `/ia/correcciones` → tab "Contexto sensible" muestra las 2 medium (`the authorities`, `the information`).
- [ ] Tras la siguiente transcripción con "the gente...", abrir `/ia/api-transcriptor/jobs/{id}` y comparar `text_raw` vs `text` para confirmar la sustitución.

## Open Questions To Resolve During Implementation

- ¿La ronda 1 ya se ejecutó? Si sí, este round la cubre; si no, ejecuta ambos rounds en uno.
- ¿Vale la pena poblar el campo `source='audit-2026-08-11'` para trazabilidad? Decisión del admin.
- ¿Las reglas low deben tener `applies_count=0` o el corrector lo incrementará automáticamente en próximas aplicaciones? El campo se incrementa en `CorrectionService`; no requiere intervención.
