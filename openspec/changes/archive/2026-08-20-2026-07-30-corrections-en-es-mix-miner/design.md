# Design: Barrido histórico + miner EN↔ES

## 1. Componentes

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          FLUJO DEL MINER                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌──────────────┐     ┌─────────────────┐     ┌───────────────────────┐    │
│  │  Artisan Cmd │────▶│  EnEsMixMiner   │────▶│  CorrectionService::   │    │
│  │  mine-en-es  │     │  (multi-estrate-│     │  propose() en bulk     │    │
│  │  --days=30   │     │  gia)            │     │  → INSERT pending      │    │
│  └──────────────┘     └─────────────────┘     └───────────────────────┘    │
│                              │                         │                    │
│                              ▼                         ▼                    │
│                     ┌─────────────────┐     ┌───────────────────────┐    │
│                     │ Escanea corpus  │     │  correction_pending    │    │
│                     │ (transcription_ │     │  con source='mining-  │    │
│                     │  segments)      │     │  2026-07-30'         │    │
│                     └─────────────────┘     └───────────────────────┘    │
│                                                                             │
│                              ┌──────────────────────┐                     │
│                              │ Admin UI (/ia/      │                     │
│                              │  correcciones) con │                     │
│                              │  bulk moderation    │                     │
│                              │  (change 2026-07-30) │                     │
│                              └──────────────────────┘                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

## 2. `EnEsMixMiner` — algoritmo

### 2.1. Strategy A: Mapeos conocidos

Constant `KNOWN_EN_ES_MAPPINGS` (en `app/app/Services/Ia/EnEsMixMiner.php`). Inicia con los 50 del GRUPO A de bootstrapping + descubiertos posteriores:

```php
const KNOWN_EN_ES_MAPPINGS = [
    // === Estructurales EN→ES (50 GRUPO A de bootstrapping) ===
    'in the world'         => 'en el mundo',
    'of the world'         => 'del mundo',
    'at the end'           => 'al final',
    'all the time'         => 'todo el tiempo',
    'at the time'          => 'en ese momento',
    'of the people'        => 'de la gente',
    'of the year'          => 'del año',
    'at the moment'        => 'en este momento',
    'of the government'    => 'del gobierno',
    'in the history'       => 'en la historia',
    'of the day'           => 'del día',
    'in the region'        => 'en la región',
    'in the department'    => 'en el departamento',
    'of the president'     => 'del presidente',
    'in the city'          => 'en la ciudad',
    'of the night'         => 'de la noche',
    'of the department'    => 'del departamento',
    'and the people'       => 'y la gente',
    'in the market'        => 'en el mercado',
    'in the zone'          => 'en la zona',
    'of the community'     => 'de la comunidad',
    'of the state'         => 'del estado',
    'of the nation'        => 'de la nación',
    'at the same time'     => 'al mismo tiempo',
    'of the region'        => 'de la región',
    'in the territory'     => 'en el territorio',
    'in the area'          => 'en el área',
    'for the people'       => 'para la gente',
    'of the market'        => 'del mercado',
    'in the morning'       => 'en la mañana',
    'of the territory'     => 'del territorio',
    'with the people'      => 'con la gente',
    'and the government'   => 'y el gobierno',
    'in the country'       => 'en el país',
    'by the way'           => 'por cierto',
    'of the society'       => 'de la sociedad',
    'at the university'    => 'en la universidad',
    'with the community'   => 'con la comunidad',
    'for the moment'       => 'por el momento',
    'of the area'          => 'del área',
    'of the country'       => 'del país',
    'with the government'  => 'con el gobierno',
    'in the government'    => 'en el gobierno',
    'for the government'   => 'por el gobierno',
    'at the hospital'      => 'en el hospital',
    'at the beginning'     => 'al principio',
    'in the meantime'      => 'mientras tanto',

    // === Variantes adicionales (rounds 2-3) ===
    'in this moment'       => 'en este momento',
    'at this moment'       => 'en este momento',
    'in that moment'       => 'en ese momento',
    'at that moment'       => 'en ese momento',
    'in the system'        => 'en el sistema',
    'in the building'      => 'en el edificio',
    'to the world'         => 'al mundo',
    'for the world'        => 'para el mundo',
    'on the world'         => 'en el mundo',
    'with the world'       => 'con el mundo',
    'from the world'       => 'del mundo',
    'over the world'       => 'sobre el mundo',
    'around the world'     => 'por todo el mundo',
    'through the world'    => 'por el mundo',
    'into the world'       => 'en el mundo',
    'across the world'     => 'por todo el mundo',
    'throughout the world' => 'en todo el mundo',
    'within the world'     => 'dentro del mundo',
    'over and over'        => 'una y otra vez',
    'day and night'        => 'día y noche',
    'echo de menos'        => 'echado de menos',
];
```

