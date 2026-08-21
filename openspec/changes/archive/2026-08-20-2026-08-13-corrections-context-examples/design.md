# Design: Ejemplos de contexto en transcripciones

## El problema de rendimiento manda sobre todo lo demás

`transcription_segments` son 20,6 M de filas y 8,3 GB. El **único** índice de texto es:

```
idx_transcription_segments_text_gin ON transcription_segments USING gin (text gin_trgm_ops)
```

Sobre `text`. **No** sobre `text_raw`, que no tiene ninguno, ni sobre `created_at`.

Todo el diseño se deriva de ese hecho.

```
        ┌─ buscar por text_raw ──────────────────────────────┐
        │  Parallel Seq Scan  cost=754.673   8,3 GB          │  ✗ tumbó producción
        └────────────────────────────────────────────────────┘

        ┌─ buscar por text ──────────────────────────────────┐
        │  Bitmap Index Scan on ..._text_gin  cost=553        │  ✓
        └────────────────────────────────────────────────────┘
```

## La consulta

```sql
SELECT id, transcription_id, segment_index, start_seconds, end_seconds, text_raw, text
FROM transcription_segments
WHERE text     ILIKE '%{probe}%'       -- condición indexable (GIN trigram)
  AND text_raw ILIKE '%{wrong_text}%'  -- filtro sobre las filas que el bitmap ya trajo
LIMIT 30
```

Plan real generado por el servicio, verificado con `EXPLAIN`:

```
Limit  (cost=553.45..2842.09 rows=1 width=198)
  ->  Bitmap Heap Scan on transcription_segments
        Recheck Cond: (text ~~* '%opportunidades%')
        Filter: (text_raw ~~* '%opportunidades%')
        ->  Bitmap Index Scan on idx_transcription_segments_text_gin
              Index Cond: (text ~~* '%opportunidades%')
```

Sin `ORDER BY`: ordenar por `created_at` obligaría a materializar todas las coincidencias sobre una columna sin índice.

### La sonda depende del estado de la corrección

```
   pending / rejected          approved
   ─────────────────           ────────
   la regla nunca se aplicó    el diccionario ya reescribió `text`
   → wrong_text sigue en       → wrong_text ya NO está ahí,
     `text`                      pero correct_text sí

   probe = wrong_text          probe = correct_text
```

Con fallback: si una aprobada devuelve 0 filas (su apply retroactivo nunca corrió), se reintenta con `wrong_text`.

### Guarda de longitud mínima

pg_trgm no puede servir patrones de menos de 3 caracteres. `text ILIKE '%of%'` degrada a `Seq Scan` sobre los 8,3 GB. Con sondas de <3 caracteres **no se consulta**: se devuelve `too_short`. Verificado: 0 consultas a `transcription_segments`.

### Otras guardas

| Guarda | Por qué |
|---|---|
| Solo bajo demanda (al abrir el modal) | Nunca al pintar la tabla, que dispararía N búsquedas |
| `SET LOCAL statement_timeout` | Un término raro puede tardar 7 s; al agotarse responde `timeout`, no un 500 |
| Caché con TTL de 7 días | Segunda apertura instantánea. La clave incluye `updated_at`, así que editar la corrección la invalida sola |
| Los `timeout` **no** se cachean | Es una condición transitoria de la BD; cachearla dejaría el botón de reintento inútil durante toda la ventana del TTL |

Costos medidos con `EXPLAIN (ANALYZE)`: 220 ms – 2,1 s en los casos probados; hasta 7,4 s con un término raro y caché de disco fría.

## El filtro que evita ejemplos engañosos

El `ILIKE` es un pre-filtro por **substring**, pero la aplicación real del diccionario exige **fronteras de palabra**. Sin descartar la diferencia se colaban ejemplos donde la regla nunca actuó.

Caso real detectado durante la construcción, con `ahorita → ahora`:

```
  raw: "Ahorita yo creo que ... la selección ahora es muy grande"
  text: idéntico  ← la regla no cambió nada aquí
```
Ambos `ILIKE` daban positivo (`ahorita` en `text_raw`, `ahora` en `text`), y el segmento se presentaba como evidencia de una regla que no había disparado.

La solución reutiliza el matcher real en vez de reimplementarlo:

```
CorrectionService::applyRule(Correction, string): string     ← NUEVO, público
        │
        └─ applyTextWithPairs()  ← el mismo matcher que usa el corrector,
                                    con las correcciones de frontera UTF-8
                                    de isWordCharAt()
```

Se descarta el segmento si `applyRule()` devuelve el texto sin cambios. Y el resultado se aprovecha dos veces: sirve de filtro **y** de campo `preview`, que es lo que el modal muestra como "cómo quedaría con esta regla" — la pregunta que el moderador se está haciendo. Nótese que `preview` **no** es `text`: `text` refleja el diccionario entero ya aplicado, no esta regla sola.

Reimplementar el matcher aparte habría sido un error: arrastra bugs corregidos con historia (`dise → de` rompía "diseño", `is → es` rompía "veintiséis").

## Deduplicación por transcripción

Cinco segmentos del mismo archivo no aportan más criterio que uno. Se traen 30 filas y se deduplica por `transcription_id` en PHP, quedándose con 5. En PHP y no con `DISTINCT ON` porque eso obligaría a ordenar todo el conjunto de coincidencias en la BD.

## Frontend

El resaltado usa lookarounds `(?<![\p{L}\p{N}])…(?![\p{L}\p{N}])`, la misma definición de frontera que `isWordCharAt()` en PHP, para que lo marcado coincida con lo que el corrector reemplazaría. Si el navegador no soporta lookbehind, degrada a coincidencia por substring: peor resaltado, nunca un error.

El texto viene de transcripciones y no es de confianza: **siempre** se escapa a HTML antes de insertar el `<mark>`.

## Archivos

| Archivo | Cambio |
|---|---|
| `app/app/Services/Ia/CorrectionContextFinder.php` | **nuevo** — la búsqueda, las guardas y la caché |
| `app/app/Services/Ia/CorrectionService.php` | `applyRule()` público: aplicar una sola regla |
| `app/app/Http/Controllers/Ia/CorreccionesController.php` | `contextExamples()` |
| `app/routes/web.php` | `GET /ia/correcciones/{id}/contexto` |
| `app/config/corrections.php`, `app/.env.example` | bloque `context` con las guardas |
| `app/resources/views/ia/correcciones/index.blade.php` | columna, modal, helpers Alpine |
| `app/app/Console/Commands/WarmCorrectionContextCommand.php` | **nuevo** — precalentado nocturno |
| `app/tests/Feature/CorreccionesContextTest.php` | **nuevo** — 9 tests |

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Término raro → búsqueda lenta | `statement_timeout` + estado `timeout` con botón de reintento |
| Muchos admins abriendo modales a la vez | Caché de 7 días; el precalentado nocturno cubre la cola de pendientes |
| Un `wrong_text` corto se cuela | Guarda de longitud mínima, testeada: 0 consultas |
| El corpus no tiene el término | Estado `no_matches` con explicación, no un modal vacío |
