# Design: AI-powered corrector suggester con contexto y exclusión de marcas

## 1. Arquitectura general

```
┌──────────────────────────────────────────────────────────────────────────┐
│                          AI SUGGEST FLOW                                  │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────────┐    ┌───────────────────┐    ┌────────────────────┐    │
│  │ Artisan Cmd  │───▶│ LlmCorrection     │───▶│ LLM Provider        │    │
│  │ ai-suggest   │    │ Suggester         │    │ (Kilo Gateway,      │    │
│  │ --days=1     │    │                   │    │  minimax-m3)       │    │
│  │ --sample=200 │    └────────┬──────────┘    └────────────────────┘    │
│  └──────────────┘             │                                            │
│                               ▼                                            │
│                    ┌─────────────────────┐                                │
│                    │ Post-filter          │                                │
│                    │ (defense in depth)   │                                │
│                    │ - brand exclusion    │                                │
│                    │ - all-caps sigla     │                                │
│                    │ - proper noun regex  │                                │
│                    └────────┬────────────┘                                │
│                              ▼                                            │
│                    ┌─────────────────────┐                                │
│                    │ CorrectionService   │                                │
│                    │ ::aiSuggestEnEsMix()│                                │
│                    │ - idempotent        │                                │
│                    │ - source='ai-...-'  │                                │
│                    └────────┬────────────┘                                │
│                              ▼                                            │
│                    corrections (status=pending,                          │
│                                  source='ai-suggest-2026-08-01')        │
│                              │                                            │
│                              ▼                                            │
│                    ┌─────────────────────┐                                │
│                    │ Admin UI            │                                │
│                    │ /ia/correcciones    │                                │
│                    │ Badge "AI Suggest"  │                                │
│                    │ Bulk moderation     │                                │
│                    └─────────────────────┘                                │
└──────────────────────────────────────────────────────────────────────────┘
```

## 2. `LlmCorrectionSuggester` — algoritmo

### 2.1. Muestreo

```php
public function sampleSegments(int $days, int $sampleSize): array
{
    $since = now()->subDays(max(1, $days));
    return DB::table('transcription_segments')
        ->where('created_at', '>=', $since)
        ->whereNotNull('text_raw')
        ->where('text_raw', '!=', '')
        ->inRandomOrder()
        ->limit($sampleSize)
        ->get(['id', 'text_raw'])
        ->map(fn($r) => ['id' => $r->id, 'text' => (string) $r->text_raw])
        ->all();
}
```

200 segmentos es el sweet spot:
- < 100: poco recall de patrones raros.
- 200-500: balance coste/cobertura.
- 1000: gasto de tokens innecesario para el caso típico.

### 2.2. Caché de segmentos ya procesados hoy

Para no reprocesar los mismos segmentos en cada corrida cada 4h, usar Laravel cache:

```php
private function alreadyProcessedToday(int $segmentId): bool
{
    $key = "ai_suggest:processed:{$segmentId}:" . now()->toDateString();
    return Cache::has($key);
}

private function markProcessedToday(int $segmentId): void
{
    $key = "ai_suggest:processed:{$segmentId}:" . now()->toDateString();
    Cache::put($key, true, now()->addHours(25));
}
```

TTL 25h: cubre las 6 corridas/día + margen, y se borra automáticamente al día siguiente.

### 2.3. Construcción del prompt

#### System message (la regla de oro)

