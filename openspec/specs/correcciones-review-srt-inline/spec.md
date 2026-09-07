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

### Requirement: Cada ejemplo del modal de contexto expone un flujo AI contextualizado

El modal "Ejemplos en transcripciones" SHALL mostrar por cada ejemplo un botón "Corregir este segmento con IA" (sentence case, sin ALL CAPS) que solicite al endpoint `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` una corrección atómica del segmento, ahora con los vecinos ±5 en el prompt. Tras la respuesta, SHALL aparecer una **barra compacta de acciones** en la parte inferior del ejemplo con: "Aprobar y agregar regla", "Solo ver", "Reintentar", "Proteger marca" (si hay selección de texto) y "Detectar marcas". El modal SHALL evitar el patrón de tres tarjetas idénticas y SHALL presentar el ejemplo como un **timeline visual** (texto con highlights en una sola columna, sin headers en ALL CAPS como "CORRECCIÓN IA" o "CÓMO QUEDARÍA").

#### Scenario: El admin ve la corrección inline en el mismo ejemplo
- **WHEN** admin hace click en "Corregir este segmento con IA" sobre un ejemplo del modal
- **THEN** el modal muestra sobre ese ejemplo la corrección propuesta por el LLM como un nodo anexo, con la barra compacta de acciones visible debajo.
- **AND** las acciones "Aprobar y agregar regla", "Solo ver" y "Reintentar" están disponibles.

#### Scenario: El modal vuelve a su estado base tras aprobar
- **WHEN** admin hace click en "Aprobar y agregar regla" exitosamente
- **THEN** el modal cierra la caja IA y vuelve al estado base del ejemplo.
- **AND** la nueva regla queda visible en la pestaña "Pendientes" filtrada por origen `ai-context-correct-context-YYYY-MM-DD` (origen ampliado, distinto del anterior `ai-context-correct-*`).

#### Scenario: Otros ejemplos del mismo modal no consumen tokens
- **WHEN** admin abre el modal con 4 ejemplos y hace click en "Corregir este segmento con IA" solo sobre uno
- **THEN** los otros 3 ejemplos siguen en su estado base sin llamadas al LLM.

#### Scenario: Reintentar invoca al LLM de nuevo
- **WHEN** admin hace click en "Reintentar" sobre la corrección inline
- **THEN** se ejecuta una nueva llamada al LLM (ignora cache) y la corrección se reemplaza.

---

### Requirement: API key nunca se transmite al frontend

El endpoint SHALL aceptar sólo `correctionId` y `exampleId` en la URL; la API key SHALL leerse del backend (`LlmCorrectionSettings::apiKey()`). El frontend SHALL mostrar "Configurar API key" en lugar del botón cuando `aiSuggestStatus` indique que falta la key, sin pedirla al usuario.

#### Scenario: Modal de contexto deshabilita el botón IA si falta la key
- **WHEN** `LlmCorrectionSettings::apiKey() === ''`
- **THEN** el botón "Corregir esta frase con IA" se renderiza deshabilitado con tooltip "Configura la API key en /ia/correcciones → IA Suggest"
- **AND** ningún fetch sale del navegador hacia el endpoint de corrección.

### Requirement: Curaduría de marca protegida desde selección de texto

El modal SHALL permitir al admin seleccionar texto dentro del `text_raw` de un ejemplo y SHALL mostrar el botón "Proteger marca" habilitado solo cuando hay selección no vacía. Al hacer click, SHALL invocar `POST /ia/correcciones/protected-terms` con `{term, source: "modal-context", example_id}` y SHALL refrescar la cache del service.

#### Scenario: Admin protege "ARMOFL" desde selección
- **WHEN** admin selecciona "ARMOFL" en el text_raw y hace click en "Proteger marca"
- **THEN** el endpoint responde 201 con `{term: "ARMOFL", id, is_new: true}`.
- **AND** la próxima llamada al post-filtro defensivo del LLM ya considera "ARMOFL" como marca protegida.

### Requirement: Detección sugerida por LLM de marcas en el segmento

El modal SHALL exponer un botón "Detectar marcas" sobre cada ejemplo que invoca al LLM con un prompt dedicado y muestra los candidatos como checkboxes. El admin confirma cuáles agregar y pulsa "Agregar seleccionadas".

#### Scenario: LLM sugiere marcas candidatas
- **WHEN** admin hace click en "Detectar marcas" sobre un segmento con "ARMOFL", "ONU", "Diego"
- **AND** "Word" y "ONU" ya están protegidas
- **THEN** la UI muestra checkboxes para "ARMOFL" y "Diego" (excluyendo los ya protegidos).
- **AND** el admin confirma y se agregan vía el mismo endpoint.

### Requirement: Layout del modal sigue la skill frontend-design

El modal SHALL evitar los patrones AI-generados marcados por la skill `frontend-design`: SHALL NO usar cream background cerca de `#F4F1EA`, SHALL NO usar ALL CAPS para etiquetas, SHALL NO usar tres tarjetas idénticas con el mismo border-radius, SHALL NO usar terracotta/warm-clay o verde-acid como accent, SHALL NO usar "→" al final del texto de botones, SHALL usar tipografía con peso deliberado (no la mezcla default sans/serif de IA), SHALL usar sentence case en toda la copy, SHALL usar verbos activos en CTAs ("Agregar", "Proteger", "Reintentar", "Aprobar") en lugar de frases descriptivas, SHALL reservar motion para respuestas a acciones del usuario (abrir/cerrar/confirmar) y NO SHALL animar entradas de sección en cada load.

#### Scenario: Modal rediseñado sin patrones AI-generados
- **WHEN** admin abre el modal de contexto
- **THEN** la copy del modal está en sentence case (no "CORRECCIÓN IA" ni "CÓMO QUEDARÍA").
- **AND** los CTAs usan verbos activos: "Proteger marca", "Detectar marcas", "Aprobar y agregar regla", "Reintentar", "Solo ver".
- **AND** no hay tres tarjetas idénticas con el mismo border-radius; cada ejemplo se renderiza como un renglón con highlights en una sola columna.
- **AND** no hay fade-and-slide-up en cada sección al cargar; el motion aparece solo cuando el admin abre/cierra/confirmar.

#### Scenario: Cream background y terracotta accent no aparecen
- **WHEN** admin abre el modal en una instalación nueva
- **THEN** el fondo del modal NO es `#F4F1EA` ni cercano (sigue el sistema de tokens vigente).
- **AND** los colores de acento siguen la paleta vigente (violeta para IA), no terracotta/warm-clay ni verde-acid.
