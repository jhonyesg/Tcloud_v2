## ADDED Requirements

### Requirement: Listado de matches del cliente respeta `transcription_access`

El sistema SHALL limitar la lista de `KeywordMatch` que el cliente ve en `/mis-avisos` y `/mis-avisos/corrections/mine` a aquellos cuyas transcripciones pertenecen a storages en los que el usuario autenticado tiene `transcription_access = true`. Matches históricos de storages sin acceso SHALL permanecer visibles (filtro prospectivo, no retroactivo).

#### Scenario: Cliente con acceso al storage ve el match
- **WHEN** el cliente "prueba" abre `/mis-avisos` y existe un `KeywordMatch` suyo cuya transcripción es del storage 11
- **AND** `user_storages(prueba, 11).transcription_access = true`
- **THEN** el match aparece en su listado

#### Scenario: Cliente sin acceso al storage no ve el match nuevo
- **WHEN** el cliente "prueba" abre `/mis-avisos` y llega un `KeywordMatch` suyo del storage 11
- **AND** `user_storages(prueba, 11).transcription_access = false`
- **THEN** el match NO aparece en el listado (porque el KeywordMatcher upstream ya lo bloqueó)

#### Scenario: Match histórico de storage sin acceso sigue visible
- **WHEN** el cliente "prueba" tiene un match del storage 11 del 2026-08-15 y al 2026-08-21 el admin le revocó el acceso a 11
- **THEN** ese match histórico sigue apareciendo en su listado

#### Scenario: Cliente sin acceso a ningún storage ve listado vacío
- **WHEN** el cliente no tiene `user_storages.transcription_access = true` para ningún storage
- **THEN** `/mis-avisos` muestra el estado vacío "Aún no se han detectado coincidencias" aunque el `KeywordMatcher` haya generado matches históricos
