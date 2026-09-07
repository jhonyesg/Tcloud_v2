## ADDED Requirements

### Requirement: DELETE routes validate numeric ids at route level

The system MUST declare `->whereNumber(...)` constraints on every `Route::delete(...)` declaration whose controller signature expects `int $id` (or equivalent numeric param). The validation MUST happen at the route level, before reaching the controller, so that a non-numeric id returns HTTP 404 (route mismatch) instead of HTTP 500 (TypeError).

#### Scenario: DELETE with non-numeric id returns 404
- **WHEN** an authenticated client issues a DELETE request to any of:
  - `/papelera/{file}` (file is `int`)
  - `/api-transcriptor/jobs/{id}` (id is `int`)
  - `/avisos-inteligentes/{userId}/keywords/{keywordId}` (both `int`)
  - `/mis-avisos/keywords/{keywordId}` (`int`)
- **AND** the route parameter value is not numeric
- **THEN** Laravel returns HTTP 404 without invoking the controller

#### Scenario: DELETE with valid numeric id still works
- **WHEN** an authenticated client issues a DELETE with a valid numeric id on any of the routes above
- **THEN** the controller is invoked and processes the delete normally (existing behavior)
