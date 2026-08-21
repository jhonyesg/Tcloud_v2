# Spec: correcciones-review-srt-inline

## Purpose

El modal de detalle de revisión de transcripciones en `/ia/correcciones` debe permitir al admin ver el SRT completo sin navegar a otra página, manteniendo el flujo de revisión (segmentos cambiados, decisión, notas) en el mismo contexto visual.

## Requirements

### Requirement: El modal de revisión muestra el SRT completo en un panel inline
El modal de detalle de revisión (`/ia/correcciones → Revisar transcripciones → [clic en fila]`) SHALL exponer una acción "Ver SRT completo" que, al activarse, expande un panel dentro del propio modal mostrando los segmentos del transcript con su `start_label → text` en una lista con scroll interno (altura máxima visible de viewport). El panel SHALL alimentarse vía AJAX del endpoint existente `GET /api-transcriptor/jobs/{id}/transcript` y SHALL permanecer oculto por defecto para no romper el flujo principal de revisión.

#### Scenario: Admin abre el panel de SRT completo desde el modal
- **WHEN** el admin hace click en "Ver SRT completo" dentro del modal de revisión
- **THEN** el modal hace fetch asíncrono a `GET /api-transcriptor/jobs/{id}/transcript`
- **THEN** el panel se expande mostrando cada segmento como `[start_label] text`
- **THEN** el modal permanece abierto sobre la misma pantalla (no se abre pestaña ni ventana nueva)

#### Scenario: Admin cierra el panel sin salir del modal
- **WHEN** el admin hace click de nuevo en "Ocultar SRT completo"
- **THEN** el panel se colapsa
- **THEN** los segmentos cambiados, la decisión y las notas del modal siguen visibles sin recargar

#### Scenario: Admin accede al SRT completo en página completa si lo necesita
- **WHEN** el admin quiere ver el SRT en la página del job (`/ia/api-transcriptor/jobs/{id}`)
- **THEN** el modal SHALL ofrecer una acción secundaria "SRT original" con `target="_blank"`
- **THEN** la página principal del modal NO se ve afectada por esa apertura

### Requirement: El panel inline usa carga lazy y cachea el transcript por modal abierto
El panel de SRT SHALL hacer fetch solo en el primer toggle de "Ver SRT completo" (lazy); toggles subsecuentes en el mismo modal SHALL reusar el resultado cacheado localmente. Al cerrar el modal (`@click.self`, `Escape` o botón X), el cache SHALL descartarse para que la próxima apertura haga un fetch fresco.

#### Scenario: Fetch lazy en la primera apertura
- **WHEN** el admin abre el panel por primera vez en una sesión de modal
- **THEN** se muestra un estado de carga mientras el endpoint responde
- **THEN** al recibir respuesta, se renderizan los segmentos

#### Scenario: Reapertura del panel sin refetch
- **WHEN** el admin cierra y vuelve a abrir el panel en el mismo modal abierto
- **THEN** no se dispara otro fetch; se muestran los segmentos cacheados

#### Scenario: Apertura en un modal nuevo hace fetch fresco
- **WHEN** el admin cierra el modal y abre la revisión de otra transcripción
- **THEN** el siguiente toggle de "Ver SRT completo" hace fetch del nuevo transcript
- **THEN** no se mezclan los datos entre transcripciones distintas
