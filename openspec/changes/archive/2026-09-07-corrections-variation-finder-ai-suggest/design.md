## Context

El Variation Finder muestra 100+ variantes literales. El admin tiene que:
1. Leer cada fila
2. Identificar cuáles son typos del MISMO nombre vs nombres distintos
3. Decidir un `correct` canónico por grupo
4. Crear las reglas bulk

Pasos 1-3 son trabajo mecánico que un LLM puede hacer mejor y más rápido. Paso 4 sigue siendo del admin (validación). Este change agrega el atajo "AI Suggest" que automatiza 1-3.

Es complementario a `corrections-ai-suggest` (escaneo global sin scope) y a `corrections-ai-context-aware-with-mark-curation` (corrección de 1 ejemplo). Éste específicamente: SQL trae variantes, LLM las agrupa.

## Goals / Non-Goals

**Goals:**
- Endpoint POST que agrupa variantes "Sin regla" vía LLM.
- Modal con grupos + checkboxes pre-seleccionados según confidence.
- Edit inline del `canonical_correct` por grupo.
- Bulk-create por grupo (reusa endpoint existente).
- Cost preview opcional antes de la llamada LLM.
- Idempotente: no crea rows si no hay confirmación.

**Non-Goals:**
- Sin fuzzy matching — el LLM sólo agrupa variantes literales que ya están en la lista.
- Sin reemplazar Variation Finder manual — es opcional.
- Sin editar reglas existentes.
- Sin tocar `CorrectionService::applyRetroactively()`.
- Sin cambiar el motor de matching.
- Sin cron automático — todo es manual bajo demanda explícita del admin.

## Decisions

### 1. Prompt especializado con instrucciones anti-marca y de agrupación

```php
$prompt = <<<EOT
Sos un asistente de normalización de nombres para un diccionario de transcripciones en español.

La palabra objetivo que el usuario buscó es: "{$word}".

Estas son las variantes literales encontradas en su corpus (cada una con su frecuencia):
{$variantsList}

Tu tarea:
1. Agrupá las variantes que son typos fonéticos o tipográficas DEL MISMO nombre.
   - Ejemplo: "Abelardo de las Prias", "Abelardo de las Prieya" → mismo grupo (typos de "Espriella")
2. NO agrupes nombres distintos aunque compartan palabras.
   - Ejemplo: "Alberto de las Pellas" NO va con "Abelardo de las Pellas"
3. Para cada grupo, proponé una canonical_correct razonable (la forma canónica en español).
4. Asigná confidence 0-1 a cada variante según qué tan seguro estés de que pertenece al grupo.

Devolvé JSON estricto con este shape:
{
  "groups": [
    {
      "canonical_correct": "Abelardo de la Espriella",
      "reason": "Todas son variantes fonéticas de Espriella",
      "variants": [
        {"wrong": "Abelardo de las Prias", "count": 4, "confidence": 0.95},
        ...
      ]
    }
  ]
}
EOT;
```

**Rationale:** el prompt le da al LLM reglas explícitas anti-marca (no agrupar nombres distintos) y formato JSON estricto para que el parser PHP no se rompa. La `confidence` permite al UI filtrar visualmente las propuestas dudosas.

**Alternatives considered:**
- "Free-form" prompt sin instrucciones: respuestas inconsistentes, requiere más parsing.
- Few-shot examples: añade tokens innecesarios; las reglas son claras sin ejemplos.

### 2. Parsing defensivo de la respuesta JSON

```php
$rawText = $response->content;
$decoded = json_decode($rawText, true);
if (!is_array($decoded) || !isset($decoded['groups'])) {
    throw new AiParseException('LLM returned invalid JSON', $rawText);
}
foreach ($decoded['groups'] as $group) {
    if (!isset($group['canonical_correct'], $group['variants'])) {
        throw new AiParseException('Group missing required fields', json_encode($group));
    }
    foreach ($group['variants'] as $v) {
        if (!isset($v['wrong'], $v['confidence'])) {
            throw new AiParseException('Variant missing fields', json_encode($v));
        }
    }
}
```

**Rationale:** los LLMs a veces envuelven JSON en ```json ... ``` markdown fences. Necesitamos strip + retry. Si falla, devolvemos 503 con `raw_excerpt` para que el admin pueda copiar/pegar al issue tracker.

