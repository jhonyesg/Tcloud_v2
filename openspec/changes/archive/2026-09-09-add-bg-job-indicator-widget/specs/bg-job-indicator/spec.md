## Purpose

Expone a través de la UI el estado de cualquier proceso en background disparado desde cualquier módulo de TCloud, sin obligar al operador a permanecer en la página que lanzó el proceso ni a mantener un modal bloqueante abierto. El indicador global funciona como punto único de consulta y de retorno al detalle.

## ADDED Requirements

### Requirement: Endpoint unificado expone todos los jobs en background activos

El sistema SHALL exponer `GET /bg-jobs/active` que devuelve un objeto JSON con `jobs: [...]`. Cada elemento SHALL representar un job en background activo en cualquier módulo del sistema. Si no hay jobs activos, SHALL devolver `{jobs: []}` con HTTP 200. El endpoint SHALL estar protegido por los middleware `auth` y `admin` (solo operadores con permisos administrativos pueden lanzar y ver jobs).

Cada elemento del array SHALL tener la forma:
```
{
  "kind": "<identificador corto del job, ej: 'avisos-scan', 'transcriptor-batch'>",
  "runId": "<identificador único de la corrida>",
  "module": "<nombre legible del módulo>",
  "label": "<etiqueta humana, ej: 'Escaneo de menciones (tanda 12/24)'>",
  "startedAt": "<ISO8601>",
  "progress": {
    "<campo>: <valor>"  // ej: { "scanned": 3400, "estimate": 5000, "hits_new": 1229, "failed": 0 }
  },
  "url": "<URL absoluta a la página del módulo con ?focus=bg-{kind}-{runId}>"
}
```

El endpoint SHALL agregar al menos los siguientes tipos de job:
- `avisos-scan`: jobs del módulo de Avisos Inteligentes (key de cache `avisos_scan_bg:active` + `avisos_scan_run:{runId}`)
- `transcriptor-batch`: jobs del API Transcriptor (key de cache `transcription_batch:{runId}`)

#### Scenario: Sin jobs activos devuelve array vacío
- **WHEN** no hay jobs en background en ningún módulo
- **THEN** el endpoint responde 200 con `{jobs: []}` en menos de 100ms
- **AND** el widget no se renderiza en el layout

#### Scenario: Un escaneo de avisos activo aparece en la respuesta
- **WHEN** hay un `avisos_scan_bg:active` en cache apuntando a un run con 3400 escaneadas y 1229 hits nuevos
- **THEN** el endpoint responde 200 con `{jobs: [{kind: 'avisos-scan', runId: '...', module: 'Avisos Inteligentes', label: 'Escaneo de menciones', startedAt: '...', progress: {scanned: 3400, hits_new: 1229, ...}, url: '/ia/avisos-inteligentes?focus=bg-avisos-scan-{runId}'}]}`

#### Scenario: Batch de transcripción activo aparece junto con avisos-scan
- **WHEN** hay simultáneamente un escaneo de avisos y un batch del transcriptor activos
- **THEN** el endpoint responde con dos elementos en `jobs`, uno por cada tipo, sin duplicados
- **AND** el orden es determinístico (orden de discovery)

#### Scenario: Endpoint protegido por auth y admin
- **WHEN** un usuario no autenticado o sin rol admin hace GET al endpoint
- **THEN** responde 401 o 403 según corresponda (no expone existencia de jobs)

### Requirement: Widget flotante en el layout global muestra los jobs activos

El layout `app/resources/views/layouts/app.blade.php` SHALL incluir un widget flotante anclado a la esquina inferior derecha (`fixed bottom-4 right-4 z-40`). Cuando hay al menos un job activo, SHALL renderizar una card por job con:
- Nombre del módulo
- Etiqueta del job
- Barra de progreso (si hay `progress.estimate` o `progress.total_to_process`)
- Texto con contadores clave (procesados, pendientes, hits, errores según el módulo)
- Botón "Ver detalles" que navega al `url` del job
- Botón "X" para colapsar el widget (lo oculta pero sigue polleando en background)

Cuando no hay jobs activos, SHALL no renderizar nada (display: none). El widget SHALL hacer polling cada 5 segundos del endpoint `/bg-jobs/active`. Cuando el array viene vacío, SHALL detener el polling hasta el próximo focus/visibility de la pestaña.