```
You are a bilingual EN↔ES correction analyst for a Spanish transcription system.

TASK: Detect English words or phrases that have been incorrectly inserted into
Spanish transcriptions. For each English insertion found, suggest a natural
Spanish replacement.

CRITICAL RULES — DO NOT VIOLATE:

1. NEVER propose changes to brand names, product names, or company names.
   These MUST stay as-is in their original language. Examples (non-exhaustive):
   - Software: Microsoft, Word, Excel, PowerPoint, Outlook, Teams, Salesforce,
     Slack, Zoom, Google, Drive, Gmail, Office 365, Word Enterprise, Office
   - Hardware/Cosumer: Apple, iPhone, MacBook, iPad, AirPods, Sony, Samsung
   - Colombian/Spanish brands: Dionato, Ecopetrol, Bancolombia, Comcel,
     Claro, Movistar, Tigo
   - News outlets (proper nouns): Caracol, RCN, Blu Radio, La W

2. NEVER propose changes to acronyms in all-caps (≥ 2 chars all caps).
   Examples: ONU, EE.UU., USA, API, JSON, SQL, HTTP, CC, CE, BPA, IVA, GPS.

3. NEVER propose changes to person names (first or last).

4. For English technical terms already established in Spanish by use
   (screenshot → captura de pantalla, login → inicio de sesión, etc.),
   you MAY propose the Spanish equivalent IF it's clearly a typo ASR
   artifact. Do NOT propose changes if the English word is the conventional
   term in the technical context of the media company.

5. For English words like "meeting", "statement", "schedule" when they
   appear alone in Spanish context, propose natural Spanish equivalents
   (reunión, declaración, agenda). For compound phrases ("the meeting is
   tomorrow"), propose natural Spanish rewrites.

6. ONLY suggest changes when the English is clearly an ASR-inserted word
   in Spanish narration. DO NOT propose changes to legitimate mixed Spanish-
   English speech (spanglish from a speaker who is bilingual).

OUTPUT FORMAT:
Return ONLY valid JSON, no prose around it. Schema:

{
  "candidates": [
    {
      "wrong": "exact English phrase as it appears in the segment",
      "correct": "natural Spanish replacement",
      "freq": 1,
      "reason": "short justification (max 80 chars)"
    }
  ]
}

If no candidates found, return: {"candidates": []}

Rules on duplicates:
- If the same `wrong` appears in multiple segments, INCREASE freq.
- Cap freq at the count of distinct segments where the wrong appears in
  this sample; do not hallucinate.

Rules on granularity:
- Prefer phrase-level over word-level when the phrase makes sense
  ("in the world" → "en el mundo", not just "in" → "en").
- Word-level is OK when no phrase context is needed ("the meeting" →
  "la reunión").

Be conservative. High precision over recall. Better to miss a pattern
than to propose false-positive corrections (especially on brand names).
```

#### User message

```
Below are {count} transcription segments from a Spanish radio/news broadcast
recorded in the last {days} days. Find English intrusions in Spanish
narration. Apply the rules above strictly. Return ONLY the JSON.

Segments (one per line, format: <n>: <text>):
{n}: {segment_text}
{n+1}: {segment_text}
...
```

### 2.4. Llamada al LLM

```php
private function callLlm(string $systemPrompt, string $userPrompt): array
{
    $cfg = config('llm-correction');
    $response = Http::withToken($cfg['api_key'])
        ->timeout($cfg['timeout_seconds'])
        ->post($cfg['base_url'] . '/chat/completions', [
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $cfg['temperature'],
            'max_tokens' => $cfg['max_tokens'],
            'response_format' => ['type' => 'json_object'],
        ]);

    if (!$response->successful()) {
        throw new \RuntimeException("LLM error: HTTP {$response->status()} " . $response->body());
    }

    $body = $response->json();
    $content = $body['choices'][0]['message']['content'] ?? '{}';
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || !isset($parsed['candidates'])) {
        throw new \RuntimeException("LLM returned invalid JSON: {$content}");
    }
    return $parsed['candidates'];
}
```

`response_format: json_object` es un parámetro de OpenAI-compatible que fuerza JSON. No todos los gateways lo soportan — si falla, hacer fallback a extracción regex.

### 2.5. Post-filtro defensivo (regla de marcas)

Dos filtros:

#### 2.5.1. Filtro regex / heurístico