### 3. UI del modal: confianza decide pre-check

```js
confidence ≥ 0.8 → marcado por default, sin warning
confidence 0.5-0.8 → NO marcado, warning icon visible con reason
confidence < 0.5 → row colapsado por default (admin debe expandir para ver)
```

**Rationale:** 0.8 es un threshold común para "alta confianza" en clasificación LLM. Mostrar warnings explícitos previene que el admin apruebe propuestas malas sin darse cuenta.

### 4. Reutilizar endpoint bulk-create existente

Por cada grupo que el admin confirma, llamar `POST /ia/correcciones/variations/bulk-create` con `{ variants: [...], correct: canonical_correct }`.

**Rationale:** el endpoint ya implementa:
- Validación de duplicados (rollback de la transacción)
- Source tag `variation-finder-<YYYY-MM-DD>`
- pending status

Reusarlo evita duplicar lógica y mantiene consistencia.

**Alternatives considered:**
- Crear endpoint `POST /ia/correcciones/variations/ai-suggest/apply` que reciba todos los grupos juntos → atómico pero más complejo. Una falla a mitad aborta todo.
- Múltiples bulk-create separados (1 por grupo) → chosen. Si grupo 2 falla, grupos 1 y 3 quedan aplicados.

### 5. Cost preview con doble llamada

```php
if ($request->input('confirm_cost') === true) {
    $tokens = $this->estimateTokens($variants);
    $costUsd = $this->estimateCostUsd($tokens, $model);
    return ['estimate' => ['variants_count' => count($variants), 'estimated_input_tokens' => $tokens, 'estimated_cost_usd' => $costUsd]];
}
// else: actually call LLM and return full result
```

**Rationale:** el usuario pidió explícitamente "que use IA" pero con conciencia de costo. Una segunda llamada para el preview es ~50ms y le da al admin control antes de gastar tokens.

### 6. El LLM sólo sugiere, NUNCA crea

El endpoint AI Suggest es **read-only** sobre la BD. Solo devuelve sugerencias. La creación de reglas es 100% decisión del admin via el botón "Confirmar" → bulk-create.

**Rationale:** principio de mínima automatización. Si el LLM tiene un mal día, ningún row se corrompe. El admin tiene control total del flujo de aprobación.

## Risks / Trade-offs

- **Costo de tokens**: ~$0.001-$0.01 por query según modelo (GPT-4o-mini vs GPT-4o). El preview mitiga surprises.
- **Latencia del LLM**: 5-30s según modelo y tamaño del input. El endpoint devuelve 503 con timeout de 30s para no colgar la UI.
- **Agrupaciones incorrectas**: el admin DEBE revisar los grupos. La UI muestra confidence explícita.
- **Variantes nuevas después del LLM**: si entre la sugerencia y la confirmación el admin corre otra búsqueda, las variantes pueden cambiar. El endpoint reusa `findVariations` con los mismos parámetros, así que el admin ve el snapshot consistente.

## Migration Plan

Sin migración de BD. Rollout:
1. **Pre-deploy**: verificar que `llm-correction.enabled = 0` en producción (master switch OFF por default). El botón aparece deshabilitado con tooltip.
2. **Deploy**: mergear + deploy. El admin debe activar el switch manualmente desde AI Suggest.
3. **Verificación manual**:
   - Con switch OFF → ver botón deshabilitado.
   - Activar switch + API key → ver botón habilitado.
   - Click → ver modal con 3-5 grupos propuestos (puede variar según LLM).
   - Confirmar grupo pequeño (3 variants) → ver 3 pending en tab Pendientes.
4. **Rollback**: revert del merge. El endpoint anterior (variations/find) sigue funcionando.

## Open Questions

- **¿El LLM debería también proponer variantes NUEVAS no presentes en la lista?** Decisión: NO. El motor de fuzzy matching es un change aparte (`corrections-fuzzy-match`). Este sólo agrupa lo que está.
- **¿Cache de respuestas por (word, scope)?** Decisión: NO en este change. El LLM puede dar respuestas distintas cada vez (mejores con modelos nuevos). Cache agregaría complejidad de invalidación.
