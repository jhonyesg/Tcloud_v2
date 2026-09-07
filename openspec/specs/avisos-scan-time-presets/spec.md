# avisos-scan-time-presets Specification

## Purpose
Define los presets de ventana temporal del disparo manual "Escanear ahora" del módulo de avisos inteligentes: qué ventanas se ofrecen, cómo se resuelven a rangos de terminación de transcripciones, cómo interactúan con el modo histórico completo y qué estimado ve el admin antes de confirmar.

## Requirements

### Requirement: El modal de escaneo manual ofrece presets de ventana temporal

El modal de confirmación "Escanear ahora" SHALL ofrecer las opciones: Últimas 8 horas, Último día (24 h), Últimos 3 días, Últimos 7 días, Hoy y Rango personalizado. Al confirmar, el preset SHALL resolverse a un rango `from`–`to` sobre `transcriptions.finished_at` anclado al momento del disparo (8h = now−8h … 7d = now−168h; Hoy = medianoche local → now). El rango personalizado SHALL mostrar campos Desde/Hasta que acepten fecha y hora opcional.

#### Scenario: Admin escanea las últimas 8 horas
- **WHEN** el admin selecciona "Últimas 8 horas" y confirma
- **THEN** la corrida escanea únicamente transcripciones `done` con `finished_at >= now − 8h` que no tengan hits, respetando el storage si está filtrado

#### Scenario: Preset Hoy arranca en medianoche
- **WHEN** el admin selecciona "Hoy" a las 14:30
- **THEN** el rango efectivo es desde la medianoche de hoy hasta ahora, sin incluir el día anterior

#### Scenario: Rango personalizado con hora
- **WHEN** el admin elige "Rango personalizado" y fija Desde = 2026-09-06 14:00
- **THEN** el límite inferior del escaneo es ese momento exacto, no el inicio del día 2026-09-06

### Requirement: El estimado previo a la confirmación refleja los filtros elegidos

El estimado de "Pendientes estimados" que muestra el modal antes de iniciar SHALL calcularse aplicando el storage y la ventana/preset seleccionados (o el rango personalizado), no la ventana global del automático. Al cambiar de preset o de storage, el estimado SHALL refrescarse antes de habilitar "Iniciar escaneo".

#### Scenario: Estimado acotado al preset
- **WHEN** el admin selecciona "Último día" y el sistema muestra el resumen de confirmación
- **THEN** el número de pendientes corresponde a las transcripciones sin hits terminadas en las últimas 24 h (con el storage elegido si aplica), no al total histórico ni a la ventana global de 72 h

#### Scenario: Estimado del histórico completo no se ve afectado
- **WHEN** el admin marca "Histórico completo"
- **THEN** el estimado vuelve a abarcar todo el histórico sin rango, como hoy

### Requirement: Exclusividad entre presets de ventana e histórico completo

El modo "Histórico completo" (sin límite de fechas) y un preset/rango de ventana SHALL ser mutuamente excluyentes: seleccionar un preset desactiva y deshabilita el modo histórico, y activar el modo histórico desactiva y deshabilita los presets. El disparo nunca SHALL enviar simultáneamente `noWindow` y un rango explícito.

#### Scenario: Activar histórico apaga el preset
- **WHEN** el admin marca "Histórico completo" teniendo "Últimos 3 días" seleccionado
- **THEN** el selector de presets se deshabilita y la corrida se dispara con `noWindow` y sin rango

#### Scenario: Elegir preset desactiva histórico
- **WHEN** el admin con "Histórico completo" activo selecciona el preset "Últimas 8 horas"
- **THEN** la casilla de histórico se desmarca y la corrida se dispara con el rango de 8 horas

### Requirement: La corrida manual registra la ventana efectiva

Cada corrida manual SHALL dejar registrado en su log (`avisos.scan.run`) y en el resumen visible del historial el rango efectivo aplicado (preset elegido o rango personalizado resuelto), de modo que el admin pueda auditar qué ventana se escaneó.

#### Scenario: Auditoría de la ventana escaneada
- **WHEN** el admin lanza un escaneo con el preset "Últimos 7 días"
- **THEN** la corrida registrada muestra en el historial que se aplicó la ventana now−168h → now (o su rango resuelto equivalente)
