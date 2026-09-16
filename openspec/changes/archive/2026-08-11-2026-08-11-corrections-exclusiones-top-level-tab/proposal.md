# Change: Exclusiones como pestaña top-level en /ia/correcciones

## Why

El módulo `/ia/correcciones` muestra seis pestañas principales: **Pendientes**, **Aprobadas**, **Contexto sensible**, **Revisar transcripciones**, **IA Suggest** y **AI Suggest Results**. La lista de exclusiones dinámicas —términos que el AI Suggest nunca debe traducir— se renderiza hoy como un panel apilado **dentro** del tab "IA Suggest" (`app/resources/views/ia/correcciones/index.blade.php:1104` con `x-show="tab === 'ai-settings'"`).

Esto causa tres problemas:

1. **Descubribilidad baja**: para llegar a "Exclusiones" el admin debe entrar primero a "IA Suggest", que es una pestaña de *configuración* (form de settings del modelo LLM, ventanas de botones rápidos, override de envs). El admin busca datos operacionales, no settings.
2. **Mezcla conceptual**: "Exclusiones" es una lista CRUD (agregar, archivar, restaurar), igual que "Pendientes" o "Aprobadas". No tiene semántica de configuración.
3. **Confusión visual con "Contexto sensible"**: ambas pestañas comparten icono `fa-shield-halved` y temática de "protección", pero una está top-level y la otra escondida.

El admin reportó el 2026-08-11:

> *"esa parte que se llama exclusiones debería estar al mismo nivel de las ventanas anteriormente descritas, no dentro de una subventana. Porque no tiene lógica para mí"*

## What Changes

### 1. Nueva pestaña top-level "Exclusiones"

Promover el panel Exclusiones (líneas 1103–1228 de `index.blade.php`) a una pestaña independiente en la barra de tabs (líneas 180–202). Posición: **entre "Aprobadas" y "Contexto sensible"** porque las tres son listas CRUD del diccionario, mientras que Contexto sensible es revisión/auditoría y pertenece al siguiente grupo visual.

El panel conserva su contenido actual (header, búsqueda, toggle "Mostrar archivadas", tabla con acciones Archivar/Restaurar, modal "Agregar exclusión"). Solo cambia su `x-show`.

### 2. Icono diferenciado

Cambiar el icono del header del panel y del botón de tab de `fa-shield-halved` a `fa-ban`, para evitar colisión visual con "Contexto sensible" (que conserva `fa-shield-halved`).

- `fa-ban` (circle-slash) representa "no traducir / bloquear término" — semánticamente correcto para exclusiones.
- `fa-shield-halved` representa "proteger / auditar sensibilidad" — queda en Contexto sensible.

### 3. Badge con contador de activas

Agregar badge `<span>` al botón de tab que muestre `exclusionesActiveFiltered.length` (cantidad de exclusiones con `archived_at === null`) en el mismo estilo que los badges de Pendientes (`bg-red-500`) y Aprobadas (`bg-emerald-500`). Para exclusiones se usa `bg-purple-500` para mantener coherencia con la paleta morada del panel.

### 4. Separar carga en `switchTab()`

Hoy `switchTab('ai-settings')` carga tanto `aiSettings` como `exclusiones` (líneas 1886–1896). Después del refactor:

- `switchTab('ai-settings')` → solo `loadAiSettings()`.
- `switchTab('exclusiones')` → `loadExclusiones()` si la lista está vacía.

Los modales shortcut (líneas 1230+) y el modal "Agregar exclusión" (líneas 1181–1228) **no se mueven** porque ya están fuera de cualquier `<div x-show="tab === ...">` y funcionan independientemente.

### 5. Delta al spec `transcription-corrections`

Actualizar las referencias de ruta en los requisitos existentes:

- `Requirement: Admin puede gestionar exclusiones dinámicas desde UI` (spec línea 400): cambiar `/ia/correcciones → IA Suggest → Exclusiones` por `/ia/correcciones → Exclusiones`.
- `Requirement: Atajo "Excluir" archiva la corrección asociada en la misma operación` (spec línea 461): ajustar referencias al "subpanel Exclusiones" donde aplique.

Marcar ambos como **MODIFIED** en `specs/transcription-corrections/spec.md`.

## Non-goals

- No se modifica la API (`/ia/correcciones/protected-terms*`), ni el modelo `CorrectionProtectedTerm`, ni el servicio `CorrectionProtectedTermsService`.
- No se cambia la lógica de filtrado (marcas dinámicas + config `protected_brands`), ni el cache TTL, ni el comportamiento del AI Suggest.
- No se reordena toda la barra de tabs (solo se inserta "Exclusiones" en una posición específica).
- No se renombra el panel/modal/variables Alpine — solo se reubica el panel.
- No se hace trabajo sobre la spec de exclusiones más allá de ajustar las referencias de ruta obsoletas.

## Success Criteria

- La pestaña "Exclusiones" aparece en la barra entre "Aprobadas" y "Contexto sensible".
- Al hacer click, muestra el panel CRUD sin necesidad de entrar a "IA Suggest" primero.
- El icono del tab es `fa-ban`, distinto del icono de Contexto sensible.
- El badge muestra el número correcto de exclusiones activas y se actualiza al archivar/restaurar.
- El panel "IA Suggest" ya no contiene la sección Exclusiones (queda solo configuración).
- `switchTab('ai-settings')` ya no dispara `loadExclusiones()`.
- Los atajos "Excluir" desde filas de Pendientes y Aprobadas, y los bulk exclusions, siguen funcionando idéntico (los modales no se mueven).
- Las referencias en `openspec/specs/transcription-corrections/spec.md` están actualizadas con la nueva ruta.