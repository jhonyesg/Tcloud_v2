# Spec: Pase de coherencia IA sobre segmentos con inglés residual

## ADDED Requirements

### Requirement: Segmentos con inglés residual se corrigen con IA a español coherente

El sistema SHALL, al persistir los segmentos de una transcripción, aplicar un pase de coherencia IA sobre los segmentos que el diccionario no pudo corregir (inglés residual), produciendo un `text` en español coherente.

#### Scenario: Segmento con spanglish se corrige con IA
- **WHEN** un segmento tiene inglés residual (score >= `ai_coherence_threshold`) y `ai_coherence_enabled=true`
- **THEN** el sistema envía el segmento al LLM configurado y guarda el texto corregido en `text`
- **AND** `text_raw` conserva el original del transcriptor (inmutable)

#### Scenario: Segmento sin inglés residual no se toca
- **WHEN** un segmento tiene score < `ai_coherence_threshold`
- **THEN** el sistema NO lo envía al LLM (ahorro de costo/latencia) y conserva el texto del diccionario

#### Scenario: Tope de segmentos por transcripción
- **WHEN** una transcripción tiene más de `ai_coherence_max_segments` segmentos flagged
- **THEN** el sistema solo corrige los primeros N (los más recientes) y deja el resto con el texto del diccionario

#### Scenario: Fallo del LLM no rompe el parseo
- **WHEN** el LLM falla (timeout, HTTP error, respuesta inválida)
- **THEN** el sistema conserva el texto del diccionario (sin IA) y loguea el error
- **AND** la transcripción se guarda normalmente (state=done)

#### Scenario: Nombres propios y marcas se respetan
- **WHEN** el LLM corrige un segmento con nombres propios (Cali, Bogotá, Quindío) o marcas
- **THEN** el texto corregido conserva esos nombres sin alterarlos

## MODIFIED Requirements

### Requirement: Correcciones se aplican en el parseo del SRT (ampliado)

El sistema SHALL aplicar primero el diccionario de correcciones (`applyToSegments`) y luego el pase de coherencia IA sobre los segmentos con inglés residual, en ese orden. El diccionario sigue siendo el primer barrido (rápido y gratis); la IA solo complementa donde el diccionario no cubre.

#### Scenario: Diccionario primero, IA después
- **WHEN** se persisten los segmentos de una transcripción
- **THEN** el sistema aplica primero el diccionario de correcciones a todos los segmentos
- **AND** luego aplica el pase de coherencia IA solo a los segmentos que aún tienen inglés residual
- **AND** el resultado final en `text` es español coherente, sin spanglish
