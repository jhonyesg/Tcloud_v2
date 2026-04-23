## Context

El `ShareController::index()` actualmente retorna solo JSON, incluso cuando el usuario accede directamente desde el navegador. Esto contrasta con `UserController::index()` que detecta si es una request AJAX o navegación normal.

**Patrón actual en UserController::index():**
```php
if ($request->ajax()) {
    return response()->json($users);
}
return view('admin.users');
```

**Problema en ShareController::index():**
```php
return response()->json($shares);  // Siempre JSON, sin vista
```

## Goals / Non-Goals

**Goals:**
- Detectar si la request es AJAX vs navegación normal
- Retornar vista Blade para navegación normal
- Mantener compatibilidad con API AJAX existente

**Non-Goals:**
- No modificar la estructura de la vista existente `shares/index.blade.php`
- No cambiar el comportamiento de la API JSON

## Decisions

**Decisión 1**: Usar `$request->ajax()` para detectar peticiones AJAX

| Método | Ventaja | Desventaja |
|--------|---------|------------|
| `$request->ajax()` | Laravel estándar, consistente | Depende de header X-Requested-With |
| `$request->wantsJson()` | Detecta Accept header | Puede dar falsos positivos |

Elegimos `ajax()` porque es el mismo patrón usado en `UserController`.

**Decisión 2**: Pasar datos a la vista vs usar view()->with()

| Enfoque | Ejemplo |
|---------|---------|
| `view('shares.index', ['shares' => $shares])` | Más explícito |
| `view('shares.index')->with(compact('shares'))` | Estilo Laravel clásico |

Usamos el primer enfoque por claridad.

## Risks / Trade-offs

- **Riesgo**: La vista `shares/index.blade.php` no existe → **Mitigación**: Ya existe según glob anterior
- **Riesgo**: Incompatibilidad con frontend JavaScript que espera JSON → **Mitigación**: AJAX requests seguirán retornando JSON
- **Trade-off**: Pequeño cambio vs implementación más robusta → Apropiado para bug fix

## Migration Plan

1. Modificar `ShareController::index()` para agregar check de AJAX
2. Probar acceso directo al módulo compartidos
3. Probar que AJAX del frontend sigue funcionando

**Rollback**: Revertir el cambio en una línea
