## Context

Mis Avisos ya está construido y plenamente funcional en `app/Http/Controllers/MisAvisosController.php` con ~26 llamadas a `Session::get('user_id')` que el usuario nunca quiere tocar. Adicionalmente, `EnsureMisAvisosEnabled` (middleware client-side) hace `Session::get('user_id')` para decidir si el usuario tiene el módulo activado. Cualquier solución que preserve la sesión real del admin y aun así cargue el módulo como si fueras el cliente debe pasar por una técnica de impersonación transparente: lo que toca al admin es una capa externa, no un refactor del controller.

El proyecto usa sesiones en Redis (vía sesión custom `SessionService` + tabla `user_sessions`), NO JWT. Eso facilita el swap temporal `session('user_id')` porque el ciclo de vida del dato vive en la request HTTP, no en un token firmado.

## Goals / Non-Goals

**Goals:**
- Hacer visible `Mis Avisos` para el admin como si fuera el cliente, vía overlay no-sticky sobre la sesión actual.
- Mantener el controller, las rutas del cliente y la BD sin cambios.
- Producir un trail de auditoría mínimo (1 log por request) para que quede registro de quién vio a quién.
- Render inmediato sin un build step (Blade + Alpine).

**Non-Goals:**
- No se modifica `MisAvisosController` ni una sola línea.
- No se replica la sesión del admin ni se emite una sesión paralela.
- No se otorgan permisos extras al cliente impersonado — el cliente real NO ve cambios que el admin no haya confirmado.
- No se cambia el módulo de auth (`AuthController`, `SessionService`, `UserSession`).
- No se crea nueva tabla de auditoría. La bitácora se escribe en `storage/logs/admin-preview.log`.

## Decisions

### 1. Mecanismo de impersonación: swap en middleware, no refactor del controller
**Decisión:** un middleware `AdminPreviewSwap` que en su `handle()` detecta `?as_user={id}`, valida admin + módulo del target, swap `session('user_id')` con try/finally, pasa al siguiente middleware, y al restaurar deja la sesión intacta.

**Alternativas:**
- *Refactor de 26 sitios en `MisAvisosController`* — descartado: alto riesgo de regresión sin tests confiables; la coyuntural llamada al cliente `ensureMisAvisosEnabled` también tendría que cambiar.
- *Sub-ruta dedicada `/admin/mis-avisos/{id}`* — descartado: duplica todas las ~10 rutas del cliente y el dropdown del banner debe poder cambiar la URL de un request a otro sin tocar `href`s.
- *Cookie sticky* — descartado: el admin puede olvidarla activa y contaminar otras pestañas; además abre portillo para CSRF.

**Por qué el middleware único:** invariante: "durante una request con `?as_user=X`, todo el árbol de middleware + controller ve `session('user_id') === X.id`", y "después de la request, no queda nada en sesión". El try/finally garantiza esto último sin importar cómo termine la request.

### 2. URL plana (single-URL pattern)
**Decisión:** la impersonación se activa **únicamente vía query string** `?as_user={id}`. La URL visible para todos (admin o no-admin) es `/mis-avisos`. Sin prefijos nuevos en el routing.

**Razón:** la Blade del banner construye URLs a partir de `?as_user=`, no hay que tocar el layout global, y el flujo del admin es siempre el mismo: ir a `/mis-avisos` y elegir dropdown.

### 3. Verificación de admin en `session('user')?->role`
**Decisión:** el middleware chequea `session('user')` (objeto user cacheado al login) por su campo `role === 'admin'`. Si no es admin, el parámetro se ignora silenciosamente.

**Razón:** el proyecto nunca usa `auth()->user()` (convención AGENTS.md); siempre pasa por `session('user')`. Esto evita depender de guards y mantiene el patrón del repo.

### 4. Datos para el dropdown — endpoint liviano, no Blade inline
**Decisión:** nuevo endpoint `GET /admin/preview/impersonatable-users` (en el grupo `['auth', 'admin']`), devuelve un array JSON con `[{id, username, name}]` filtrado por `user_alerts_inteligentes.enabled = true`. La Blade lo hidrata con un `fetch` al cargar la página.

