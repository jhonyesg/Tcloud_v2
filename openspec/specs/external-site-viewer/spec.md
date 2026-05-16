## ADDED Requirements

### Requirement: Usuario accede al visor de site externo
El sistema SHALL renderizar el site externo en un iframe fullscreen dentro del layout de Tcloud cuando el usuario navega a `/sites/{site}`.

#### Scenario: Acceso autorizado
- **WHEN** el usuario navega a `/sites/{site}` y tiene el site asignado y activo
- **THEN** el sistema muestra un iframe con `src = site.url` ocupando todo el área del `<main>`

#### Scenario: Acceso no autorizado
- **WHEN** el usuario navega a `/sites/{site}` pero no tiene ese site asignado
- **THEN** el sistema devuelve 403

#### Scenario: Site deshabilitado
- **WHEN** el usuario navega a `/sites/{site}` pero el site tiene `enabled = false`
- **THEN** el sistema devuelve 403

---

### Requirement: Sidebar y topbar permanecen visibles durante la vista del site
El sistema SHALL mantener el layout completo (sidebar + topbar) visible mientras el iframe está activo.

#### Scenario: Navegación sin pérdida de interfaz
- **WHEN** el site externo está cargado en el iframe
- **THEN** el sidebar lateral y la barra superior de Tcloud permanecen visibles y funcionales

---

### Requirement: Indicador de site activo en sidebar
El sistema SHALL resaltar el ítem del site en el sidebar cuando su visor esté abierto.

#### Scenario: Item activo resaltado
- **WHEN** el usuario está en `/sites/{site}`
- **THEN** el ítem correspondiente en la sección "Sites Externos" del sidebar aparece con estilo activo (fondo destacado)

---

### Requirement: Fallback si el iframe no carga
El sistema SHALL mostrar un mensaje informativo si el site externo bloquea su carga en iframe.

#### Scenario: Iframe bloqueado por X-Frame-Options
- **WHEN** el site externo responde con `X-Frame-Options: DENY` y el iframe no carga
- **THEN** se muestra un mensaje indicando que el sitio no permite ser embebido, con un botón para abrirlo en nueva pestaña