**Algoritmo**:
```php
public function mineKnown(int $daysBack, int $minFreq): array
{
    $candidates = [];
    foreach (self::KNOWN_EN_ES_MAPPINGS as $wrong => $correct) {
        $count = DB::table('transcription_segments')
            ->where('created_at', '>=', now()->subDays($daysBack))
            ->where('text_raw', 'ILIKE', '%' . $wrong . '%')
            ->count();
        if ($count < $minFreq) continue;

        // Skip si ya está en el diccionario (approved)
        $alreadyApproved = Correction::approved()
            ->where('wrong_normalized', Keyword::asciiLower($wrong))
            ->exists();
        if ($alreadyApproved) continue;

        $candidates[] = [
            'wrong' => $wrong,
            'correct' => $correct,
            'freq' => $count,
            'strategy' => 'known',
        ];
    }
    return $candidates;
}
```

### 2.2. Strategy B: Detección abierta

```php
const EN_FUNCTIONS = [
    'the', 'a', 'an', 'in', 'on', 'at', 'of', 'for', 'with',
    'by', 'to', 'from', 'and', 'or', 'but', 'is', 'are', 'was',
    'were', 'this', 'that', 'these', 'those', 'have', 'has',
    'had', 'do', 'does', 'did', 'will', 'would', 'should',
    'could', 'may', 'might', 'must', 'can',
];

// Lista de high-frequency Spanish nouns (top 500). En producción se
// construye dinámicamente contando en el corpus.
const COMMON_ES_NOUNS = [
    'mundo', 'gente', 'gobierno', 'país', 'día', 'tiempo', 'momento',
    'presidente', 'ciudad', 'noche', 'comunidad', 'estado', 'nación',
    'región', 'departamento', 'zona', 'sociedad', 'historia',
    'mañana', 'centro', 'caso', 'momento', 'agencia', 'sistema',
    'edificio', 'hospital', 'universidad', 'programa', 'manera',
    'grupo', 'familia', 'equipo', 'problema', 'tema', 'punto',
    'idea', 'información', 'servicio', 'trabajo', 'parte',
    'número', 'lado', 'caso', 'hecho', 'palabra', 'agua',
    'dinero', 'área', 'fuerza', 'cambio', 'razón', 'nivel',
    // ... extender a top 500
];

public function mineOpen(int $daysBack, int $minFreq): array
{
    // 1. Buscar segmentos que contengan una EN_FUNCTION seguida de una ES_NOUN
    $candidates = [];
    $sample = DB::table('transcription_segments')
        ->where('created_at', '>=', now()->subDays($daysBack))
        ->whereNull('text_raw')
        ->inRandomOrder()
        ->limit(50000)  // muestra manejable
        ->get(['text_raw']);

    $hits = [];
    foreach ($sample as $row) {
        $tokens = preg_split('/\s+/', strtolower($row->text_raw));
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (!in_array($tokens[$i], self::EN_FUNCTIONS, true)) continue;
            $noun = preg_replace('/[^\pL]/u', '', $tokens[$i + 1] ?? '');
            if (!in_array($noun, self::COMMON_ES_NOUNS, true)) continue;

            $phrase = $tokens[$i] . ' ' . $noun;
            // Verificar word-boundary: no matchear "the" dentro de "weather"
            if (strlen($noun) > 0 && $tokens[$i] !== end($tokens)) {
                $hits[$phrase] = ($hits[$phrase] ?? 0) + 1;
            }
        }
    }

    // 2. Filtrar por frecuencia
    foreach ($hits as $phrase => $count) {
        if ($count < $minFreq) continue;

        // Convertir "in world" → "en el mundo" usando heurística
        $correct = $this->heuristicSpanish($phrase);
        if ($correct === null) continue; // no se pudo mapear

        // Skip si ya está como approved
        $alreadyApproved = Correction::approved()
            ->where('wrong_normalized', Keyword::asciiLower($phrase))
            ->exists();
        if ($alreadyApproved) continue;

        $candidates[] = [
            'wrong' => $phrase,
            'correct' => $correct,
            'freq' => $count,
            'strategy' => 'open',
        ];
    }
    return $candidates;
}

private function heuristicSpanish(string $phrase): ?string
{
    // Mapeo function_en → preposición_es
    $prepMap = [
        'in' => 'en', 'on' => 'en', 'at' => 'en', 'of' => 'de',
        'for' => 'para', 'with' => 'con', 'by' => 'por',
        'to' => 'a', 'from' => 'de', 'and' => 'y', 'or' => 'o',
    ];
    $parts = explode(' ', $phrase);
    if (count($parts) !== 2) return null;
    [$fn, $noun] = $parts;
    if (!isset($prepMap[$fn])) return null;
    // Detectar artículo: el/la/los/las según terminación
    $article = $this->guessArticle($noun);
    return $prepMap[$fn] . ' ' . $article . ' ' . $noun;
}

private function guessArticle(string $noun): string
{
    // Heurística muy básica; en producción se usa frecuencia real
    if (in_array(substr($noun, -1), ['a', 'e', 'd', 'ión', 'umbre', 'umbre'])) return 'la';
    return 'el';
}
```

