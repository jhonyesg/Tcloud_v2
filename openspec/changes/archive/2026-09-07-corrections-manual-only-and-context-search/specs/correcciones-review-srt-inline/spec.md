# Spec Delta: correcciones-review-srt-inline

## Purpose

El modo sensibles de la lista de revisión (y la búsqueda de contexto asociada al flujo de revisión) debe responder de forma acotada y con timeout, para que el módulo siga siendo usable sin colgar workers.

---

## ADDED Requirements

### Requirement: La lista de revisión en modo sensibles responde acotada y con timeout

El modo `sensitive` de `GET /ia/correcciones/transcription-review` SHALL ejecutar su matching de reglas approved (risk medium/high) contra `transcription_segments` bajo un `statement_timeout` acotado (default 10 s, configurable) y SHALL limitar la búsqueda a las N transcripciones candidatas (default 10) antes del matching — nunca un `whereExists` sin acotar sobre el histórico completo. Si el timeout expira, el endpoint SHALL responder el listado con el conteo de matches vacío/parcial y un estado explícito de degradación, sin colgar el worker PHP-FPM ni terminar en 504 de nginx.

#### Scenario: Modo sensibles sobre 10 candidatas responde en tiempo acotado
- **WHEN** admin pide `GET /ia/correcciones/transcription-review?mode=sensitive`
- **THEN** la consulta de matching se ejecuta contra las 10 transcripciones más recientes done (no contra el histórico completo)
- **AND** la consulta corre bajo `statement_timeout` y responde sin 504.

#### Scenario: Timeout del matching no derriba el endpoint
- **WHEN** el matching trgm de una candidata excede el statement_timeout
- **THEN** el endpoint captura el error, marca esa candidata con conteo degradado y responde 200 con el resto del listado.

#### Scenario: La búsqueda de contexto de una corrección usa índice trgm
- **WHEN** admin abre "Contexto" (GET `/ia/correcciones/{id}/contexto`) de cualquier corrección
- **THEN** la búsqueda `ILIKE '%…%'` sobre `transcription_segments` usa el índice GIN pg_trgm y responde dentro del statement_timeout existente de 10 s para términos de longitud ≥ 3.