## MODIFIED Requirements

### Requirement: Chips UI para usuarios asignados
El sistema SHALL mostrar los usuarios asignados a un storage como chips visuales compactos dentro del modal de gestión, en lugar de una tabla, permitiendo identificar y accionar sobre cada usuario de forma rápida. Los chips y el panel de edición inline de permisos SHALL usar el house style visual (redondeo, paleta de marca para el panel, botones con primaria de marca y cancelar neutro).

#### Scenario: Visualización de usuarios como chips
- **WHEN** el admin abre el modal de gestión de usuarios de un storage
- **THEN** cada usuario asignado se muestra como un chip con su `@username` visible y badges que indican sus permisos activos (lectura, escritura, shares)

#### Scenario: Chip con permisos como badges
- **WHEN** se renderizan los chips de usuarios
- **THEN** cada chip incluye badges de color diferenciado para cada permiso activo del usuario (por ejemplo: "R", "W", "S" para read/write/shares)

#### Scenario: Panel de edición inline en house style
- **WHEN** el admin despliega la edición de permisos de un chip
- **THEN** el panel usa fondo pastel de marca, labels uppercase y su botón de guardado es de marca con cancelar neutro, sin cambiar los controles funcionales