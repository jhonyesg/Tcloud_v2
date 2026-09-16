# Change: Detector de segmentos con inglés residual en transcripciones ES

## Why

Los cambios `2026-08-11-corrections-english-residual-rules` (4 reglas) y `2026-08-11-corrections-english-residual-bulk-rules` (12 reglas) cubren patrones focales del corrector — frases recurrentes como `the gente`, `ahora is`, etc. — pero la auditoría del 2026-08-11 mostró que **el problema de fondo es estructural**:

- 17,057 transcripciones hoy, 2.28M segmentos, 0.91% modificados por el corrector.
- Patrones como `i think` (1,653/día), `you know` (1,635/día), `i mean` (255/día) NO son candidatos a reglas de diccionario (son code-switching legítimo).
- Segmentos enteros en inglés dentro de entrevistas bilingües (caso `#164215 camarafm_antioquia` con ~30 segmentos de rap Craig Mac) NO son errores del corrector, son transcripciones fieles — pero siguen apareciendo en el corpus sin que el admin tenga visibilidad de cuáles transcripciones acumulan más mezcla EN/ES.
- El UI actual de "Revisión de transcripciones" (alta: `TranscriptionReviewService`) solo lista las 10 más recientes y solo flaguea segmentos donde `text_raw != text`. Si la corrección del corrector no tocó nada, el segmento problemático es invisible.

**Hoy no existe un detector que mire el `text` post-corrección y emita una señal de "esta transcripción tiene mezcla EN/ES estructural que requiere atención humana"**.

Servicios ya disponibles que NO cubren este hueco:
- `EnEsMixMiner` → **propone reglas** al diccionario basadas en frecuencia de bigramas.
- `ContextShiftAuditor` → **clasifica reglas existentes** (falsos amigos, muletillas).
- `TranscriptionReviewService` → **lista y actualiza** revisiones humanas.
- Ninguno detecta segmentos cuya **proporción de tokens en inglés** supere un umbral.

## What Changes

### 1. Nuevo servicio `EnglishResidualSegmentDetector`

Encargado de puntuar segmentos y transcripciones por su grado de mezcla EN/ES residual. Componentes:

```text
EnglishResidualSegmentDetector
├── scoreSegment(text) → {score: float, en_tokens: int, es_tokens: int, total: int, hits: [{token, lang}]}
│       │ tokeniza, clasifica cada token, calcula ratio en/total
│       └ score = en_tokens / (en_tokens + es_tokens)
│
├── scoreTranscription(transcriptionId) → {transcription_id, avg_score, seg_count, flagged_seg_count, hits: [segIdx,...]}
│       │ itera sus segments, agrega resultados
│       └ devuelve summary para storage
│
├── findFlaggedTranscriptions(threshold, daysBack) → [transcription_id, score]
│       │ SQL-efficient: WHERE text ~* <english-markers>
│       └ retorna solo los que superen threshold
│
└── flagForReview(transcriptionId, score, hits) → TranscriptionReview
        │ upsert transcription_reviews status='needs_review'
        │ notes: incluye score, # segmentos flagged, primeros hits
        └ idempotente
```

### 2. Nuevo CLI command `corrections:detect-english-residual`

```bash
# Dry-run: muestra qué flagearía
php artisan corrections:detect-english-residual --days=1 --threshold=0.4

# Aplicar: marca needs_review en transcription_reviews
php artisan corrections:detect-english-residual --days=1 --threshold=0.4 --apply

# Una sola transcripción
php artisan corrections:detect-english-residual --id=165445 --apply

# Output JSON para integración
php artisan corrections:detect-english-residual --days=1 --json
```

Por defecto `--dry-run` (no toca BD). El operador decide cuándo aplicar.

### 3. Mecanismo de scoring

Cada token se clasifica en `en` / `es` / `unknown` según esta jerarquía:

1. **Lista de function words ingleses** (de `EnEsMixMiner::EN_FUNCTIONS`): `the, a, an, in, on, at, of, for, with, by, to, from, and, or, but, is, are, was, were, this, that, these, those, have, has, had, do, does, did, will, would, should, could, may, might, must, can` → `en`.
2. **Lista de stopwords españolas comunes**: `el, la, los, las, un, una, unos, unas, y, o, pero, que, de, en, a, por, para, con, sin, sobre, entre, es, son, era, eran, fue, fueron, ser, estar, tener, haber, este, esta, estos, estas, ese, esa, esos, esas, muy, más, menos, sí, no, ya, también, porque, como, cuando, donde, si, lo, le, les, me, te, se, nos, mi, tu, su, yo, tú, él, ella, ellos, ellas, nosotros, vosotros, ha, han, he, has, hemos, hay` → `es`.
3. **Acento ortográfico**: token que contenga `á, é, í, ó, ú, ñ` → `es`.
4. **Default**: anglicismos lexicalizados (`marketing`, `stress`, `tuit`) sin acento y sin en/es marker → `unknown` (no cuentan en el score).

```text
score = en_count / (en_count + es_count)
```

Un segmento de 10 tokens con 4 ingleses + 4 españoles + 2 unknown = 4/8 = 0.50 → flag si threshold <= 0.5.

### 4. Threshold y comportamiento

- Default `threshold=0.4` (40% tokens en inglés = mix severo).
- Configurable por CLI y por `config/corrections.php` (key: `english_residual.threshold`).
- Un segmento qualify con `score >= threshold` → se agrega a `hits[]` de la transcripción.
- Una transcripción qualifies si `len(hits) >= 1` (al menos un segmento).

### 5. Idempotencia

- `flagForReview()` usa `updateOrCreate` por `transcription_id` (mismo patrón que `TranscriptionReviewService::updateReview`).
- Si ya está `needs_review` con la misma `notes`, no re-escribe.
- Si está `correct` o `ignored`, **NO** se pisa (status humano protegible). El comando reporta estas como "skipped_manual".

### 6. Sin cambios al corrector

El detector **NO modifica** el `text` de los segmentos. Solo marca la transcripción para revisión humana. La regla `needs_review` queda visible en la UI existente de "Revisión de transcripciones".

### 7. Sin UI nueva

El UI actual de "Revisión" ya lista `needs_review` con badge ámbar. Las transcripciones flaggeadas aparecerán al recargar.

### 8. Compatibilidad con cron

El comando puede schedularse con `app/Console/Kernel.php` (cron diario). El operador decide si activarlo. Por defecto NO se programa automáticamente (decisión consciente).

## Non-goals

- No se traduce ni edita el `text` de los segmentos.
- No se agregan reglas al diccionario `corrections` (eso es `EnEsMixMiner`).
- No se pisa `status='correct'` o `status='ignored'` ya puestos por humanos.
- No se cambia el UI de "Revisión de transcripciones".
- No se programa cron automáticamente (decisión del operador).
- No se modifican `CorrectionService`, `EnEsMixMiner`, `ContextShiftAuditor`, `TranscriptionReviewService`.
- No se procesan segmentos en estados `pending`, `queued`, `processing`, `error`, `dead`.

## Success Criteria

- Existe `EnglishResidualSegmentDetector` con métodos públicos `scoreSegment`, `scoreTranscription`, `findFlaggedTranscriptions`, `flagForReview`.
- Existe CLI `php artisan corrections:detect-english-residual --days=1 --dry-run` que retorna una tabla con `transcription_id`, `score`, `flagged_segments`, `hits`.
- Idem con `--apply` actualiza `transcription_reviews` con `status='needs_review'`.
- Una transcripción con >40% de tokens en inglés en algún segmento recibe `needs_review` con nota que incluye el score y los primeros 3 hits.
- Re-correr el comando con `--apply` no duplica filas (idempotente), no pisa status humano (`correct`/`ignored`).
- El operador puede ejecutar el comando sobre una sola transcripción con `--id=X`.
- No hay cambios al `text` de ningún segmento.
- Las conversaciones existentes (`EnEsMixMiner`, `ContextShiftAuditor`, `TranscriptionReviewService`) no son modificadas.