```php
private function looksLikeBrandOrProperNoun(string $wrong): bool
{
    $wrongTrim = trim($wrong);
    if ($wrongTrim === '') return true;

    // 1. Sigla todo mayúsculas sostenida (≥ 2 chars)
    if (preg_match('/^[A-Z]{2,}$/', $wrongTrim)) return true;
    // Variante con espacios: "EE. UU."
    if (preg_match('/^(\b[A-Z]{2,}\.?\s*){2,}$/', $wrongTrim)) return true;

    // 2. Capitalización interna en una palabra de 2+ chars (probable nombre propio)
    // ej. "iPhone", "MacBook", "PowerPoint", "WordEnterprise" → todo es marca
    if (preg_match('/\B[A-Z][a-z]+|\b[A-Z]{2,}[a-z]+/', $wrongTrim)
        && !preg_match('/^[a-z]+ [a-z]+$/', $wrongTrim)) {
        // "in the" no tiene caps internas; "PowerPoint" sí.
        // Excluir frases totalmente lowercase (que es lo que debería proponer el LLM)
        if (preg_match('/[A-Z]/', $wrongTrim)) return true;
    }

    // 3. Lista explícita de marcas conocidas
    $knownBrands = [
        'microsoft', 'apple', 'google', 'sony', 'samsung', 'amazon', 'meta',
        'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok',
        'dionato', 'ecopetrol', 'bancolombia', 'comcel', 'claro',
        'movistar', 'tigo', 'caracol', 'rcn', 'blu radio', 'la w',
        'salesforce', 'slack', 'zoom', 'office', 'word', 'excel',
        'powerpoint', 'outlook', 'teams', 'office 365', 'word enterprise',
        'gmail', 'drive', 'iphone', 'macbook', 'ipad', 'airpods',
    ];
    $wrongLower = mb_strtolower($wrongTrim);
    foreach ($knownBrands as $brand) {
        if ($wrongLower === $brand) return true;
        // Match exacto o como sub-token: ej. "Word Enterprise" como frase
        if (str_contains($wrongLower, $brand) && strlen($wrongLower) < 30) {
            // Si la frase candidata es corta y contiene una marca conocida, descartar.
            return true;
        }
    }

    return false;
}
```

#### 2.5.2. Marca `confidence` baja para revisión adicional

Si el LLM está seguro de un candidato pero el filtro no lo descarta, se acepta. Si el filtro tiene dudas (ej. `length < 5` y contiene mayúscula), marca confidence=low para revisión adicional en UI.

### 2.6. Punto de entrada unificado

```php
public function suggest(int $days, int $sampleSize): array
{
    $segments = $this->sampleSegments($days, $sampleSize);
    // Filtrar ya procesados hoy (caché)
    $unprocessed = array_filter(
        $segments,
        fn($s) => !$this->alreadyProcessedToday($s['id'])
    );
    if (empty($unprocessed)) {
        return [
            'candidates' => [],
            'segments_processed' => 0,
            'cached_today' => count($segments),
            'source' => 'ai-suggest-' . now()->toDateString(),
        ];
    }

    // Construir prompt
    $userPrompt = $this->buildUserPrompt($unprocessed);
    $systemPrompt = $this->getSystemPrompt();

    // Llamar LLM
    $candidates = $this->callLlm($systemPrompt, $userPrompt);

    // Marcar como procesados
    foreach ($unprocessed as $s) {
        $this->markProcessedToday($s['id']);
    }

    // Post-filtro defensivo
    $accepted = [];
    $rejected = [];
    foreach ($candidates as $c) {
        if ($this->looksLikeBrandOrProperNoun($c['wrong'])) {
            $rejected[] = ['wrong' => $c['wrong'], 'reason' => 'looksLikeBrandOrProperNoun'];
            continue;
        }
        if (!$this->isApproved($c['wrong'])) {
            $accepted[] = [
                'wrong' => $c['wrong'],
                'correct' => $c['correct'],
                'freq' => max(1, (int) ($c['freq'] ?? 1)),
                'reason' => $c['reason'] ?? '',
                'strategy' => 'ai-suggest',
                'confidence' => 'normal',
            ];
        }
    }

    return [
        'candidates' => $accepted,
        'rejected_by_filter' => $rejected,
        'segments_processed' => count($unprocessed),
        'cached_today' => count($segments) - count($unprocessed),
        'source' => 'ai-suggest-' . now()->toDateString(),
    ];
}
```

## 3. Config layer: `config/llm-correction.php`

