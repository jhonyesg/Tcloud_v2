## 1. Base de Datos y Modelos [migración requerida]

- [x] 1.1 Crear migración `create_external_sites_table` (id, name, url, icon, color, enabled, timestamps)
- [x] 1.2 Crear migración `create_external_site_user_table` (id, external_site_id FK, user_id FK, sort_order, created_at)
- [x] 1.3 Correr migraciones en producción (`php artisan migrate`)
- [x] 1.4 Crear modelo `App\Models\ExternalSite` con `$fillable`, `$casts` y relación `users()`
- [x] 1.5 Añadir relación `externalSites()` en `App\Models\User` (BelongsToMany con pivot sort_order)

## 2. Backend — Admin CRUD

- [x] 2.1 Crear `ExternalSiteController` con métodos: `index`, `store`, `update`, `destroy`
- [x] 2.2 Implementar `store`: validar name (required), url (required, url, starts_with:https), icon (required), color (required), enabled (boolean)
- [x] 2.3 Implementar `update`: mismas validaciones, responde 200 con site actualizado
- [x] 2.4 Implementar `destroy`: elimina site + cascade pivot (`external_site_user`) vía FK o manual
- [x] 2.5 Añadir métodos de asignación: `assignUser(site, user)` — upsert en pivot, `removeUser(site, user)` — delete pivot
- [x] 2.6 Añadir método `users(site)` — devuelve JSON con usuarios asignados al site
- [x] 2.7 Registrar rutas admin en `routes/web.php` bajo middleware `['auth','admin']`:
      `GET/POST /admin/external-sites`, `PUT/DELETE /admin/external-sites/{site}`,
      `GET/POST /admin/external-sites/{site}/users`, `DELETE /admin/external-sites/{site}/users/{user}`

## 3. Backend — Visor de Usuario

- [x] 3.1 Crear `ExternalSiteViewController` con método `show(ExternalSite $site)`
- [x] 3.2 Implementar verificación de acceso: el user debe tener el site asignado y activo (403 si no)
- [x] 3.3 Registrar ruta `GET /sites/{site}` con middleware `auth` en `routes/web.php`

## 4. AppServiceProvider — Datos del Sidebar

- [x] 4.1 En `AppServiceProvider::boot()`, ampliar el view composer de `layouts.app` para inyectar `$userExternalSites` (sites activos asignados al usuario, ordenados por sort_order)

## 5. Frontend — Vista Admin

- [x] 5.1 Crear `resources/views/admin/external-sites.blade.php` extendiendo `layouts.app`
- [x] 5.2 Implementar tabla de sites con columnas: nombre, URL, icono, color, estado, acciones (editar/eliminar)
- [x] 5.3 Implementar modal Alpine.js para crear/editar site: campos name, url, icon picker (lista de ~20 iconos FA), color picker, toggle enabled
- [x] 5.4 Implementar modal de asignación de usuarios: búsqueda de usuario + lista de asignados con botón remover (igual patrón que storages)
- [x] 5.5 Añadir link "Sites Externos" en el menú admin del sidebar o en la navegación admin

## 6. Frontend — Visor iframe

- [x] 6.1 Crear `resources/views/sites/show.blade.php` extendiendo `layouts.app`
- [x] 6.2 El `@section('content')` contiene: `<iframe src="{{ $site->url }}" class="w-full h-full border-0">` dentro de un `div` con `h-full`
- [x] 6.3 Implementar fallback Alpine.js: si el iframe dispara `onerror` o no carga en 10s, mostrar mensaje con botón "Abrir en nueva pestaña"

## 7. Frontend — Sidebar

- [x] 7.1 En `layouts/app.blade.php`, añadir sección "Sites Externos" en `<nav>` después de los módulos existentes, visible solo si `count($userExternalSites) > 0`
- [x] 7.2 Renderizar cada site como nav-link: icono FA en el color configurado + nombre (cuando sidebar abierto) / solo icono con title (cuando colapsado)
- [x] 7.3 Aplicar clase activa al ítem si la URL actual empieza con `/sites/{site->id}`
- [x] 7.4 Añadir separador visual y label "SITES EXTERNOS" (igual estilo que "NAVEGACIÓN" existente)