> **Limitación conocida**: el heuristic article es ingenuo. Para algunos nouns (ej. `problema` → `el problema`, `gente` → `la gente`) está bien; para otros (ej. `día` → `el día`) también; pero casos como `momento` (masc), `parte` (fem), `programa` (masc) requieren un diccionario. Para v1, el miner acepta estos como propuestas con baja confianza; el admin los corrige en bulk moderation.

### 2.3. Punto de entrada unificado

```php
public function mine(int $daysBack, int $minFreq, string $strategy): array
{
    $candidates = [];
    if ($strategy === 'known' || $strategy === 'both') {
        $candidates = array_merge($candidates, $this->mineKnown($daysBack, $minFreq));
    }
    if ($strategy === 'open' || $strategy === 'both') {
        $candidates = array_merge($candidates, $this->mineOpen($daysBack, $minFreq));
    }
    return $candidates;
}
```

## 3. `CorrectionService::mineEnEsMix()`

```php
public function mineEnEsMix(int $daysBack, int $minFreq, string $strategy, User $by): array
{
    $miner = new EnEsMixMiner();
    $candidates = $miner->mine($daysBack, $minFreq, $strategy);

    $source = 'mining-' . now()->toDateString();
    $inserted = 0;
    foreach ($candidates as $c) {
        // Skip si ya existe pending con misma wrong_normalized
        $existingPending = Correction::pending()
            ->where('wrong_normalized', Keyword::asciiLower($c['wrong']))
            ->exists();
        if ($existingPending) continue;

        $correction = $this->propose($by, $c['wrong'], $c['correct']);
        $correction->source = $source;
        $correction->save();
        $inserted++;
    }

    return [
        'mined' => count($candidates),
        'inserted' => $inserted,
        'skipped_duplicate' => count($candidates) - $inserted,
        'candidates' => $candidates,
        'source' => $source,
    ];
}
```

Idempotente: si vuelvo a correr el miner, las reglas ya en pending no se duplican.

## 4. Artisan command

