# Spec: transcription-api-orchestrator (delta)

## ADDED Requirements

### Requirement: Todo ajuste expuesto tiene un consumidor real

Cada clave del `SCHEMA` de `TranscriptorSettings` SHALL tener al menos un consumidor que la lea **a través de la capa de settings**. Una clave que solo se menciona en comentarios, o que solo se lee con `config('transcriptor.…')`, NO SHALL exponerse en la pantalla de configuración.

La razón es de diagnóstico, no de estética: un panel con palancas que no mueven nada invita a diagnosticar con ellas. Durante la auditoría del 2026-08-20, `ai_coherence_threshold` mostraba 0,4 mientras el pase de coherencia corría con un corte fijo de 0.5, y `ai_coherence_model` no lo leía nadie.

Cuando un criterio deba fijarse en código y no en la UI, SHALL vivir como constante nombrada junto a la lógica que gobierna, con el motivo escrito.

Cada clave del `SCHEMA` SHALL existir también en `config/transcriptor.php` con el mismo default. Sin esa correspondencia el valor efectivo sigue saliendo del esquema, pero la pantalla informa un origen ("archivo") que no es cierto.

#### Scenario: Se propone un ajuste nuevo
- **WHEN** se añade una clave al `SCHEMA`
- **THEN** existe un consumidor que la lee vía `TranscriptorSettings`, y la clave está declarada en `config/transcriptor.php` con el mismo default

#### Scenario: Un ajuste se queda sin consumidor
- **WHEN** un cambio deja una clave sin nadie que la lea
- **THEN** la clave se retira del `SCHEMA` en el mismo cambio, y si el valor sigue haciendo falta pasa a ser una constante junto a su lógica

#### Scenario: Verificación automática de la correspondencia
- **WHEN** se ejecuta la suite de tests
- **THEN** `TranscriptorSettingsTest` comprueba que toda clave del esquema tiene respaldo en `config/transcriptor.php` y falla si alguna no lo tiene

### Requirement: Los topes de interfaz se sirven desde la capa de settings

Los límites que la interfaz aplica del lado del navegador —tope del deslizador de lote, máximo de envíos en paralelo y tamaño de lote por defecto— SHALL entregarse a la vista desde `TranscriptorSettings`, en el mismo payload que el resto de datos de la página, y NO SHALL leerse de `config()` al renderizar.

El servidor clampea `processBatch` con `ui_batch_max` de la capa de settings. Si la vista pinta un tope distinto, el usuario puede pedir lotes que el servidor recorta sin decírselo: las dos mitades tienen que salir de la misma fuente.

#### Scenario: Override guardado sin abrir la pestaña de configuración
- **WHEN** un admin guarda `ui_batch_max = 75` y otro admin abre `/ia/api-transcriptor` sin entrar en la pestaña Configuración
- **THEN** la página pinta 75 como tope del deslizador desde la primera carga, el mismo valor con el que el servidor clampeará

#### Scenario: Sin override
- **WHEN** no hay override guardado
- **THEN** la vista recibe el valor de `config/transcriptor.php`, sin cambio de comportamiento respecto a antes
