## Why

El sidebar de admin muestra el módulo **Avisos Inteligentes** dentro del grupo `ADMINISTRACIÓN` con un `<i>` que renderiza vacío. Verificado por el cliente y reproducido en el bundle desplegado.

Causa raíz: la clase `fa-radar` referenciada en `app/resources/views/layouts/app.blade.php:271` **no existe** en el bundle de Font Awesome Free 6.5.1 servido desde `app/public/css/fontawesome.min.css` (102 KB, `webfonts/fa-solid-900.woff2`). `grep -oE "fa-radar|fa-bell|fa-broadcast-tower|fa-bullhorn" app/public/css/fontawesome.min.css` confirma que `fa-radar` no aparece; `fa-bell`, `fa-broadcast-tower` y `fa-bullhorn` sí.

Resultado: 5 ocurrencias rotas silenciosamente en producción:

| Archivo | Línea | Contexto |
|---|---|---|
| `app/resources/views/layouts/app.blade.php` | 271 | Sidebar admin "Avisos Inteligentes" (lo reportado) |
| `app/resources/views/ia/avisos-inteligentes/index.blade.php` | 28 | Botón "Escaneo" en la página del módulo |
| `app/resources/views/ia/avisos-inteligentes/index.blade.php` | 179 | Heading "Escaneo de transcripciones" |
| `app/resources/views/ia/avisos-inteligentes/index.blade.php` | 320 | Card "Escaneo de menciones" |
| `app/resources/views/ia/avisos-inteligentes/index.blade.php` | 606 | Heading "Últimas 3 corridas de scan" |

Las del cuerpo son más chicas y menos notorias; el del sidebar es el único que deja un hueco visualmente evidente.

## What Changes

- **Reemplazar** `fas fa-radar` por `fas fa-broadcast-tower` en las 5 ocurrencias listadas. `fa-broadcast-tower` está presente en el bundle FA6.5.1 desplegado y mantiene la semántica de "emisión/transmisión de alertas" coherente con el módulo Avisos Inteligentes.
- El icono de la entrada duplicada del módulo bajo el grupo `IA` (`app.blade.php:355`) sigue siendo `fa-bell` — se conserva para diferenciar visualmente las dos entradas del mismo módulo aunque ambas llevan al mismo destino.

## Capabilities

### New Capabilities
- (ninguna)

### Modified Capabilities
- (ninguna — el sidebar no está modelado como capability en `openspec/specs/`; este fix es puramente cosmético de vista)

## Impact

- **Vistas Blade** (2 archivos, 5 líneas):
  - `app/resources/views/layouts/app.blade.php:271`
  - `app/resources/views/ia/avisos-inteligentes/index.blade.php` (líneas 28, 179, 320, 606)
- **Sin migraciones** de BD.
- **Sin cambios** en controladores, JS, Alpine stores, ni rutas.
- **Sin impacto** operacional más allá de `php artisan view:clear` (si el compilador Blade cachea vistas).
- **Bundle FA**: no se toca. Si en el futuro se quiere agregar `fa-radar` u otros iconos faltantes, eso es un change aparte (auditoría completa del bundle vs. iconos referenciados).

## Non-goals

- No se diagnostica ni se reemplaza el bundle de Font Awesome (sería un change de mayor alcance: comparar contra el paquete completo `fontawesome-free-6.5.1-web.zip`, decidir subset vs. completo, riesgo de regresión de performance).
- No se introduce un linter/checker que valide que toda clase `fa-*` referenciada exista en el bundle desplegado. Es deseable pero fuera de alcance.
- No se cambia el icono de la entrada duplicada del módulo bajo el grupo `IA` (`fa-bell` se queda).
- No se renombra ni reorganiza el sidebar (la duplicación de "Avisos Inteligentes" en dos grupos es un ticket aparte).
