# Design: corrections-ai-context-aware-with-mark-curation

## Context

Ver proposal.md — Why. Cambios:

1. El modal de contexto hoy dispara `AiContextCorrectService::suggest()` con `wrong_text + text_raw` aislados. El LLM devolvió "no se puede reconstruir" (captura del admin 2026-09-05) porque no tiene los vecinos ±5 segmentos que le permitirían entender el dominio.
2. El admin quiere además poder **agregar marcas** a `protected_brands` desde el modal (caso "ARMOFL es una marca" → "Aceptar" → "Listo, aceptar") sin salir ni deployar.
3. La UI actual cae en patrones AI-generados que la skill `frontend-design` marca como tells: cream background implícito del layout, ALL CAPS en etiquetas (`CORRECCIÓN IA`, `CÓMO QUEDARÍA`), tres tarjetas idénticas con el mismo border-radius, "→" al final del texto de los botones, copy descriptiva ("Corregir esta frase con IA") en lugar de verbos activos.

## Goals / Non-Goals

Goals:
- **Deep module** `AiContextAwareService` con una sola entrada `correctExample(correction, example, forceFresh)`. Vecinos ±5, prompt, cache, post-filtro defensivo: **todo oculto tras la interfaz**.
- **Deep module** `AiBrandSuggestionService` con una sola entrada `suggestBrands(text)`. Prompt, cache, post-filtro de marcas protegidas: ocultos tras la interfaz.
- **Markup curatorial manual** vía `CorrectionProtectedTermsService::addFromModal(term, source, exampleId)`. Idempotente, anti-duplicados, refresh de cache.
- **Rediseño del modal** aplicando `frontend-design`: sentence case, timeline visual (no tres tarjetas), verbos activos en CTAs, motion solo en respuesta a acciones del admin.
- **Mismas gates** que el flujo anterior: master switch + API key + post-filtro anti-marca.
- **Tests** unitarios con introspección de contratos (mismo patrón que el change anterior).

Non-Goals:
- No automatizar curación ni auto-aprobar traducciones.
- No traducir transcripciones completas de un archivo.
- No reemplazar al suggester global; ambos coexisten con orígenes distintos.
- No agregar nuevo `status` ni nuevas tablas; usa `corrections_protected_terms` ya existente.

## Decisions

### D1 — Deep module `AiContextAwareService` con una entrada

Por qué no seguir la estructura del suggester global (`LlmCorrectionSuggester` tiene ~470 líneas con 8 métodos públicos): aplicar la skill `codebase-design` literalmente. El servicio nuevo expone **una sola función pública**:

```php
final class AiContextAwareService {
    public function correctExample(
        Correction $parent,
        array $example,
        bool $forceFresh = false,
        ?int $neighborWindow = 5
    ): array;
}
```

Todo lo demás (consulta de vecinos, construcción del prompt, gate de settings, cache hit/miss, post-filtro de marcas, llamada al LLM, mapeo de errores) queda **privado** dentro del módulo. El caller (controlador, futura skill de Kilo) no debe aprender cómo se hace; solo qué recibe.

**Profundidad**: la interfaz tiene 4 parámetros (uno opcional, dos con defaults). El comportamiento que habilita es grande: vecinos + prompt + cache + post-filtro + persistencia + telemetría. Localidad para el mantenedor: cualquier cambio al pipeline vive en este archivo.

**Alternativa descartada**: copiar la estructura de `AiContextCorrectService` con métodos públicos `findNeighbors`, `buildPrompt`, `callLlm`, `filterByBrands`. Eso viola el principio "depth is a property of the interface, not the implementation": cada uno de esos métodos es un punto donde un caller podría romper la invariante.

### D2 — `AiBrandSuggestionService` como módulo separado

Interfaz:

```php
final class AiBrandSuggestionService {
    /** @return array<int, string> Lista de marcas candidatas (deduplicadas, sin las ya protegidas) */
    public function suggestBrands(string $text): array;
}
```

