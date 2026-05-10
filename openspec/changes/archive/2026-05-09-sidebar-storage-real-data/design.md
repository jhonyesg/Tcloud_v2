## Context

El layout `layouts/app.blade.php` se renderiza en todas las páginas de la aplicación. El sidebar contiene un bloque de almacenamiento con valores completamente hardcodeados ("2.5 GB / 10 GB", barra al 25%). Laravel no pasa datos al layout automáticamente — cada controller inyecta datos solo a su vista propia.

El modelo `User` ya tiene los campos necesarios: `personal_used_bytes` (bytes usados) y `personal_quota_bytes` (cuota asignada en bytes). La sesión guarda `user_id` y `user_role` en cada login.

## Goals / Non-Goals

**Goals:**
- Mostrar datos reales de almacenamiento en el sidebar para todos los usuarios.
- Para usuarios normales: uso personal (`personal_used_bytes` / `personal_quota_bytes`).
- Para admins: total del sistema (suma de `File::sum('size')`).
- Formatear valores en la unidad adecuada (KB, MB, GB).
- Manejar cuota ilimitada (cuando `personal_quota_bytes = 0`).

**Non-Goals:**
- Cambiar la lógica de cuotas del sistema.
- Crear nuevas rutas o APIs.
- Modificar el dashboard ni otras vistas.

## Decisions

### View Composer en AppServiceProvider

**Decisión**: Registrar un View Composer en `AppServiceProvider::boot()` que escucha el layout `layouts.app` e inyecta la variable `$sidebarQuota`.

**Alternativas consideradas**:
- *Pasar datos desde cada controller*: Requeriría modificar todos los controllers existentes y futuros — alto acoplamiento y mantenimiento costoso.
- *Middleware que comparte datos vía `view()->share()`*: Funciona pero carga el usuario en cada request incluyendo rutas que no usan el layout (APIs, redirects). El View Composer solo se ejecuta cuando el layout realmente se renderiza.

**Estructura de `$sidebarQuota`**:
```php
[
    'used_label'  => '1.2 GB',      // texto formateado del uso
    'limit_label' => '5 GB',         // texto formateado del límite, o 'Ilimitado'
    'percentage'  => 24,             // entero 0-100 para el width de la barra
    'is_unlimited'=> false,          // oculta la barra cuando true
    'color_class' => 'bg-brand-300', // cambia a rojo si >90%
]
```

### Formateo de bytes en el View Composer

**Decisión**: Función privada en el closure del composer (no helper global).

El formateo convierte bytes a la unidad más legible: < 1 MB → KB, < 1 GB → MB, >= 1 GB → GB. Dos decimales de precisión.

### Color de alerta

Si el porcentaje supera el 90%, la barra cambia a `bg-red-400` para alertar al usuario. Bajo el 90% usa `bg-brand-300`.

## Risks / Trade-offs

- **Consulta en cada render del layout** → el composer hace `User::find()` en cada request que renderiza el layout. Mitigación: la consulta es por PK (índice primario), es O(1) y trivialmente rápida. No justifica caché para esta escala.
- **Admin ve uso total del sistema** → `File::sum('size')` sin índice podría ser lento con millones de archivos. Mitigación: aceptable para uso admin; si escala, se puede cachear con `Cache::remember`.
- **Si `user_id` no está en sesión** → el composer devuelve valores en cero para evitar errores en páginas de error o redirects inesperados.
