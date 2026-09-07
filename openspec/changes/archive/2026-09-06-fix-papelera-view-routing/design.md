## Context

Ver `proposal.md` para la motivación. El bug: `PapeleraController::index` siempre responde JSON y la vista Blade vive en una ubicación que Laravel no descubre. La maqueta ya existe y está correcta — solo falta conectarla.

Estado actual:

```
GET /papelera
  │
  └─ PapeleraController@index($request)
       │
       └─ return response()->json([items, pagination])   ← SIEMPRE JSON, sin rama HTML

app/app/Modules/Papelera/resources/views/index.blade.php    ← ubicación no descubrible
app/resources/views/papelera/index.blade.php               ← NO existe
```

Convención del proyecto: vistas bajo `app/resources/views/<sección>/`. Módulos en `app/app/Modules/<X>/` solo aportan Controllers / Services / Models — las vistas se centralizan en `resources/views/`. Ver `files/`, `shares/`, `sites/`, `ia/`, `grabaciones_puntuales/`, `mis-avisos/`.

Patrón de referencia: `FileController::index` (`app/app/Http/Controllers/FileController.php:39-272`) usa el mismo patrón dual-mode que necesitamos replicar.

## Goals / Non-Goals

**Goals:**
- `GET /papelera` devuelve HTML maqueteado cuando es navegación normal.
- El mismo endpoint devuelve JSON cuando el request tiene `Accept: application/json` o `X-Requested-With: XMLHttpRequest` (consumido por Alpine).
- La vista Blade vive en `app/resources/views/papelera/index.blade.php` (descubrible por `view('papelera.index')`).
- El cambio es puramente cableado: no cambia `PapeleraService`, no cambia rutas, no cambia sidebar, no cambia migraciones.

**Non-goals:**
- Rediseño visual (banner urgentes, breadcrumb, responsive cards, doble confirmación). Ver follow-up `papelera-ui-polish`.
- Crear `php artisan trash:purge` y scheduler.
- Auditar `FileController@destroy` o `PublicShareController@show`.

## Decisions

### D1. Patrón dual-mode (HTML default, JSON bajo header)

```php
public function index(Request $request)
{
    $userId = (int) Session::get('user_id');
    $user = User::find($userId);
    if (!$user) {
        if ($request->ajax() || $request->expectsJson() || $request->wantsJson()) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        return redirect('/login');
    }

    if ($request->ajax() || $request->expectsJson() || $request->wantsJson()) {
        // ... paginator logic + response()->json([items, pagination]) ...
    }

    return view('papelera.index');
}
```

**Por qué:** idéntico a `FileController::index`. Alpine ya manda `Accept: application/json` + `X-Requested-With: XMLHttpRequest` en `loadItems()` (`papelera.index.blade.php` líneas 130-134), así que `$request->expectsJson()` cubre los dos headers.

**Alternativas descartadas:**
- Mover el JSON a un endpoint separado (`/papelera/items`): innecesario; el patrón del proyecto es el mismo URL responde ambos formatos. Separar incrementa la superficie sin beneficio.
- Devolver siempre HTML con datos serializados en `<script>`: más complejo y rompe la convención existente.

### D2. Mover la vista a `app/resources/views/papelera/index.blade.php`

**Por qué:** convención del proyecto. Modules aportan lógica, vistas van a `resources/views/`. Descubrimiento automático de Blade.

**Limpieza:** eliminar `app/app/Modules/Papelera/resources/views/index.blade.php` después del move para evitar confusión. Si quedara, alguien podría volver a romperla en el futuro.

### D3. Sin cambios en el servicio, rutas, ni sidebar

- `PapeleraService` ya implementa `softTrash/restore/hardDelete/purge/emptyFor/countFor` correctamente.
- Las rutas ya están registradas en `app/routes/web.php:113-117`.
- El sidebar badge en `layouts/app.blade.php:232` + `AppServiceProvider:103` ya funcionan.
- Los endpoints `restore`, `destroy`, `empty` ya son JSON-only (POST/DELETE con bodies vacíos) y no necesitan el cambio.

## Risks / Trade-offs

- **[Riesgo] El controller no maneja el caso "sesión expirada en navegador"** → si un usuario llega a `/papelera` con sesión expirada, recibe 401 JSON en vez de redirect a `/login`. **Mitigación**: agregar rama explícita `$request->expectsJson() ? JSON : redirect('/login')` en el guard de `$user`. Esto imita exactamente a `FileController::index:43-47`.
- **[Riesgo] Si alguien deja la vista huérfana en `Modules/Papelera/resources/views/`**, un cambio futuro podría seguir editándola pensando que es la canónica. **Mitigación**: borrar la copia vieja (paso 3 de tasks.md) y dejar comentario en `PapeleraController::index` indicando que la vista vive en `resources/views/`.
- **[Trade-off] Sin rediseño visual**: la maqueta actual (tabla, empty state, modal) es funcional pero básica comparada con `files/index.blade.php` (que tiene grid/list toggle, breadcrumbs, multi-select). Se acepta como mínimo viable; follow-up `papelera-ui-polish`.

## Migration Plan

### Deploy
1. `git pull` (toma los 3 archivos: controller, view nueva, eliminación de view vieja).
2. `php artisan view:clear && php artisan view:cache` (Blade recompila).
3. Smoke test: login → navegar a `/papelera` → ver HTML maqueteado, no JSON.

### Rollback
- `git revert <commit>` → vuelve al estado con JSON-en-pantalla. No hay migración de BD, no hay datos en riesgo.

### Post-deploy verification (manual, via Playwright)
1. Login con usuario de prueba.
2. `GET /papelera` → HTML con sidebar, empty state o tabla.
3. `GET /papelera` con `Accept: application/json` → JSON `{items: [], pagination: {...}}`.
4. Borrar un archivo desde el explorador → aparece en `/papelera` → restaurar → vuelve al origen.

## Open Questions

Ninguna que bloquee. Decisiones diferibles a follow-ups sin cambio de specs:
- ¿Conviene servir el listado inicial server-side (en `view()`) en vez de fetch desde Alpine? — Mejora perceived performance para papelera con muchos items; no se aborda en este pase.
- ¿Conviene tener comando `trash:purge` antes de exponer el botón "Vaciar papelera" a usuarios reales? — Sí, pero fuera de scope.
