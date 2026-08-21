## Why

La auditoría de transcripciones del 2026-08-15T22:23 (Bogotá) reveló que el diccionario activo contiene 6 reglas `"of X" → "de X"` sin artículo que sobre-corrigen nombres propios en inglés embebidos en frases españolas (`Power of Love` → `Power de love`, `Solidarity of Colombia` → `Solidarity de colombia`), y 2 reglas con género mal aplicado (`of cambio` → `de el cambio` cuando debería ser `del cambio`). El usuario aprobó el alcance "toda la familia rota" sin retroactivo.

## What Changes

**Marcar como `status='rejected'` con `rejected_reason='overreach-of-mapping-<date>'` (sin tocar BD retroactiva) las 6 reglas sin artículo:**

- [ ] `id=2991`: `of love` → `de love` (604 aplicaciones históricas)
- [ ] `id=2990`: `of colombia` → `de colombia` (1.570 apps)
- [ ] `id=2992`: `of security` → `de security` (585 apps)
- [ ] `id=3003`: `of bogotá` → `de bogotá` (536 apps)
- [ ] `id=2263`: `of melanoma` → `de melanoma` (3 apps)
- [ ] `id=3094`: `of emergency` → `de emergency` (0 apps)

**Corregir `correct_text` (sin cambiar status, siguen siendo `approved`) en las 2 reglas con género mal:**

- [ ] `id=253`: `of cambio` → `del cambio` (50 apps históricas). Razón: "cambio" es masculino; el artículo debe ser `del` no `de el`.
- [ ] `id=261`: `of agua` → `del agua` (33 apps). Razón: "agua" en singular femenino usa `del` (excepción del español: `el agua` pero `del agua`).

**Sin retroactivo**: las 3.381 aplicaciones ya hechas en `transcription_segments` NO se revierten en este change. Eso requeriría un comando de retroactivo-revert con filtro por `correction_id` y queda fuera del alcance aprobado. Los segmentos quedan con su `text` actual; las nuevas transcripciones ya no se ven afectadas.

## Non-goals

- No se reescriben los segmentos retroactivamente. El `text` actual se preserva.
- No se modifica el código de `EnEsMixMiner`, `CorrectionService` ni los comandos CLI. El cambio es 100% datos.
- No se tocan las reglas `"of the X"` (específicas, correctas, en `KNOWN_EN_ES_MAPPINGS`).
- No se eliminan las reglas con `applies_count > 1000` sin revisión manual caso por caso.

## Capabilities

### New Capabilities
Ninguna.

### Modified Capabilities
Ninguna. El comportamiento observable para futuros segmentos SRT es el cambio correcto: el matcher usará el `correct_text` actualizado y los nuevos segmentos no se sobre-corregirán. No es un cambio de requisito funcional sino de datos.

**skip_specs: true** (cleanup de datos, sin cambio de comportamiento documentado en spec).

## Impact

- **BD**: 8 updates en `corrections`. Ningún cambio en `transcription_segments`.
- **Aplicación retroactiva**: el próximo `corrections:apply-retroactive` (manual desde `/ia/correcciones`) **no revertirá** los segmentos; el comando actual re-aplica desde cero usando `text_raw`, así que las 3.381 aplicaciones erróneas históricas **se sobre-escribirán automáticamente al correct_text bueno** cuando se corra retroactivo. Esto es un efecto secundario favorable del flujo existente; no requiere código.
- **Nuevos segmentos SRT**: a partir del deploy, el matcher usará las 6 reglas eliminadas (no aplica) y los 2 `correct_text` corregidos.
- **UI de correcciones**: las 6 reglas eliminadas aparecerán en la pestaña "Rechazadas" con motivo, lo cual es comportamiento esperado.