```php
class MineEnEsCorrectionsCommand extends Command
{
    protected $signature = 'corrections:mine-en-es
                            {--days=30 : Ventana de análisis}
                            {--min-freq=3 : Frecuencia mínima para proponer}
                            {--strategy=both : known|open|both}
                            {--dry-run : Solo muestra, no inserta}';

    public function handle(CorrectionService $service): int
    {
        $days = (int) $this->option('days');
        $minFreq = (int) $this->option('min-freq');
        $strategy = $this->option('strategy');
        $dryRun = (bool) $this->option('dry-run');

        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->error('No admin user found.');
            return self::FAILURE;
        }

        $this->info("Mining EN↔ES: days={$days}, min-freq={$minFreq}, strategy={$strategy}");

        if ($dryRun) {
            $miner = new EnEsMixMiner();
            $candidates = $miner->mine($days, $minFreq, $strategy);
            $this->table(
                ['Wrong', 'Correct', 'Freq', 'Strategy'],
                array_map(fn($c) => [$c['wrong'], $c['correct'], $c['freq'], $c['strategy']], $candidates)
            );
            $this->info("Dry-run: " . count($candidates) . " candidatos detectados. Usar sin --dry-run para insertar.");
            return self::SUCCESS;
        }

        $result = $service->mineEnEsMix($days, $minFreq, $strategy, $admin);
        $this->info("Mined: {$result['mined']}");
        $this->info("Inserted: {$result['inserted']}");
        $this->info("Skipped (duplicate pending): {$result['skipped_duplicate']}");
        $this->info("Source: {$result['source']}");
        return self::SUCCESS;
    }
}
```

## 5. Scheduling

`routes/console.php`:
```php
// Mining semanal de mezclas EN↔ES — corre los domingos a las 02:00.
Schedule::command('corrections:mine-en-es --days=14 --min-freq=5')
    ->weekly()->sundays()->at('02:00')
    ->withoutOverlapping(120)
    ->name('corrections:mine-en-es-scheduled');
```

Configurable via env `CORRECTIONS_MINING_DAYS` y `CORRECTIONS_MINING_MIN_FREQ` (opcional).

## 6. UI: badge "última minería"

Endpoint `GET /ia/correcciones/mining-status`:
```php
public function miningStatus()
{
    $lastBatch = Correction::pending()
        ->where('source', 'LIKE', 'mining-%')
        ->orderBy('created_at', 'desc')
        ->first();
    
    $pendingFromMining = Correction::pending()
        ->where('source', 'LIKE', 'mining-%')
        ->count();
    
    return response()->json([
        'last_mining_at' => $lastBatch?->created_at?->toIso8601String(),
        'pending_from_mining' => $pendingFromMining,
    ]);
}
```

En el header del `/ia/correcciones`:
```html
<div class="text-xs text-slate-500 mt-1">
    Última minería: <span x-text="miningStatusLabel"></span>
    · <span class="font-medium" x-text="pendingFromMining"></span> candidatos pendientes de minería
</div>
```

## 7. Tests

`app/tests/Feature/CorreccionesEnEsMixTest.php`:

- `test_known_mapping_mines_in_world_when_frequent_in_corpus()` — con corpus mock, verifica que `in the world` aparece como candidato.
- `test_known_mapping_skips_if_already_approved()` — si ya hay una approved con mismo normalized, no se propone.
- `test_known_mapping_respects_min_freq()` — solo propone si freq >= min_freq.
- `test_known_mapping_filters_by_days_back()` — solo segmentos dentro de la ventana.
- `test_open_strategy_finds_function_en_followed_by_es_noun()` — corpus con "in mundo", "of gente", se proponen.
- `test_open_strategy_skips_function_en_alone()` — "the" sin noun no es candidato.
- `test_idempotency_second_run_does_not_duplicate()` — correr 2 veces, no crea duplicados.
- `test_dry_run_does_not_insert()` — verificar que con --dry-run, no se insertan filas.
- `test_command_signature_has_options()` — reflection del comando.
- `test_miner_returns_array_with_strategy_keys()` — forma del retorno.

## 8. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Miner mete 500 candidatos spam | min-freq default 3 + filtrar por days_back; admin revisa en bulk |
| Strategy B tiene alta tasa de falsos positivos (heurística de artículo) | Marcar todos los candidatos de strategy=open con confianza baja; en el spec, documentar que la revisión admin es necesaria |
| Mining corre lento sobre 10M segments | El sample de 50k es manejable; usar índice en `created_at`; cache de frecuencias |
| Cron del miner compite con retroactivo | `withoutOverlapping(120)` previene concurrencia |
| `COMMON_ES_NOUNS` no incluye palabras técnicas (médica, política) | Lista es extensible; se puede agregar en config sin tocar código |
| Palabras que aparecen en EN y ES con misma forma (ej. "radio", "program") | El heuristic omite estas (no están en EN_FUNCTIONS ni en COMMON_ES_NOUNS conocidos) |