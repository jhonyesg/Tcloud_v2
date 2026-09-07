# avisos-scan-catchup Specification

## Purpose
TBD - created by archiving change 2026-09-07-avisos-scan-catchup. Update Purpose after archive.

## Requirements

### Requirement: Modo catch-up para drenar el histórico de transcripciones sin avisos

La sub-ventana de escaneo SHALL ofrecer un modo "Poner al día" (checkbox en "Escanear ahora") que drena las transcripciones terminadas sin avisos SIN límite de ventana, desde la más antigua, en tandas sucesivas de 50 con el modal de progreso acumulado. El proceso SHALL ser seguro de detener y retomar (idempotencia del motor: lo ya escaneado se salta). El cron automático SHALL NUNCA ejecutar catch-up: siempre respeta su ventana configurada.

#### Scenario: El admin pone al día el histórico gradualmente
- **WHEN** el admin activa "histórico completo" y lanza el escaneo
- **THEN** el modal muestra el total estimado (333k+), drena en tandas de 50 lo más antiguo primero, y puede detener y retomar otro día sin duplicar trabajo

#### Scenario: El cron automático no ejecuta catch-up
- **WHEN** corre el tick del cron automático mientras un catch-up está pendiente o en curso
- **THEN** el cron escanea solo su ventana configurada y nunca el histórico completo

#### Scenario: Magnitud visible antes de iniciar
- **WHEN** el admin activa "histórico completo"
- **THEN** el modal muestra la estimación total de transcripciones sin avisos y avisa que puede detener y retomar en cualquier momento
