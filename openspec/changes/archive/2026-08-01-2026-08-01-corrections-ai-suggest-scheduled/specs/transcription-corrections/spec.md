## ADDED Requirements

### Requirement: AI suggest EN↔ES corre cada 2 horas de forma automática
El sistema SHALL programar `Schedule::command('corrections:ai-suggest --days=1 --sample=200')->everyTwoHours()->withoutOverlapping(10)` en `routes/console.php`, de modo que el suggester LLM-powered (`2026-08-01-corrections-ai-suggest-context-aware`) se ejecute ~12 veces al día **sin intervención del admin**. Cada corrida SHALL producir candidatos `pending` con `source='ai-suggest-YYYY-MM-DD'` para que el admin los apruebe desde `/ia/correcciones` y, una vez `approved`, las correcciones fluyan automáticamente a `CorrectionService::applyToSegments()` (SRT nuevos) y al retroactivo manual (`corrections:apply-run`). El botón "AI Suggest" manual SHALL seguir disponible para corridas fuera de schedule. La salida de cada corrida SHALL appendear a `storage/logs/ai-suggest-scheduled.log` para diagnóstico.

#### Scenario: El admin espera varias horas sin tocar el módulo
- **WHEN** el admin no interactúa con `/ia/correcciones` durante 8 horas
- **THEN** el scheduler habrá disparado ~4 corridas de `corrections:ai-suggest --days=1 --sample=200`, las nuevas `pending` están en la tabla `corrections` con `source='ai-suggest-YYYY-MM-DD'` y aparecen en el badge "AI Suggest" de la UI con su `last_ai_suggest_at` actualizado
- **THEN** el admin ve los nuevos pending al refrescar la pestaña y puede aprobarlos en lote

#### Scenario: Corrida automática coincide con botón manual
- **WHEN** la próxima corrida automática está por disparar Y el admin clickea "AI Suggest" en la UI al mismo tiempo
- **THEN** `withoutOverlapping(10)` previene que arranquen dos procesos LLM-burning simultáneos: el que llegó primero mantiene el lock 10 minutos; el otro espera o se salta (comportamiento determinista de `withoutOverlapping`)

#### Scenario: Corrida automática no descarrila por cambios de presupuesto
- **WHEN** el admin configura `enabled=false` en AI Settings (UI)
- **THEN** la corrida automática sale con warn `LLM_CORRECTION_ENABLED=false` y `exit 0` sin gastar tokens (defensa existente en `AiSuggestEnEsCorrectionsCommand::handle()`)

#### Scenario: Log persistente permite diagnosticar sin re-correr
- **WHEN** el admin o Kilo necesita ver qué hizo la última corrida automática
- **THEN** `storage/logs/ai-suggest-scheduled.log` contiene el stdout de la corrida (línea de inicio `AI suggest EN↔ES: days=1 sample=200 model=...`, contadores `Mined/Inserted/Skipped/Rejected`, cualquier warn/error del post-filtro)
- **THEN** NO se requiere re-correr el comando para diagnóstico — basta `tail -100 storage/logs/ai-suggest-scheduled.log`
