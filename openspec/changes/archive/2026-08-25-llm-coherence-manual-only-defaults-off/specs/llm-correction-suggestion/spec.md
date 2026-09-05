## ADDED Requirements

### Requirement: LLM correction master switch defaults to OFF

El toggle maestro `llm-correction.enabled` SHALL tener default `false` en el schema (`LlmCorrectionSettings::SCHEMA`). Un fresh-install o un `migrate:fresh` deja el suggester apagado por default — el admin debe prenderlo explícitamente desde `/ia/correcciones` → AI Settings o vía `.env` (`LLM_CORRECTION_ENABLED=true`).

Esta política ya está activa en producción desde el incidente del 2026-08-25 05:28 UTC, donde la hemorragia de tokens (~300 requests/6 min al gateway `api.minimax.io/v1` con HTTP 401) fue posible porque el toggle maestro había sido prendido por el deploy del 2026-08-19 sin que nadie lo revisara.

#### Scenario: Fresh install con defaults del código
- **WHEN** un admin corre `php artisan migrate:fresh --seed`
- **THEN** `SELECT value FROM system_settings WHERE key='llm-correction.enabled'` retorna `'0'`.
- **AND** `php artisan corrections:ai-suggest --days=1` sale con `WARNING LLM_CORRECTION_ENABLED=false (o override UI=false). Saliendo sin gastar tokens.` y código SUCCESS.
- **AND** los queue workers no invocan el suggester.

#### Scenario: Admin prende el toggle desde la UI
- **WHEN** admin abre AI Settings y activa "Switch maestro"
- **THEN** la fila `llm-correction.enabled` queda en `'1'`.
- **AND** los workers re-leídos en su próximo reinicio invocan `LlmCorrectionSuggester` para los suggestions manuales o programados.

#### Scenario: Admin desactiva desde .env por emergencia
- **WHEN** admin edita `.env` y pone `LLM_CORRECTION_ENABLED=false`
- **THEN** sin importar el valor en `system_settings`, el suggester considera el toggle apagado.
- **AND** `LlmCorrectionSettings::bool('enabled')` retorna `false` independientemente de la fila de DB.

### Requirement: Primary provider defaults to OFF

El toggle `llm-correction.primary_enabled` SHALL tener default `false` en el schema. El provider primario (Kilo Gateway) solo se usa si:

1. El admin lo activó explícitamente desde AI Settings, o
2. El admin lo activó vía `.env` (`LLM_PRIMARY_ENABLED=true`), o
3. El provider primario es el único `enabled` y se requiere para el round-robin del coherence pass.

#### Scenario: Kilo Gateway apagado por default
- **WHEN** un admin revisa AI Settings en una instalación nueva
- **THEN** el toggle "Proveedor primario (Kilo) habilitado" muestra estado apagado y un badge "manual-only".
- **AND** el coherence pass no invoca Kilo aunque `llm-correction.enabled=true`.

#### Scenario: Round-robin no incluye primary si está apagado
- **WHEN** el coherence pass arma la lista de providers en `callWithRetry()`
- **AND** `primary_enabled=false`
- **THEN** `primary` no aparece en `$providers[]`.
- **AND** el round-robin salta directo a `secondary/tertiary/quaternary` si están prendidos.
- **AND** si ninguno está prendido, lanza `RuntimeException('No hay proveedores LLM habilitados.')` como antes.

### Requirement: manual-only policy documented in suggester SPEC

El SPEC de `llm-correction-suggestion` SHALL explicitar que el suggester es **manual-only en default**: el admin decide cuándo correrlo (modal AI Suggest + botones 1-click del header en `/ia/correcciones`). El cron de scheduling (cada 4h) sigue deshabilitado del 2026-08-11 y esta Spec ratifica esa decisión: el suggester no se reactiva automáticamente en el scheduler aunque `llm-correction.enabled=true` en DB.

#### Scenario: Admin verifica el estado del scheduler
- **WHEN** admin corre `php artisan schedule:list`
- **THEN** la salida NO contiene `corrections:ai-suggest` en la lista de jobs agendados.
- **AND** el archivo `routes/console.php` mantiene el `corrections:ai-suggest` comentado en el bloque histórico del 2026-08-11.

#### Scenario: Admin quiere correr el suggester manualmente
- **WHEN** admin hace click en el botón "AI Suggest 1d" del header de `/ia/correcciones`
- **THEN** el comando se dispara como corrida on-demand única con `days=1`, `sample=200`, `dry-run=false` (o según config admin).
- **AND** los resultados se insertan como `pending` o se auto-aprueban según `auto_approve`.