Profundidad: oculta el prompt específico, la cache de 1 h por hash del texto, y el post-filtro contra marcas ya protegidas. Localidad: si en el futuro queremos detectar empresas, nombres de productos o variantes regionales, todo cambia aquí.

### D3 — Vecinos: query single-shot, no N+1

El servicio consulta `transcription_segments` por `transcription_id` con `WHERE segment_index BETWEEN (current - 5) AND (current + 5)`, una sola query. Ordena en PHP por `segment_index`. Coste esperado: ~5-15ms por click en BD ya con índices (`transcription_segments_transcription_id_index`). Incluye el segmento objetivo en el resultado y lo etiqueta `#[OBJETIVO]` en el prompt para que el LLM no confunda.

### D4 — Prompt estructurado por capas

Cuatro secciones en el system prompt, en este orden:
1. Rol y reglas estrictas (no inventar, no marcas, no sigles).
2. **Snippet de contexto** con etiquetas `#[-5]` a `#[+5]` (las que existan), `#[OBJETIVO]` marca el segmento a corregir.
3. Regla padre (`arms of fuel → arms de fuel`) como referencia, no como destino.
4. Forma del JSON de respuesta con `risk`.

El snippet va **antes** del `text_raw` para que el LLM lea primero el dominio. Si los vecinos están vacíos (segmento al inicio/fin de la transcripción), el prompt lo declara explícitamente y el LLM sabe que no debe inventar.

### D5 — Cache key incluye la ventana de vecinos

Clave: `ai_context_aware:{correction_id}:{example_id}:{neighbor_window}:{YYYY-MM-DD}`. TTL configurable (`config('corrections.ai_context_aware.cache_ttl', 86400)`, default 24 h). Re-abrir el modal sin cambios → cache hit, sin gastar tokens.

La fecha entra en la clave porque el admin puede editar la regla padre o los vecinos; queremos una respuesta fresca al día siguiente.

### D6 — Persistencia como regla `wrong → correct` del segmento completo

El botón Aprobar persiste `wrong_text = example['text_raw']` (segmento completo, hasta 2000 chars) y `correct_text = candidate['correct']`. Esto cubre el caso "vamos a traducirla para agregarla basada en el contexto": la traducción queda como regla que el motor `applyToText` aplica retroactivamente a otras transcripciones donde aparezca el mismo fragmento.

Idempotencia: `wrong_normalized` colisiona → 409 con `existing_id`. Si el admin quiere "actualizar" la regla, va a Pendientes, edita manualmente.

### D7 — Markup manual de marcas vía `CorrectionProtectedTermsService::addFromModal`

El endpoint `POST /ia/correcciones/protected-terms` con `{term, source, example_id}` llama a un nuevo método público del service existente:

```php
public function addFromModal(string $term, ?int $exampleId, int $userId): array
```

Devuelve `{term, id, is_new}`. Refresca la cache de 5 min del service automáticamente. Anti-duplicados: SELECT por `lower(term) ASC` (BD única con índice único en lower term) → si existe, devuelve la fila con `is_new: false`.

Validaciones:
- `mb_strlen(trim($term)) >= 2`
- No es un stopword común (lista hardcoded: artículos, preposiciones, pronombres, números).
- No es el `wrong_text` exacto de una corrección existente (eso es un false positive casi seguro).

### D8 — Frontend-design aplicado al modal

Aplicación literal de la skill `frontend-design`:

| Patrón AI-generado actual | Lo que cambia |
|---|---|
| Etiquetas en ALL CAPS (`CORRECCIÓN IA`, `CÓMO QUEDARÍA`, `COMO LO TRANSCRIBIÓ`) | Sentence case. La copy visible usa solo frases naturales. |
| Tres tarjetas idénticas con `border-radius` uniforme | Un timeline por ejemplo: el `text_raw` con highlights en una sola columna; la corrección IA aparece como **un nodo anexo al mismo renglón**, no como caja separada. |
| Botón descriptivo ("Corregir esta frase con IA") | Verbo activo: "Traducir este segmento con IA". |
| `→` al final del texto de los botones | Sin `→`. El motion al click basta. |
| Fade-and-slide-up en cada sección al cargar | Motion solo en respuesta a acciones del admin (abrir modal, click en aprobar, etc.). El modal se monta plano. |
| Copy técnica (`reason` italic) | El `reason` se mantiene (es información útil para el admin), pero se mueve a un tooltip al lado del resultado, no como italic visible. |
| Color de acento `#7c3aed` (violeta) repetido en todos los botones | Una sola paleta: primario = violeta para CTA; neutro = slate para secundarias; ámbar para errores. Mantener la actual pero **sin el verde-emerald repetido** (lo separamos a un "match" sutil inline, no a una caja verde). |

