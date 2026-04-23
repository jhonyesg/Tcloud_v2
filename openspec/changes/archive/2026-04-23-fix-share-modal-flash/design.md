## Context

El módulo de Compartidos utiliza Alpine.js para manejar la interacción del frontend. El modal de confirmación de eliminación ("Revocar enlace") está controlado por una variable `deleteModal.show` que inicialmente es `false`. Sin embargo, durante la carga de la página, antes de que Alpine.js se inicialice completamente, el navegador puede mostrar brevemente el elemento con `x-show="deleteModal.show"` antes de que se evalúe como `false`, causando un "flash" del modal.

## Goals / Non-Goals

**Goals:**
- Eliminar el flash visual del modal de "Revocar enlace" al cargar la página de compartidos.
- Implementar una solución genérica que pueda aplicarse a otros modales similares en el proyecto.

**Non-Goals:**
- No se modificará la funcionalidad del modal ni su comportamiento después de la carga.
- No se cambiarán animaciones o transiciones existentes.

## Decisions

- **Uso de `x-cloak`**: Agregar el atributo `x-cloak` a los elementos modales y CSS配套 para ocultarlos mientras Alpine.js se inicializa. Esta es la solución estándar recomendada por Alpine.js.
  - Alternativa: Usar `x-if` en lugar de `x-show`, pero `x-if` destruye/crea el DOM lo que puede causar otros problemas de renderizado.

- **Regla CSS global**: Definir `[x-cloak] { display: none !important; }` en el layout principal (`layouts/app.blade.php`) para que se aplique a todas las vistas.

## Risks / Trade-offs

- **[Riesgo]** Si el layout principal no tiene la regla CSS de cloak, el problema persistirá → **[Mitigación]** Verificar que la regla exista y esté correctamente aplicada.

- **[Trade-off]** El `x-cloak` añade un attribute al HTML pero es mínimo y semánticamente correcto para este caso de uso.

## Migration Plan

1. Agregar regla `[x-cloak] { display: none !important; }` al layout principal si no existe.
2. Agregar `x-cloak` a los modales en `shares/index.blade.php`.
3. Verificar que no haya otros modales en el proyecto con el mismo problema.

## Open Questions

- ¿Existen otros modales en el proyecto con el mismo problema de flash?
- ¿El layout principal ya tiene alguna regla de cloak definida?