**Alternativas:**
- *Hydrate en Blade al renderizar* — descartado: si la lista crece (>100 clientes), el HTML se infla; hidratar asíncrono es más limpio.
- *Usar otra caché* — innecesario, una query O(N users) es trivial.

### 5. Audit log: archivo dedicado, sin tabla nueva
**Decisión:** añadir canal `admin_preview` en `config/logging.php` que escribe a `storage/logs/admin-preview.log`. Una línea JSON por request. Sin esquema de BD.

**Razón:** queries de "quién vio a quién" se hacen con `grep` o con un loader future-friendly. Una tabla de auditoría obligaría a otro módulo de UI para verla, no es el MVP. Si el operador quiere enriquecer más tarde (e.g. quién editó qué keyword durante preview), eso es un follow-up.

**Estructura del log entry** (1 línea JSON por request):
```
{"time":"...","level":"info","channel":"admin_preview","event":"preview.applied","admin_id":1,"impersonated_id":42,"result":"ok"|"rejected","reason":null|"no_module_disabled", "method":"GET","path":"/mis-avisos","ip":"1.2.3.4","ua":"Mozilla/5.0..."}
```

### 6. Banner: condicional puro en Blade, sin reactividad Alpine
**Decisión:** dentro de `resources/views/mis-avisos/index.blade.php`, al INICIO del `<div x-data="misAvisosPage()">`, agregar un bloque `@if (session('admin_previewing_id')) ... @endif` que renderiza el banner. Como el flag se setea durante el request y se limpia al `finally`, **cada request que llega con `?as_user=X` re-evalúa el condicional** — no hace falta Alpine reactivity, el ciclo de vida es por request.

**Por qué condicional en lugar de Alpine:** la impersonación es por-request. Cualquier reactividad Alpine agregaría complejidad innecesaria (watch a una variable que de hecho no cambia en cliente).

### 7. Dropdown del banner: `<form>` + `GET` method=GET
**Decisión:** el dropdown es un `<form action="/mis-avisos" method="GET">` con un input hidden `name="as_user"` que se actualiza al cambiar el select. No necesita JS adicional.

**Alternativas:**
- *JavaScript que cambia `window.location`* — descartado: requeriría un handler `onchange` y rompe si el admin navega con teclado.

### 8. La lista de impersonables NO excluye al admin mismo
**Decisión:** la lista dropdown incluye también al propio admin si su `user_alerts_inteligentes.enabled = true`. Esto le permite al admin "ver su propia Mis Avisos como cliente" en un click (cuando admin hizo una edición, le sirve ver el resultado sin que confunda su vista administrativa con la del cliente real).

### 9. Link "Volver a mi cuenta"
**Decisión:** `href="/mis-avisos"` simple. Sin `?as_user=` → no se hace swap → el middleware no toca nada y se renderiza con `session('user_id')` (admin).

## Risks / Trade-offs

- **[Risk] El middleware debe restaurar sesión si HAY una excepción downstream.** → Mitigation: el `finally` del middleware hace `Session::put('user_id', $original)` antes de propagar. Esto es el patrón más importante: si alguien lanza `abort(403, ...)` en `EnsureMisAvisosEnabled` cuando el módulo del target está caído, la sesión del admin debe volver intacta igual. Probado con la regla explícita del design §2.

- **[Risk] La Blade renderiza el banner condicional en cada request, pero el `AdminPreviewSwap` corre también en endpoints JSON (`/mis-avisos/feed`, `/mis-avisos/history`, etc.).** → Mitigation: el middleware se aplica al grupo completo de rutas misavisos, no a una sola, así que SIEMPRE entra a impersonar (lo cual es lo que queremos para que las llamadas internas de Alpine / API json reflejen los datos impersonados). El "issue" sería que un endpoint JSON renderice un banner — eso no aplica porque los JSON no pasan por Blade.

