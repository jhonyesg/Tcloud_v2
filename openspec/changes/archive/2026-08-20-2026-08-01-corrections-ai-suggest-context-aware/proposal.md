# Change: AI-powered corrector suggester con contexto y exclusión de marcas

## Why

Hoy tenemos dos mineros para correcciones EN↔ES:

1. **`corrections:mine-en-es` (rule-based)** — heurística + 71 pares conocidos. Rápido, barato, pero:
   - Solo cubre patrones estructurales (`in the world`, `of the government`).
   - La heurística de género gramatical produce errores (`of agua` → `de la agua` en vez de `del agua`).
   - No detecta palabras sueltas en inglés dentro de frases en español (`el proceso es very complex`, `el presidente dio un statement`).
   - El admin las tiene que corregir una por una en bulk moderation.

2. **El admin lo hace a mano** revisando SRTs — 5-10 min por ronda de revisión.

Adicionalmente el modelo de ASR del transcriptor cambió (mencionado por el admin el 2026-08-01): el nuevo modelo mezcla más inglés con español (interferencia del speaker + bias del modelo). Esto genera **patrones nuevos** que el rule-based miner no captura.

Necesitamos un tercer nivel: un **suggester con LLM** que:

- Lea muestras de transcripciones recientes (hoy/ayer)
- Entienda **contexto** (no solo la frase aislada)
- Detecte palabras/frases EN insertadas en ES
- Proponga candidatos con `correct` ES natural
- **NUNCA toque marcas, productos, nombres propios u organizaciones** (Dionato, Word Enterprise, Microsoft, etc.)
- Sea ejecutable on-demand y agendable (cron cada 4h)

El LLM objetivo es `minimax/minimax-m3` (mismo que corre este chat), accesible via Kilo Gateway con API OpenAI-compatible.

## What Changes

### Servicio nuevo: `App\Services\Ia\LlmCorrectionSuggester`

Toma una muestra de segmentos recientes y produce candidatos `{wrong, correct, freq, reason, strategy}` usando el LLM. Implementa filtros defensivos post-LLM para garantizar la regla de exclusión de marcas.

### Configuración: `config/llm-correction.php`

```
llm_correction:
  enabled: true|false
  provider: openai-compatible (fijo por ahora)
  base_url: env(LLM_BASE_URL) → ej. https://api.kilo.ai/v1
  api_key: env(LLM_API_KEY)
  model: env(LLM_MODEL) → ej. minimax/minimax-m3
  timeout_seconds: 60
  max_tokens: 4000
  temperature: 0.2
  sample_size_default: 200
  days_back_default: 1
  prompt_version: '2026-08-01'
```

### Comando: `corrections:ai-suggest`

```
php artisan corrections:ai-suggest --days=1 --sample=200 --dry-run
php artisan corrections:ai-suggest --days=2 --sample=300          # insert
```

Flags:
- `--days=N` (default 1)
- `--sample=N` (default 200, max 1000)
- `--dry-run` (no inserta)
- `--reset-disabled-pending` (borra pending del source con > 7 días sin aprobar; opcional, off por default)

### Wrapper: `CorrectionService::aiSuggestEnEsMix()`

Misma forma que `mineEnEsMix()` pero invoca el LLM. Idempotente. Source = `ai-suggest-YYYY-MM-DD`. Aplica filtro defensivo de marcas sobre las respuestas del LLM.

### Filtro defensivo de marcas / nombres propios

Doble barrera:

1. **En el prompt del sistema** (primera capa): instruye explícitamente al LLM que NO proponga cambios sobre:
   - Marcas registradas (Dionato, Microsoft, Sony, Apple)
   - Productos comerciales (Word Enterprise, Office 365, Salesforce)
   - Nombres de personas
   - Nombres de organizaciones
   - Siglas en mayúsculas sostenidas (ONU, EE.UU., USA)
   - Términos técnicos cuyo original es en inglés por convención (API, JSON, SQL)

2. **Post-filtro en PHP** (segunda capa): heurística que detecta patrones sospechosos:
   - Si `wrong` está completamente en mayúsculas (probable sigla) → descarta.
   - Si `wrong` empieza con mayúscula y NO es la primera palabra de la oración (probable nombre propio) → descarta.
   - Si `wrong` matchea una lista de marcas conocidas (Dionato, Word, Microsoft, Apple, Google, etc.) → descarta.
   - Si `wrong` contiene caracteres que en español son válidos solo en préstamos establecidos (ej. "k" en "kilo") → OK, deja pasar.
   - Marca el candidato como `confidence=low` si tiene dudas, así el admin lo revisa con más cuidado.

### Scheduling: cada 4 horas

```
Schedule::command('corrections:ai-suggest --days=1 --sample=200')
    ->everyFourHours()
    ->withoutOverlapping(120)
    ->name('corrections:ai-suggest-scheduled');
```

