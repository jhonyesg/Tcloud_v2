## Why

El panel de cliente de Avisos Inteligentes permite hoy crear keywords libremente con un cupo por usuario (decisión previa `avisos_keywords_cliente_gestiona_admin_fija_limite`), pero no hay forma de **agruparlas visualmente ni filtrarlas por tema**. Cuando un cliente tiene 30, 50 o 100 palabras clave se vuelve difícil encontrar una específica, distinguir las que están relacionadas (nombres de políticos, artistas, instituciones, marcas) y entender qué está cubierto. Esto se resuelve sin tocar la lógica de matching del transcriptor (que sigue operando por texto) ni los cron/avisos: solo se agrega una etiqueta que el cliente asigna y usa como filtro y agrupador visual.

## What Changes

- Crear tabla `keyword_categories` con `owner_scope` (`admin` para el set base definido por el admin, `user` para las que crea cada cliente), `owner_id` (FK a `users`, NULL para admin), `name`, `slug`, `color_hex`. Index unique por `(owner_scope, owner_id, slug)`.
- Crear tabla `user_keyword_categories` pivot (muchos-a-muchos entre `user_keyword` y `keyword_categories`) para soportar la regla "una keyword puede tener una categoría a la vez" — agregar columna `category_id` NULLABLE con FK a `keyword_categories` y `ON DELETE SET NULL`. Si la categoría se borra, la keyword queda sin categoría pero no se pierde.
- Sembrar el set admin base `Político`, `Artista`, `Institución` en una migración `seed` (idempotente, corre cada deploy).
- Extender `Keyword` con relación `category()` y scope `inCategory(...)`.
- Nuevos endpoints REST en `AvisosInteligentesController`:
  - `GET /ia/avisos-inteligentes/{user}/categories` (lista merged: admin ∪ user, sin duplicados por nombre normalizado).
  - `POST /ia/avisos-inteligentes/{user}/categories` (crear categoría del cliente; valida cupo si se agrega, opcional).
  - `PATCH /ia/avisos-inteligentes/{user}/categories/{id}` (renombrar, recolorear; solo del cliente).
  - `DELETE /ia/avisos-inteligentes/{user}/categories/{id}` (borrar; `category_id` de las keywords pasa a NULL).
  - `PATCH /ia/avisos-inteligentes/{user}/keywords/{id}` (asignar `category_id` o `null`; valida que la categoría sea visible para ese usuario).
- Endpoint admin: `POST /admin/avisos/categories`, `PATCH`, `DELETE` para gestionar el set base (Político, Artista, Institución).
- UI cliente (`resources/views/ia/avisos-inteligentes/user-detail.blade.php`): barra de filtros con pills por categoría + sección colapsable "Sin categoría". Cada keyword muestra selector inline `[Categoría ▾]` editable en sitio. Selector de categoría al crear (`POST /keywords` acepta `category_id` opcional).
- UI admin (`resources/views/admin/avisos/categories.blade.php`): CRUD del set base con nombre, slug auto, color.

## Capabilities

### New Capabilities
- `avisos-keyword-categories`: taxonomía de categorías por usuario + set base admin, asignación opcional por keyword, filtrado y vista agrupada. Cubre creación/edición/borrado de categorías del cliente, gestión admin del set base, asignación de categoría a keyword, y comportamiento del filtro en el panel.

### Modified Capabilities
_Ninguna._ El matching y el envío de avisos no cambian; la lógica de negocio del transcriptor queda intacta.

## Impact

- **Migración nueva** (obligatoria): `2026_09_xx_create_keyword_categories_table.php` + `add_category_id_to_user_keyword_table.php` + `seed_admin_keyword_categories.php`.
- **Modelos**: `Keyword` (nueva relación), nuevo `KeywordCategory`.
- **Controllers**: `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (5 endpoints nuevos). Nuevo controlador admin: `app/app/Http/Controllers/Admin/AvisosCategoriesController.php`.
- **Vistas**: extensión de `resources/views/ia/avisos-inteligentes/user-detail.blade.php`; nueva `resources/views/admin/avisos/categories.blade.php`.
- **Rutas**: agregar en `app/routes/web.php` dentro de los grupos `auth` y `admin` ya existentes.
- **Sin cambios en**: `KeywordMatcher`, `AvisosScanService`, `AlertDispatcher`, cron jobs, cupo de keywords existente, papelera de reciclaje, sistema de mails.
- **Riesgo bajo**: la columna `category_id` es NULLABLE y el filtro por categoría es opt-in. Ningún cliente pierde funcionalidad; quien no use categorías sigue viendo la lista plana actual.

## Non-goals

- No se modifica la lógica de matching/transcripción: `KeywordMatcher` y los crons de escaneo siguen trabajando por texto sobre `keywords` (intactos).
- No se hace auto-categorización con LLM (consistente con `ai_suggester_cron_phase_finished_2026_08_21`); toda asignación es manual del cliente.
- No se rediseña el panel admin de Avisos Intelgentes; solo se agrega la sub-sección "Categorías base" para CRUD del set admin.
- No se cambia el cupo de keywords por usuario. Las categorías del cliente son ilimitadas salvo que el admin decida cupar (configurable via `system_settings`, default NULL = sin límite).
- No se migran las keywords ya existentes: las que no tengan categoría aparecen en la sección "Sin categoría" hasta que el cliente las clasifique.
- No se introduce el patrón "1 keyword → N categorías". Solo 1→1; si se necesita N se evalúa después.
- No se tocan las menciones (`keyword_matches`), los mails de aviso, ni la cadencia de envío.
