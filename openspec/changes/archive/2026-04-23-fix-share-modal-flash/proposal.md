## Why

En el módulo de Compartidos, al cargar la página aparece brevemente un modal de confirmación de "Revocar enlace" antes de que Alpine.js se inicialice completamente. Esto causa un flash visual unprofessional que afecta la experiencia de usuario, especialmente en producción donde debería ser imperceptible.

## What Changes

- Corregir el parpadeo del modal de confirmación de eliminación en la vista de compartidos (`shares/index.blade.php`).
- Implementar `x-cloak` y/o ajustar la inicialización de Alpine.js para evitar que elementos ocultos se muestren temporalmente durante la carga.

## Capabilities

### New Capabilities
- `modal-flash-fix`: Corrección del flash de modales en vistas Alpine.js mediante técnicas de cloak y proper initialization.

### Modified Capabilities
<!-- No hay especificaciones existentes que requieran cambios a nivel de requisitos -->

## Impact

- **Frontend**: Vista `shares/index.blade.php` y potencialmente otras vistas con modales ocultos controlados por Alpine.js.
- **UX**: Eliminación del flash visual unprofessional al cargar la página de compartidos.
