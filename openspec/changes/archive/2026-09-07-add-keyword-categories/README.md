# Add keyword categories

Permite que cada cliente organice sus keywords en categorías propias + un set base
administrado por el admin (`Político`, `Artista`, `Institución`). Las categorías son
una etiqueta visual y un filtro; el matching y el envío de avisos **no cambian**.

## Cambios

- 3 migraciones (todas additive):
  - `2026_09_07_180000_create_keyword_categories_table.php`
  - `2026_09_07_180100_add_category_id_to_user_keyword_table.php`
  - `2026_09_07_180200_seed_admin_keyword_categories.php`
- Modelos: `App\Models\KeywordCategory`, `App\Models\UserKeyword`.
- Controllers:
  - `App\Http\Controllers\Ia\AvisosInteligentesController` (5 endpoints nuevos + `storeKeyword` extendido).
  - `App\Http\Controllers\Ia\AvisosCategoriesAdminController` (CRUD admin).
- Vistas:
  - `resources/views/ia/avisos-inteligentes/user-detail.blade.php` (pill filter + inline `<select>`).
  - `resources/views/ia/avisos-inteligentes/admin-categories.blade.php` (nueva).
- Rutas: bloque bajo `Route::middleware(['auth', 'admin'])->prefix('ia')` en `routes/web.php`.

## Lo que NO se toca

- `KeywordMatcher`, `AlertDispatcher`, `AvisosScanService` (verificado: 0 referencias a `category_id`).
- Cron jobs.
- Cupo de keywords por usuario.
- Papelera / soft-delete.

## Rollback (si rompe en producción)

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app

# 1. Revertir las migraciones (orden inverso, todas additive → down limpio)
php artisan migrate:rollback --step=3

# 2. Revertir el merge (controllers + view + routes; no hay workers)
cd ..
git revert <commit-hash-de-la-feature>

# 3. Refrescar PHP-FPM para que la nueva vista quede fuera de opcode cache
systemctl reload php84-php-fpm   # o el equivalente del servidor
```

La columna `category_id` es nullable y `ON DELETE SET NULL`, así que el rollback
nunca borra keywords — solo desclasifica. Las keywords existentes siguen
matcheando exactamente como antes.

## Freno de emergencia alternativo (sin deploy)

Como el filtrado por categoría es puramente client-side (Alpine getter), y la
columna `category_id` no afecta matching/dispatch, no hace falta un interruptor
runtime. Si la UI de categorías muestra algo inesperado, se puede revertir el
deploy con los pasos de arriba sin pérdida de datos.

## Smoke tests ejecutados localmente

- ✅ Migrations corren en orden (todas DONE, 3 rows sembradas).
- ✅ Visibilidad por scope: Cliente A ve sus privadas + base; Cliente B no ve las de A.
- ✅ Slug único dentro de scope, FK `ON DELETE SET NULL` cascade verificado.
- ✅ CRUD categorias (cliente): index 200, store 201, update 200, destroy 200.
- ✅ Asignar/quitar categoría a keyword: 200 con pivot actualizado correctamente.
- ✅ Aislamiento cross-user: Cliente B → endpoints de A → 403.
- ✅ Asignar categoría no visible → 200 OK pero pivot queda NULL (defensa en profundidad).
- ✅ Admin categorias CRUD: index 200, store 201, update 200, destroy 200 (sin keywords).
- ✅ Pipeline matching/scanner sin referencias a `category_id` (verificado con grep).
