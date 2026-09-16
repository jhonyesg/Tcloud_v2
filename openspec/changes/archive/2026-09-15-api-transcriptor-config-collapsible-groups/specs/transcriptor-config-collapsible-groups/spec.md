## Purpose

Permite al operador del módulo API Transcriptor plegar y desplegar los grupos de configuración (Ritmo de envío, Staging local, etc.) de manera independiente para enfocar la vista en lo que necesita tocar, sin perder scroll ni saturar la pantalla.

## ADDED Requirements

### Requirement: Grupos de configuración plegables individualmente

El sistema MUST permitir al operador plegar y desplegar cada uno de los 11 grupos de knobs (`ritmo`, `staging`, `descubrimiento`, `api`, `workers`, `saturacion`, `burst`, `webhook`, `confiabilidad`, `ia`, `ui`) mediante un control claro en el header del grupo. Múltiples grupos pueden estar abiertos simultáneamente (no es un acordeón "uno a la vez").

#### Scenario: Plegar un grupo abierto
- **WHEN** el operador hace click en el header de un grupo que está desplegado
- **THEN** el cuerpo del grupo (lista de knobs) se oculta con una transición visual
- **AND** el icono del header cambia de chevron-down a chevron-right

#### Scenario: Desplegar un grupo plegado
- **WHEN** el operador hace click en el header de un grupo que está plegado
- **THEN** el cuerpo del grupo se muestra con una transición visual
- **AND** el icono del header cambia de chevron-right a chevron-down

#### Scenario: Estado independiente entre grupos
- **WHEN** el operador tiene abiertos los grupos `ritmo` y `staging`
- **AND** luego pliega `ritmo`
- **THEN** `staging` permanece desplegado
- **AND** ningún otro grupo se ve afectado

### Requirement: Botones globales "Expandir todo" y "Plegar todo"

El sistema MUST exponer dos botones en la parte superior del listado de grupos: "Expandir todo" y "Plegar todo". Estos botones afectan únicamente los 11 grupos de knobs (no el panel "Tarea programada + estado en vivo", no los modales).

#### Scenario: Expandir todos los grupos
- **WHEN** el operador hace click en "Expandir todo"
- **THEN** los 11 grupos de knobs quedan desplegados
- **AND** el cambio es persistente (sobrevive a recargas posteriores)

#### Scenario: Plegar todos los grupos
- **WHEN** el operador hace click en "Plegar todo"
- **THEN** los 11 grupos de knobs quedan plegados
- **AND** el cambio es persistente (sobrevive a recargas posteriores)

#### Scenario: Botones no afectan al panel de monitoreo
- **WHEN** el operador hace click en "Plegar todo"
- **THEN** el panel "Tarea programada + estado en vivo" permanece en su estado original (no es parte del alcance)

### Requirement: Persistencia del estado de plegado

El sistema MUST persistir el estado abierto/cerrado de cada grupo entre recargas de la página utilizando almacenamiento local del navegador (`localStorage`). La clave usada es `tcloud:api-transcriptor:cfg-groups:v1`.

#### Scenario: El estado sobrevive a una recarga
- **WHEN** el operador pliega `descubrimiento` y recarga la página
- **THEN** `descubrimiento` se renderiza plegado al volver a cargar

#### Scenario: El estado sobrevive a un cambio de pestaña SPA
- **WHEN** el operador tiene abiertos `ritmo` y `staging`
- **AND** navega a la pestaña "Jobs"
- **AND** regresa a la pestaña "Configuración"
- **THEN** `ritmo` y `staging` siguen desplegados

### Requirement: Estado por defecto razonable

El sistema MUST inicializar el estado de plegado de la siguiente manera cuando no hay valor persistente en `localStorage` (primera visita, o almacenamiento limpio):
- Abiertos por defecto: ninguno. Todos los 11 grupos arrancan plegados.
- Decisión confirmada por el operador el 2026-09-15 tras revisar el despliegue inicial: la vista contraída con la lista de grupos como índice limpio es la UX preferida.

#### Scenario: Primera carga sin storage
- **WHEN** el operador abre la pestaña Configuración por primera vez (sin valor en localStorage)
- **THEN** los 11 grupos se renderizan plegados

#### Scenario: Storage corrupto o inválido
- **WHEN** el valor en localStorage no es un objeto JSON válido o tiene claves que no son grupos válidos
- **THEN** el sistema cae al estado por defecto (ritmo + staging abiertos) sin romper el render
- **AND** no se produce ningún error en la consola del navegador

### Requirement: Compatibilidad con el acordeón por knob existente

El sistema MUST preservar intacto el acordeón "Ver detalle / Ocultar detalle" por knob individual (`detailOpen` / `toggleDetail`). Plegar o desplegar el grupo contenedor NO debe afectar el estado de detalle de los knobs que contiene.

#### Scenario: Detalle del knob sobrevive al plegado del grupo
- **WHEN** el operador tiene abierto el detalle del knob `dispatch_pacing_max` dentro de `ritmo`
- **AND** luego pliega el grupo `ritmo`
- **AND** vuelve a desplegar `ritmo`
- **THEN** `dispatch_pacing_max` sigue con su detalle desplegado

### Requirement: Atributos de tour preservados

El sistema MUST preservar los atributos `data-tour` existentes en los headers y knobs de cada grupo (`cfg-group-<grupo>`, `cfg-knob-<key>`), de modo que el tour guiado de la pestaña Configuración siga funcionando con grupos plegados.

#### Scenario: Tour llega al grupo plegado
- **WHEN** el operador inicia el tour de Configuración y el primer paso apunta a un grupo plegado
- **THEN** el sistema hace scroll al elemento data-tour correspondiente
- **AND** el highlight visual es visible incluso si el grupo está plegado (el header siempre es visible)
