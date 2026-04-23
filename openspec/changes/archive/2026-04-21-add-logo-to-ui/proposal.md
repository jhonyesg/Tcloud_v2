## Why

El sistema actualmente usa un ícono genérico de Font Awesome (fa-cloud) en lugar del logo personalizado `logo.png` que está en el directorio raíz. El usuario quiere unificar la identidad visual usando su logo en tres lugares:

1. Favicon del navegador
2. Página de login (encima del texto "Tcloud")
3. Barra superior del panel (junto al texto "Tcloud")

## What Changes

1. **Favicon**: Copiar `logo.png` a `public/` y referenciarlo como favicon en el layout
2. **Login**: Agregar el logo.png centrado encima del texto "Tcloud" con buena proporción
3. **Header del panel**: Reemplazar el ícono Font Awesome por logo.png manteniendo el texto "Tcloud"

## Capabilities

### Modified Capabilities

- `ui-branding`: Agregar logo personalizado a favicon, login y header del panel

## Impact

- **Archivos afectados**:
  - `public/logo.png` (copiar desde raíz)
  - `app/resources/views/layouts/app.blade.php` (favicon + header)
  - `app/resources/views/auth/login.blade.php` (logo en login)
- **Assets**: Ninguno nuevo, solo usar logo.png existente