```php
<?php

return [
    'enabled' => env('LLM_CORRECTION_ENABLED', true),
    'provider' => 'openai-compatible',

    'base_url' => env('LLM_BASE_URL', 'https://api.kilo.ai/v1'),
    'api_key' => env('LLM_API_KEY'),
    'model' => env('LLM_MODEL', 'minimax/minimax-m3'),

    'timeout_seconds' => (int) env('LLM_TIMEOUT_SECONDS', 60),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 4000),
    'temperature' => (float) env('LLM_TEMPERATURE', 0.2),
    'sample_size_default' => (int) env('LLM_SAMPLE_SIZE_DEFAULT', 200),
    'days_back_default' => (int) env('LLM_DAYS_BACK_DEFAULT', 1),

    'prompt_version' => '2026-08-01',

    // Lista blanca explícita de marcas protegidas (segunda capa).
    // El prompt también incluye esta lista; aquí está por si se actualiza
    // sin redeployar el código.
    'protected_brands' => [
        'dionato', 'microsoft', 'apple', 'google', 'sony', 'samsung',
        'amazon', 'meta', 'salesforce', 'slack', 'zoom',
        'office', 'word', 'excel', 'powerpoint', 'outlook', 'teams',
        'gmail', 'drive', 'iphone', 'macbook', 'ipad', 'airpods',
        'ecopetrol', 'bancolombia', 'comcel', 'claro', 'movistar', 'tigo',
        'caracol', 'rcn',
    ],
];
```

### `.env.example` additions

```
LLM_CORRECTION_ENABLED=true
LLM_BASE_URL=https://api.kilo.ai/v1
LLM_API_KEY=sk-...
LLM_MODEL=minimax/minimax-m3
LLM_TIMEOUT_SECONDS=60
LLM_MAX_TOKENS=4000
LLM_TEMPERATURE=0.2
LLM_SAMPLE_SIZE_DEFAULT=200
LLM_DAYS_BACK_DEFAULT=1
```

## 4. `CorrectionService::aiSuggestEnEsMix()`

```php
public function aiSuggestEnEsMix(int $days, int $sampleSize, User $by): array
{
    $suggester = new LlmCorrectionSuggester();
    $result = $suggester->suggest($days, $sampleSize);

    $source = $result['source'];
    $inserted = 0;
    $skipped = 0;
    foreach ($result['candidates'] as $candidate) {
        if ($candidate['confidence'] === 'low') {
            // confidence=low requiere revisión admin adicional
            // (no implementado en v1, ver design §6 "futuras mejoras")
        }
        $existing = Correction::pending()
            ->where('wrong_normalized', Keyword::asciiLower($candidate['wrong']))
            ->exists();
        if ($existing) {
            $skipped++;
            continue;
        }
        $correction = $this->propose($by, $candidate['wrong'], $candidate['correct']);
        $correction->source = $source;
        $correction->save();
        $inserted++;
    }

    return [
        'mined' => count($result['candidates']),
        'inserted' => $inserted,
        'skipped_duplicate' => $skipped,
        'rejected_by_filter' => count($result['rejected_by_filter']),
        'segments_processed' => $result['segments_processed'],
        'cached_today' => $result['cached_today'],
        'source' => $source,
    ];
}
```

## 5. Artisan command

```php
class AiSuggestEnEsCorrectionsCommand extends Command
{
    protected $signature = 'corrections:ai-suggest
                            {--days= : Ventana de días (default config llm-correction.days_back_default)}
                            {--sample= : Tamaño de muestra (default 200)}
                            {--dry-run : Solo muestra, no inserta}';

    public function handle(CorrectionService $service): int
    {
        if (!config('llm-correction.enabled')) {
            $this->warn('LLM_CORRECTION_ENABLED=false. Saliendo.');
            return self::SUCCESS;
        }
        if (empty(config('llm-correction.api_key'))) {
            $this->error('LLM_API_KEY no configurada.');
            return self::FAILURE;
        }

        $days = (int) ($this->option('days') ?? config('llm-correction.days_back_default'));
        $sample = (int) ($this->option('sample') ?? config('llm-correction.sample_size_default'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info("AI suggest EN↔ES: days={$days} sample={$sample}" . ($dryRun ? ' [DRY-RUN]' : ''));

        if ($dryRun) {
            $suggester = new LlmCorrectionSuggester();
            $result = $suggester->suggest($days, $sample);
            $this->table(
                ['Wrong', 'Correct', 'Freq', 'Confidence', 'Reason'],
                array_map(
                    fn($c) => [$c['wrong'], $c['correct'], $c['freq'], $c['confidence'], $c['reason']],
                    $result['candidates']
                )
            );
            $this->info("Rechazados por filtro de marcas: " . count($result['rejected_by_filter']));
            $this->info("Segments procesados: {$result['segments_processed']}, cacheados hoy: {$result['cached_today']}");
            return self::SUCCESS;
        }

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        if (!$admin) return self::FAILURE;

        $result = $service->aiSuggestEnEsMix($days, $sample, $admin);
        $this->info("Mined: {$result['mined']}");
        $this->info("Inserted: {$result['inserted']}");
        $this->info("Skipped (pending duplicado): {$result['skipped_duplicate']}");
        $this->info("Rechazados por filtro: {$result['rejected_by_filter']}");
        $this->info("Source: {$result['source']}");
        return self::SUCCESS;
    }
}
```

