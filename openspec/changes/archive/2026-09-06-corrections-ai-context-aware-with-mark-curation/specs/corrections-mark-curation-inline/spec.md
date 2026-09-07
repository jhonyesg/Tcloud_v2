# Spec Delta: corrections-mark-curation-inline

## Purpose

Convertir el modal de contexto de correcciones en una **estación de curaduría de marcas protegidas**: el admin puede marcar manualmente una marca/sigla/nombre propio del segmento, o pedir al LLM que sugiera candidatos, y agregarlas a `protected_brands` (vía `CorrectionProtectedTermsService`) sin salir del modal ni hacer deploy. Esto evita que el post-filtro defensivo del LLM marque como "marca" términos que el admin sabe legítimamente traducir, y acelera el bootstrap del diccionario de marcas del proyecto.

---

## ADDED Requirements

### Requirement: Curaduría manual por selección de texto

Cuando el admin selecciona una palabra o frase dentro del `text_raw` de un ejemplo (texto seleccionado nativamente en el navegador) y hace click en "Proteger marca", el sistema SHALL:
1. POST al endpoint `/ia/correcciones/protected-terms` con body `{term: <texto seleccionado>, source: "modal-context", example_id: <id>}`.
2. Validar que el término tiene al menos 2 caracteres y no es un stopword común.
3. Insertar en `corrections_protected_terms` (vía `CorrectionProtectedTermsService::addFromModal`).
4. Refrescar la cache de 5 min del service.
5. Devolver 201 con `{term, id, is_new: bool}`.

#### Scenario: Admin protege "ARMOFL"
- **WHEN** admin selecciona "ARMOFL" en el text_raw del segmento y hace click en "Proteger marca"
- **THEN** el endpoint responde 201 con `{term: "ARMOFL", id: <nuevo>, is_new: true}`
- **AND** la palabra queda inmediatamente disponible para el post-filtro defensivo de los siguientes clicks en el modal.

#### Scenario: Término duplicado se rechaza idempotentemente
- **WHEN** admin intenta proteger "ARMOFL" pero ya existe en la tabla
- **THEN** el endpoint responde 200 con `{term: "ARMOFL", id: <existing>, is_new: false}` y `is_new:false` indica que no se duplicó.

### Requirement: Detección sugerida por LLM

`AiBrandSuggestionService::suggestBrands(string $text): array` SHALL invocar el LLM con un prompt dedicado ("del siguiente texto en español/inglés, lista tokens que parezcan marca, sigla o nombre propio, devuelve JSON array de strings") y SHALL aplicar el mismo post-filtro defensivo (marcas ya protegidas se excluyen de la sugerencia). La respuesta SHALL ser cacheada bajo `ai_brand_suggest:{hash(text)}:{YYYY-MM-DD}` con TTL 1 h (corto, las marcas cambian menos que el contexto).

#### Scenario: LLM sugiere marcas candidatas
- **WHEN** admin hace click en "Detectar marcas" sobre el segmento que contiene "ARMOFL", "ONU", "Word", "Diego"
- **AND** `protected_brands` ya contiene "Word" y "ONU"
- **THEN** el LLM devuelve `["ARMOFL", "Diego"]` (Word y ONU excluidos por post-filtro).
- **AND** la UI muestra los dos candidatos como checkboxes. El admin marca los que confirma y pulsa "Agregar seleccionadas".

### Requirement: Botones del modal siguen el principio "una acción por ejemplo"

Cada ejemplo SHALL tener una sola barra compacta de acciones en la parte inferior:
- "Proteger marca" — deshabilitado si no hay selección de texto.
- "Detectar marcas" — botón secundario; consume tokens.
- "Aprobar y agregar regla" — botón primario visible solo cuando hay corrección IA cargada.
- "Solo ver" / "Reintentar" — disponibles con la corrección IA cargada.
- "Cerrar" — disponible siempre.

La barra SHALL compartir tipografía y espaciado del sistema; SHALL evitar el patrón "tres tarjetas idénticas" y SHALL usar sentence case (no ALL CAPS).

#### Scenario: Layout limpio sin ALL CAPS
- **WHEN** admin abre el modal de contexto
- **THEN** la UI muestra para cada ejemplo: el `text_raw` con highlights, la corrección IA inline cuando existe, y una sola barra de acciones en sentence case ("Proteger marca", "Detectar marcas", "Aprobar y agregar regla").
- **AND** no aparecen etiquetas en ALL CAPS (e.g. "CORRECCIÓN IA", "CÓMO QUEDARÍA").
