# Design: Detector de segmentos con inglés residual

## Overview

```text
┌──────────────────────────────────────────────────────────────┐
│              Pipeline propuesto                              │
│                                                              │
│  Comando CLI / opt API                                       │
│         │                                                    │
│         ▼                                                    │
│  EnglishResidualSegmentDetector                              │
│         │                                                    │
│         ├── scan window (days=1 por default)                 │
│         ├── scoreSegment(text) por cada segmento             │
│         ├── filtra por threshold (0.4)                       │
│         │                                                    │
│         ▼                                                    │
│  transcription_reviews                                       │
│         upsert status='needs_review' + notes                 │
│         (no pisa 'correct' / 'ignored')                      │
│                                                              │
│         ▼                                                    │
│  UI "Revisión de transcripciones"                            │
│  muestra las transcripciones flaggeadas                      │
│  con badge "Necesita revisión"                               │
└──────────────────────────────────────────────────────────────┘
```

## Algoritmo de scoring

### Tokenización

```php
// Separadores: whitespace + puntuación básica
$tokens = preg_split('/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
```

Cada token se pasa por `classifyToken()`:

```text
classifyToken(token) →
  1. if token in EN_FUNCTIONS → 'en'
  2. if token in ES_STOPWORDS → 'es'
  3. if token matches /[áéíóúñ]/u → 'es'
  4. else → 'unknown'
```

### Score

```text
score = en_count / (en_count + es_count)
```

- `en_count` = tokens clasificados como `en`
- `es_count` = tokens clasificados como `es`
- `unknown` se ignora (no cuenta en denominador ni numerador)

### Ejemplos

```text
"Lady, disculpame that I gave the notation to Maria Jose"
  └ tokens: lady, disculpame, that, i, gave, the, notation, to, maria, jose
  └ en:    that, i, gave, the, to           = 5
  └ es:    disculpame, maria, jose           = 3
  └ unknown: lady, notation                = 2
  └ score = 5 / (5+3) = 0.625  → FLAGGED (>= 0.4)
```

```text
"En este momento las cosas son diferentes"
  └ tokens: en, este, momento, las, cosas, son, diferentes
  └ en:    0
  └ es:    este, momento, las, cosas, son, diferentes = 6
  └ score = 0 / 6 = 0.0  → OK
```

```text
"And the brains booted with bad MG with stamina like Rooshina"
  └ tokens: and, the, brains, booted, with, bad, mg, with, stamina, like, rooshina
  └ en:    and, the, with, with, like  = 5
  └ es:    0  (ninguno es stopword ES o tiene acento)
  └ unknown: brains, booted, bad, mg, stamina, rooshina = 6
  └ score = 5 / 5 = 1.0  → FLAGGED (>= 0.4)
```

### Heurísticas explicítamente conservadoras

- **No se intenta clasificar nombres propios o anglicismos lexicalizados**. "MG", "Rooshina", "marketing" → `unknown`. Esto es por diseño: no queremos `score` sensible a esos tokens.
- **La métrica es `en_count vs es_count`**. Si un segmento tiene muchos tokens `unknown` (letras de canciones, nombres propios), el score se diluye por denominador pequeño. Espera: en el ejemplo "And the brains booted..." el denominador es solo `en+es=5`, no `total=11`. Si la mayoría son `unknown`, el score se infla artificialmente. **Esto es deseable**: una canción casi toda en inglés tiene muy poco español, y el score alto refleja eso.
- **No se usa heurística de terminaciones** (a/e → feminine, o → masculine) porque produce falsos positivos (`marketing` terminaría en -ing y se clasificaría como `en` por la palabra "marketing" del lado ES, no es trivial).

### Acento ortográfico

El chequeo `token matches /[áéíóúñ]/u` es robusto y rápido. Solo dispara en palabras con tilde o ñ, ambos inequívocamente españoles (no existen en inglés estándar).

## Configuración

```php
// config/corrections.php
return [
    // ...
    'english_residual' => [
        'threshold' => env('EN_RESIDUAL_THRESHOLD', 0.4),
        'en_functions' => EnEsMixMiner::EN_FUNCTIONS,
        'es_stopwords' => [
            'el','la','los','las','un','una','unos','unas',
            'y','o','pero','que','de','en','a','por','para','con',
            'sin','sobre','entre','es','son','era','eran','fue','fueron',
            'ser','estar','tener','haber',
            'este','esta','estos','estas','ese','esa','esos','esas',
            'muy','más','menos','sí','no','ya','también','porque','como',
            'cuando','donde','si','lo','le','les','me','te','se','nos',
            'mi','tu','su','yo','tú','él','ella','ellos','ellas',
            'nosotros','vosotros','ha','han','he','has','hemos','hay',
        ],
    ],
];
```

Las listas se centralizan en config para que un operador / admin pueda extenderlas sin tocar código.

## Data Model

No se agregan tablas. Solo se usa `transcription_reviews` (existente).

```text
transcription_reviews
  transcription_id  (unique implícito por upsert)
  status:           'needs_review'
  reviewed_by:      <admin_id del operador que corrió el comando>
  reviewed_at:      NOW()
  notes:            '<score> | <N> segmentos flagged | hits: <seg1>, <seg2>, <seg3>'
```

