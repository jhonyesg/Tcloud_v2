## Why

El admin hoy puede agregar reglas al diccionario (`/ia/correcciones → Nueva corrección`) pero descubre las **variantes** que faltan en su corpus por casualidad: cuando un apply-retroactive toca 0 segmentos o cuando Mis Avisos muestra menciones con texto mal escrito. **No existe una herramienta que tome una palabra/frase y diga "estas son todas las variantes de esa palabra en tus últimas N horas/días de transcripciones"**.

Esto genera:
- Reglas que cubren mal las variantes (caso "Abelardo" donde había 14+ variantes distintas y sólo 6 reglas)
- Gasto de tokens innecesario en AI Suggest (escanea todo el corpus para descubrir variantes cuando el admin ya sabe la palabra objetivo)
- Imposibilidad de validar cobertura antes de aprobar una corrección masiva

## What Changes

Una nueva pestaña **"Variation Finder"** en `/ia/correcciones` con:

1. **Input**: campo de texto + selector de scope temporal (mismo dropdown 1h/8h/1d/3d/7d/14d/30d/all que ya existe en Re-aplicar).
2. **Backend endpoint** `POST /ia/correcciones/variations/find`:
   - Query: `transcription_segments.text LIKE '%<word>%' AND created_at >= <since>` (índice por texto, no full-scan).
   - Para cada match, extrae una "ventana de contexto" alrededor de la palabra (default ±25 caracteres).
   - Agrupa por la ventana normalizada (lowercase + trim + collapse whitespace).
   - Devuelve top N (default 100) con: variante, count, ejemplo completo, "ya es regla aprobada" (bool), "ya es regla pendiente" (bool).
3. **UI resultados**:
   - Tabla con columnas: Variante | Frecuencia | Estado | Acciones.
   - Acciones por fila:
     - **"Ver ejemplos"**: abre modal con 3 ejemplos completos (audio + transcripción) del scope.
     - **"Crear corrección"** (sólo si no existe regla): input modal con `wrong` (la variante) pre-llenado y `correct` vacío → crea rule pending. Admin la aprueba desde el tab Pendientes.
     - **"Ver regla existente"** (si ya existe): link a la regla en Aprobadas/Pendientes.
4. **Acciones masivas**: checkbox por fila + botón "Crear corrección para N seleccionadas" que abre un solo modal donde el admin llena el `correct` una vez y se crean N reglas pending en bulk.
5. **Sin IA obligatoria**: la herramienta es 100% SQL (búsqueda + agrupamiento). El admin decide si después pasa esa lista por AI Suggest si quiere que el LLM refine.
6. **Performance**: query acotada por `since` + `LIMIT 10000` interno. Para scopes grandes (>1M segments), el endpoint agrega una nota "se analizaron los primeros N matches" en vez de quedarse colgado.

## Capabilities

### New Capabilities
- `corrections-variation-finder`: Especifica el flujo de discovery de variantes: input + scope + endpoint de búsqueda + agregación + UI de creación de reglas. Distinto de `llm-correction-suggestion` (que es LLM-driven y global) y de `corrections-ai-context-aware-with-mark-curation` (que es contextual dentro del modal de una regla específica).

## Impact

- **Código**:
  - `app/app/Http/Controllers/Ia/CorreccionesController.php`: nuevo método `findVariations(Request)` que arma el query, agrupa resultados y devuelve JSON.
  - `app/resources/views/ia/correcciones/index.blade.php`: nueva tab "Variation Finder" (entre "AI Suggest" y "AI Suggest Results") con form + tabla de resultados + modales de creación.
  - `app/routes/web.php`: nueva ruta `POST /ia/correcciones/variations/find` y `POST /ia/correcciones/variations/bulk-create`.
  - `app/app/Models/Correction.php`: scope nuevo `withNormalizedMatches($word)` que filtra por LIKE en `wrong_normalized` (para detectar variantes que YA son reglas existentes o pending).
- **APIs/Routes**: 2 nuevos endpoints POST. Sin breaking changes.
- **Migraciones**: ninguna.
- **Tests**: nuevo test file `tests/Feature/CorrectionsVariationFinderTest.php` cubriendo el endpoint de búsqueda con seed controlado.
- **Costo**: 0 tokens de IA (todo es SQL). El admin decide después si pasa la lista por AI Suggest.

## Non-goals

- **No incluye matching fonético ni fuzzy** (Levenshtein, soundex). Eso es otro OpenSpec change (`corrections-fuzzy-match`) más invasivo que toca el motor de apply. Este es **descubrimiento de variantes literales** solamente.
- **No reemplaza AI Suggest** — sigue siendo útil para discovery LLM-driven sobre el corpus completo sin palabra objetivo. Variation Finder es complementario: discovery dirigido por el admin.
- **No es retroactivo automático**: el admin debe correr Re-aplicar después de aprobar las nuevas reglas. Variation Finder sólo DISCOVER + CREATE rules pending.
- **No toca apply-retroactive**: el flujo de aplicar correcciones queda intacto.
