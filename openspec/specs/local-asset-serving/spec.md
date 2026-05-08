## ADDED Requirements

### Requirement: Todos los recursos estáticos se sirven localmente
El sistema SHALL cargar todos los recursos JS, CSS y fuentes desde el propio servidor, sin realizar ninguna petición a dominios CDN externos.

#### Scenario: Carga de página sin conexión a Internet
- **WHEN** el servidor está operativo pero sin acceso a Internet
- **THEN** todas las páginas cargan correctamente con estilos, iconos e interactividad

#### Scenario: Auditoría de recursos en vista de autenticación
- **WHEN** se abre la vista de recuperación de contraseña
- **THEN** no se realiza ninguna petición de red a dominios externos (jsdelivr, cloudflare, tailwindcss.com, etc.)

#### Scenario: Auditoría de recursos en layout principal
- **WHEN** se abre cualquier módulo interno (dashboard, archivos, admin, etc.)
- **THEN** todos los recursos JS, CSS y fuentes provienen de rutas `/js/`, `/css/` o `/webfonts/` del propio servidor