Ejemplo de notes:

```
"english_residual: score=0.62 | 4 segs flagged | segs=[39,42,43,200] | threshold=0.40"
```

Esta nota le da al admin (en la UI existente) el contexto necesario para abrir la transcripción y revisar los segmentos específicos.

## API del servicio

```php
namespace App\Services\Ia;

class EnglishResidualSegmentDetector
{
    public function scoreSegment(string $text): array;
    // Returns: ['score'=>float, 'en'=>int, 'es'=>int, 'unknown'=>int,
    //           'hits'=>[['token'=>'for','lang'=>'en'], ...]]

    public function scoreTranscription(int $transcriptionId): array;
    // Returns: ['transcription_id'=>int,
    //           'avg_score'=>float,
    //           'max_score'=>float,
    //           'flagged_segments'=>[['segment_index'=>int, 'score'=>float, 'text_preview'=>string]],
    //           'total_segments'=>int]

    public function findFlaggedTranscriptions(float $threshold, int $daysBack): array;
    // Returns: [['transcription_id'=>int, 'flagged_segments'=>int,
    //            'max_score'=>float, 'finished_at'=>Carbon], ...]

    public function flagForReview(int $transcriptionId, int $reviewerId, array $score): TranscriptionReview;
    // Upsert. Si status='correct'/'ignored', NO pisa (return existing).
}
```

## CLI command

```php
namespace App\Console\Commands;

class DetectEnglishResidualCommand extends Command
{
    protected $signature = 'corrections:detect-english-residual
                            {--days=1 : Ventana de análisis en días}
                            {--threshold=0.4 : Score mínimo para flag}
                            {--id=* : Solo estas transcripciones (omite ventana)}
                            {--apply : Persiste en transcription_reviews (default: dry-run)}
                            {--json : Output JSON para integración}';

    public function handle(EnglishResidualSegmentDetector $detector): int
    {
        // 1. resolver scope: --id o ventana de días
        // 2. findFlaggedTranscriptions(threshold, days)
        // 3. si dry-run: mostrar tabla + stats
        // 4. si --apply: flagForReview por cada uno, contando skipped_manual
        // 5. exit code 0 si éxito, 1 si error
    }
}
```

## Performance

- `findFlaggedTranscriptions(days=1)` itera ~17,000 transcripciones × 100 segments avg = 1.7M filas. **No** se carga `text` de todas; se hace SQL-level prefilter con `text ~* '\<english-marker\>'` (cheap regex), luego se cargan solo los segmentos de las transcripciones que pasan.
- Tokenización y scoring se hacen en PHP sobre texto cargado; 1.7M tokens × ~5 µs/token = ~8s de CPU. Aceptable para un comando manual.
- Para el cron (si se activa), el scan no debería correr en horas pico. Configurar ejecución nocturna.

## Edge cases

| Caso | Comportamiento |
|------|----------------|
| Segmento vacío `""` | score = 0, no flagged |
| Segmento con solo puntuación `". ,"` | tokens vacíos, score = 0 |
| Segmento en ES perfecto | score ≈ 0, no flagged |
| Segmento en EN puro (canción) | score ≈ 1.0, flagged (correcto: requiere revisión) |
| Spanglish legítimo (`"ok, perfecto"`) | 1 en, 1 es → score = 0.5, flagged (borderline, baja prioridad) |
| Nombre propio transcrito (`"Beatles"`) | "Beatles" es `unknown`, no afecta score |
| Status humano preexistente (`correct`/`ignored`) | respeta, no pisa. Reporta como `skipped_manual` |

## Verificación post-implementación

```bash
# Dry-run
php artisan corrections:detect-english-residual --days=1 --threshold=0.4
# Expected: tabla con transcripciones que tienen segmentos ES con score>=0.4

# Aplicar
php artisan corrections:detect-english-residual --days=1 --threshold=0.4 --apply
# Expected: N transcripciones con transcription_reviews.status='needs_review'

# UI
# /ia/correcciones → tab "Revisión" → lista muestra badge ámbar en las nuevas

# Idempotencia: re-ejecutar no debe crecer el counter
php artisan corrections:detect-english-residual --days=1 --apply
# Expected: "0 updated, N skipped (already needs_review)"
```

## Open Questions To Resolve During Implementation

- ¿El cron de scan nocturno debe existir por defecto? Recommend: NO, dejar al operador decidir.
- ¿La UI debe distinguir "needs_review por corrector" vs "needs_review por detector"? Ambos se ven igual hoy. Decisión: distinguir con un campo derivado (no persistido) en `TranscriptionReview` o vía prefijo en `notes`.
- ¿Qué hacer si una transcripción tiene MUCHOS segmentos flagged (ej. una entrevista entera en inglés)? El summary debe truncarse; no inundar la `notes`.
- ¿Vale la pena cachear el score por segmento (1.7M × ~50 bytes = ~85MB)? Si se activa el cron, sí. Si es solo manual, no necesario.