#### Scenario: Widget aparece cuando hay un job activo
- **WHEN** el operador está en cualquier página autenticada (ej: `/dashboard`, `/mis-archivos`) y el endpoint devuelve un job activo
- **THEN** el widget se renderiza en la esquina inferior derecha con la card del job
- **AND** la barra de progreso refleja los números del endpoint

#### Scenario: Widget se actualiza en vivo
- **WHEN** el widget está visible y pasan 5s
- **THEN** el widget hace GET al endpoint y actualiza los contadores y la barra de progreso sin recargar la página

#### Scenario: Click en "Ver detalles" navega al módulo
- **WHEN** el operador hace click en "Ver detalles" de una card
- **THEN** el navegador navega al `url` del job (ej: `/ia/avisos-inteligentes?focus=bg-avisos-scan-...`)

#### Scenario: Click en "X" colapsa el widget pero no detiene el polling
- **WHEN** el operador hace click en el botón "X" del widget
- **THEN** el widget se oculta visualmente
- **AND** el polling continúa (el sistema no debe "olvidar" el job activo)
- **AND** el widget reaparece automáticamente cuando el endpoint devuelve cambios relevantes (nuevo job, job finalizado, error)

#### Scenario: Polling se detiene cuando no hay jobs
- **WHEN** el endpoint devuelve `{jobs: []}` durante 2 polls consecutivos
- **THEN** el polling se pausa
- **AND** el polling se reanuda cuando la pestaña recupera foco (`visibilitychange`) o cuando hay interacción del usuario (click, keydown)

### Requirement: Módulo de avisos NO auto-abre su modal al cargar la página

El módulo `/ia/avisos-inteligentes` SHALL seguir attachando el polling del progreso del scan activo cuando carga, pero SHALL NO forzar `activeTab = 'escaneo'` ni abrir el modal `scanModal` automáticamente. El modal SHALL abrirse solo cuando el operador (a) hace click explícito en "Escanear ahora" o (b) navega a la página con el parámetro `?focus=bg-avisos-scan-{runId}` desde el widget global, en cuyo caso sí abre el modal con `phase: 'running'`.

#### Scenario: Recarga con scan activo no fuerza la pestaña ni abre el modal
- **WHEN** el operador recarga `/ia/avisos-inteligentes` mientras hay un escaneo activo
- **THEN** la página carga en la pestaña que el operador tenía activa antes (no fuerza "Escaneo")
- **AND** el modal NO se abre automáticamente
- **AND** el widget global del layout muestra la card del escaneo en curso

#### Scenario: Click en el indicador global abre el modal con el detalle
- **WHEN** el operador hace click en "Ver detalles" del widget y la URL resultante es `/ia/avisos-inteligentes?focus=bg-avisos-scan-{runId}`
- **THEN** la página abre el modal `scanModal` con `phase: 'running'` y los datos del cache
- **AND** el operador puede seguir interactuando con el modal (Cancelar, Detener, cerrar)

#### Scenario: Lanzar un escaneo nuevo desde el módulo sigue funcionando como antes
- **WHEN** el operador hace click en "Escanear ahora" en `/ia/avisos-inteligentes`
- **THEN** se abre el modal en `phase: 'confirm'` (comportamiento existente, sin cambios)

### Requirement: API Transcriptor NO auto-abre su modal al cargar la página

El módulo `/ia/api-transcriptor` SHALL NO auto-abrir el modal de batch al cargar la página. El polling del batch activo SHALL vivir en el indicador global del layout. El modal SHALL abrirse solo cuando el operador hace click en "Escanear storages" o cuando navega con `?focus=bg-transcriptor-batch-{runId}` desde el widget.

#### Scenario: Recarga de `/ia/api-transcriptor` con batch activo no fuerza el modal
- **WHEN** el operador recarga la página con un batch activo
- **THEN** la página carga en el estado normal (modal cerrado)
- **AND** el widget global muestra el batch en curso
- **AND** el operador puede seguir interactuando con la página sin que nada le bloquee

#### Scenario: Click en el indicador global abre el modal del batch
- **WHEN** el operador hace click en "Ver detalles" de un batch del transcriptor en el widget
- **THEN** la URL resultante es `/ia/api-transcriptor?focus=bg-transcriptor-batch-{runId}`
- **AND** el modal de batch se abre con el progreso del run activo