Cadencia justificada: el ASR nuevo puede introducir patrones nuevos en cualquier momento. Una corrida cada 4h mantiene el diccionario actualizado con coste bajo (~200 segmentos × 4 corridas/día × 30 días = ~24k tokens/día, ~$0.10-0.20/día con minimax m3).

### UI: badge separado "AI-suggest"

En el header de `/ia/correcciones`, agregar otro renglón bajo "Minería EN↔ES":

```
Minería EN↔ES:  [Última: hoy]  [N pendientes de minería]
AI Suggest:     [Última: hace 1h]  [M pendientes]  [por aprobar]
```

Endpoint `GET /ia/correcciones/ai-suggest-status` que retorna:
- `last_ai_suggest_at` (timestamp de la última corrida)
- `pending_from_ai_suggest` (count de pendientes)
- `last_run_status` (success/failed/dry_run)
- `last_run_summary` (Mined/Inserted/Skipped)

### Kilo Skill: `.kilo/skills/corrections-ai-suggest/SKILL.md`

Crea una skill invocable desde el chat: cuando el admin dice "dame nuevas sugerencias de corrección" o similar, Kilo carga el SKILL.md que documenta:
- Qué comando correr
- Qué archivos tocar
- Cómo verificar el resultado

Esto permite que el admin pida desde el chat "haz un scan con IA de las correcciones de hoy" y Kilo ejecute el comando apropiado + reporte resultados.

### Caché de resultados LLM (anti-duplicados)

Para no gastar tokens en el mismo segmento dos veces en un día, mantener una **caché ligera** de segmentos ya procesados por el LLM en el día actual:
- Hash SHA256 del `text_raw` (primeros 200 chars) → cache key
- TTL: 25 horas
- Store: Laravel cache (Redis o array según driver)

Si una corrida ve segmentos ya procesados, los skipea. Así las corridas cada 4h no desperdician cuota procesando los mismos segmentos.

## Non-goals

- **No auto-aprueba**: igual que el miner rule-based, solo propone pending. Admin sigue siendo el gatekeeper.
- **No detecta otros idiomas**: solo EN↔ES. FR↔ES, PT↔ES fuera de scope (no tenemos corpus).
- **No ejecuta en webhook SRT**: sigue siendo batch.
- **No reemplaza al miner rule-based**: lo complementa. El rule-based es rápido/cheap y cubre el grueso; el LLM captura el long-tail.
- **No intenta re-entrenar el ASR**: si el modelo sigue metiendo ruido, el diccionario lo absorbe; el training es responsabilidad del proveedor del transcriptor.
- **No modifica registros cuyo wrong sea un término técnico consolidado en español** (ej. "screenshot" → "captura de pantalla" es válido, pero "airpods" → "audífonos" depende de contexto y lo decide el admin).

## Impact

- **Specs affected**: `transcription-corrections` (3 nuevos ADDED requirements) + nuevo spec `llm-correction-suggestion`.
- **Code affected (nuevos)**:
  - `app/app/Services/Ia/LlmCorrectionSuggester.php`
  - `app/app/Services/Concerns/CallsLlmChatCompletion.php` (helper para POST a /chat/completions)
  - `app/app/Console/Commands/AiSuggestEnEsCorrectionsCommand.php`
  - `app/config/llm-correction.php`
  - `app/.kilocode/skills/corrections-ai-suggest/SKILL.md` (skill Kilo)
  - `app/tests/Feature/LlmCorrectionSuggesterTest.php`
  - `app/tests/Feature/AiSuggestCommandTest.php`
- **Code affected (modificados)**:
  - `app/app/Services/Ia/CorrectionService.php` (`+aiSuggestEnEsMix()`)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (`+aiSuggestStatus()`)
  - `app/routes/web.php` (`+GET /correcciones/ai-suggest-status`)
  - `app/routes/console.php` (`+Schedule everyFourHours`)
  - `app/resources/views/ia/correcciones/index.blade.php` (segundo renglón de badge)
  - `app/.env.example` (LLM_* vars)
  - `openspec/changes/2026-07-30-corrections-en-es-mix-miner/specs/transcription-corrections/spec.md` (3 ADDED)
  - `openspec/changes/2026-08-01-corrections-ai-suggest-context-aware/specs/llm-correction-suggestion/spec.md` (spec nuevo)
- **Migrations**: ninguna.
- **Costes operativos**: ~$3-6 USD/mes con minimax m3 + Kilo Gateway (200 segmentos × 6 corridas/día × 30 días).
- **Riesgos de seguridad**: la API key se guarda en env, nunca en código. El endpoint de Kilo gateway debe configurarse con allowlist de IPs o token restringido.

## Open questions (resueltas)

- **Proveedor LLM**: Kilo Gateway con `minimax/minimax-m3` (mismo modelo que este chat).
- **Cadencia**: cada 4 horas.
- **Sample size**: 200 segmentos por corrida.
- **Exclusión de marcas**: doble barrera (prompt + post-filtro PHP).
- **Idempotencia**: campo `source` + filtro de pending existentes + caché de segmentos procesados hoy.
