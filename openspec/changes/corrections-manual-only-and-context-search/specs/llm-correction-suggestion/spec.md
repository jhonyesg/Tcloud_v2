# Spec Delta: llm-correction-suggestion

## MODIFIED Requirements

### Requirement: AI-suggest runs every 4 hours via scheduler

**CAMBIO (2026-09-05)**: el schedule se elimina definitivamente. `corrections:ai-suggest` SHALL NOT estar agendado en `routes/console.php` en ninguna cadencia; la única vía de ejecución es la acción explícita del admin (POST `/ia/correcciones/ai-suggest-now` o `php artisan corrections:ai-suggest`).

#### Scenario: Cron dispara AI-suggest automático
- **WHEN** pasan 4 horas desde la última corrida
- **THEN** el scheduler NO ejecuta `corrections:ai-suggest`: la entrada fue eliminada de `routes/console.php` y no se consumen tokens de LLM.

#### Scenario: AI-suggest y otro proceso largo corren en paralelo
- **WHEN** hay un `corrections:apply-run` activo o un `corrections:mine-en-es` activo y el admin dispara el AI-suggest manualmente
- **THEN** la corrida manual no degrada al otro proceso: la idempotencia por caché de segmentos y el filtro de duplicados pending evitan efectos dobles.
- **THEN** sin cron, no existe colisión de `withoutOverlapping` que gestionar.

#### Scenario: La única vía es manual
- **WHEN** admin hace click en "AI Suggest 1d" del header de `/ia/correcciones` o corre `php artisan corrections:ai-suggest --days=1`
- **THEN** el comando corre como corrida única on-demand con el guardrail del master switch (`llm-correction.enabled`).

### Requirement: LLM correction master switch defaults to OFF

El toggle maestro `llm-correction.enabled` SHALL tener default `false` en el schema (`LlmCorrectionSettings::SCHEMA`) y SHALL estar persistido con valor `0` en `system_settings` tras este deploy (migración de settings idempotente). Con la fila persistida, el estado OFF deja de depender de la ausencia de variables env: estado real detectado 2026-09-05 — `.env` sin variables LLM y fila ausente hacían que el default env `true` ganara. Ningún proceso automático SHALL cambiar este valor.

Esta política está activa en producción desde el incidente del 2026-08-25 05:28 UTC (hemorragia de tokens con HTTP 401 por toggle maestro encendido sin revisión) y se ratifica el 2026-09-05 con la persistencia en BD.

#### Scenario: Fresh install con defaults del código
- **WHEN** un admin corre `php artisan migrate:fresh --seed`
- **THEN** `SELECT value FROM system_settings WHERE key='llm-correction.enabled'` retorna `'0'` (la migración de settings de este change persiste la fila).
- **AND** `php artisan corrections:ai-suggest --days=1` sale con el WARNING del switch apagado y código SUCCESS, sin gastar tokens.

#### Scenario: Admin prende el toggle desde la UI
- **WHEN** admin abre AI Settings y activa "Switch maestro"
- **THEN** la fila `llm-correction.enabled` queda en `'1'`.
- **AND** el suggester queda operativo solo para corridas manuales (no existe cron que lo invoque).

#### Scenario: Admin desactiva desde .env por emergencia
- **WHEN** admin edita `.env` y pone `LLM_CORRECTION_ENABLED=false`
- **THEN** sin importar el valor en `system_settings`, el suggester considera el toggle apagado.
- **AND** `LlmCorrectionSettings::bool('enabled')` retorna `false` independientemente de la fila de DB.