Tipografía: `font-sans` ya cargada (Inter por default del proyecto). Tamaño base 14px (no 12px como en la UI actual que se ve pequeña). Line-height 1.6 para texto largo (segmentos del ASR). Labels de la barra compacta: `text-xs font-medium uppercase tracking-wide` **fuera**; cambiar a `text-xs font-medium text-slate-600` (sentence case).

### D9 — Sin motion AI-generado en cada load

El modal no debe animar entradas al abrir. Solo anima en respuesta a una acción del admin: el segmento de corrección aparece con un fade-in de 150ms cuando el LLM responde; las marcas detectadas aparecen con un slide de 100ms al expandir la lista. La apertura del modal es instantánea (opacity 0 → 1, sin translate).

### D10 — Copys del usuario (voice and tone)

Acción → verbo:
- "Traducir este segmento con IA" (no "Corregir esta frase con IA")
- "Proteger marca" (no "Agregar a marcas protegidas")
- "Detectar marcas" (no "Sugerir marcas con IA")
- "Agregar seleccionadas" (no "Confirmar selección")
- "Aprobar y agregar regla" (no "Aprobar y crear regla")
- "Reintentar" (sin cambios)
- "Solo ver" (sin cambios)

Mensajes:
- Estado vacío: "No hay ejemplos en la ventana actual."
- Error genérico: "No pudimos conectar con el modelo. Reintenta."
- Éxito: "Marca protegida: «ARMOFL». Se aplicará en las próximas correcciones."

Tono: profesional, sin emoji decorativo. La skill `frontend-design` lo prohibe explícitamente ("failure as direction, not mood").

## Risks / Trade-offs

- [Coste por click] Vecinos ±5 → +~1k tokens input, +200 tokens output esperados. Coste total ~1,5-2k tokens. Admin consciente de que "Reintentar" consume.
- [Traducciones largas como `wrong`] El motor `applyToText` busca por substring; un `wrong` de 100+ chars es más restrictivo y baja falsos positivos. Ventaja; nada que mitigar.
- [Selección de texto cross-browser] `document.getSelection()` funciona en Chromium/Firefox/Safari. No en navegadores viejos (IE). El proyecto usa Chrome/Chromium mayoritariamente (basado en el e2e con Playwright). No se mitiga.
- [Marcar como marca una palabra que no es] El admin decide; el botón es explícito ("Proteger marca") y queda trazable vía `source='modal-context', example_id`. Si marca mal, puede revertir desde la UI de exclusiones.
- [Motion intencional] Aplicar la skill `frontend-design` significa reducir el motion actual; admins acostumbrados a ver fades pueden notar la falta. Aceptamos por consistencia visual con el resto del sistema.

## Migration Plan

Sin migraciones de BD. Deploy:
1. Code: `AiContextAwareService`, `AiBrandSuggestionService`, `ProtectedTermsInlineController`, rediseño del modal.
2. `php artisan view:clear` (Blade cacheado).
3. Verificación manual: admin abre modal, traduce un ejemplo con vecinos; marca una marca; aprueba.
4. Rollback: `git revert` + eliminar el nuevo endpoint. La cache `ai_context_aware:*` en Redis expira sola en 24 h.

## Open Questions

- ¿La curación manual debe distinguir entre "marca protegida" (de solo lectura) y "exclusión del diccionario" (no se aplica la regla aquí)? Por ahora todas entran como `protected_terms`; las exclusiones reales viven en otra tabla. No bloqueante.
