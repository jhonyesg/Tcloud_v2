## Context

El logo está ubicado en `/home/jsuarez/Música/plataforma Cobertura/Tcloud_v2_alternativa/logo.png` y necesita ser:
1. Copiado a `public/` para acceso web
2. Referenciado en el HTML como favicon
3. Mostrado en login.blade.php
4. Mostrado en layouts/app.blade.php (header)

## Goals / Non-Goals

**Goals:**
- Favicon personalizado con logo.png
- Logo visible en login centrado encima de "Tcloud"
- Logo visible en header del panel al lado de "Tcloud"

**Non-Goals:**
- No modificar el tamaño o diseño del logo
- No agregar animaciones o efectos
- No cambiar la estructura de navegación

## Decisions

**Decisión 1: Ubicación del logo**

Copiar `logo.png` a `public/logo.png` para que sea accesible vía URL `/logo.png`.

**Decisión 2: Tamaños y proporciones**

| Ubicación | Tamaño sugerido | Notas |
|-----------|----------------|-------|
| Favicon | 32x32 | Usar imagen tal cual |
| Login | 80-100px de ancho | Centrado, margen inferior |
| Header | 32x32 | Reemplaza ícono fa-cloud |

**Decisión 3: Implementación de favicon**

```html
<link rel="icon" type="image/png" href="/logo.png">
```

**Decisión 4: Login logo**

```html
<div class="text-center mb-6">
    <img src="/logo.png" alt="Logo" class="h-20 mx-auto mb-4">
    <h1 class="text-3xl font-bold text-gray-800">Tcloud</h1>
</div>
```

**Decisión 5: Header logo**

Reemplazar el div con fa-cloud:
```html
<div class="w-8 h-8 rounded-lg overflow-hidden">
    <img src="/logo.png" alt="Logo" class="w-full h-full object-contain">
</div>
```

## Risks / Trade-offs

- **Riesgo**: Logo con fondo transparente puede verse mal en ciertos fondos → Revisar que el logo tenga buen contraste
- **Trade-off**: Ninguno significativo

## Migration Plan

1. Copiar logo.png a public/
2. Agregar link de favicon en layouts/app.blade.php
3. Modificar login.blade.php para agregar logo
4. Modificar layouts/app.blade.php header para usar logo.png

**Rollback**: 
- Eliminar archivo de public/
- Revertir cambios en blade files
