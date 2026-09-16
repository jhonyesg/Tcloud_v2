# Design: Bootstrapping del diccionario

## 1. Word-boundary en lugar de substring

### Problema actual

`str_ireplace("Active to", "Activa tu", $text)` reemplaza `Active to` DENTRO de otras palabras, destruyéndolas.

```
attractive touristic → attrActiva tuuristic    💥
proactive to market  → proActiva tu market      💥
psychoactive to      → psychoActiva tu          💥
reactive to demand   → reActiva tu demand       💥
```

### Solución

Cambiar la capa de matching a `preg_replace` con `\b` (word boundary) en las dos funciones afectadas:

```php
// Antes
$text = str_ireplace($correction->wrong_normalized, $correction->correct_text, $text);

// Después
$pattern = '/\b' . preg_quote($correction->wrong_normalized, '/') . '\b/i';
$text = preg_replace($pattern, $correction->correct_text, $text);
```

**Trade-off**: `\b` se define por `\w` que en PHP/PCRE incluye letras Unicode si se pasa el flag `u`. Como `wrong_normalized` es ASCII (Keyword::asciiLower ya strip diacríticos), `\b` funciona correctamente.

**Casos a verificar en tests**:
- ✅ `Active to` ya NO matchea dentro de `attractive` (silencioso)
- ✅ `Active to` SÍ matchea como frase completa (`Active to Bogotá`)
- ✅ Multi-word (`valor the time`) sigue matcheando correctamente porque `\b` se aplica al inicio y fin de la frase, no a los espacios intermedios
- ⚠️ Frases con caracteres no-palabra al borde (ej. `,of the night,`) — `\b` los trata como borde válido, lo cual es el comportamiento deseado

### Orden de aplicación

Mantener `orderByRaw('LENGTH(wrong_normalized) DESC')` — ahora se vuelve aún más importante: las frases largas DEBEN evaluarse antes que las cortas para evitar que una sub-frase las desarme. Por ejemplo, si tenemos tanto `the world` como `in the world`, queremos que `in the world → en el mundo` se evalúe primero.

## 2. Seeder de bootstrap

Crear `app/database/seeders/CorreccionesDictionaryBootstrappingSeeder.php` que:

1. Llama `app(CorrectionService::class)->upsertApproved($wrong, $correct, $admin)` para cada par del GRUPO A (50 reglas, todas de confianza alta/media-alta).
2. Para el GRUPO B (12 reglas de confianza media), llama `propose($admin, $wrong, $correct)` para crear `pending`.
3. Encuentra al admin con `User::where('role', 'admin')->first()` (mismo patrón que `CorreccionesDictionarySeeder` existente).
4. Es idempotente: si la regla ya existe (approved o pending con mismo `wrong_normalized`), la actualiza sin duplicar.

### Lista exacta de reglas (referencia)

**GRUPO A — 50 pares estructurales (48 approved + 2 pending):**

Approved (48):
- `in the world`, `of the world`, `at the end`, `all the time`, `at the time`, `of the people`, `of the year`, `at the moment`, `of the government`, `in the history`, `of the day`, `in the region`, `in the department`, `of the president`, `in the city`, `of the night`, `of the department`, `and the people`, `in the market`, `in the zone`, `of the community`, `of the state`, `of the nation`, `of the history`, `at the same time`, `of the region`, `in the territory`, `in the area`, `for the people`, `of the market`, `in the morning`, `of the territory`, `with the people`, `and the government`, `in the country`, `by the way`, `of the society`, `at the university`, `with the community`, `for the moment`, `of the area`, `of the country`, `with the government`, `in the government`, `for the government`, `at the hospital`, `at the beginning`, `in the meantime`

Pending (2):
- `over and over`, `day and night`

**GRUPO B — 36 pares typos fonéticos (24 approved + 12 pending):**

