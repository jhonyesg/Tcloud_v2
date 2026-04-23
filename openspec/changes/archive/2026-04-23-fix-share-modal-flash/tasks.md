## 1. Verificación y diagnóstico

- [x] 1.1 Verificar que existe el problema de flash en modal de compartidos
- [x] 1.2 Revisar si el layout principal tiene regla `[x-cloak]`
- [x] 1.3 Identificar todos los modales con `x-show` en el proyecto

## 2. Solución: Regla CSS global de cloak

- [x] 2.1 Agregar regla `[x-cloak] { display: none !important; }` al layout principal (`layouts/app.blade.php`)

## 3. Solución: Aplicar x-cloak a modales

- [x] 3.1 Agregar `x-cloak` al modal de "Revocar enlace" en `shares/index.blade.php`
- [x] 3.2 Agregar `x-cloak` a los modales Profile y Settings en `layouts/app.blade.php`
- [x] 3.3 Agregar `x-cloak` a cualquier otro modal con `x-show` en otras vistas

## 4. Verificación

- [x] 4.1 Recargar página de compartidos y verificar que no hay flash del modal
- [x] 4.2 Verificar que los modales funcionan correctamente después de la carga
- [x] 4.3 Probar en diferentes vistas con modales
