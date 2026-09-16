# Spec: corrections-manual-only-cadence

## Purpose

Define la cadencia operativa del módulo de correcciones tras la decisión manual-only (2026-08-21, ratificada 2026-09-05): ningún proceso programado genera pendientes ni marcas de revisión automáticamente; todo el ciclo detectar → sugerir → moderar ocurre bajo demanda explícita del admin.

## Requirements

### Requirement: No scheduled process creates pending corrections or review flags

El scheduler SHALL NOT ejecutar ningún comando que inserte `corrections` (status pending) o escriba `transcription_reviews` (status needs_review). Los comandos `corrections:detect-english-residual` y `corrections:cycle-suggestions` dejan de estar agendados y quedan disponibles solo para ejecución manual.

#### Scenario: El listado de schedule no contiene los comandos
- **WHEN** admin corre `php artisan schedule:list`
- **THEN** la salida NO contiene `corrections:detect-english-residual` ni `corrections:cycle-suggestions`.

#### Scenario: Comandos siguen disponibles manualmente
- **WHEN** admin corre `php artisan corrections:detect-english-residual --hours=4 --threshold=0.5 --apply` o `php artisan corrections:cycle-suggestions --hours=4 ...` en un terminal
- **THEN** el comando se ejecuta como corrida única y reporta sus resultados en consola.

### Requirement: Corridas manuales masivas requieren confirmación explícita

Los comandos `corrections:detect-english-residual` y `corrections:cycle-suggestions` SHALL exigir la opción `--confirm` cuando corran con efectos de escritura en ventana amplia: `--apply` (detector) o inserción de reglas (cycle, sin `--dry-run`) con ventana mayor a 24 horas. Sin `--confirm`, el comando se comporta como dry-run y explica cómo habilitar la escritura.

#### Scenario: Apply sin confirm se niega
- **WHEN** admin corre `corrections:detect-english-residual --days=30 --apply` sin `--confirm`
- **THEN** el comando NO escribe en `transcription_reviews`, imprime el resumen del dry-run y advierte que se requiere `--confirm`.

#### Scenario: Apply confirmado ejecuta
- **WHEN** admin corre `corrections:detect-english-residual --days=30 --apply --confirm`
- **THEN** el comando escribe las marcas `needs_review` con el comportamiento idempotente existente (no pisa `correct`/`ignored`).

#### Scenario: Ventana pequeña sigue sin confirm
- **WHEN** admin corre `corrections:detect-english-residual --hours=4 --apply` sin `--confirm`
- **THEN** el comando ejecuta la escritura (ventana ≤ 24 h se considera de bajo impacto).

### Requirement: Master switch del suggester queda OFF persistido en BD

`llm-correction.enabled` SHALL estar persistido en `system_settings` con valor `0` para que el estado defaults-off del suggester no dependa de variables env ausentes. Ninguna ruta de código SHALL reactivar este valor salvo acción explícita del admin desde la UI.

#### Scenario: Estado del switch verificable
- **WHEN** se consulta `system_settings` para la clave `llm-correction.enabled`
- **THEN** el valor persistido es `0`.

#### Scenario: Botón AI Suggest sin activación manual
- **WHEN** el suggester corre vía botón con el switch en `0`
- **THEN** el endpoint responde 503 con el hint de habilitar el toggle en IA Suggest (comportamiento existente).