Approved (24): `atencion`, `ejecution`, `incumpliment`, `incumpliments`, `opinion`, `emision`, `comision`, `direccion`, `organizacion`, `diagnostico`, `pronostico`, `turistico`, `turistica`, `artistico`, `artistica`, `caracteristica`, `caracteristicas`, `publicamente`, `rapidamente`, `unicamente`, `logicamente`, `basicamente`, `practicamente`, `epoca`, `unico`, `publico`, `comico`, `magico`, `tipico`, `clasico`, `clasica`, `paralisis`, `hipotesis`, `metafora`

Pending (12): `recursion`, `version`, `religion`, `region`, `sesion`, `occasion`, `publica`, `unica`, `musica`, `magica`, `artisticamente`, `caracteristico`

> Las pending son palabras donde la forma SIN tilde tiene contexto válido como verbo conjugado u otro uso (`publica` puede ser "él publica", `sesion` puede ser un nombre propio, `musica` sin tilde no es palabra estándar pero aparece 1000x — verificar origen).

## 3. Actualización de la regla `id=2`

La regla `Active to → Activa tu` ya existe. Con el word-boundary fix, el bug desaparece automáticamente sin necesidad de tocar la fila. Pero el `wrong_text="Active to"` debe capitalizarse correctamente en BD para que `wrong_normalized` (lowercase) sea `active to` (sin mayúscula intermedia), porque `preg_quote` no normaliza case. Verificamos:

```php
// wrong_text: "Active to"
// wrong_normalized: "active to"  ← ASCII lowercase
// pattern: '/\bactive to\b/i'     ← case-insensitive, OK
```

No requiere UPDATE. Solo aplicar el fix de preg_replace y el bug desaparece.

## 4. Verificación end-to-end

Después del seeder + fix + retroactivo:

1. `count(transcription_segments where text != text_raw)` baseline → esperado: **3.000+ segmentos divergentes** (vs. 144 actuales). Cada par del GRUPO A+B contribuye según su frecuencia en el corpus.
2. Verificar que `applies_count` de las nuevas reglas se incremente en el run retroactivo.
3. Inspeccionar manualmente 5-10 segmentos corregidos para confirmar que no hay falsos positivos catastróficos.
4. Verificar que `attractive`, `proactive`, `psychoactive`, `reactive` en segmentos viejos YA corregidos (`text` con basura) ahora siguen preservados en `text_raw` (inmutable) — son evidencia histórica del bug. NO se pueden "descorregir" retroactivamente.

## 5. Rollback

Si la verificación revela falsos positivos inaceptables:

1. **Suave**: cambiar `status='approved'` a `status='rejected'` para las reglas problemáticas (vía SQL o vía admin UI).
2. **Total**: `DELETE FROM corrections WHERE source='bootstrapping-2026-07-29' AND status='approved'` (seeder debe grabar este `source` para permitir rollback limpio).

Para eso, el seeder agrega un campo de identificación en `rejected_reason` o se recomienda agregar columna `source` al modelo `Correction`. **Decisión**: usar `proposed_by` igual al admin que carga + prefijo en `wrong_text` no, mejor agregar columna `source` por migration. **Pero** para mantener este change sin migration, el seeder usa `User` admin con email predefinido (`admin@tcloud.local` o similar) y se identifica por su `id`. El rollback sería: `DELETE FROM corrections WHERE status='approved' AND wrong_normalized IN (...lista...)`.

**Decisión final**: agregar `source` por migration es lo más limpio. Ver tasks.md.

## 6. Tests nuevos / actualizados

En `app/tests/Unit/CorrectionApplyToTextTest.php`:

- `test_word_boundary_does_not_match_inside_other_words()` — confirma el fix del bug.
- `test_word_boundary_matches_phrase_at_sentence_start()` — confirma que `in the world` al inicio de frase matchea.
- `test_word_boundary_with_punctuation()` — `,in the world,` debe matchear.
- `test_multi_word_phrase_preserved()` — `valor the time` no se desarma si existe `valor the` separado.

En `app/tests/Unit/CorrectionServiceTest.php`:

- `test_apply_to_segments_with_word_boundary()` — integración servicio.