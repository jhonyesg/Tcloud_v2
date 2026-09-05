## 1. Catálogo de iconos en el modal (admin/external-sites.blade.php)

- [x] 1.1 Verificar la versión de Font Awesome cargada en el proyecto y validar que los glyphs candidatos existen; ajustar la lista final a ≥40 iconos válidos
- [x] 1.2 Reemplazar el array plano `icons` por estructura categorizada (categoría → iconos) cubriendo Media, Datos, Comunicación, Seguridad, Herramientas y General, conservando los 24 actuales
- [x] 1.3 Convertir el grid `grid-cols-10` en grid con encabezados de categoría e input de búsqueda que filtre en vivo por nombre; estado `sin resultados` cuando no hay matches
- [x] 1.4 Garantizar que el icono seleccionado de un site existente aparece marcado y visible al abrir el modal de edición

## 2. Paleta de colores ampliada

- [x] 2.1 Extender `colors` a 16 entradas con tripletas (base/pastel/texto) para indigo, teal, orange, sky, lime, fuchsia, pink, yellow, sin alterar los hex de los 8 existentes
- [x] 2.2 Extender `colorBg()`/`colorText()` con las 8 variantes nuevas (mismo archivo, mismos mapas)

## 3. Preview del modal

- [x] 3.1 Aumentar el preview de 36px a 48px y verificar actualización en vivo de icono, color de fondo, texto, nombre y URL

## 4. Backend alineado

- [x] 4.1 En `app/app/Http/Controllers/ExternalSiteController.php`, extender la regla `in:` de `color` en `store()` con los 8 colores nuevos
- [x] 4.2 Extender la misma regla `in:` en `update()` y verificar con petición de prueba que un color nuevo persiste y un color inválido responde 422

## 5. Sidebar (solo datos)

- [x] 5.1 En `app/resources/views/layouts/app.blade.php`, añadir las 8 entradas nuevas al array `$siteColors` con los mismos hex del módulo admin, sin tocar markup ni clases

## 6. Validación

- [x] 6.1 Verificación cruzada de paletas: `colors` JS ↔ `in:` del controller ↔ `$siteColors` del sidebar contienen exactamente el mismo conjunto de nombres
- [x] 6.2 `php artisan view:clear && php artisan view:cache` y verificación visual: catálogo agrupado + búsqueda, 16 colores, preview 48px, site con color nuevo correcto en sidebar y en tabla