- **[Risk] En el flujo "ver como cliente", las llamadas JSON internas (`/mis-avisos/categories`) tienen que seguir funcionando con el `session('user_id')` swappeado.** → Mitigation: el fetch del cliente hace `credentials: 'same-origin'`, el PHP-FPM mantiene la misma sesión Redis, el middleware se re-ejecuta en cada request y re-swappea desde el `?as_user=` de la URL; sin embargo, el fetch normal NO lleva `?as_user=`, sino que pasa la sesión... sí. Bug potencial. Hay DOS formas de resolver:
  - **A) Reenviar `?as_user=` en cada `fetch` del componente Alpine** → requiere cambiar todos los `apiFetch('/mis-avisos/...')` por `apiFetch('/mis-avisos/...?as_user=' + window.__as_user)`. Limpio en una sola pasada.
  - **B) Stash el `as_user` en una cookie que se envíe en cada request** → el middleware lee `cookie('as_user')` además de `request('as_user')`. Permite URL limpia en sub-requests.
  - Resolución: **A** porque es declarativo y no introduce estado de cookie. La Blade expone la URL canónica actual en una variable `window.__asUser` y los `apiFetch` la inyectan. Esto añade ~10 cambios pequeños en `mis-avisos/index.blade.php` (los `apiFetch` ya centralizados en una sola función helper `apiFetch`). Si en el futuro se prefiere B, queda como follow-up.

- **[Risk] `EnsureMisAvisosEnabled` corre DESPUÉS del swap.** Si el admin impersona a un usuario sin módulo activado, la vista mostraría que "no tienes acceso al módulo de avisos". → Mitigation: el `AdminPreviewSwap` chequea primero si el target tiene el módulo activado y, si no, devuelve 404 antes de propagar. Así, `EnsureMisAvisosEnabled` no llega a entrar.

- **[Trade-off] Sin audit granular de las ediciones que el admin haga durante preview.** Cada keyword editada NO se loggea individualmente; solo el "entrar a preview". → acepta como scope mínimo. Si después se requiere: añadir un hook antes/después en cada endpoint del `MisAvisosController` que loggee `{actor_id, impersonated_id, action, keyword_id}`. No es este change.

- **[Trade-off] El admin impersona a sí mismo si su id está en `as_user`.** El banner no se muestra (no hay impersonación efectiva: el swap es a la misma persona). → por contrato esto es "no-op" y no debe disparar log de `preview.applied`. La regla del middleware: si `as_user === session('user_id')` (admin impersonando a sí mismo), se ignora silenciosamente y el banner NO se dibuja. Coherente con spec §"Impersonation event log".

## Migration Plan

- **No requiere schema migration.** Sin migraciones nuevas, sin cambios en tablas.
- **Deploy orden:**
  1. Subir `AdminPreviewSwap.php` + insertar middleware en `routes/web.php` (1 línea).
  2. Agregar canal `admin_preview` en `config/logging.php`.
  3. Editar `mis-avisos/index.blade.php`: agregar el bloque de banner + dropdown + ajustar ~10 `apiFetch` calls para que propaguen `?as_user=`.
  4. (Opcional) `GET /admin/preview/impersonatable-users` endpoint.
- **Rollback:** basta con quitar el middleware del grupo de rutas en `routes/web.php`. La Blade tendrá código muerto, pero el sistema sigue funcionando (la impersonación no se aplica).
- **No requiere restart de PHP-FPM** porque el cambio en routes.php invalida la caché de rutas automáticamente. La Blade sí debe limpiarse con `php artisan view:clear`.

## Open Questions

- ¿Queremos validar que el admin no esté impersonando **a sí mismo** silenciosamente (log entry sin efecto) o FORZAR que se vea como cliente siempre (incluso si es él)? Por ahora: silent ignore, el admin que quiera "ver como cliente" usa un cliente real. Esto está en spec §"Admin impersonating themselves".
- El dropdown podría incluir búsqueda si la lista de impersonables crece mucho (>50). No es el MVP, queda como follow-up.
