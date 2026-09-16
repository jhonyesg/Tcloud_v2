# expose-all-transcriptor-settings-in-config-ui — Design

## Context

Ver `proposal.md` para motivación. El estado relevante para este design:

- 10 grupos en schema (4 ya no se renderizan en UI).
- 14 settings activos pero inaccesibles desde el modal de Configuración.
- Sin cambios al modelo, al schema, ni a config/transcriptor.php: solo el render de la UI.

## Goals / Non-Goals

**Goals:**

- 14 settings visibles en la UI Configuración.
- Labels legibles para los 4 grupos nuevos.
- Helps honestos sobre el alcance de cada grupo (incluida la advertencia de que `burst_*` solo aplica a uso manual del comando, no al cron).

**Non-Goals:**

- No tocar schema, config ni comandos.
- No introducir collapsed sections por grupo.
- No reordenar los grupos ya existentes.

## Decisions

### Decisión 1: orden narrativo de los grupos

**Elegido**:

```
ritmo, descubrimiento, api, workers, saturacion, burst, webhook, confiabilidad, ia, ui
```

**Por qué**:

- `ritmo` y `descubrimiento` van primero (lo más visible para el operador día a día).
- `api` después de los workers, porque siendo un módulo "engine", el orden cliente→servidor es workers (instancia local) → api (interfaz con upstream).
- `saturacion` después de api: las nuevas claves de circuit breaker e idempotency cubren el mismo dominio de la sección api, van agrupadas conceptualmente.
- `burst` después de saturacion: es una alternativa de despacho (manual) que el operador encuentra después de entender el resto.
- `webhook` después de burst: otro modo de transporte (entrante en vez de poll), lo menos frecuente.
- `confiabilidad` antes de ia: el cierre de jobs (polling, max_retries, srt, etc.) es operativo; el pase IA es post-procesado.
- `ia` antes de ui: la IA opera server-side, ui afecta cliente.
- `ui` al final, como sucede ya en otros módulos.

**Alternativa**: insertar nuevos entre los existentes sin lógica narrativa. Descartada porque al operador le ayuda entender el orden de lectura (fundacional → HTTP/saturacion → alt-transporte → recovery → post-process → cliente).

### Decisión 2: labels y helps

Cuatro grupos nuevos, cada uno con label legible y help honesto:

```
saturacion:
  label: 'Defensa contra saturación'
  help:   'Circuit breaker, idempotency y backoff. Protege a la API upstream de nuestros reintentos cuando va mal.'

burst:
  label: 'Ráfaga manual'
  help:   'Solo aplica si ejecutas `transcription:burst-dispatch` a mano. El cron automático NO usa este flujo todavía.'

webhook:
  label: 'Webhook entrante (experimental)'
  help:   'Recepción alternativa de resultados por webhook en vez de polling. Off por defecto; requiere coordinación con la API upstream (Fase D).'

ia:
  label: 'Pase de coherencia IA'
  help:   'Corrige con LLM los segmentos con inglés residual que el diccionario no cubre. Activo por defecto; usar LLM cuesta latencia y dinero, ajustá los topes si lo necesitás.'
```

**Por qué este wording**: cada help dice **explícitamente** lo que el setting toca y **advierte** si tiene caveats (burst no automático; webhook experimental; ia cuesta $).

### Decisión 3: no tocar schema ni config

**Elegido**: solo blade.

**Por qué**: la lógica de los 14 settings ya está bien. El config ya está alineado con el schema (lo arreglamos en el change anterior `transcriptor-pg-native-queue-tests-cleanup`). El único gap era de presentación.

**Alternativa**: agrupar settings en el schema para que el UI encuentre el orden. Descartada porque el schema ya está agrupado correctamente; el problema es solo de presentación.

### Decisión 4: orden de los keys dentro de cada grupo

**Elegido**: dejar el orden tal cual el schema (orden de declaración del SCHEMA en `TranscriptorSettings::SCHEMA`).

**Por qué**: el orden actual de cada grupo sigue un orden lógico (frecuencia de uso / dependencias). Re-ordenar requiere tocar el schema, fuera de scope.

**Cómo lo hace el UI**: `cfgKeysIn(group)` ya filtra los keys por grupo y respeta el orden de `Object.keys(this.cfgMeta)`, que coincide con el orden del schema.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Operador se confunde con `burst_*` porque el comando no corre automáticamente | Help text del grupo es explícito: "El cron automático NO usa este flujo". Se complementa con el `help` de cada setting interno (que ya existe). |
| Operador activa `submit_with_callback = true` sin configurar `webhook_secret` | El help del setting ya lo advierte ("Requiere TCLOUD_CALLBACK_URL configurado"). El de `webhook_secret` exige generar con `openssl rand -hex 32`. |
| Operador sube demasiado `submit_with_idempotency_key = false` y se llena de duplicados | El help del setting ya lo recomienda explícitamente. Sin embargo, no podemos evitar el error del operador; queda registrado. |
| El operador ve por primera vez los grupos `burst` y `webhook` y pregunta "¿y esto cuándo se usa?" | El help text del grupo ya responde. No requiere documentación extra. |

## Migration Plan

**Deploy:** ninguno especial. Cambio de un archivo Blade.

**Pasos:**

1. `git pull` con el commit.
2. (Opcional) `php artisan view:clear` si el cache de Blade está activo.
3. Smoke test en `/ia/api-transcriptor` (rol admin) → pestaña "Configuración" → ver los 4 grupos nuevos al final.

**Rollback:** `git revert <commit>`. Sin estado persistente que limpiar.

## Open Questions

Ninguna.
