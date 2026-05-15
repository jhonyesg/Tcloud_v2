## Why

Los usuarios necesitan acceder a aplicaciones web externas (paneles, dashboards, herramientas) sin salir de la plataforma. Hoy deben abrir nuevas pestañas perdiendo el contexto de trabajo. Un módulo de Sites Externos permite embeber URLs asignadas por el admin directamente en el sidebar de Tcloud, manteniendo la navegación unificada.

## What Changes

- **Nuevo modelo** `ExternalSite` — registra URL, nombre, icono y estado (activo/inactivo)
- **Nuevo pivot** `external_site_user` — asigna sites a usuarios específicos con orden
- **Nuevas rutas admin** bajo `/admin/external-sites` — CRUD completo de sites y asignación a usuarios
- **Nueva ruta de usuario** `/sites/{site}` — renderiza el visor iframe embebido
- **Sidebar actualizado** — nueva sección "Sites Externos" visible solo si el usuario tiene sites asignados
- **Migración requerida** — dos tablas nuevas: `external_sites`, `external_site_user`

## Capabilities

### New Capabilities

- `external-site-management`: CRUD de sites externos por admin (URL, nombre, icono, activo/inactivo) y asignación a usuarios
- `external-site-viewer`: Renderizado de la URL asignada dentro de un iframe fullscreen manteniendo sidebar y topbar de la plataforma
- `sidebar-external-sites`: Sección "Sites Externos" en el sidebar lateral que lista los sites asignados al usuario autenticado con nombre e icono personalizado

### Modified Capabilities

- `spa-navigation`: Agregar soporte para que la navegación al visor de site externo no rompa el estado del sidebar

## Impact

- **Nuevos modelos**: `App\Models\ExternalSite`, pivot `external_site_user`
- **Nuevos controladores**: `ExternalSiteController` (admin CRUD), `ExternalSiteViewController` (visor usuario)
- **Rutas**: `/admin/external-sites/*`, `/sites/{site}`
- **Vista**: `layouts/app.blade.php` — sección sidebar nueva
- **Migración**: `create_external_sites_table`, `create_external_site_user_table`
- **No requiere** cambios en StorageProvider, File ni Share

## Non-goals

- No se gestionan cookies ni sesiones del sitio externo embebido
- No se soporta SSO ni autenticación automática en el sitio externo
- No se permite al usuario agregar sus propios sites (solo el admin los gestiona)
- No se implementa comunicación postMessage entre iframe y plataforma
