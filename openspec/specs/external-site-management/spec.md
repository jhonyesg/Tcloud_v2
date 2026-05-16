## ADDED Requirements

### Requirement: Admin puede crear un site externo
El sistema SHALL permitir al admin registrar un site externo con nombre, URL (HTTPS), icono FontAwesome y color.

#### Scenario: Creación exitosa
- **WHEN** el admin envía nombre, URL válida HTTPS, icono y color
- **THEN** el sistema crea el registro en `external_sites` y devuelve 201

#### Scenario: URL no HTTPS rechazada
- **WHEN** el admin envía una URL que no comienza con `https://`
- **THEN** el sistema devuelve error 422 con mensaje de validación

#### Scenario: Nombre duplicado permitido
- **WHEN** el admin crea dos sites con el mismo nombre
- **THEN** el sistema los crea ambos (no hay restricción de unicidad en nombre)

---

### Requirement: Admin puede editar un site externo
El sistema SHALL permitir al admin modificar cualquier campo de un site existente.

#### Scenario: Edición exitosa
- **WHEN** el admin envía campos actualizados para un site existente vía PUT
- **THEN** el sistema actualiza el registro y devuelve 200

#### Scenario: Deshabilitar un site
- **WHEN** el admin marca `enabled = false`
- **THEN** el site deja de aparecer en el sidebar de los usuarios asignados

---

### Requirement: Admin puede eliminar un site externo
El sistema SHALL eliminar el site y sus asignaciones a usuarios.

#### Scenario: Eliminación en cascada
- **WHEN** el admin elimina un site
- **THEN** el sistema borra el registro de `external_sites` y todas las filas de `external_site_user` relacionadas

---

### Requirement: Admin asigna un site a un usuario
El sistema SHALL permitir asignar un site externo a uno o más usuarios.

#### Scenario: Asignación exitosa
- **WHEN** el admin envía user_id para un site existente
- **THEN** se crea fila en `external_site_user` y el usuario ve el site en su sidebar

#### Scenario: Asignación duplicada ignorada
- **WHEN** el admin intenta asignar el mismo site al mismo usuario dos veces
- **THEN** el sistema devuelve 200 sin crear duplicado (upsert)

---

### Requirement: Admin puede remover la asignación de un usuario
El sistema SHALL permitir quitar un site de un usuario sin eliminar el site.

#### Scenario: Remoción exitosa
- **WHEN** el admin elimina la asignación user+site
- **THEN** se borra la fila del pivot y el site desaparece del sidebar del usuario