## 6. Scheduling

```php
Schedule::command('corrections:ai-suggest')
    ->everyFourHours()
    ->withoutOverlapping(120)
    ->name('corrections:ai-suggest-scheduled')
    ->withoutOverlapping(120);
```

## 7. UI: badge AI-suggest

Endpoint `GET /ia/correcciones/ai-suggest-status`:

```php
public function aiSuggestStatus()
{
    $lastAi = Correction::query()
        ->where('source', 'LIKE', 'ai-suggest-%')
        ->orderByDesc('created_at')
        ->first(['created_at']);

    $pending = Correction::pending()
        ->where('source', 'LIKE', 'ai-suggest-%')
        ->count();

    return response()->json([
        'last_ai_suggest_at' => $lastAi?->created_at?->toIso8601String(),
        'pending_from_ai_suggest' => $pending,
    ]);
}
```

En el header de `/ia/correcciones/index.blade.php`:

```html
<div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
    <span>Minería EN↔ES:</span>
    <span class="px-2 py-0.5 rounded-full font-medium"
          :class="miningStatusBadgeClass"
          x-text="miningStatusLabel"></span>
    <template x-if="miningStatus?.pending_from_mining > 0">
        <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium">
            <span x-text="miningStatus?.pending_from_mining"></span> pendientes
        </span>
    </template>
</div>
<div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
    <span>AI Suggest:</span>
    <span class="px-2 py-0.5 rounded-full font-medium"
          :class="aiSuggestBadgeClass"
          x-text="aiSuggestLabel"></span>
    <template x-if="aiSuggestStatus?.pending_from_ai_suggest > 0">
        <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 font-medium">
            <span x-text="aiSuggestStatus?.pending_from_ai_suggest"></span> por aprobar
        </span>
    </template>
</div>
```

Alpine additions:
- `aiSuggestStatus: null`
- `async loadAiSuggestStatus()`
- `get aiSuggestLabel()`
- `get aiSuggestBadgeClass()` (verde < 12h, amarillo 12-24h, rojo > 24h; porque la cadencia es 4h, queremos ver si se saltó una corrida)

## 8. Kilo skill: `.kilocode/skills/corrections-ai-suggest/SKILL.md`

```markdown
# corrections-ai-suggest

Esta skill invoca el suggester LLM-powered para sugerir correcciones
EN↔ES con contexto y exclusión de marcas.

## Cuándo cargar esta skill

Cargar cuando el admin pida:
- "dame nuevas sugerencias de corrección"
- "haz un scan con IA"
- "corre las mezclas de inglés en español"
- "ejecuta el miner de IA"
- "qué oportunidades de corrección hay hoy"
- Similar.

## Comandos a ejecutar

### Scan bajo demanda (on-demand)

\`\`\`bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan corrections:ai-suggest --days=1 --sample=200 --dry-run
\`\`\`

Si el admin quiere insertar:
\`\`\`bash
php artisan corrections:ai-suggest --days=1 --sample=200
\`\`\`

### Scan más amplio (semanal)

\`\`\`bash
php artisan corrections:ai-suggest --days=7 --sample=500
\`\`\`

## Después del comando

1. **Si dry-run**: presentar tabla al admin. Preguntar si insertar.
2. **Si real**: reportar counts (Mined/Inserted/Skipped/Rechazados).
3. **Sugerir verificación manual**: el admin debe ir a `/ia/correcciones`,
   filtrar por source `ai-suggest-YYYY-MM-DD`, revisar y aprobar/rechazar
   en bulk.

## Qué revisar en la salida

- **Rechazados por filtro**: indica candidatos que el LLM propuso sobre
  marcas o nombres propios. Útil para auditar.
- **Inserted**: candidatos que pasaron todos los filtros y están en
  pending. Deberían ser aprobados o rechazados por el admin.
- **Skipped (pending duplicado)**: si es > 0, significa que ya existían
  reglas similares en pending; el LLM no las duplicó (idempotencia OK).

## Configuración

Variables env:
- `LLM_API_KEY` (obligatoria)
- `LLM_BASE_URL` (default `https://api.kilo.ai/v1`)
- `LLM_MODEL` (default `minimax/minimax-m3`)

