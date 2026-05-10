## 1. View Composer en AppServiceProvider

- [x] 1.1 Registrar View Composer en `AppServiceProvider::boot()` que escucha `layouts.app`
- [x] 1.2 Implementar función privada `formatBytes(int $bytes): string` que devuelve KB/MB/GB con 2 decimales
- [x] 1.3 En el composer: leer `user_id` y `user_role` de la sesión; devolver `$sidebarQuota` con valores en cero si no hay sesión
- [x] 1.4 Para rol `admin`: calcular `File::sum('size')` como uso total, sin límite (ilimitado)
- [x] 1.5 Para usuarios normales: leer `personal_used_bytes` y `personal_quota_bytes` del modelo `User`
- [x] 1.6 Calcular porcentaje (0–100) y `color_class` (`bg-brand-300` o `bg-red-400` si > 90%)
- [x] 1.7 Manejar cuota ilimitada (`personal_quota_bytes = 0`): `is_unlimited = true`, sin porcentaje

## 2. Actualizar el sidebar del layout

- [x] 2.1 En `layouts/app.blade.php`, reemplazar el bloque hardcodeado (líneas ~211-215) con variables de `$sidebarQuota`
- [x] 2.2 Mostrar `$sidebarQuota['used_label']` y `$sidebarQuota['limit_label']` en el texto superior
- [x] 2.3 Mostrar barra de progreso con `$sidebarQuota['percentage']` y `$sidebarQuota['color_class']`
- [x] 2.4 Ocultar la barra (`@if(!$sidebarQuota['is_unlimited'])`) cuando el usuario tiene cuota ilimitada
