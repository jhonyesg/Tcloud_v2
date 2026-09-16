## Purpose

Define el comportamiento observable del widget flotante global que muestra el estado de los jobs en background lanzados desde los módulos de IA (API Transcriptor, Avisos Inteligentes, etc.), incluyendo la política de expiración de tarjetas y la persistencia del descarte manual del operador.

## ADDED Requirements

### Requirement: Jobs completados MUST ocultarse automáticamente después de un tiempo corto

El sistema MUST ocultar del widget toda tarjeta cuyo job haya alcanzado estado terminal (`done`, `partial`, `error`, `cancelled`, `queued`) después de un periodo fijo de gracia, sin intervención del operador.

#### Scenario: Job de scan de storages del API Transcriptor termina OK y desaparece del widget a los 5 minutos
- **WHEN** el operador lanza un escaneo desde el botón "Escanear storages" y el worker finaliza exitosamente, marcando `status=done` y guardando `finishedAt` en el estado del run
- **THEN** la tarjeta permanece visible durante 5 minutos mostrando el resumen final ("✓ N pendientes · M encolados") y desaparece del widget al cumplirse ese plazo, sin reaparecer en recargas posteriores

#### Scenario: Job de scan del API Transcriptor termina con error y desaparece del widget a los 5 minutos
- **WHEN** el operador lanza un escaneo y el worker termina con `status=error`
- **THEN** la tarjeta permanece visible durante 5 minutos mostrando la etiqueta "✗" y el conteo de storages con fallo, y desaparece al cumplirse el plazo

### Requirement: El estado terminal del run MUST quedar registrado al finalizar el job

El sistema MUST garantizar que cuando un job en background del API Transcriptor termina (por éxito, error o cancelación), su entrada en el registro de runs activos quede con un campo de timestamp de finalización poblado, independientemente de si el runId ya estaba registrado al arrancar.

#### Scenario: RunId registrado como string plano al arrancar queda con timestamp al terminar
- **WHEN** el controller registra un runId en la lista de runs activos en formato string al lanzar el worker, y luego el worker termina su ejecución
- **THEN** la entrada del runId en la lista de runs activos queda con forma `{runId, finishedAt}` con el timestamp real de finalización, no permanece como string plano

#### Scenario: RunId ya registrado como array al arrancar conserva su timestamp actualizado
- **WHEN** el worker termina un job cuyo runId ya estaba registrado con `finishedAt` previo (caso de re-entry o reintento)
- **THEN** la entrada queda con `finishedAt` igual al timestamp de esta nueva finalización, sobrescribiendo cualquier valor anterior

### Requirement: Descartes manuales del operador MUST mantenerse por al menos 24 horas

El sistema MUST recordar la decisión del operador de ocultar una tarjeta específica durante al menos 24 horas, de modo que la misma tarjeta no vuelva a aparecer tras una recarga de página en ese periodo.

#### Scenario: Operador descarta un card de un job completado y recarga 30 minutos después
- **WHEN** el operador hace click en el botón "×" de una tarjeta de un job completado y luego recarga la página 30 minutos después
- **THEN** esa tarjeta NO vuelve a aparecer en el widget durante esa sesión de recarga

#### Scenario: Operador descarta un card y recarga 25 horas después
- **WHEN** el operador hace click en el botón "×" de una tarjeta y recarga la página 25 horas después
- **THEN** el widget puede volver a mostrar esa tarjeta si el run sigue figurando como activo en el backend (caso aceptado: el job hace mucho que no debería estar activo)

### Requirement: Las tarjetas de jobs activos en ejecución MUST seguir apareciendo en cada recarga

El sistema MUST seguir mostrando en cada recarga del navegador toda tarjeta cuyo job esté efectivamente corriendo (estado no terminal), independientemente de descartes manuales anteriores que pudieran existir de una corrida previa con el mismo runId.

#### Scenario: Mismo runId se vuelve a ejecutar tras haber sido descartado
- **WHEN** un runId fue descartado por el operador en una corrida anterior y luego el mismo runId arranca un nuevo ciclo de ejecución
- **THEN** la tarjeta vuelve a aparecer en el widget porque el job está actualmente activo

### Requirement: El descarte manual MUST persistirse localmente en el navegador

El sistema MUST persistir en `localStorage` del navegador el conjunto de runIds descartados por el operador, de modo que la decisión se mantenga entre recargas y entre pestañas del mismo origen.

#### Scenario: Operador descarta dos tarjetas en una pestaña y abre otra pestaña del mismo origen
- **WHEN** el operador descarta dos tarjetas en una pestaña y luego abre otra pestaña del mismo origen sin recargar
- **THEN** las dos tarjetas descartadas NO aparecen en el widget de la nueva pestaña en su próxima consulta

### Requirement: Entradas activas legacy sin timestamp de finalización MUST descartarse por fallback

El sistema MUST poder descartar entradas de runs activos que no tengan campo `finishedAt` poblado, usando como fallback el campo `updated_at` (o `finished_at`) de la cache individual del run, de modo que entradas huérfanas pre-existentes no queden atrapadas para siempre en el widget.

#### Scenario: Entrada-string legacy con cache individual caliente
- **WHEN** existe una entrada en la lista de runs activos como string plano (sin `finishedAt`) y su cache individual `transcription_batch:{runId}` tiene `updated_at` con más de 5 minutos de antigüedad
- **THEN** esa entrada se descarta del widget y se elimina de la lista de runs activos en la siguiente consulta del scanner

#### Scenario: Entrada-string legacy con cache individual aún caliente (recién finalizada)
- **WHEN** existe una entrada-string legacy y su cache individual tiene `updated_at` con menos de 5 minutos de antigüedad
- **THEN** esa entrada se trata como recién completada: el widget la muestra con el resumen final durante el tiempo restante hasta cumplir los 5 minutos, y luego la descarta