Editar en `.env` del proyecto, no en código.

## Frecuencia automática

Si está habilitada (default), corre cada 4 horas vía Laravel scheduler.
Verificar con:

\`\`\`bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan schedule:list
\`\`\`
```

## 9. Tests

`app/tests/Feature/LlmCorrectionSuggesterTest.php`:

- `test_looks_like_brand_or_proper_noun_detects_all_caps_sigla()` — `ONU`, `EE.UU.`, `USA`.
- `test_looks_like_brand_or_proper_noun_detects_known_brand()` — `Dionato`, `Word Enterprise`, `iPhone`.
- `test_looks_like_brand_or_proper_noun_detects_internal_capitalization()` — `MacBook`, `PowerPoint`, `iPhone`.
- `test_looks_like_brand_or_proper_noun_allows_lowercase_english_phrase()` — `in the world`, `of the government`.
- `test_prompt_system_message_contains_brand_exclusion_rules()` — el system prompt contiene las reglas críticas.
- `test_already_processed_today_caches_correctly()` — seg call idempotente.
- `test_call_llm_throws_on_http_error()` — manejo de errores HTTP.
- `test_call_llm_parses_json_response()` — parses OK.
- `test_call_llm_handles_invalid_json()` — excepción con mensaje claro.

`app/tests/Feature/AiSuggestCommandTest.php`:

- `test_command_signature_has_options()` — reflection.
- `test_command_skips_when_disabled()` — LLM_CORRECTION_ENABLED=false → no hace nada.
- `test_command_errors_when_api_key_missing()` — sin LLM_API_KEY → FAILURE con mensaje claro.
- `test_idempotency_second_run_does_not_duplicate()` — correr 2 veces, no crea duplicados.

## 10. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| LLM tarda o falla | `withoutOverlapping(120)` + manejo de excepciones; la app sigue funcionando sin sugerir |
| LLM mete marca en candidato | Doble barrera: prompt explícito + post-filtro PHP basado en regex/lista |
| Coste de API se dispara | Cache de segmentos procesados hoy (`TTL=25h`); sample máximo configurable; `enabled=false` para deshabilitar |
| Cache crece mucho | Solo guarda `boolean: true` por (segment_id, fecha); Redis/array driver lo maneja eficiente |
| LLM alucina correcciones absurdas | `temperature: 0.2` (baja creatividad); system prompt conservador; `response_format: json_object` |
| Cambiar modelo degrada calidad | `LLM_MODEL` configurable vía env sin deploy; prompt versionado en `prompt_version` |
| Mark como `pending` y nunca se aprueba | Igual que con miner rule-based; UI badge "AI Suggest" lo hace visible |
| Filtrar por source no captura el flujo admin | El filtro de pendientes ya está implementado en cambios previos (`sourceFilter` en Alpine) |

## 11. Futuras mejoras (no en v1)

- **Score de confianza**: prompt al LLM para que devuelva `confidence: low|medium|high` por candidato; UI muestra low en amarillo.
- **Re-entrenamiento del prompt**: guardar pares wrong→correct aprobados de AI-suggest y, cada cierto tiempo, ajustar el prompt con esos ejemplos (few-shot).
- **Soporte multi-idioma**: extender a PT↔ES, FR↔ES cuando haya corpus.
- **WebSocket de progreso**: emitir progreso cada N segmentos procesados vía Reverb/Pusher para mostrar en UI.
- **Auto-aprobación**: cuando confidence=high y freq=alta (>10), sugerir aprobar directamente. Pero esto requiere confianza muy alta en el LLM y queda fuera del MVP.
