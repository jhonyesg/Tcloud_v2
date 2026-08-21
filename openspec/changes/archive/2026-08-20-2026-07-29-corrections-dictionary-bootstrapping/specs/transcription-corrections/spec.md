## ADDED Requirements

### Requirement: Diccionario se bootstrappea desde análisis de corpus

El sistema SHALL permitir al admin sembrar el diccionario con N correcciones detectadas en una pasada de análisis sobre `transcription_segments.text_raw` reciente. Las detecciones de **alta confianza** (≥2 apariciones del wrong en la muestra y forma correcta más frecuente en el corpus, o ratio wrong:right ≥1:2) entran como `status=approved` y aplican inmediatamente. Las de **confianza media** (≥1 aparición y forma correcta existente) entran como `status=pending` para revisión admin. Cada corrección del bootstrap SHALL tener `source='bootstrapping-YYYY-MM-DD'` para permitir rollback selectivo.

#### Scenario: Bootstrap de alta confianza aplica a nuevos SRT
- **WHEN** el admin corre el seeder de bootstrap que inserta `in the world → en el mundo` con `source='bootstrapping-2026-07-29'` y `status='approved'`
- **THEN** los SRT nuevos que lleguen con `text_raw` conteniendo `in the world` se guardan con `text="...en el mundo..."` automáticamente.

#### Scenario: Bootstrap de confianza media queda en cola
- **WHEN** el seeder inserta `region → región` con `status='pending'` (caso donde `region` puede ser verbo válido en algunos contextos)
- **THEN** el admin ve la corrección en `/ia/correcciones` pestaña "Pendientes" y decide aprobar/rechazar manualmente.

#### Scenario: Rollback selectivo por source
- **WHEN** el admin ejecuta `UPDATE corrections SET status='rejected' WHERE source='bootstrapping-2026-07-29' AND applies_count < 5` para revertir reglas de bajo impacto
- **THEN** las reglas afectadas dejan de aplicar al próximo SRT nuevo y al próximo retroactivo. Las correcciones con `applies_count >= 5` se mantienen (señal de impacto real).

---

### Requirement: Bugfix de word-boundary en `applyToText`

El sistema SHALL garantizar que las correcciones NO apliquen cuando `wrong_normalized` aparece como substring dentro de otra palabra. La capa de matching SHALL usar `\b` (PCRE word-boundary) alrededor de `wrong_normalized` en lugar de `str_ireplace` plano.

#### Scenario: "Active to" ya no rompe "attractive"
- **WHEN** existe la corrección `Active to → Activa tu` (status=approved) y llega un segmento con `text_raw="the attractive touristic destination"`
- **THEN** `text` queda `"the attractive touristic destination"` (palabra intacta). NO se produce `"the attrActiva tuuristic destination"`.

#### Scenario: "in the world" sigue matcheando como frase completa
- **WHEN** existe la corrección `in the world → en el mundo` y llega `text_raw="peace in the world today"`
- **THEN** `text` queda `"peace en el mundo today"` (frase completa reemplazada). El `\b` al inicio y fin de la frase permite matchear entre espacios/puntuación.

#### Scenario: Frase con puntuación al borde matchea
- **WHEN** llega `text_raw="the situation, in the world of politics,"`
- **THEN** `text` queda `"the situation, en el mundo de politics,"`. La coma actúa como borde de palabra válido.

#### Scenario: Orden por longitud DESC preservado
- **WHEN** existen correcciones `the world` (corta) y `in the world` (larga) y el texto contiene `in the world`
- **THEN** se aplica primero la larga (`in the world → en el mundo`); la corta queda sin match (correcto, porque `\b the world\b` no matchea dentro de "en el mundo" ya procesado).

---

## MODIFIED Requirements

### Requirement: Comando retroactivo reaplica el diccionario a todas las transcripciones

(El comportamiento sigue igual que en `2026-07-24-corrections-production-readiness`. Cambio agregado: ahora respeta word-boundary, lo cual evita que el retroactivo introduzca falsos positivos catastróficos al re-aplicar el diccionario actual sobre los 9.98M segmentos.)