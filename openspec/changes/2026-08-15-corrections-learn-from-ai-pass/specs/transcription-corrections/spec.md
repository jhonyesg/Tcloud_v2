# Spec: Aprender de las correcciones IA para alimentar el diccionario

## ADDED Requirements

### Requirement: El pase IA propone pares aprendidos como correcciones pending

El sistema SHALL, cuando el pase de coherencia IA corrige un segmento, extraer los pares `wrong → correct` (diferencia entre `text_raw` y `text`) y proponerlos como correcciones `pending` para revisión humana.

#### Scenario: Segmento corregido por IA genera un par aprendido
- **WHEN** el pase IA corrige "in this moment" → "en este momento" en un segmento
- **THEN** el sistema extrae el par `wrong="in this moment"`, `correct="en este momento"`
- **AND** lo inserta como `Correction` con `status=pending`, `source='ai-coherence-learn'`, `risk_level=medium`

#### Scenario: Par ya existente no se duplica
- **WHEN** el par extraído ya existe como `pending` o `approved` (mismo `wrong_normalized`)
- **THEN** el sistema NO crea duplicado (idempotencia)

#### Scenario: Par de baja calidad se descarta
- **WHEN** el par es un nombre propio, marca, o un segmento entero (> 4 palabras)
- **THEN** el sistema NO lo propone (filtro de calidad)

#### Scenario: Tope por transcripción
- **WHEN** una transcripción genera más de N pares aprendidos
- **THEN** el sistema solo propone los primeros N (control de volumen)

#### Scenario: Admin aprueba el par aprendido
- **WHEN** el admin aprueba un par `pending` con `source='ai-coherence-learn'`
- **THEN** entra al diccionario activo y se aplicará en la primera pasada de transcripciones futuras
