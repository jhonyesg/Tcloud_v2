# Change: Bootstrapping del diccionario de correcciones desde corpus real

## Why

El módulo `transcription-corrections` está en producción desde el 2026-07-06 pero su diccionario solo contiene **5 reglas approved** (`Active to`, `applicate vacunes`, `orgular`, `valor the time`, `with orgasm`) — todas detectadas manualmente sobre UN solo audio ("Bogotá Modo Metro"). El resultado: solo **144 / 9.98M segmentos** (~0.0014%) tienen `text ≠ text_raw`.

Además, el 2026-07-29 un análisis de las **628.095 transcripciones creadas HOY** reveló tres problemas que deben resolverse juntos antes de poblar el diccionario a escala:

1. **El transcriptor produce mezcla estructural EN↔ES de forma masiva.** Frases como `in the world`, `of the people`, `at the moment`, `by the way` aparecen cientos de veces cada una (medido en 628k segmentos) y claramente son transcripciones defectuosas del español. Sin reglas en el diccionario, llegan al cliente como ruido.

2. **El transcriptor omite tildes predecibles** en palabras como `atencion`, `opinion`, `comision`, `region`, `sesion`, `organizacion`. La forma acentuada correcta (`atención`, `opinión`, `comisión`, `región`, `sesión`, `organización`) es 2-50 veces más frecuente en el corpus, así que la corrección es de alta confianza.

3. **La regla `Active to → Activa tu` rompe otras palabras** porque usa `str_ireplace` sin word-boundary. Hoy destruyó 11 segmentos: `attractive → attrActiva tu`, `proactive → proActiva tu`, `psychoactive → psychoActiva tu`, `reactive → reActiva tu`. Cada SRT nuevo en inglés (frecuente) sigue multiplicando el daño.

## What Changes

- **Bootstrap del diccionario**: insertar **86 correcciones** detectadas en el análisis del corpus de 2026-07-29 (50 estructurales EN→ES + 36 typos fonéticos sin tilde). Las de **alta confianza** (74) entran como `status=approved` (pueden aplicar de inmediato en SRT nuevo y retroactivo). Las de **confianza media** (12, ej. `publica → pública`, `region → región`) entran como `status=pending` para que el admin las revise.
- **Bugfix word-boundary**: cambiar `CorrectionService::applyToText()` y `Correction::applyToText()` de `str_ireplace` a `preg_replace` con `\b` para que las reglas solo se apliquen cuando `wrong_text` sea una palabra/frase completa, no un substring dentro de otra palabra.
- **Regla de "Active to"**: dado el bug confirmado, **actualizar** la regla `id=2` cambiando `wrong_text` de `Active to` a `\bactive to\b` con `match_type='word_boundary'` (nueva columna opcional) o usar la nueva implementación preg_replace de forma retrocompatible.
- **Reaplicar retroactivo** después del bootstrap para que `text` se actualice en los segmentos históricos donde aplique.
- **Verificación**: documentar la medición baseline `count(text ≠ text_raw)` antes/después para validar el efecto.

## Non-goals

- Detector automático de candidatos (correction_candidates). Cambio separado, sigue siendo non-goal.
- Capa de overrides puntuales (segment_overrides). Cambio separado.
- Auto-tartamudeos: el análisis reveló que los "tartamudeos" como `no no`, `que que` son FALSE POSITIVES en su mayoría — `no` se elimina en cadena real. NO se agregan como reglas. Si se quiere limpieza automática, debe ser un processor ortogonal.
- Cambios al visor `/ia/api-transcriptor/jobs/{id}`. Solo afecta el cockpit `/ia/correcciones`.
- Cambios a `transcription_processor` o al flujo del webhook — `applyToSegments` ya está bien integrado.

## Impact

- **Specs affected**: `transcription-corrections` (delta: agregar Requirement "Diccionario se bootstrappea desde análisis de corpus" + Requirement "Bugfix de word-boundary en applyToText").
- **Code affected**:
  - `app/app/Services/Ia/CorrectionService.php` (cambiar `applyText` a `preg_replace` con `\b`)
  - `app/app/Models/Correction.php` (cambiar `applyToText` a `preg_replace` con `\b`)
  - `app/app/Console/Commands/CorrectionsApplyRunCommand.php` (sin cambios, lo reusamos)
  - `app/database/seeders/CorreccionesDictionaryBootstrappingSeeder.php` (NUEVO)
  - `app/tests/Unit/CorrectionApplyToTextTest.php` (agregar tests para word-boundary)
- **Migrations**: ninguna. Esquema actual compatible (status enum ya soporta `approved`/`pending`).
- **Compatibilidad**:
  - `wrong_text` queda como string legible ("Active to"). La capa de matching aplica `\b` automáticamente.
  - El seeder es idempotente: usa `upsertApproved()` que detecta duplicados por `wrong_normalized`.
- **OpenSpec**: `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/specs/transcription-corrections/spec.md` (delta).