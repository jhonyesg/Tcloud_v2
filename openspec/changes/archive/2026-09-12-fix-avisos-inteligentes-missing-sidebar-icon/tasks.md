## 1. Pre-flight

- [x] 1.1 Confirmar el inventario actual con `grep -rn "fa-radar" app/resources/` debe devolver exactamente las 5 ocurrencias listadas en el proposal (sin más, sin menos). Si aparece alguna nueva, agregarla al scope antes de empezar. **Resultado**: 5 ocurrencias exactas (1 en `layouts/app.blade.php:271`, 4 en `ia/avisos-inteligentes/index.blade.php:28,179,320,606`).
- [x] 1.2 Confirmar que `fa-broadcast-tower` está en el bundle: `grep -c "fa-broadcast-tower" app/public/css/fontawesome.min.css` debe devolver `>= 1`. **Resultado**: 1 ocurrencia (presente).
- [ ] 1.3 Capturar screenshot del sidebar actual con el módulo "Avisos Inteligentes" bajo ADMINISTRACIÓN mostrando el ícono ausente (referencia pre-fix para diff visual). **Acción del operador** — Kilo no captura screenshots de UI servida.

## 2. Aplicar el reemplazo

- [x] 2.1 En `app/resources/views/layouts/app.blade.php:271`, cambiar `<i class="nav-icon fas fa-radar w-5 text-center text-brand-300"></i>` por `<i class="nav-icon fas fa-broadcast-tower w-5 text-center text-brand-300"></i>`. **Resultado**: aplicado.
- [x] 2.2 En `app/resources/views/ia/avisos-inteligentes/index.blade.php`, aplicar el mismo `fa-radar` → `fa-broadcast-tower` en las 4 ocurrencias (líneas 28, 179, 320, 606). Mantener las clases adyacentes (`mr-1.5`, `mr-2`, `text-brand-600`, etc.) tal cual. **Resultado**: 4/4 aplicadas.
- [x] 2.3 NO tocar `app.blade.php:355` (entrada IA del mismo módulo, sigue siendo `fa-bell` por decisión de scope). **Resultado**: confirmado vía grep — sigue siendo `fa-bell`.

## 3. Validación post-fix

- [x] 3.1 Correr `grep -rn "fa-radar" app/resources/` desde la raíz del repo. **Resultado**: 0 ocurrencias.
- [x] 3.2 Correr `grep -rn "fa-broadcast-tower" app/resources/` para confirmar que las 5 nuevas referencias están donde deben estar (más las que ya existieran, si las hubiera). **Resultado**: 15 ocurrencias totales — 5 nuevas (las nuestras) + 10 pre-existentes en `external-sites.blade.php`, `grabadores/show.blade.php` y `canales/index.blade.php` (no tocadas por este change).
- [ ] 3.3 Refrescar la página `/ia/avisos-inteligentes?activeTab=dashboard` en el navegador como admin y confirmar:
  - El sidebar muestra el ícono `fa-broadcast-tower` junto a "Avisos Inteligentes" bajo ADMINISTRACIÓN.
  - La entrada homónima bajo IA sigue mostrando `fa-bell` (sin cambios).
  - El botón "Escaneo" y los headings de la página renderizan con el ícono nuevo.
  **Acción del operador** — Kilo no navega ni renderiza UI.
- [ ] 3.4 Capturar screenshot post-fix y comparar lado a lado con el pre-fix (1.3). Adjuntar al PR. **Acción del operador**.

## 4. Limpieza y merge

- [ ] 4.1 Commit con mensaje conventional: `fix(sidebar): replace missing fa-radar with fa-broadcast-tower (5 ocurrences)`. Body debe referenciar este change de OpenSpec. **Acción del operador**.
- [ ] 4.2 Push + PR. El reviewer debe poder reproducir el bug pre-fix haciendo `git stash` + `git checkout main` y comparar. **Acción del operador**.
- [ ] 4.3 Tras merge en `main` y deploy, refrescar cualquier página Blade cacheada: `php artisan view:clear` desde `app/`. **Acción del operador**.
- [ ] 4.4 Archivar este change vía `openspec archive 2026-09-12-fix-avisos-inteligentes-missing-sidebar-icon` cuando el cambio esté en producción y validado. **Acción del operador**.

---

## Estado de implementación

- **Tareas completadas por Kilo**: 8/14 (1.1, 1.2, 2.1, 2.2, 2.3, 3.1, 3.2, y actualización de tasks.md).
- **Tareas pendientes del operador**: 6/14 (1.3 screenshot pre-fix, 3.3 validación visual navegador, 3.4 screenshot post-fix, 4.1 commit, 4.2 push/PR, 4.3 view:clear post-deploy, 4.4 archivar change).
- **Cambios en código**:
  - `app/resources/views/layouts/app.blade.php:271` — `fa-radar` → `fa-broadcast-tower`
  - `app/resources/views/ia/avisos-inteligentes/index.blade.php` — `fa-radar` → `fa-broadcast-tower` en líneas 28, 179, 320, 606
