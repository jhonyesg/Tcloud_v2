## Context

Tcloud no tiene forma de integrar herramientas web externas en su interfaz. Los usuarios acceden a paneles como `https://prensa.mediaserver.com.co/panel` en pestañas separadas, perdiendo el contexto de la plataforma. Se necesita un módulo que permita al admin registrar URLs y asignarlas a usuarios, y que el usuario las abra embebidas dentro del layout existente (sidebar + topbar intactos).

El layout ya es `h-screen overflow-hidden` con sidebar fijo y `<main class="flex-1 overflow-auto">`, lo que hace trivial reemplazar el contenido del main con un iframe fullscreen.

## Goals / Non-Goals

**Goals:**
- Admin puede crear/editar/eliminar sites externos (URL, nombre, icono FontAwesome, activo/inactivo)
- Admin asigna sites a usuarios individuales
- Usuario ve sección "Sites Externos" en sidebar solo si tiene sites asignados
- Al hacer click, el site se carga en un `<iframe>` que ocupa todo el `<main>` sin perder sidebar ni topbar
- El icono del site se selecciona de un set predefinido de iconos FontAwesome

**Non-Goals:**
- SSO ni autenticación delegada al sitio externo
- Usuarios gestionando sus propios sites
- Comunicación postMessage entre iframe y plataforma
- Soporte para sites que bloqueen iframes via `X-Frame-Options: DENY`

## Decisions

### D1: Iframe vs. proxy reverso
**Decisión:** Iframe directo.
**Alternativa considerada:** Proxy reverso (Laravel redirige el tráfico).
**Razón:** El proxy añade complejidad de seguridad, latencia y problemas con URLs relativas del sitio externo. El iframe es estándar y suficiente para el caso de uso (paneles internos de la organización que controlan sus propios headers).

### D2: Icono — FontAwesome predefinido vs. upload de imagen
**Decisión:** Set predefinido de ~20 iconos FontAwesome relevantes (fa-globe, fa-tv, fa-chart-bar, fa-video, etc.) seleccionable con un picker visual.
**Alternativa considerada:** Upload de imagen/favicon.
**Razón:** Simplicidad de implementación, consistencia visual con el resto del sidebar, sin gestión de archivos extra.

### D3: Modelo de asignación — pivot directo vs. grupos
**Decisión:** Pivot `external_site_user` (user_id, external_site_id, sort_order).
**Alternativa considerada:** Grupos de usuarios con sites.
**Razón:** Consistente con el patrón existente de `user_storages`. Permite asignación granular y orden personalizable por usuario en el futuro.

### D4: Ruta del visor — página Blade dedicada vs. Alpine modal
**Decisión:** Página Blade dedicada (`/sites/{site}`) que extiende `layouts.app` y pone el iframe en `@section('content')`.
**Alternativa considerada:** Modal Alpine.js con iframe.
**Razón:** URL limpia y navegable, compartible, sin conflictos con el estado Alpine del layout. El iframe ocupa `h-full w-full` dentro del `<main>` naturalmente.

### D5: Seguridad de URL — validación en backend vs. solo frontend
**Decisión:** Validar URL en backend al guardar (debe ser HTTPS, formato válido). Renderizar en vista sin re-validar.
**Razón:** Evita XSS/SSRF. El admin es de confianza pero se valida de igual forma.

## Data Model

```
external_sites
  id              bigint PK
  name            varchar(120)
  url             varchar(500)   -- HTTPS required
  icon            varchar(60)    -- FontAwesome class: 'fa-globe'
  color           varchar(20)    -- Tailwind color name: 'blue', 'green', etc.
  enabled         boolean default true
  created_at / updated_at

external_site_user  (pivot)
  id              bigint PK
  external_site_id  FK → external_sites
  user_id           FK → users
  sort_order        integer default 0
  created_at
```

## Architecture

```
Admin:
  GET  /admin/external-sites          → ExternalSiteController@index  (vista con tabla + modal CRUD)
  POST /admin/external-sites          → ExternalSiteController@store
  PUT  /admin/external-sites/{site}   → ExternalSiteController@update
  DEL  /admin/external-sites/{site}   → ExternalSiteController@destroy
  GET  /admin/external-sites/{site}/users       → @users  (JSON: usuarios asignados)
  POST /admin/external-sites/{site}/users       → @assignUser
  DEL  /admin/external-sites/{site}/users/{user} → @removeUser

Usuario:
  GET  /sites/{site}  → ExternalSiteViewController@show
       - Verifica que el user tenga el site asignado (403 si no)
       - Devuelve vista con iframe src=site.url

Sidebar (layouts/app.blade.php - AppServiceProvider):
  - View composer inyecta $userExternalSites (colección)
  - Sección "Sites Externos" solo si count > 0
```

## Blade Views

- `resources/views/admin/external-sites.blade.php` — CRUD admin (tabla + modal Alpine)
- `resources/views/sites/show.blade.php` — visor iframe (extiende layouts.app)
- `resources/views/layouts/app.blade.php` — añadir sección Sites Externos en nav

## AppServiceProvider

Añadir second view composer en `layouts.app` que inyecta `$userExternalSites`:
```php
$userExternalSites = $user->externalSites()->where('enabled', true)->orderBy('sort_order')->get();
$view->with('userExternalSites', $userExternalSites);
```

## Risks / Trade-offs

- **[Risk] X-Frame-Options bloqueado** → El iframe muestra error en blanco. Mitigación: documentar limitación, mostrar mensaje de fallback si el iframe no carga (evento `onerror`).
- **[Risk] URL maliciosa** → Admin podría apuntar a phishing interno. Mitigación: validar HTTPS en backend, el admin es rol de confianza.
- **[Risk] Iframe rompe sesión del sitio externo** → Cookies SameSite=Strict pueden impedir login en el iframe. Mitigación: documentado como limitación conocida (Non-goal).

## Migration Plan

1. Crear migraciones: `external_sites` + `external_site_user`
2. Correr `php artisan migrate` en producción (sin downtime, tablas nuevas)
3. Rollback: `php artisan migrate:rollback` elimina ambas tablas (no hay datos críticos)

## Open Questions

- ¿El admin también ve sus propios sites en el sidebar? → Sí, si se los asigna a sí mismo.
- ¿Se permite más de una instancia del mismo site para distintos usuarios? → Sí, el pivot lo permite.
